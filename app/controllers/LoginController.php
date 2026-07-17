<?php
/**
 * Controlador de Autenticación - MULTIEMPRESA
 */

class LoginController
{
    private AuthService $authService;

    public function __construct()
    {
        $this->authService = new AuthService();
    }

    public function index()
    {
        if (SessionManager::userLogged()) {
            if (ContextGateService::hasOperationalContext()) {
                header('Location: ?url=dashboard');
            } else {
                header('Location: ?url=context/cambiarSede');
            }
            exit;
        }
        require BASE_PATH . '/app/views/login.php';
    }

    public function authenticate()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header("Location: ?url=login");
            exit;
        }

        // 1. GUARDAR ERROR/DATOS ANTES DE TODO
        $_SESSION['login_username'] = trim($_POST['username'] ?? '');
        $username = $_SESSION['login_username'];
        $password = trim($_POST['password'] ?? '');

        if (empty($username) || empty($password)) {
            $_SESSION['login_error'] = "Todos los campos son obligatorios";
            header("Location: ?url=login");
            exit;
        }

        $auth = $this->authService->authenticate($username, $password);

        if (!$auth['success']) {
            $_SESSION['login_error'] = $auth['error'] ?? 'No se pudo iniciar sesión';
            header("Location: ?url=login");
            exit;
        }

        try {
            $tracking = new SesionTrackingService();
            $tracking->openSessionForCurrentUser();
        } catch (Throwable $e) {
            error_log('SesionTrackingService (login): ' . $e->getMessage());
        }

        $target = $auth['redirect'] ?? '?url=dashboard';
        header('Location: ' . $target);
        exit;
    }

    public function logout()
    {
        $motivo = (isset($_GET['motivo']) && $_GET['motivo'] === 'idle') ? 'idle' : 'logout';
        $tracking = new SesionTrackingService();
        $tracking->closeCurrentSession($motivo);

        SessionManager::destroy();
        header("Location: ?url=login");
        exit;
    }

    /**
     * GET ?url=login/forgot — formulario para solicitar recuperación de contraseña.
     */
    public function forgot()
    {
        if (SessionManager::userLogged()) {
            header('Location: ?url=dashboard');
            exit;
        }
        require BASE_PATH . '/app/views/login_forgot.php';
    }

    /**
     * POST ?url=login/forgotSend — envía el enlace si el usuario/correo existe.
     * Siempre redirige con el mismo mensaje genérico (anti-enumeración de usuarios).
     */
    public function forgotSend()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?url=login/forgot');
            exit;
        }

        $identificador = trim($_POST['identificador'] ?? '');

        if ($identificador !== '') {
            try {
                $service = new PasswordResetService();
                $service->requestReset($identificador, $_SERVER['REMOTE_ADDR'] ?? null);
            } catch (Throwable $e) {
                error_log('PasswordResetService::requestReset: ' . $e->getMessage());
            }
        }

        $_SESSION['forgot_message'] = 'Si el usuario o correo existe en el sistema, enviamos un enlace para restablecer la contraseña.';
        header('Location: ?url=login/forgot');
        exit;
    }

    /**
     * GET ?url=login/resetPassword&token=... — formulario de nueva contraseña.
     */
    public function resetPassword()
    {
        if (SessionManager::userLogged()) {
            header('Location: ?url=dashboard');
            exit;
        }

        $token = trim($_GET['token'] ?? '');
        $service = new PasswordResetService();
        $validation = $service->validateToken($token);

        require BASE_PATH . '/app/views/login_reset_password.php';
    }

    /**
     * POST ?url=login/resetPasswordSave — guarda la nueva contraseña.
     */
    public function resetPasswordSave()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?url=login/forgot');
            exit;
        }

        $token = trim($_POST['token'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $passwordConfirm = (string)($_POST['password_confirm'] ?? '');

        $service = new PasswordResetService();
        $result = $service->resetPassword($token, $password, $passwordConfirm);

        if (!$result['success']) {
            $_SESSION['reset_error'] = $result['error'];
            header('Location: ?url=login/resetPassword&token=' . urlencode($token));
            exit;
        }

        $_SESSION['forgot_success'] = 'Contraseña actualizada. Ya puedes iniciar sesión.';
        header('Location: ?url=login');
        exit;
    }
}