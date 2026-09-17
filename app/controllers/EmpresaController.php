<?php

/**
 * Rutas bajo `?url=empresa/...` sin sustituir el CRUD automático del ítem:
 * `index` delega en ModuleController; las demás acciones son modales / especiales.
 */
class EmpresaController
{
    private EmpresaUsuarioService $usuarioService;
    private EmpresaSedeService $sedeService;

    public function __construct()
    {
        $this->usuarioService = new EmpresaUsuarioService();
        $this->sedeService = new EmpresaSedeService();
    }

    private function isSuperAdmin(): bool
    {
        return !empty($_SESSION['es_super_admin'])
            || (int)($_SESSION['rol_id'] ?? 0) === 1;
    }

    /**
     * CRUD estándar del ítem `empresa`.
     */
    public function index(): void
    {
        require_once BASE_PATH . '/app/controllers/ModuleController.php';
        (new ModuleController())->index();
    }

    /**
     * Modal: gestión de usuarios vinculados a la empresa.
     * Ruta: ?url=empresa/usuarios/{empresaId}
     * GET  → renderiza listado + buscador para vincular nuevos.
     * POST → opera según `_action`:
     *        - link       : agregar usuario_empresa (por usuario_id o username/NIT).
     *        - toggle     : invertir estado del vínculo.
     *        - delete     : quitar vínculo usuario_empresa (solo superadmin).
     *        - search     : búsqueda de usuarios candidatos por término.
     */
    public function usuarios($empresaId = null): void
    {
        SessionManager::requireLogin();

        $eid = $empresaId !== null && $empresaId !== '' ? (int)$empresaId : 0;
        if ($eid <= 0) {
            $this->renderModalError('Seleccione una empresa en la tabla y vuelva a abrir la acción.', 'Usuarios de la empresa', 'empresa-usuarios-modal');
            return;
        }

        $empresa = $this->usuarioService->obtenerEmpresa($eid);
        if (!$empresa) {
            $this->renderModalError('Empresa no encontrada.', 'Usuarios de la empresa', 'empresa-usuarios-modal');
            return;
        }

        $crudService = new CrudService();
        if (!$crudService->userCanManageEmpresa($eid)) {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $this->jsonResponse([
                    'success' => false,
                    'message' => 'No tiene permiso para gestionar los usuarios de esta empresa.',
                ]);
                return;
            }
            $this->renderModalError(
                'No tiene permiso para gestionar los usuarios de esta empresa.',
                'Usuarios de la empresa',
                'empresa-usuarios-modal'
            );
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->handleUsuariosPost($eid);
            return;
        }

        $usuarios = $this->usuarioService->fetchUsuarios($eid);

        require BASE_PATH . '/app/views/empresa/usuarios_modal.php';
    }

    private function handleUsuariosPost(int $empresaId): void
    {
        $action = (string)($_POST['_action'] ?? '');

        if ($action === 'search') {
            $term = (string)($_POST['term'] ?? '');
            $esSuperAdmin = $this->isSuperAdmin();
            $currentUid = (int)($_SESSION['user_id'] ?? 0);
            $this->jsonResponse($this->usuarioService->buscar($term, $empresaId, $esSuperAdmin, $currentUid));
            return;
        }

        if ($action === 'link') {
            $usuarioId = (int)($_POST['usuario_id'] ?? 0);
            $esSuperAdmin = $this->isSuperAdmin();
            $currentUid = (int)($_SESSION['user_id'] ?? 0);
            $this->jsonResponse($this->usuarioService->vincular($empresaId, $usuarioId, $esSuperAdmin, $currentUid));
            return;
        }

        if ($action === 'toggle') {
            $usuarioId = (int)($_POST['usuario_id'] ?? 0);
            $currentUid = (int)($_SESSION['user_id'] ?? 0);
            $sessionEmpresaId = isset($_SESSION['empresa_id']) ? (int)$_SESSION['empresa_id'] : 0;
            $esSuperAdmin = $this->isSuperAdmin();
            $this->jsonResponse($this->usuarioService->alternarEstado(
                $empresaId,
                $usuarioId,
                $currentUid,
                $sessionEmpresaId,
                $esSuperAdmin
            ));
            return;
        }

        if ($action === 'delete') {
            if (!$this->isSuperAdmin()) {
                $this->jsonResponse([
                    'success' => false,
                    'message' => 'Solo un superadministrador puede eliminar vínculos de usuario.',
                ]);
                return;
            }
            $usuarioId = (int)($_POST['usuario_id'] ?? 0);
            $currentUid = (int)($_SESSION['user_id'] ?? 0);
            $this->jsonResponse($this->usuarioService->eliminar($empresaId, $usuarioId, $currentUid));
            return;
        }

        $this->jsonResponse(['success' => false, 'message' => 'Acción inválida.']);
    }

    /**
     * Subida de logo / logo2 del formulario empresa.
     * Ruta: ?url=empresa/uploadLogo (POST multipart campo "archivo")
     */
    public function uploadLogo(): void
    {
        $prevDisplayErrors = ini_get('display_errors');
        ini_set('display_errors', '0');

        try {
            SessionManager::requireLogin();

            if (class_exists('PermisoService') && !PermisoService::can('empresa', 'guardar')) {
                $this->jsonResponse(['ok' => false, 'error' => 'Sin permiso']);
                return;
            }

            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                $this->jsonResponse(['ok' => false, 'error' => 'Método no permitido']);
                return;
            }

            if (empty($_FILES['archivo']) || !is_uploaded_file((string)($_FILES['archivo']['tmp_name'] ?? ''))) {
                $this->jsonResponse(['ok' => false, 'error' => 'Archivo requerido']);
                return;
            }

            $f = $_FILES['archivo'];
            if ((int)($f['error'] ?? 0) !== UPLOAD_ERR_OK) {
                $this->jsonResponse(['ok' => false, 'error' => 'Error al subir']);
                return;
            }

            if ((int)($f['size'] ?? 0) > 3 * 1024 * 1024) {
                $this->jsonResponse(['ok' => false, 'error' => 'Máximo 3 MB']);
                return;
            }

            $tmp = (string)$f['tmp_name'];
            $mime = '';
            if (class_exists('finfo')) {
                $info = new finfo(FILEINFO_MIME_TYPE);
                $mime = $info->file($tmp) ?: '';
            }
            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            if ($mime === 'image/jpg') {
                $mime = 'image/jpeg';
            }
            if (!isset($allowed[$mime])) {
                $this->jsonResponse(['ok' => false, 'error' => 'Solo JPG, PNG o WebP']);
                return;
            }

            $name = 'e_' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
            $path = StorageService::instance()->putUploadedFile(
                StorageService::ZONE_EMPRESAS,
                $name,
                $f
            );
            if ($path === null) {
                $this->jsonResponse(['ok' => false, 'error' => 'No se pudo guardar el archivo']);
                return;
            }

            $this->jsonResponse(['ok' => true, 'path' => $path]);
        } catch (Throwable $e) {
            error_log('uploadLogo: ' . $e->getMessage());
            $this->jsonResponse(['ok' => false, 'error' => 'Error interno al subir']);
        } finally {
            if ($prevDisplayErrors !== false) {
                ini_set('display_errors', (string)$prevDisplayErrors);
            }
        }
    }

    /**
     * Endpoint JSON: lookup por NIT desde el formulario CRUD de empresa.
     * Ruta: ?url=empresa/lookupNit&nit=XXXX[&empresa_id=NN]
     */
    public function lookupNit(): void
    {
        SessionManager::requireLogin();
        $this->assertEmpresaFormJsonAccess();

        $nit = trim((string)($_GET['nit'] ?? ''));
        $excludeEmpresaId = isset($_GET['empresa_id']) && $_GET['empresa_id'] !== ''
            ? (int)$_GET['empresa_id']
            : null;

        $svc = new EmpresaTerceroLookupService();
        $result = $svc->lookupNit($nit, $excludeEmpresaId);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * GET ?url=empresa/searchRepresentante&q=...
     */
    public function searchRepresentante(): void
    {
        SessionManager::requireLogin();
        $this->assertEmpresaFormJsonAccess();

        $term = trim((string)($_GET['q'] ?? $_GET['term'] ?? ''));
        $svc = new EmpresaTerceroLookupService();
        $items = $svc->searchRepresentante($term);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * GET ?url=empresa/lookupRepresentante&tipodocumento_id=&numero_documento=
     */
    public function lookupRepresentante(): void
    {
        SessionManager::requireLogin();
        $this->assertEmpresaFormJsonAccess();

        $tipo = (int)($_GET['tipodocumento_id'] ?? 0);
        $numero = trim((string)($_GET['numero_documento'] ?? ''));

        $svc = new EmpresaTerceroLookupService();
        $result = $svc->lookupRepresentanteByDocumento($tipo, $numero);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function assertEmpresaFormJsonAccess(): void
    {
        if (class_exists('PermisoService')
            && !PermisoService::can('empresa', 'ver')
            && !PermisoService::can('empresa', 'guardar')
        ) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Sin permiso'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    /**
     * Modal: gestión de sedes de la empresa seleccionada.
     * Ruta: ?url=empresa/sedes/{empresaId}
     * GET  → renderiza listado + formulario.
     * POST → opera según campo `_action`: save | toggle | delete (solo superadmin).
     */
    public function sedes($empresaId = null): void
    {
        SessionManager::requireLogin();

        $eid = $empresaId !== null && $empresaId !== '' ? (int)$empresaId : 0;

        if ($eid <= 0) {
            $this->renderModalError('Seleccione una empresa en la tabla y vuelva a abrir la acción.');
            return;
        }

        $empresa = $this->usuarioService->obtenerEmpresa($eid);

        if (!$empresa) {
            $this->renderModalError('Empresa no encontrada.');
            return;
        }

        $crudService = new CrudService();
        if (!$crudService->userCanManageEmpresa($eid)) {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $this->jsonResponse([
                    'success' => false,
                    'message' => 'No tiene permiso para gestionar las sedes de esta empresa.',
                ]);
                return;
            }
            $this->renderModalError('No tiene permiso para gestionar las sedes de esta empresa.');
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->handleSedesPost($eid);
            return;
        }

        $sedes = $this->sedeService->obtenerSedes($eid);

        require BASE_PATH . '/app/views/empresa/sedes_modal.php';
    }

    private function handleSedesPost(int $empresaId): void
    {
        $action = (string)($_POST['_action'] ?? '');

        if ($action === 'save') {
            $this->jsonResponse($this->sedeService->guardar($empresaId, $_POST));
            return;
        }

        if ($action === 'toggle') {
            $sedeId = (int)($_POST['sede_id'] ?? 0);
            $this->jsonResponse($this->sedeService->alternarEstado($empresaId, $sedeId));
            return;
        }

        if ($action === 'delete') {
            $sedeId = (int)($_POST['sede_id'] ?? 0);
            $this->jsonResponse($this->sedeService->eliminar($empresaId, $sedeId, $this->isSuperAdmin()));
            return;
        }

        $this->jsonResponse(['success' => false, 'message' => 'Acción inválida.']);
    }

    /**
     * Modal: qué ítems del menú puede usar la empresa (solo superadmin).
     * Ruta: ?url=empresa/items/{empresaId}
     * GET  → matriz de ítems por módulo con checkbox habilitado/no.
     * POST → reemplaza el conjunto completo (`item_ids[]`).
     */
    public function items($empresaId = null): void
    {
        SessionManager::requireLogin();

        $eid = $empresaId !== null && $empresaId !== '' ? (int)$empresaId : 0;
        if ($eid <= 0) {
            $this->renderModalError('Seleccione una empresa en la tabla y vuelva a abrir la acción.', 'Ítems de la empresa', 'empresa-items-modal');
            return;
        }

        if (!$this->isSuperAdmin()) {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $this->jsonResponse(['success' => false, 'message' => 'Solo el superadministrador puede gestionar los ítems habilitados de una empresa.']);
                return;
            }
            $this->renderModalError(
                'Solo el superadministrador puede gestionar los ítems habilitados de una empresa.',
                'Ítems de la empresa',
                'empresa-items-modal'
            );
            return;
        }

        $empresa = $this->usuarioService->obtenerEmpresa($eid);

        if (!$empresa) {
            $this->renderModalError('Empresa no encontrada.', 'Ítems de la empresa', 'empresa-items-modal');
            return;
        }

        $service = new EmpresaItemService();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $itemIds = array_map('intval', (array)($_POST['item_ids'] ?? []));
            $result = $service->guardar($eid, $itemIds);

            if (!$result['success']) {
                $this->jsonResponse($result);
                return;
            }

            $this->jsonResponse([
                'success' => true,
                'message' => 'Ítems habilitados actualizados.',
                'grupos' => $service->listItemsGroupedForEmpresa($eid),
            ]);
            return;
        }

        $grupos = $service->listItemsGroupedForEmpresa($eid);

        require BASE_PATH . '/app/views/empresa/items_modal.php';
    }

    /**
     * Modal: qué roles puede usar la empresa (solo superadmin).
     * Ruta: ?url=empresa/roles/{empresaId}
     * GET  → lista de roles con checkbox habilitado/no.
     * POST → reemplaza el conjunto completo (`rol_ids[]`).
     */
    public function roles($empresaId = null): void
    {
        SessionManager::requireLogin();

        $eid = $empresaId !== null && $empresaId !== '' ? (int)$empresaId : 0;
        if ($eid <= 0) {
            $this->renderModalError('Seleccione una empresa en la tabla y vuelva a abrir la acción.', 'Roles de la empresa', 'empresa-roles-modal');
            return;
        }

        if (!$this->isSuperAdmin()) {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $this->jsonResponse(['success' => false, 'message' => 'Solo el superadministrador puede gestionar los roles habilitados de una empresa.']);
                return;
            }
            $this->renderModalError(
                'Solo el superadministrador puede gestionar los roles habilitados de una empresa.',
                'Roles de la empresa',
                'empresa-roles-modal'
            );
            return;
        }

        $empresa = $this->usuarioService->obtenerEmpresa($eid);

        if (!$empresa) {
            $this->renderModalError('Empresa no encontrada.', 'Roles de la empresa', 'empresa-roles-modal');
            return;
        }

        $service = new EmpresaRolService();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $rolIds = array_map('intval', (array)($_POST['rol_ids'] ?? []));
            $result = $service->guardar($eid, $rolIds);

            if (!$result['success']) {
                $this->jsonResponse($result);
                return;
            }

            $this->jsonResponse([
                'success' => true,
                'message' => 'Roles habilitados actualizados.',
                'roles' => $service->listRolesForEmpresa($eid),
            ]);
            return;
        }

        $roles = $service->listRolesForEmpresa($eid);

        require BASE_PATH . '/app/views/empresa/roles_modal.php';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonResponse(array $payload): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function renderModalError(
        string $message,
        string $title = 'Sedes de la empresa',
        string $modalClass = 'empresa-sedes-modal'
    ): void {
        $footerClass = $modalClass === 'empresa-usuarios-modal' ? 'empresa-usuarios-footer' : 'empresa-sedes-footer';
        echo '<div class="' . htmlspecialchars($modalClass, ENT_QUOTES, 'UTF-8') . ' modal-inner">'
            . '<header class="modal-form-head"><h3 class="modal-form-title">'
            . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h3></header>'
            . '<p class="modal-form-alert">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<footer class="' . htmlspecialchars($footerClass, ENT_QUOTES, 'UTF-8') . '">'
            . '<button type="button" class="btn-cancel" onclick="closeModalGod()">Cerrar</button>'
            . '</footer></div>';
        exit;
    }
}
