<?php

class PasswordResetRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function invalidateAllForUser(int $usuarioId): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE usuario_password_reset
            SET used_at = NOW(3)
            WHERE usuario_id = ?
            AND used_at IS NULL
        ");
        $stmt->execute([$usuarioId]);
    }

    public function create(int $usuarioId, string $tokenHash, string $expiresAt, ?string $ipOrigen): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO usuario_password_reset (usuario_id, token_hash, expires_at, ip_origen, created_at)
            VALUES (?, ?, ?, ?, NOW(3))
        ");
        $stmt->execute([$usuarioId, $tokenHash, $expiresAt, $ipOrigen]);
    }

    /**
     * Devuelve la fila vigente (no usada, no vencida) para el hash dado, o null.
     */
    public function findValidByTokenHash(string $tokenHash): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM usuario_password_reset
            WHERE token_hash = ?
            AND used_at IS NULL
            AND expires_at >= NOW(3)
            LIMIT 1
        ");
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function markUsed(int $id): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE usuario_password_reset
            SET used_at = NOW(3)
            WHERE id = ?
        ");
        $stmt->execute([$id]);
    }

    /**
     * Cantidad de solicitudes de reseteo (a cualquier destinatario) hechas desde esta IP
     * en la ventana dada — para limitar el abuso masivo desde un mismo origen.
     */
    public function countRecentByIp(string $ip, int $windowMinutes): int
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM usuario_password_reset
            WHERE ip_origen = ?
            AND created_at >= DATE_SUB(NOW(3), INTERVAL ? MINUTE)
        ");
        $stmt->execute([$ip, $windowMinutes]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Momento de la última solicitud para este usuario (usada o no, vencida o no),
     * para aplicar un cooldown y evitar reenvíos inmediatos al mismo correo.
     */
    public function lastRequestedAtForUser(int $usuarioId): ?string
    {
        $stmt = $this->pdo->prepare("
            SELECT MAX(created_at)
            FROM usuario_password_reset
            WHERE usuario_id = ?
        ");
        $stmt->execute([$usuarioId]);
        $value = $stmt->fetchColumn();

        return ($value !== false && $value !== null) ? (string)$value : null;
    }
}
