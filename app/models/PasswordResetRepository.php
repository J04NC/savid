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
}
