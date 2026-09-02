<?php

class ForgotPasswordIntentoRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function record(string $ip, string $identificador): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO forgot_password_intento (ip, identificador, created_at)
            VALUES (?, ?, NOW(3))
        ');
        $stmt->execute([$ip, $identificador]);
    }

    /** Solicitudes de esta IP dentro de la ventana de tiempo dada. */
    public function countRecent(string $ip, int $windowMinutes): int
    {
        $stmt = $this->pdo->prepare('
            SELECT COUNT(*)
            FROM forgot_password_intento
            WHERE ip = ?
            AND created_at >= DATE_SUB(NOW(3), INTERVAL ? MINUTE)
        ');
        $stmt->execute([$ip, $windowMinutes]);

        return (int)$stmt->fetchColumn();
    }

    /** Momento de la solicitud más antigua dentro de la ventana (para calcular cuándo se libera el bloqueo). */
    public function oldestInWindow(string $ip, int $windowMinutes): ?string
    {
        $stmt = $this->pdo->prepare('
            SELECT MIN(created_at)
            FROM forgot_password_intento
            WHERE ip = ?
            AND created_at >= DATE_SUB(NOW(3), INTERVAL ? MINUTE)
        ');
        $stmt->execute([$ip, $windowMinutes]);
        $value = $stmt->fetchColumn();

        return $value !== false && $value !== null ? (string)$value : null;
    }
}
