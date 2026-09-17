<?php

/**
 * Acción administrativa: concluye directamente en SIHOS (por aceptación
 * EPS/EAPB) una glosa "en curso" cuya factura referenciada ya tiene saldo
 * de cartera $0 — el hallazgo central del reporte de Auditoría Glosa (ver
 * SihosAuditoriaGlosaService). Escribe AnotGlos + un documento GLC que
 * revierte la cuenta de orden "en trámite" (8333/8915) + AnotCeCo +
 * DetaFaCr, SIN tocar cartera ni cuenta de ingreso real (esas solo se
 * tocan con aceptación IPS, no EAPB — decisión explícita del usuario
 * 2026-09-16/17, verificada contra 5 casos reales de producción).
 *
 * Mismo patrón que SihosCancelacionCuentaService: conexión de escritura
 * separada de la de solo lectura, re-verificación en fresco dentro de la
 * transacción, chequeo de cierre de período, y registro manual en
 * `auditoria` de SAVID (AuditingPDO no cubre esta escritura externa).
 *
 * Alcance v1 (deliberadamente acotado): solo glosas con EXACTAMENTE un
 * concepto en DetaGlos y una contabilización original de EXACTAMENTE 2
 * líneas (cuenta de orden + contra-cuenta) — ver docblock de
 * SihosExternalRepository::fetchEstadoParaConcluirGlosaAceptacionEps().
 * Multi-concepto/multi-línea se deja para una fase posterior.
 */
class SihosGlosaConclusionService
{
    private SihosEmpresaConfigRepository $configRepository;
    private SihosUsuaDigiResolver $usuaDigiResolver;

    public function __construct()
    {
        $this->configRepository = new SihosEmpresaConfigRepository();
        $this->usuaDigiResolver = new SihosUsuaDigiResolver();
    }

    public function concluirAceptacionEps(int $empresaId, string $codiDocuGlosa, string $numeGlosa, string $fechaCorte): array
    {
        $configFila = $this->configRepository->findByEmpresaId($empresaId);
        if ($configFila === null || $configFila['host'] === '' || $configFila['base_datos'] === '') {
            return ['ok' => false, 'message' => 'Esta empresa no tiene conexión a SIHOS configurada.'];
        }

        $codiInst = trim((string)($configFila['codi_inst'] ?? ''));
        $usuarioEscritura = trim((string)($configFila['usuario_escritura'] ?? ''));
        if ($codiInst === '' || $usuarioEscritura === '' || empty($configFila['password_escritura_cifrado'])) {
            return [
                'ok' => false,
                'message' => 'Configure las credenciales de escritura de esta empresa en Conexión SIHOS antes de usar esta acción.',
            ];
        }

        $repositorioLectura = new SihosExternalRepository([
            'host' => $configFila['host'],
            'port' => (string)$configFila['puerto'],
            'database' => $configFila['base_datos'],
            'username' => $configFila['usuario'],
            'codiInst' => $codiInst,
            'password' => SihosCredentialCipher::decrypt($configFila['password_cifrado'] ?? null),
            'charset' => $configFila['charset'],
        ]);

        try {
            $estado = $repositorioLectura->fetchEstadoParaConcluirGlosaAceptacionEps($codiDocuGlosa, $numeGlosa);
        } catch (PDOException $e) {
            return ['ok' => false, 'message' => 'No se pudo consultar SIHOS: ' . $e->getMessage()];
        }

        if ($estado === null) {
            return [
                'ok' => false,
                'message' => "La glosa {$codiDocuGlosa}-{$numeGlosa} ya no cumple las condiciones para concluir "
                    . '(no existe, está anulada, no tiene exactamente un concepto/2 líneas contables, o ya fue concluida antes).',
            ];
        }

        try {
            $maneNIIF = $repositorioLectura->fetchManeNIIF();
        } catch (PDOException $e) {
            return ['ok' => false, 'message' => 'No se pudo verificar la configuración NIIF de SIHOS: ' . $e->getMessage()];
        }

        if ($maneNIIF) {
            try {
                $homologaciones = $repositorioLectura->fetchHomologacionesNIIF([$estado['CuentaOrden'], $estado['CuentaContra']]);
            } catch (PDOException $e) {
                return ['ok' => false, 'message' => 'No se pudo verificar la homologación NIIF en SIHOS: ' . $e->getMessage()];
            }

            $faltantes = array_diff([$estado['CuentaOrden'], $estado['CuentaContra']], array_keys($homologaciones));
            if ($faltantes !== []) {
                return [
                    'ok' => false,
                    'message' => 'Esta institución maneja NIIF y falta homologación (HomoNIIF) para: ' . implode(', ', $faltantes)
                        . ' — configúrela en SIHOS antes de continuar.',
                ];
            }
        }

        try {
            $fechaResuelta = $this->resolverFechaNotaContable($repositorioLectura, $estado['FechDocu']);
        } catch (PDOException $e) {
            return ['ok' => false, 'message' => 'No se pudo verificar el cierre de periodos en SIHOS: ' . $e->getMessage()];
        }

        if ($fechaResuelta === null) {
            return [
                'ok' => false,
                'message' => 'No se encontró un mes contable abierto en SIHOS en un rango razonable — revise los cierres del módulo de Contabilidad.',
            ];
        }

        [$fecha, $codiAno, $codiMes] = $fechaResuelta;

        try {
            $codigosGlc = $repositorioLectura->resolveCodigosDocumentoPorAplicacionSinFiltroPresupuesto([68]);
        } catch (PDOException $e) {
            return ['ok' => false, 'message' => 'No se pudo resolver el tipo de documento GLC en SIHOS: ' . $e->getMessage()];
        }

        if ($codigosGlc === []) {
            return [
                'ok' => false,
                'message' => 'No se encontró el tipo de documento "Glosa Aceptada o Concluida" (DocuApli=68) configurado en SIHOS para esta empresa.',
            ];
        }
        $codiDocuGlc = $codigosGlc[0];

        $fechaCorteValida = DateTime::createFromFormat('Y-m-d', $fechaCorte);
        $fechOfic = $fechaCorteValida !== false ? $fechaCorteValida->format('Y-m-d') : $fecha;
        $numeOfic = 'SAVID' . ($fechaCorteValida !== false ? $fechaCorteValida->format('Ymd') : date('Ymd'));
        $obseGlos = sprintf(
            'SAVID: se concluye glosa por aceptacion EPS - saldo de cartera de la factura %s-%s en 0 al %s.',
            $estado['TiDoRefe'],
            $estado['NuDoRefe'],
            $fechaCorte
        );

        $repositorioEscritura = new SihosExternalWriteRepository([
            'host' => $configFila['host'],
            'port' => (string)$configFila['puerto'],
            'database' => $configFila['base_datos'],
            'username' => $usuarioEscritura,
            'codiInst' => $codiInst,
            'password' => SihosCredentialCipher::decrypt($configFila['password_escritura_cifrado']),
            'charset' => $configFila['charset'],
        ]);

        try {
            $resultado = $repositorioEscritura->concluirGlosaAceptacionEps(
                $codiDocuGlosa,
                $numeGlosa,
                $codiDocuGlc,
                $fecha,
                $codiAno,
                $codiMes,
                $maneNIIF,
                $obseGlos,
                $numeOfic,
                $fechOfic,
                $this->usuaDigiResolver->resolver((int)($_SESSION['user_id'] ?? 0), $repositorioLectura)
            );
        } catch (SihosOperacionEnCursoException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        } catch (PDOException $e) {
            return ['ok' => false, 'message' => 'No se pudo escribir en SIHOS: ' . $e->getMessage()];
        }

        if (!$resultado['ok']) {
            return ['ok' => false, 'message' => $resultado['motivo'] ?? 'No se pudo completar la acción en SIHOS.'];
        }

        $this->registrarAuditoria($empresaId, $codiInst, $codiDocuGlosa, $numeGlosa, $resultado);

        return [
            'ok' => true,
            'message' => "Se concluyó la glosa {$codiDocuGlosa}-{$numeGlosa}: documento {$resultado['codiDocuGlc']}-{$resultado['numeDocuGlc']} en SIHOS ({$fecha}), "
                . number_format($resultado['valorGlosa'], 0, ',', '.') . ' revertido de la cuenta de orden.',
        ];
    }

    /**
     * Primer día del mes contable (Modulo=22) que esté abierto, empezando a
     * buscar desde el año/mes de la glosa hacia adelante (tope 36 meses).
     * Mismo criterio que SihosCancelacionCuentaService::resolverFechaNotaContable().
     */
    private function resolverFechaNotaContable(SihosExternalRepository $repositorioLectura, string $fechaGlosa): ?array
    {
        $anno = (int)substr($fechaGlosa, 0, 4);
        $mes = (int)substr($fechaGlosa, 5, 2);

        for ($i = 0; $i < 36; $i++) {
            $codiAno = (string)$anno;
            $codiMes = (string)$mes;

            if (!$repositorioLectura->isContabilidadCerrada($codiAno, $codiMes)) {
                $fecha = sprintf('%04d-%02d-01', $anno, $mes);

                return [$fecha, $codiAno, $codiMes];
            }

            $mes++;
            if ($mes > 12) {
                $mes = 1;
                $anno++;
            }
        }

        return null;
    }

    /**
     * Registro manual en la auditoría de SAVID: AuditingPDO solo cubre
     * escrituras en la BD propia de SAVID, no ésta contra SIHOS. Mismo
     * formato que SihosCancelacionCuentaService::registrarAuditoria().
     */
    private function registrarAuditoria(int $empresaId, string $codiInst, string $codiDocuGlosa, string $numeGlosa, array $resultado): void
    {
        $database = new Database();
        $pdo = $database->connect();

        $stmt = $pdo->prepare('
            INSERT INTO auditoria (
                accion, tabla, registro_id,
                datos_anteriores, datos_nuevos, campos_cambiados,
                sql_resumen, usuario_id, empresa_id, sede_id,
                ip, user_agent, request_url
            ) VALUES (?, ?, ?, NULL, ?, NULL, ?, ?, ?, ?, ?, ?, ?)
        ');

        $stmt->execute([
            'INSERT',
            'sihos.AnotGlos',
            "{$codiInst}-{$codiDocuGlosa}-{$numeGlosa}",
            json_encode($resultado, JSON_UNESCAPED_UNICODE),
            "Conclusión de glosa en SIHOS por aceptación EPS (saldo cartera $0): {$codiDocuGlosa}-{$numeGlosa} -> "
                . "{$resultado['codiDocuGlc']}-{$resultado['numeDocuGlc']}",
            $_SESSION['user_id'] ?? null,
            $empresaId,
            $_SESSION['sede_id'] ?? null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
            $_SERVER['REQUEST_URI'] ?? null,
        ]);
    }
}
