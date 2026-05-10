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

        $target = $auth['redirect'] ?? '?url=dashboard';
        header('Location: ' . $target);
        exit;
    }

    public function logout()
    {
        SessionManager::destroy();
        header("Location: ?url=login");
        exit;
    }
}