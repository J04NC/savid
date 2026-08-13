<?php

/**
 * Notificaciones proactivas por correo a los superadministradores: hoy nadie se
 * entera de un bloqueo por fuerza bruta o de una suscripción por vencer salvo que
 * entre manualmente a revisar (Centro de seguridad / Suscripciones).
 */
class SecurityAlertService
{
    private PDO $pdo;
    private MailerService $mailer;

    public function __construct()
    {
        $database = new Database();
        $this->pdo = $database->connect();
        $this->mailer = new MailerService();
    }

    public function notificarBloqueoFuerzaBruta(string $username, string $ip): void
    {
        $destinatarios = $this->superAdminEmails();
        if (empty($destinatarios)) {
            return;
        }

        $html = '
            <p>Se bloqueó temporalmente el inicio de sesión tras varios intentos fallidos.</p>
            <ul>
                <li><strong>Usuario intentado:</strong> ' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . '</li>
                <li><strong>IP:</strong> ' . htmlspecialchars($ip, ENT_QUOTES, 'UTF-8') . '</li>
                <li><strong>Fecha:</strong> ' . date('Y-m-d H:i:s') . '</li>
            </ul>
            <p>Puedes revisar el detalle en el Centro de seguridad del sistema.</p>
        ';

        foreach ($destinatarios as $email => $nombre) {
            $this->mailer->send($email, $nombre, 'Bloqueo por fuerza bruta detectado - SAVID', $html);
        }
    }

    public function notificarSuscripcionPorVencer(string $empresaNombre, string $fechaFin, int $diasRestantes): void
    {
        $destinatarios = $this->superAdminEmails();
        if (empty($destinatarios)) {
            return;
        }

        $html = '
            <p>La suscripción de <strong>' . htmlspecialchars($empresaNombre, ENT_QUOTES, 'UTF-8') . '</strong> vence en ' . (int)$diasRestantes . ' día(s) (' . htmlspecialchars($fechaFin, ENT_QUOTES, 'UTF-8') . ').</p>
            <p>Revisa el plan de la empresa y renueva si corresponde.</p>
        ';

        foreach ($destinatarios as $email => $nombre) {
            $this->mailer->send($email, $nombre, 'Suscripción próxima a vencer - SAVID', $html);
        }
    }

    /**
     * @return array<string, string> email => nombre
     */
    private function superAdminEmails(): array
    {
        $uNd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'usuario', 'u');

        $stmt = $this->pdo->query("
            SELECT DISTINCT t.email,
                   COALESCE(NULLIF(TRIM(CONCAT_WS(' ', t.nombres, t.apellidos)), ''), u.username) AS nombre
            FROM usuario u
            INNER JOIN usuario_rol ur ON ur.usuario_id = u.id AND ur.rol_id = 1 AND ur.estado_id = 1
            LEFT JOIN terceroidentificacion ti ON ti.id = u.terceroidentificacion_id
            LEFT JOIN tercero t ON t.id = ti.tercero_id
            WHERE u.estado_id = 1
            {$uNd}
            AND t.email IS NOT NULL
            AND t.email != ''
        ");

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string)$row['email']] = (string)$row['nombre'];
        }

        return $out;
    }
}
