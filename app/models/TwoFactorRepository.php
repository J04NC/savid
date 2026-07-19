<?php

class TwoFactorRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /* ---------------------------------------------------------- Códigos ---------------------------------------------------------- */

    public function invalidateAllCodesForUser(int $usuarioId): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE usuario_2fa_codigo
            SET used_at = NOW(3)
            WHERE usuario_id = ?
            AND used_at IS NULL
        ");
        $stmt->execute([$usuarioId]);
    }

    public function createCode(int $usuarioId, string $codeHash, string $expiresAt, ?string $ipOrigen): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO usuario_2fa_codigo (usuario_id, code_hash, expires_at, ip_origen, created_at)
            VALUES (?, ?, ?, ?, NOW(3))
        ");
        $stmt->execute([$usuarioId, $codeHash, $expiresAt, $ipOrigen]);
    }

    /**
     * Código vigente más reciente del usuario (no usado, no vencido), o null.
     */
    public function findLatestValidCodeForUser(int $usuarioId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM usuario_2fa_codigo
            WHERE usuario_id = ?
            AND used_at IS NULL
            AND expires_at >= NOW(3)
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([$usuarioId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function incrementAttempts(int $id): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE usuario_2fa_codigo
            SET intentos = intentos + 1
            WHERE id = ?
        ");
        $stmt->execute([$id]);
    }

    public function markCodeUsed(int $id): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE usuario_2fa_codigo
            SET used_at = NOW(3)
            WHERE id = ?
        ");
        $stmt->execute([$id]);
    }

    /* ------------------------------------------------------- Dispositivos ------------------------------------------------------- */

    public function createTrustedDevice(int $usuarioId, string $tokenHash, string $expiresAt, ?string $ipOrigen, ?string $userAgent): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO usuario_2fa_dispositivo (usuario_id, token_hash, expires_at, ip_origen, user_agent, created_at)
            VALUES (?, ?, ?, ?, ?, NOW(3))
        ");
        $stmt->execute([$usuarioId, $tokenHash, $expiresAt, $ipOrigen, $userAgent]);
    }

    /**
     * Dispositivo confiable vigente (no revocado, no vencido) para ese usuario y token, o null.
     */
    public function findValidTrustedDevice(int $usuarioId, string $tokenHash): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM usuario_2fa_dispositivo
            WHERE usuario_id = ?
            AND token_hash = ?
            AND revoked_at IS NULL
            AND expires_at >= NOW(3)
            LIMIT 1
        ");
        $stmt->execute([$usuarioId, $tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function revokeAllTrustedDevicesForUser(int $usuarioId): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE usuario_2fa_dispositivo
            SET revoked_at = NOW(3)
            WHERE usuario_id = ?
            AND revoked_at IS NULL
        ");
        $stmt->execute([$usuarioId]);
    }
}
