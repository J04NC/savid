<?php

/**
 * Acciones administrativas sobre DetaPlan en SIHOS: borrar el huérfano de
 * una nota sobre una factura de vigencia ANTERIOR (nunca debería tener
 * presupuesto — regla de negocio confirmada por el usuario) y construir el
 * que le falta a una nota sobre una factura de la MISMA vigencia (sección 3
 * del reporte, formula validada contra casos sanos reales: CodiPlan del
 * TipoUsua de la factura, Valor de la nota).
 *
 * La reconstrucción presupuestal (SaldPlan/ValoUsad/SaldDisp y demás
 * columnas de saldo acumulado en SIHOS) queda deliberadamente FUERA de
 * ambas acciones — es un paso manual que el usuario sigue haciendo en SIHOS
 * después, porque esa lógica es particular de cada instalación y no se
 * replica aquí.
 */
class SihosPresupuestoEliminacionService
{
    private SihosEmpresaConfigRepository $configRepository;
    private SihosUsuaDigiResolver $usuaDigiResolver;

    public function __construct()
    {
        $this->configRepository = new SihosEmpresaConfigRepository();
        $this->usuaDigiResolver = new SihosUsuaDigiResolver();
    }

    public function eliminarDetaPlan(int $empresaId, string $codiDocu, string $numeDocu): array
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
            $estado = $repositorioLectura->fetchEstadoParaEliminacionDetaPlan($codiDocu, $numeDocu);
        } catch (PDOException $e) {
            return ['ok' => false, 'message' => 'No se pudo consultar SIHOS: ' . $e->getMessage()];
        }

        if ($estado === null) {
            return ['ok' => false, 'message' => "No se encontró el documento {$codiDocu}-{$numeDocu} en SIHOS."];
        }

        if ((int)$estado['Anulado'] === 1) {
            return ['ok' => false, 'message' => 'El documento está anulado, no se modifica.'];
        }

        $facturaFecha = $estado['FacturaFecha'] ?? null;
        $fechaDocu = (string)$estado['FechDocu'];
        $sumaPresupuesto = (float)$estado['SumaPresupuesto'];

        $esElegible = $facturaFecha !== null
            && $fechaDocu !== ''
            && (int)substr((string)$facturaFecha, 0, 4) < (int)substr($fechaDocu, 0, 4)
            && abs($sumaPresupuesto) >= 0.01;

        if (!$esElegible) {
            return [
                'ok' => false,
                'message' => "El documento {$codiDocu}-{$numeDocu} ya no cumple la condición (nota sobre factura de vigencia anterior con presupuesto ≠ 0) — puede que ya se haya corregido.",
            ];
        }

        [$codiAno, $codiMes] = [substr($fechaDocu, 0, 4), substr($fechaDocu, 5, 2)];

        try {
            if ($repositorioLectura->isPresupuestoCerrado($codiAno, $codiMes)) {
                return [
                    'ok' => false,
                    'message' => "El módulo Presupuesto está cerrado en SIHOS para {$codiMes}/{$codiAno}. Reábralo en SIHOS antes de continuar.",
                ];
            }
        } catch (PDOException $e) {
            return ['ok' => false, 'message' => 'No se pudo verificar el cierre del período en SIHOS: ' . $e->getMessage()];
        }

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
            $filasBorradas = $repositorioEscritura->eliminarDetaPlan($codiDocu, $numeDocu);
        } catch (PDOException $e) {
            return ['ok' => false, 'message' => 'No se pudo borrar en SIHOS: ' . $e->getMessage()];
        }

        if ($filasBorradas === []) {
            return [
                'ok' => false,
                'message' => "No había filas de DetaPlan para {$codiDocu}-{$numeDocu} — puede que ya se hayan borrado.",
            ];
        }

        $this->registrarAuditoria(
            $empresaId,
            'DELETE',
            'sihos.DetaPlan',
            "{$codiInst}-{$codiDocu}-{$numeDocu}",
            json_encode($filasBorradas, JSON_UNESCAPED_UNICODE),
            null,
            "Eliminación manual de DetaPlan huérfano en SIHOS (nota sobre factura de vigencia anterior), documento {$codiDocu}-{$numeDocu}"
        );

        return [
            'ok' => true,
            'message' => "Se borraron " . count($filasBorradas) . " línea(s) de DetaPlan de {$codiDocu}-{$numeDocu}. Recuerde correr la reconstrucción presupuestal en SIHOS para {$codiMes}/{$codiAno}.",
        ];
    }

    /**
     * Construye la línea DetaPlan que le falta a una nota (NCF) sobre una
     * factura de la MISMA vigencia (sección 3 del reporte). Bloquea si el
     * mes propio de la nota está cerrado en Presupuesto — mismo principio
     * de seguridad que eliminarDetaPlan, sin buscar un mes alterno.
     */
    public function construirDetaPlan(int $empresaId, string $codiDocu, string $numeDocu): array
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
            $estado = $repositorioLectura->fetchEstadoParaConstruirDetaPlan($codiDocu, $numeDocu);
        } catch (PDOException $e) {
            return ['ok' => false, 'message' => 'No se pudo consultar SIHOS: ' . $e->getMessage()];
        }

        if ($estado === null) {
            return ['ok' => false, 'message' => "No se encontró el documento {$codiDocu}-{$numeDocu} en SIHOS, o no referencia ninguna factura."];
        }

        if ((int)$estado['Anulado'] === 1) {
            return ['ok' => false, 'message' => 'El documento está anulado, no se modifica.'];
        }

        if ((int)$estado['TieneDetaPlan'] > 0) {
            return [
                'ok' => false,
                'message' => "El documento {$codiDocu}-{$numeDocu} ya tiene DetaPlan — puede que ya se haya construido.",
            ];
        }

        $facturaTipoUsua = $estado['FacturaTipoUsua'] ?? null;
        $fechaDocu = (string)$estado['FechDocu'];
        [$codiAno, $codiMes] = [substr($fechaDocu, 0, 4), substr($fechaDocu, 5, 2)];

        if ($facturaTipoUsua === null || $facturaTipoUsua === '') {
            return ['ok' => false, 'message' => "La factura {$estado['FacturaCodiDocu']}-{$estado['FacturaNumeDocu']} no tiene tipo de usuario, no se puede resolver el rubro."];
        }

        try {
            $codiPlan = $repositorioLectura->resolveCodiPlanTipoUsua((string)$facturaTipoUsua, $codiAno);
        } catch (PDOException $e) {
            return ['ok' => false, 'message' => 'No se pudo resolver el rubro presupuestal en SIHOS: ' . $e->getMessage()];
        }

        if ($codiPlan === null) {
            return [
                'ok' => false,
                'message' => "No hay ningún rubro configurado en TipoUsua para el tipo de usuario {$facturaTipoUsua} (año ≤ {$codiAno}).",
            ];
        }

        try {
            if ($repositorioLectura->isPresupuestoCerrado($codiAno, $codiMes)) {
                return [
                    'ok' => false,
                    'message' => "El módulo Presupuesto está cerrado en SIHOS para {$codiMes}/{$codiAno}. Reábralo en SIHOS antes de continuar.",
                ];
            }
        } catch (PDOException $e) {
            return ['ok' => false, 'message' => 'No se pudo verificar el cierre del período en SIHOS: ' . $e->getMessage()];
        }

        $repositorioEscritura = new SihosExternalWriteRepository([
            'host' => $configFila['host'],
            'port' => (string)$configFila['puerto'],
            'database' => $configFila['base_datos'],
            'username' => $usuarioEscritura,
            'codiInst' => $codiInst,
            'password' => SihosCredentialCipher::decrypt($configFila['password_escritura_cifrado']),
            'charset' => $configFila['charset'],
        ]);

        $valor = (float)$estado['ValoTota'];

        try {
            $filaInsertada = $repositorioEscritura->construirDetaPlanNotaVigenciaActual(
                $codiDocu,
                $numeDocu,
                $codiAno,
                $codiPlan,
                $valor,
                $this->usuaDigiResolver->resolver((int)($_SESSION['user_id'] ?? 0), $repositorioLectura)
            );
        } catch (PDOException $e) {
            return ['ok' => false, 'message' => 'No se pudo construir el DetaPlan en SIHOS: ' . $e->getMessage()];
        }

        if ($filaInsertada === []) {
            return [
                'ok' => false,
                'message' => "El documento {$codiDocu}-{$numeDocu} ya tiene DetaPlan — puede que ya se haya construido.",
            ];
        }

        $this->registrarAuditoria(
            $empresaId,
            'INSERT',
            'sihos.DetaPlan',
            "{$codiInst}-{$codiDocu}-{$numeDocu}",
            null,
            json_encode($filaInsertada, JSON_UNESCAPED_UNICODE),
            "Construcción manual de DetaPlan faltante en SIHOS (nota sobre factura de vigencia actual), documento {$codiDocu}-{$numeDocu}, rubro {$codiPlan}"
        );

        return [
            'ok' => true,
            'message' => "Se construyó el DetaPlan de {$codiDocu}-{$numeDocu} (rubro {$codiPlan}, valor " . number_format($valor, 0, ',', '.') . "). Recuerde correr la reconstrucción presupuestal en SIHOS para {$codiMes}/{$codiAno}.",
        ];
    }

    /**
     * Registro manual en la auditoría de SAVID: AuditingPDO solo cubre
     * escrituras en la BD propia de SAVID, no éstas contra SIHOS. Mismo
     * formato de columnas que usa AuditService::persistLog() para que se
     * vea igual de consultable en ?url=auditoria.
     *
     * 'accion' es un ENUM('INSERT','UPDATE','DELETE') en SAVID (el mismo que
     * usa AuditingPDO) — no texto libre. La descripción específica va en
     * sql_resumen/tabla. Un valor fuera del ENUM lanza excepción de
     * truncamiento y corta la respuesta aunque la escritura en SIHOS ya se
     * haya completado (bug real ya encontrado y corregido aquí y en
     * SihosCancelacionCuentaService::registrarAuditoria()).
     */
    private function registrarAuditoria(
        int $empresaId,
        string $accion,
        string $tabla,
        string $registroId,
        ?string $datosAnteriores,
        ?string $datosNuevos,
        string $sqlResumen
    ): void {
        $database = new Database();
        $pdo = $database->connect();

        $stmt = $pdo->prepare('
            INSERT INTO auditoria (
                accion, tabla, registro_id,
                datos_anteriores, datos_nuevos, campos_cambiados,
                sql_resumen, usuario_id, empresa_id, sede_id,
                ip, user_agent, request_url
            ) VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?)
        ');

        $stmt->execute([
            $accion,
            $tabla,
            $registroId,
            $datosAnteriores,
            $datosNuevos,
            $sqlResumen,
            $_SESSION['user_id'] ?? null,
            $empresaId,
            $_SESSION['sede_id'] ?? null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
            $_SERVER['REQUEST_URI'] ?? null,
        ]);
    }
}
