<?php

class LoginIntentoRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function record(string $username, string $ip, bool $exitoso): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO login_intento (username, ip, exitoso, created_at)
            VALUES (?, ?, ?, NOW(3))
        ');
        $stmt->execute([$username, $ip, $exitoso ? 1 : 0]);
    }

    /**
     * Intentos fallidos para este usuario+IP desde el último éxito (o desde siempre si no hubo),
     * limitados a la ventana de tiempo dada.
     */
    public function countRecentFailuresSinceLastSuccess(string $username, string $ip, int $windowMinutes): int
    {
        $stmt = $this->pdo->prepare('
            SELECT COUNT(*)
            FROM login_intento
            WHERE username = ?
            AND ip = ?
            AND exitoso = 0
            AND created_at >= DATE_SUB(NOW(3), INTERVAL ? MINUTE)
            AND created_at > COALESCE(
                (
                    SELECT MAX(created_at)
                    FROM login_intento
                    WHERE username = ? AND ip = ? AND exitoso = 1
                ),
                \'1970-01-01\'
            )
        ');
        $stmt->execute([$username, $ip, $windowMinutes, $username, $ip]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Momento del intento fallido más antiguo dentro de la racha actual (para calcular cuándo se libera el bloqueo).
     */
    public function oldestFailureInCurrentStreak(string $username, string $ip, int $windowMinutes): ?string
    {
        $stmt = $this->pdo->prepare('
            SELECT MIN(created_at)
            FROM login_intento
            WHERE username = ?
            AND ip = ?
            AND exitoso = 0
            AND created_at >= DATE_SUB(NOW(3), INTERVAL ? MINUTE)
            AND created_at > COALESCE(
                (
                    SELECT MAX(created_at)
                    FROM login_intento
                    WHERE username = ? AND ip = ? AND exitoso = 1
                ),
                \'1970-01-01\'
            )
        ');
        $stmt->execute([$username, $ip, $windowMinutes, $username, $ip]);
        $value = $stmt->fetchColumn();

        return $value !== false && $value !== null ? (string)$value : null;
    }

    /**
     * Últimos intentos fallidos (uso global, exclusivo de vistas de superadmin — la tabla no
     * distingue empresa/sede).
     *
     * @return list<array{username: string, ip: string, created_at: string}>
     */
    public function listRecentFailures(int $limit = 20): array
    {
        $stmt = $this->pdo->prepare('
            SELECT username, ip, created_at
            FROM login_intento
            WHERE exitoso = 0
            ORDER BY created_at DESC
            LIMIT ' . max(1, (int)$limit) . '
        ');
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function countFailuresSince(string $sinceDateTime): int
    {
        $stmt = $this->pdo->prepare('
            SELECT COUNT(*) FROM login_intento WHERE exitoso = 0 AND created_at >= ?
        ');
        $stmt->execute([$sinceDateTime]);

        return (int)$stmt->fetchColumn();
    }
}
