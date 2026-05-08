<?php

class UsuarioController
{
    private UserAccessService $userAccessService;

    public function __construct()
    {
        $this->userAccessService = new UserAccessService();
    }

    public function index()
    {
        require BASE_PATH . '/app/controllers/ModuleController.php';

        $module = new ModuleController();
        $module->index();
    }

    public function roles($usuarioId = null)
    {
        SessionManager::requireLogin();

        if (!$usuarioId) {
            $_SESSION['error'] = "Usuario no especificado";
            header("Location: ?url=usuario");
            exit;
        }

        $context = $this->userAccessService->getRolesContext((int)$usuarioId);

        if (!$context) {
            $_SESSION['error'] = "Usuario no encontrado";
            header("Location: ?url=usuario");
            exit;
        }

        $usuario = $context['usuario'];
        $roles = $context['roles'];
        $selectedRoles = $context['selectedRoles'];

        require BASE_PATH . '/app/views/usuario/roles.php';
    }

    public function saveRoles($usuarioId)
    {
        SessionManager::requireLogin();

        $usuario = $this->userAccessService->getUserOrNull((int)$usuarioId);
        if (!$usuario) {
            echo json_encode(['success' => false, 'message' => 'Usuario no encontrado']);
            exit;
        }

        $roles = $_POST['roles'] ?? [];
        $this->userAccessService->saveRoles((int)$usuarioId, $roles);

        echo json_encode(['success' => true]);
        exit;
    }

    public function permisos($usuarioId = null)
    {
        SessionManager::requireLogin();

        if (!$usuarioId) {
            $_SESSION['error'] = "Usuario no especificado";
            header("Location: ?url=usuario");
            exit;
        }

        $usuario = $this->userAccessService->getUserOrNull((int)$usuarioId);
        if (!$usuario) {
            $_SESSION['error'] = "Usuario no encontrado";
            header("Location: ?url=usuario");
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $checks = $_POST['permisos'] ?? [];
            echo json_encode($this->userAccessService->saveDirectPermissions((int)$usuarioId, $checks));
            exit;
        }

        $matrix = $this->userAccessService->buildPermissionMatrix((int)$usuarioId);
        $acciones = $matrix['acciones'];
        $matriz = $matrix['matriz'];

        require BASE_PATH . '/app/views/usuario/permisos.php';
    }
}