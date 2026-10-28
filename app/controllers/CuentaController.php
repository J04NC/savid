<?php

/**
 * Autoservicio de cuenta para cualquier usuario autenticado (sin exigir
 * permiso fino — ver core/Router.php, lista de controllers siempre
 * permitidos). Hoy solo cambio de la propia contraseña.
 */
class CuentaController
{
    private CuentaPasswordService $passwordService;

    public function __construct()
    {
        $this->passwordService = new CuentaPasswordService();
    }

    public function cambiarPassword(): void
    {
        $breadcrumb = 'Mi cuenta / Cambiar contraseña';
        $view = BASE_PATH . '/app/views/cuenta/cambiar_password.php';
        require BASE_PATH . '/app/views/layouts/main.php';
    }

    public function cambiarPasswordGuardar(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?url=cuenta/cambiarPassword');
            exit;
        }

        $actual = (string)($_POST['password_actual'] ?? '');
        $nueva = (string)($_POST['password_nueva'] ?? '');
        $confirmar = (string)($_POST['password_confirmar'] ?? '');

        $result = $this->passwordService->changeOwnPassword(
            (int)$_SESSION['user_id'],
            $actual,
            $nueva,
            $confirmar
        );

        if (!$result['success']) {
            $_SESSION['flash_notice'] = $result['error'];
            header('Location: ?url=cuenta/cambiarPassword');
            exit;
        }

        $_SESSION['flash_notice'] = 'Contraseña actualizada correctamente.';
        header('Location: ?url=cuenta/cambiarPassword');
        exit;
    }
}
