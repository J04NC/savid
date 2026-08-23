<?php

class RolController
{
    private RolePermissionService $rolePermissionService;

    public function __construct()
    {
        $this->rolePermissionService = new RolePermissionService();
    }

    public function index()
    {
        require BASE_PATH . '/app/controllers/ModuleController.php';

        $module = new ModuleController();
        $module->index();
    }

    /**
     * Un admin de empresa solo puede ver/editar permisos de roles que su empresa
     * tiene habilitados vía empresa_rol — evita que "adopte" un rol global ajeno
     * simplemente escribiendo su id en la URL. Superadmin nunca se restringe.
     */
    private function rolAllowedForEmpresa(int $rolId, ?int $empresaId, bool $esSuperAdmin): bool
    {
        if ($esSuperAdmin) {
            return true;
        }
        if ($empresaId === null || $empresaId <= 0) {
            return false;
        }

        $database = new Database();
        $pdo = $database->connect();

        return (new EmpresaRolRepository($pdo))->isRolAllowed($empresaId, $rolId);
    }

    public function permisos()
    {
        if (!isset($_SESSION['user_id'])) {
            exit;
        }

        $rolId = (int)($_GET['id'] ?? 0);

        $esSuperAdmin = !empty($_SESSION['es_super_admin']) || (int)($_SESSION['rol_id'] ?? 0) === 1;

        /*
        ==========================================
        AJAX SEDES (PRIORIDAD TOTAL)
        ==========================================
        */
        if (isset($_GET['ajax']) && $_GET['ajax'] === 'sedes') {

            $empresaId = $_GET['empresa_id'] ?? null;
            [$empresaId, ] = $this->rolePermissionService->normalizeScope(
                $empresaId,
                null,
                $esSuperAdmin,
                $_SESSION['empresa_id'] ?? null
            );
            echo json_encode($this->rolePermissionService->getSedesForEmpresa($empresaId));
            exit;
        }

        /*
        ==========================================
        NUEVO: AJAX MATRIZ PERMISOS
        ==========================================
        */
        if (isset($_GET['ajax']) && $_GET['ajax'] === 'matriz') {

            $empresaId = $_GET['empresa_id'] ?? null;
            $sedeId    = $_GET['sede_id'] ?? null;
            [$empresaId, $sedeId] = $this->rolePermissionService->normalizeScope(
                $empresaId,
                $sedeId,
                $esSuperAdmin,
                $_SESSION['empresa_id'] ?? null
            );

            if (!$this->rolAllowedForEmpresa($rolId, $empresaId, $esSuperAdmin)) {
                http_response_code(403);
                echo json_encode(['error' => 'Este rol no está habilitado para su empresa.']);
                exit;
            }

            echo json_encode($this->rolePermissionService->buildMatrixResponse($rolId, $empresaId, $sedeId));
            exit;
        }

        /*
        ==========================================
        CONTEXTO EMPRESA / SEDE
        ==========================================
        */
        $empresaId = $_GET['empresa_id'] ?? null;
        $sedeId    = $_GET['sede_id'] ?? null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $empresaId = $_POST['empresa_id'] ?? null;
            $sedeId    = $_POST['sede_id'] ?? null;
        }

        [$empresaId, $sedeId] = $this->rolePermissionService->normalizeScope(
            $empresaId,
            $sedeId,
            $esSuperAdmin,
            $_SESSION['empresa_id'] ?? null
        );

        if (!$this->rolAllowedForEmpresa($rolId, $empresaId, $esSuperAdmin)) {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Este rol no está habilitado para su empresa.']);
                exit;
            }
            $_SESSION['flash_notice'] = 'Este rol no está habilitado para su empresa.';
            header('Location: ?url=rol');
            exit;
        }

        /*
        ==========================================
        GUARDAR
        ==========================================
        */
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $checks = $_POST['permisos'] ?? [];
            echo json_encode($this->rolePermissionService->saveRolePermissions($rolId, $empresaId, $sedeId, $checks));
            exit;
        }

        /*
        ==========================================
        EMPRESAS
        ==========================================
        */
        $empresas = $this->rolePermissionService->getEmpresasForPermissionScreen(
            $esSuperAdmin,
            $_SESSION['user_id']
        );

        /*
        ==========================================
        SEDES
        ==========================================
        */
        $sedes = $this->rolePermissionService->getSedesForEmpresa($empresaId);

        /*
        ==========================================
        MATRIZ (solo para carga inicial)
        ==========================================
        */
        $matrix = $this->rolePermissionService->buildMatrixResponse($rolId, $empresaId, $sedeId);
        $matriz = $matrix['matriz'];

        require BASE_PATH . '/app/views/rol/permisos.php';
    }
}