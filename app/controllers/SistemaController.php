<?php
/**
 * Configuración del sistema visible desde la UI (superadmin). Por ahora,
 * estado del SMTP saliente y envío de un correo de prueba.
 */

class SistemaController
{
    public function index(): void
    {
        if (!PermisoService::isSuperAdminSession()) {
            $_SESSION['flash_notice'] = 'Solo el superadministrador puede ver la configuración del sistema.';
            header('Location: ?url=dashboard');
            exit;
        }

        Database::bootstrapEnv();

        $config = [
            'host' => (string)(getenv('SMTP_HOST') ?: ''),
            'port' => (string)(getenv('SMTP_PORT') ?: ''),
            'secure' => (string)(getenv('SMTP_SECURE') ?: ''),
            'user' => (string)(getenv('SMTP_USER') ?: ''),
            'from_email' => (string)(getenv('SMTP_FROM_EMAIL') ?: ''),
            'from_name' => (string)(getenv('SMTP_FROM_NAME') ?: ''),
            'password_configurada' => trim((string)(getenv('SMTP_PASSWORD') ?: '')) !== '',
            'app_url' => MailerService::baseUrl(),
        ];

        $moduleService = new ModuleService();
        $breadcrumb = $moduleService->buildBreadcrumbForRuta('sistema');

        $view = BASE_PATH . '/app/views/sistema/correo.php';
        require BASE_PATH . '/app/views/layouts/main.php';
    }

    /**
     * POST ?url=sistema/correoProbar — envía un correo de prueba con la config actual.
     */
    public function correoProbar(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!PermisoService::isSuperAdminSession()) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Solo superadministrador'], JSON_UNESCAPED_UNICODE);

            return;
        }

        $destino = trim((string)($_POST['destino'] ?? ''));
        if ($destino === '' || !filter_var($destino, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Correo de destino inválido.'], JSON_UNESCAPED_UNICODE);

            return;
        }

        $html = '
            <p>Este es un correo de prueba enviado desde la configuración del sistema de SAVID.</p>
            <p>Si lo recibiste, el envío de correo saliente está funcionando correctamente.</p>
        ';

        $mailer = new MailerService();
        $error = null;
        $ok = $mailer->send($destino, $destino, 'Correo de prueba - SAVID', $html, $error);

        if (!$ok) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $error ?: 'No se pudo enviar el correo.'], JSON_UNESCAPED_UNICODE);

            return;
        }

        echo json_encode(['ok' => true, 'message' => 'Correo de prueba enviado a ' . $destino . '.'], JSON_UNESCAPED_UNICODE);
    }
}
