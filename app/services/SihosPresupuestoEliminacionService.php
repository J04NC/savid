<?php

/**
 * Acción administrativa: borrar el DetaPlan huérfano de una nota sobre una
 * factura de vigencia anterior en SIHOS (nunca debería tener presupuesto —
 * regla de negocio confirmada por el usuario). Es la ÚNICA escritura que
 * SAVID hace contra SIHOS en todo el módulo — todo lo demás es de solo
 * lectura (ver SihosExternalRepository).
 *
 * La reconstrucción presupuestal (que toca SaldCont y otras tablas
 * agregadas en SIHOS) queda deliberadamente FUERA de esta acción — es un
 * paso manual que el usuario sigue haciendo en SIHOS después de borrar,
 * porque esa lógica es particular de cada instalación y no se replica aquí.
 */
class SihosPresupuestoEliminacionService
{
    private SihosEmpresaConfigRepository $configRepository;

    public function __construct()
    {
        $this->configRepository = new SihosEmpresaConfigRepository();
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

        $this->registrarAuditoria($empresaId, $codiInst, $codiDocu, $numeDocu, $filasBorradas);

        return [
            'ok' => true,
            'message' => "Se borraron " . count($filasBorradas) . " línea(s) de DetaPlan de {$codiDocu}-{$numeDocu}. Recuerde correr la reconstrucción presupuestal en SIHOS para {$codiMes}/{$codiAno}.",
        ];
    }

    /**
     * Registro manual en la auditoría de SAVID: AuditingPDO solo cubre
     * escrituras en la BD propia de SAVID, no ésta contra SIHOS. Mismo
     * formato de columnas que usa AuditService::persistLog() para que se
     * vea igual de consultable en ?url=auditoria.
     *
     * @param array<int, array<string, mixed>> $filasBorradas
     */
    private function registrarAuditoria(
        int $empresaId,
        string $codiInst,
        string $codiDocu,
        string $numeDocu,
        array $filasBorradas
    ): void {
        $database = new Database();
        $pdo = $database->connect();

        $stmt = $pdo->prepare('
            INSERT INTO auditoria (
                accion, tabla, registro_id,
                datos_anteriores, datos_nuevos, campos_cambiados,
                sql_resumen, usuario_id, empresa_id, sede_id,
                ip, user_agent, request_url
            ) VALUES (?, ?, ?, ?, NULL, NULL, ?, ?, ?, ?, ?, ?, ?)
        ');

        $stmt->execute([
            'eliminar_detaplan_sihos',
            'sihos.DetaPlan',
            "{$codiInst}-{$codiDocu}-{$numeDocu}",
            json_encode($filasBorradas, JSON_UNESCAPED_UNICODE),
            "Eliminación manual de DetaPlan huérfano en SIHOS (nota sobre factura de vigencia anterior), documento {$codiDocu}-{$numeDocu}",
            $_SESSION['user_id'] ?? null,
            $empresaId,
            $_SESSION['sede_id'] ?? null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
            $_SERVER['REQUEST_URI'] ?? null,
        ]);
    }
}
