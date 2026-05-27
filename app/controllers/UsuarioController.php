<?php

class UsuarioController
{
    private UserAccessService $userAccessService;
    private UserScopeService $userScopeService;
    private RolePermissionService $rolePermissionService;
    private UsuarioTerceroLookupService $usuarioLookup;
    private UsuarioFormValidationService $usuarioValidation;

    public function __construct()
    {
        $this->userAccessService = new UserAccessService();
        $this->userScopeService = new UserScopeService();
        $this->rolePermissionService = new RolePermissionService();
        $this->usuarioLookup = new UsuarioTerceroLookupService();
        $this->usuarioValidation = new UsuarioFormValidationService();
    }

    private function requireUsuarioFormApiJson(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!class_exists('PermisoService') || !PermisoService::canUsuarioFormApi()) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Sin permiso'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    /**
     * Impide gestionar usuarios fuera de la empresa en sesión o cuentas superadmin.
     */
    private function requireUsuarioGestionableEnSesion(int $usuarioId): void
    {
        if ($usuarioId <= 0) {
            return;
        }

        $validator = new UsuarioFormValidationService();

        try {
            $validator->assertUsuarioGestionableEnSesion($usuarioId);
        } catch (Exception $e) {
            $decoded = json_decode($e->getMessage(), true);
            $msg = is_array($decoded)
                ? (string)(reset($decoded) ?: 'No tiene permiso para gestionar este usuario.')
                : $e->getMessage();

            if (
                !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
                && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
            ) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $_SESSION['error'] = $msg;
            header('Location: ?url=usuario');
            exit;
        }
    }

    /**
     * GET ?url=usuario/lookupDocumento&tipodocumento_id=&numero_documento=&usuario_id=
     */
    public function lookupDocumento(): void
    {
        $this->requireUsuarioFormApiJson();

        $tipo = (int)($_GET['tipodocumento_id'] ?? 0);
        $numero = trim((string)($_GET['numero_documento'] ?? ''));
        $excludeId = isset($_GET['usuario_id']) && $_GET['usuario_id'] !== ''
            ? (int)$_GET['usuario_id']
            : null;

        echo json_encode(
            $this->usuarioLookup->lookupByTipoAndNumero($tipo, $numero, $excludeId),
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    /**
     * GET ?url=usuario/lookupDocumentoNumero&numero_documento=&usuario_id=
     */
    public function lookupDocumentoNumero(): void
    {
        $this->requireUsuarioFormApiJson();

        $numero = trim((string)($_GET['numero_documento'] ?? ''));
        $excludeId = isset($_GET['usuario_id']) && $_GET['usuario_id'] !== ''
            ? (int)$_GET['usuario_id']
            : null;

        echo json_encode(
            $this->usuarioLookup->lookupByNumeroOnly($numero, $excludeId),
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    /**
     * GET ?url=usuario/lookupEmail&email=&usuario_id=
     */
    public function lookupEmail(): void
    {
        $this->requireUsuarioFormApiJson();

        $email = trim((string)($_GET['email'] ?? ''));
        $excludeId = isset($_GET['usuario_id']) && $_GET['usuario_id'] !== ''
            ? (int)$_GET['usuario_id']
            : null;

        echo json_encode(
            $this->usuarioLookup->lookupByEmail($email, $excludeId),
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    /**
     * GET ?url=usuario/lookupUsername&username=&terceroidentificacion_id=&tercero_id=&usuario_id=
     */
    public function lookupUsername(): void
    {
        $this->requireUsuarioFormApiJson();

        $username = trim((string)($_GET['username'] ?? ''));
        $personaLinkId = $this->resolvePersonaLinkIdFromRequest();
        $excludeId = isset($_GET['usuario_id']) && $_GET['usuario_id'] !== ''
            ? (int)$_GET['usuario_id']
            : null;

        echo json_encode(
            $this->usuarioValidation->lookupUsername($username, $personaLinkId, $excludeId),
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    /**
     * GET ?url=usuario/lookupEmailTercero&tercero_id=&email=
     */
    public function lookupEmailTercero(): void
    {
        $this->requireUsuarioFormApiJson();

        $terceroId = (int)($_GET['tercero_id'] ?? 0);
        $email = trim((string)($_GET['email'] ?? ''));

        echo json_encode(
            $this->usuarioValidation->lookupEmailForTercero($terceroId, $email),
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    /**
     * GET ?url=usuario/lookupIdentificacion&tercero_id=&tipodocumento_id=&numero_documento=
     */
    public function lookupIdentificacion(): void
    {
        $this->requireUsuarioFormApiJson();

        $terceroId = (int)($_GET['tercero_id'] ?? 0);
        $tipo = (int)($_GET['tipodocumento_id'] ?? 0);
        $numero = trim((string)($_GET['numero_documento'] ?? ''));

        echo json_encode(
            $this->usuarioValidation->lookupIdentificacionChange($terceroId, $tipo, $numero),
            JSON_UNESCAPED_UNICODE
        );
        exit;
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

        $this->requireUsuarioGestionableEnSesion((int)$usuarioId);

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

        $this->requireUsuarioGestionableEnSesion((int)$usuarioId);

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

        $this->requireUsuarioGestionableEnSesion((int)$usuarioId);

        $esSuperAdmin = !empty($_SESSION['es_super_admin']);

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

        $this->requireUsuarioGestionableEnSesion((int)$usuarioId);

        $currentUserId = (int)($_SESSION['user_id'] ?? 0);
        $esSuperAdmin = !empty($_SESSION['es_super_admin'])
            || (int)($_SESSION['rol_id'] ?? 0) === 1;
        $isSelfEdit = ((int)$usuarioId === $currentUserId);

        if ($isSelfEdit && !$esSuperAdmin) {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                echo json_encode([
                    'success' => false,
                    'message' => 'No puede modificar sus propias empresas/sedes desde aquí. Solicite a otro administrador.',
                ]);
                exit;
            }
            echo '<div class="modal-content" style="padding:24px;">'
                . '<h3 style="margin:0 0 12px;">Empresas y sedes del usuario</h3>'
                . '<p style="color:#c00;">No puede modificar sus propias empresas/sedes desde aquí. Solicite a otro administrador.</p>'
                . '<div style="text-align:right; margin-top:16px;">'
                . '<button type="button" class="btn-cancel" onclick="closeModalGod()">Cerrar</button>'
                . '</div></div>';
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

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonResponse(int $status, array $payload): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Redimensiona y re-codifica a JPEG en el temporal (reduce peso de fotos de cámara).
     */
    private function normalizeUploadedImageTmp(string $tmpPath, int $maxSide = 1280): void
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
            return;
        }

        $bytes = @file_get_contents($tmpPath);
        if ($bytes === false || $bytes === '') {
            return;
        }

        $im = @imagecreatefromstring($bytes);
        if ($im === false) {
            return;
        }

        $w = imagesx($im);
        $h = imagesy($im);
        if ($w < 1 || $h < 1) {
            imagedestroy($im);

            return;
        }

        $scale = min(1.0, $maxSide / max($w, $h));
        $nw = max(1, (int)round($w * $scale));
        $nh = max(1, (int)round($h * $scale));
        $work = $im;

        if ($scale < 1.0) {
            $resized = imagecreatetruecolor($nw, $nh);
            if ($resized) {
                imagecopyresampled($resized, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
                imagedestroy($im);
                $work = $resized;
            }
        }

        $flat = imagecreatetruecolor(imagesx($work), imagesy($work));
        if (!$flat) {
            imagedestroy($work);

            return;
        }

        $white = imagecolorallocate($flat, 255, 255, 255);
        imagefill($flat, 0, 0, $white);
        imagecopy($flat, $work, 0, 0, 0, 0, imagesx($work), imagesy($work));
        imagedestroy($work);
        imagejpeg($flat, $tmpPath, 82);
        imagedestroy($flat);
    }

    /**
     * Subida de imagen para foto o firma del formulario usuario (multipart campo "archivo").
     */
    public function uploadAsset(): void
    {
        $prevDisplayErrors = ini_get('display_errors');
        ini_set('display_errors', '0');

        try {
            if (!class_exists('PermisoService') || !PermisoService::canUsuarioFormApi()) {
                $this->jsonResponse(403, ['ok' => false, 'error' => 'Sin permiso']);
            }

            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                $this->jsonResponse(405, ['ok' => false, 'error' => 'Método no permitido']);
            }

            if (empty($_FILES['archivo']) || !is_uploaded_file((string)($_FILES['archivo']['tmp_name'] ?? ''))) {
                $this->jsonResponse(400, ['ok' => false, 'error' => 'Archivo requerido']);
            }

            $f = $_FILES['archivo'];
            $uploadErr = (int)($f['error'] ?? 0);

            if ($uploadErr !== UPLOAD_ERR_OK) {
                $msg = match ($uploadErr) {
                    UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Archivo demasiado grande (límite del servidor)',
                    UPLOAD_ERR_PARTIAL => 'La subida quedó incompleta',
                    UPLOAD_ERR_NO_FILE => 'Archivo requerido',
                    default => 'Error al subir',
                };
                $this->jsonResponse(400, ['ok' => false, 'error' => $msg]);
            }

            $tmp = (string)$f['tmp_name'];
            $mime = '';
            if (class_exists('finfo')) {
                $info = new finfo(FILEINFO_MIME_TYPE);
                $mime = $info->file($tmp) ?: '';
            }
            if ($mime === '' && function_exists('mime_content_type')) {
                $mime = mime_content_type($tmp) ?: '';
            }
            if ($mime === 'image/jpg') {
                $mime = 'image/jpeg';
            }

            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

            if (!isset($allowed[$mime])) {
                $this->jsonResponse(400, ['ok' => false, 'error' => 'Solo JPG, PNG o WebP']);
            }

            $this->normalizeUploadedImageTmp($tmp, 1280);
            $mime = 'image/jpeg';
            $ext = 'jpg';

            clearstatcache(true, $tmp);
            if ((int)@filesize($tmp) > 3 * 1024 * 1024) {
                $this->jsonResponse(400, ['ok' => false, 'error' => 'Máximo 3 MB tras comprimir. Acérquese más con el control «Acercar».']);
            }
            $dir = BASE_PATH . '/public/uploads/usuarios';

            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                $this->jsonResponse(500, ['ok' => false, 'error' => 'No se pudo crear la carpeta de subidas']);
            }

            if (!is_writable($dir)) {
                $this->jsonResponse(500, [
                    'ok' => false,
                    'error' => 'La carpeta de subidas no tiene permiso de escritura para el servidor web',
                ]);
            }

            $name = 'u_' . bin2hex(random_bytes(8)) . '.' . $ext;
            $dest = $dir . '/' . $name;

            if (!@move_uploaded_file($tmp, $dest)) {
                $this->jsonResponse(500, ['ok' => false, 'error' => 'No se pudo guardar el archivo en el servidor']);
            }

            $this->jsonResponse(200, ['ok' => true, 'path' => '/uploads/usuarios/' . $name]);
        } catch (Throwable $e) {
            error_log('uploadAsset: ' . $e->getMessage());
            $this->jsonResponse(500, ['ok' => false, 'error' => 'Error interno al subir el archivo']);
        } finally {
            if ($prevDisplayErrors !== false) {
                ini_set('display_errors', (string)$prevDisplayErrors);
            }
        }
    }

    /**
     * Id del vínculo persona en el formulario (terceroidentificacion_id o tercero_id legado).
     */
    private function resolvePersonaLinkIdFromRequest(): ?int
    {
        if (isset($_GET['terceroidentificacion_id']) && $_GET['terceroidentificacion_id'] !== '') {
            $v = (int)$_GET['terceroidentificacion_id'];

            return $v > 0 ? $v : null;
        }

        if (!isset($_GET['tercero_id']) || $_GET['tercero_id'] === '') {
            return null;
        }

        $terceroId = (int)$_GET['tercero_id'];
        if ($terceroId <= 0) {
            return null;
        }

        $pdo = (new Database())->connect();
        $link = new UsuarioPersonaLinkService($pdo);
        $linkColumn = $link->personaLinkColumn($link->getUsuarioColumnNames());

        if ($linkColumn === 'terceroidentificacion_id') {
            $principal = $link->fetchPrincipalIdentificacionId($terceroId);

            return $principal > 0 ? $principal : null;
        }

        return $terceroId;
    }
}