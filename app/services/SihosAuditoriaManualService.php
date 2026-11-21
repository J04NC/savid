<?php

/**
 * Registro manual en la auditoría de SAVID para escrituras contra SIHOS
 * (BD externa): AuditingPDO solo intercepta escrituras contra la BD propia
 * de SAVID, así que cada service que escribe en SIHOS necesita insertar su
 * propia fila en `auditoria`. Antes de este servicio, el mismo INSERT con
 * los mismos 13 campos estaba copiado en 10 métodos `registrarAuditoria*`
 * distintos a lo largo del módulo SIHOS; aquí queda una sola vez.
 */
class SihosAuditoriaManualService
{
    public static function registrar(
        int $empresaId,
        string $accion,
        string $tabla,
        string $registroId,
        ?string $datosAnteriores,
        ?string $datosNuevos,
        string $sqlResumen
    ): void {
        $pdo = (new Database())->connect();

        $stmt = $pdo->prepare(
            'INSERT INTO auditoria (accion, tabla, registro_id, datos_anteriores, datos_nuevos, campos_cambiados, sql_resumen,
                                    usuario_id, empresa_id, sede_id, ip, user_agent, request_url)
             VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?)'
        );

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
