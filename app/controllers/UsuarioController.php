<?php

class UsuarioController
{
    private UserAccessService $userAccessService;
    private UserScopeService $userScopeService;

    public function __construct()
    {
        $this->userAccessService = new UserAccessService();
        $this->userScopeService = new UserScopeService();
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
        $assignments = $context['assignments'];
        $empresasDisponibles = $context['empresasDisponibles'];
        $sedesPorEmpresa = $context['sedesPorEmpresa'];
        $puedeRolGlobal = !empty($_SESSION['es_super_admin']) || (int)($_SESSION['rol_id'] ?? 0) === 1;

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

        $assignments = $_POST['assignments'] ?? [];
        if (!is_array($assignments)) {
            $assignments = [];
        }
        $assignments = array_values($assignments);

        $result = $this->userAccessService->saveRoles((int)$usuarioId, $assignments);

        echo json_encode($result);
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

    public function empresa_sede($usuarioId = null)
    {
        SessionManager::requireLogin();

        if (!$usuarioId) {
            echo '<div class="modal-content"><p>Usuario no especificado</p></div>';
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $empresas = $_POST['empresas'] ?? [];
            $sedes = $_POST['sedes'] ?? [];
            echo json_encode($this->userScopeService->saveEmpresaSede((int)$usuarioId, $empresas, $sedes));
            exit;
        }

        $data = $this->userScopeService->getEmpresaSedeModalData((int)$usuarioId);

        if (!$data) {
            echo '<div class="modal-content"><p>Usuario no encontrado</p></div>';
            exit;
        }

        extract($data);

        require BASE_PATH . '/app/views/usuario/empresa_sede.php';
    }
}