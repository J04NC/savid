<?php

class UsuarioController
{
    private UserAccessService $userAccessService;
    private UserScopeService $userScopeService;
    private RolePermissionService $rolePermissionService;

    public function __construct()
    {
        $this->userAccessService = new UserAccessService();
        $this->userScopeService = new UserScopeService();
        $this->rolePermissionService = new RolePermissionService();
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

        $esSuperAdmin = (int)($_SESSION['rol_id'] ?? 0) === 1 || !empty($_SESSION['es_super_admin']);

        if (isset($_GET['ajax']) && $_GET['ajax'] === 'sedes') {
            $empresaId = $_GET['empresa_id'] ?? null;
            [$empresaId, ] = $this->rolePermissionService->normalizeScope(
                $empresaId,
                null,
                $esSuperAdmin,
                $_SESSION['empresa_id'] ?? null
            );
            [$empresaId, ] = $this->userAccessService->clampPermisoScopeToTargetUsuario(
                (int)$usuarioId,
                $empresaId,
                null,
                $esSuperAdmin
            );
            echo json_encode($this->userAccessService->getSedesAuthorizedForUsuarioEmpresa((int)$usuarioId, $empresaId));
            exit;
        }

        if (isset($_GET['ajax']) && $_GET['ajax'] === 'matriz') {
            $empresaId = $_GET['empresa_id'] ?? null;
            $sedeId = $_GET['sede_id'] ?? null;
            [$empresaId, $sedeId] = $this->rolePermissionService->normalizeScope(
                $empresaId,
                $sedeId,
                $esSuperAdmin,
                $_SESSION['empresa_id'] ?? null
            );
            [$empresaId, $sedeId] = $this->userAccessService->clampPermisoScopeToTargetUsuario(
                (int)$usuarioId,
                $empresaId,
                $sedeId,
                $esSuperAdmin
            );
            echo json_encode($this->userAccessService->buildPermissionMatrix((int)$usuarioId, $empresaId, $sedeId));
            exit;
        }

        $empresaId = $_GET['empresa_id'] ?? ($_SESSION['empresa_id'] ?? null);
        $sedeId = $_GET['sede_id'] ?? ($_SESSION['sede_id'] ?? null);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $raw = file_get_contents('php://input');
            $json = json_decode($raw, true);

            if (!is_array($json)) {
                echo json_encode(['success' => false, 'message' => 'JSON inválido']);
                exit;
            }

            $empresaId = $json['empresa_id'] ?? null;
            $sedeId = $json['sede_id'] ?? null;
            $grantIds = isset($json['grant_ids']) && is_array($json['grant_ids']) ? $json['grant_ids'] : [];
            $denyIds = isset($json['deny_ids']) && is_array($json['deny_ids']) ? $json['deny_ids'] : [];
            $removeIds = isset($json['remove_ids']) && is_array($json['remove_ids']) ? $json['remove_ids'] : [];

            [$empresaId, $sedeId] = $this->rolePermissionService->normalizeScope(
                $empresaId,
                $sedeId,
                $esSuperAdmin,
                $_SESSION['empresa_id'] ?? null
            );
            [$empresaId, $sedeId] = $this->userAccessService->clampPermisoScopeToTargetUsuario(
                (int)$usuarioId,
                $empresaId,
                $sedeId,
                $esSuperAdmin
            );

            echo json_encode($this->userAccessService->saveUsuarioPermisosBatch(
                (int)$usuarioId,
                $grantIds,
                $denyIds,
                $removeIds,
                $empresaId,
                $sedeId
            ));
            exit;
        }

        [$empresaId, $sedeId] = $this->rolePermissionService->normalizeScope(
            $empresaId,
            $sedeId,
            $esSuperAdmin,
            $_SESSION['empresa_id'] ?? null
        );

        [$empresaId, $sedeId] = $this->userAccessService->clampPermisoScopeToTargetUsuario(
            (int)$usuarioId,
            $empresaId,
            $sedeId,
            $esSuperAdmin
        );

        $empresas = $this->userAccessService->getEmpresasAuthorizedForUsuario((int)$usuarioId);
        $sedes = $this->userAccessService->getSedesAuthorizedForUsuarioEmpresa((int)$usuarioId, $empresaId);

        $matrix = $this->userAccessService->buildPermissionMatrix((int)$usuarioId, $empresaId, $sedeId);
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