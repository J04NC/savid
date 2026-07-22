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

        if (!empty($auth['needs_2fa'])) {
            header('Location: ' . ($auth['redirect'] ?? '?url=login/verificar2fa'));
            exit;
        }

        $this->finishSuccessfulLogin($auth['redirect'] ?? '?url=dashboard');
    }

    private function finishSuccessfulLogin(string $redirectTarget): void
    {
        try {
            $tracking = new SesionTrackingService();
            $tracking->openSessionForCurrentUser();
        } catch (Throwable $e) {
            error_log('SesionTrackingService (login): ' . $e->getMessage());
        }

        header('Location: ' . $redirectTarget);
        exit;
    }

    public function logout()
    {
        $motivosValidos = ['idle', 'admin'];
        $motivo = (isset($_GET['motivo']) && in_array($_GET['motivo'], $motivosValidos, true))
            ? $_GET['motivo']
            : 'logout';
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
                $service->requestReset($identificador, RequestIpService::current());
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

    /**
     * GET ?url=login/verificar2fa — formulario para ingresar el código de verificación.
     */
    public function verificar2fa()
    {
        if (SessionManager::userLogged()) {
            header('Location: ?url=dashboard');
            exit;
        }

        if (empty($_SESSION['tfa_pending_user_id'])) {
            header('Location: ?url=login');
            exit;
        }

        require BASE_PATH . '/app/views/login_verificar2fa.php';
    }

    /**
     * POST ?url=login/verificar2faReenviar — reenvía el código (con límite de frecuencia).
     */
    public function verificar2faReenviar()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['tfa_pending_user_id'])) {
            header('Location: ?url=login');
            exit;
        }

        $userId = (int)$_SESSION['tfa_pending_user_id'];

        try {
            $userRepository = new UserRepository((new Database())->connect());
            $user = $userRepository->findActiveById($userId);
            if ($user) {
                $service = new TwoFactorService();
                $result = $service->sendCode(
                    $userId,
                    (string)($user['email'] ?? ''),
                    (string)($user['nombre'] ?? $user['username']),
                    RequestIpService::current()
                );
                $_SESSION['tfa_error'] = $result['success']
                    ? 'Enviamos un nuevo código a tu correo.'
                    : ($result['error'] ?? 'No se pudo reenviar el código.');
            }
        } catch (Throwable $e) {
            error_log('TwoFactorService::sendCode (reenviar): ' . $e->getMessage());
            $_SESSION['tfa_error'] = 'No se pudo reenviar el código.';
        }

        header('Location: ?url=login/verificar2fa');
        exit;
    }

    /**
     * POST ?url=login/verificar2faConfirmar — valida el código e inicia sesión.
     */
    public function verificar2faConfirmar()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['tfa_pending_user_id'])) {
            header('Location: ?url=login');
            exit;
        }

        $userId = (int)$_SESSION['tfa_pending_user_id'];
        $code = trim($_POST['code'] ?? '');
        $rememberDevice = !empty($_POST['remember_device']);

        $service = new TwoFactorService();
        $result = $service->verifyCode($userId, $code);

        if (!$result['success']) {
            $_SESSION['tfa_error'] = $result['error'] ?? 'Código incorrecto.';
            header('Location: ?url=login/verificar2fa');
            exit;
        }

        $auth = $this->authService->completeLoginForUserId($userId);

        if (!$auth['success']) {
            unset($_SESSION['tfa_pending_user_id']);
            $_SESSION['login_error'] = $auth['error'] ?? 'No se pudo iniciar sesión';
            header('Location: ?url=login');
            exit;
        }

        if ($rememberDevice) {
            try {
                $token = $service->registerTrustedDevice(
                    $userId,
                    RequestIpService::current(),
                    $_SERVER['HTTP_USER_AGENT'] ?? null
                );
                setcookie(TwoFactorService::DEVICE_COOKIE_NAME, $token, [
                    'expires' => time() + TwoFactorService::deviceCookieTtlSeconds(),
                    'path' => '/',
                    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            } catch (Throwable $e) {
                error_log('TwoFactorService::registerTrustedDevice: ' . $e->getMessage());
            }
        }

        $this->finishSuccessfulLogin($auth['redirect'] ?? '?url=dashboard');
    }
}