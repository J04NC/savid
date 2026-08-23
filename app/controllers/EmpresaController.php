<?php

/**
 * Rutas bajo `?url=empresa/...` sin sustituir el CRUD automático del ítem:
 * `index` delega en ModuleController; las demás acciones son modales / especiales.
 */
class EmpresaController
{
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

        $database = new Database();
        $pdo = $database->connect();

        $stmt = $pdo->prepare(
            'SELECT e.id, t.razon_social
             FROM empresa e
             INNER JOIN tercero t ON t.id = e.tercero_id
             WHERE e.id = ? LIMIT 1'
        );
        $stmt->execute([$eid]);
        $empresa = $stmt->fetch(PDO::FETCH_ASSOC);

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
            $this->handleUsuariosPost($pdo, $eid);
            return;
        }

        $usuarios = $this->fetchEmpresaUsuarios($pdo, $eid);

        require BASE_PATH . '/app/views/empresa/usuarios_modal.php';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchEmpresaUsuarios(PDO $pdo, int $empresaId): array
    {
        $stmt = $pdo->prepare(
            "SELECT u.id, u.username, u.estado_id,
                    ti.numero AS nit_or_doc,
                    t.nombres, t.apellidos, t.razon_social,
                    ue.estado_id AS link_estado_id,
                    CASE WHEN ue.usuario_id IS NOT NULL THEN 1 ELSE 0 END AS vinculo_empresa,
                    (
                        SELECT COUNT(*)
                        FROM usuario_sede us
                        INNER JOIN sede s ON s.id = us.sede_id AND s.empresa_id = ?
                        WHERE us.usuario_id = u.id AND us.estado_id = 1
                    ) AS sedes_activas
               FROM usuario u
               INNER JOIN (
                    SELECT usuario_id FROM usuario_empresa WHERE empresa_id = ?
                    UNION
                    SELECT DISTINCT us.usuario_id
                      FROM usuario_sede us
                      INNER JOIN sede s ON s.id = us.sede_id AND s.empresa_id = ?
               ) AS eu ON eu.usuario_id = u.id
               LEFT JOIN usuario_empresa ue
                      ON ue.usuario_id = u.id AND ue.empresa_id = ?
               LEFT JOIN terceroidentificacion ti ON ti.id = u.terceroidentificacion_id
               LEFT JOIN tercero t ON t.id = ti.tercero_id
              ORDER BY vinculo_empresa DESC, COALESCE(ue.estado_id, 0) DESC, u.username ASC"
        );
        $stmt->execute([$empresaId, $empresaId, $empresaId, $empresaId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function handleUsuariosPost(PDO $pdo, int $empresaId): void
    {
        $action = (string)($_POST['_action'] ?? '');

        if ($action === 'search') {
            $this->handleUsuariosSearch($pdo, $empresaId);
            return;
        }

        if ($action === 'link') {
            $this->handleUsuariosLink($pdo, $empresaId);
            return;
        }

        if ($action === 'toggle') {
            $this->handleUsuariosToggle($pdo, $empresaId);
            return;
        }

        if ($action === 'delete') {
            $this->handleUsuariosDelete($pdo, $empresaId);
            return;
        }

        $this->jsonResponse(['success' => false, 'message' => 'Acción inválida.']);
    }

    private function handleUsuariosDelete(PDO $pdo, int $empresaId): void
    {
        if (!$this->isSuperAdmin()) {
            $this->jsonResponse([
                'success' => false,
                'message' => 'Solo un superadministrador puede eliminar vínculos de usuario.',
            ]);
            return;
        }

        $usuarioId = (int)($_POST['usuario_id'] ?? 0);
        if ($usuarioId <= 0) {
            $this->jsonResponse(['success' => false, 'message' => 'Usuario no especificado.']);
            return;
        }

        $st = $pdo->prepare(
            'SELECT 1 FROM usuario_rol WHERE usuario_id = ? AND rol_id = 1 AND estado_id = 1 LIMIT 1'
        );
        $st->execute([$usuarioId]);
        if ($st->fetchColumn()) {
            $this->jsonResponse(['success' => false, 'message' => 'No puede eliminar cuentas de superadministrador.']);
            return;
        }

        $currentUid = (int)($_SESSION['user_id'] ?? 0);
        if ($usuarioId === $currentUid) {
            $this->jsonResponse(['success' => false, 'message' => 'No puede eliminar su propio vínculo con la empresa.']);
            return;
        }

        $st = $pdo->prepare('SELECT 1 FROM usuario_empresa WHERE usuario_id = ? AND empresa_id = ? LIMIT 1');
        $st->execute([$usuarioId, $empresaId]);
        $tieneVinculoEmpresa = (bool)$st->fetchColumn();

        $st = $pdo->prepare(
            'SELECT 1 FROM usuario_sede us
             INNER JOIN sede s ON s.id = us.sede_id AND s.empresa_id = ?
             WHERE us.usuario_id = ? LIMIT 1'
        );
        $st->execute([$empresaId, $usuarioId]);
        $tieneAsignacionSede = (bool)$st->fetchColumn();

        if (!$tieneVinculoEmpresa && !$tieneAsignacionSede) {
            $this->jsonResponse(['success' => false, 'message' => 'El usuario no tiene vínculo ni sedes en esta empresa.']);
            return;
        }

        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare(
                'DELETE us FROM usuario_sede us
                 INNER JOIN sede s ON s.id = us.sede_id AND s.empresa_id = ?
                 WHERE us.usuario_id = ?'
            );
            $st->execute([$empresaId, $usuarioId]);

            if ($tieneVinculoEmpresa) {
                $st = $pdo->prepare('DELETE FROM usuario_empresa WHERE usuario_id = ? AND empresa_id = ?');
                $st->execute([$usuarioId, $empresaId]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            $this->jsonResponse(['success' => false, 'message' => 'No se pudo eliminar: ' . $e->getMessage()]);
            return;
        }

        $this->jsonResponse([
            'success' => true,
            'message' => 'Vínculo de usuario eliminado de la empresa.',
            'usuarios' => $this->fetchEmpresaUsuarios($pdo, $empresaId),
        ]);
    }

    private function handleUsuariosSearch(PDO $pdo, int $empresaId): void
    {
        $term = trim((string)($_POST['term'] ?? ''));
        if ($term === '' || mb_strlen($term) < 2) {
            $this->jsonResponse(['success' => true, 'options' => []]);
            return;
        }

        $like = '%' . $term . '%';

        $sql = "SELECT u.id, u.username, ti.numero AS doc, t.nombres, t.apellidos, t.razon_social,
                       (
                         EXISTS (
                           SELECT 1 FROM usuario_empresa ue
                           WHERE ue.usuario_id = u.id AND ue.empresa_id = ? AND ue.estado_id = 1
                         )
                         OR EXISTS (
                           SELECT 1 FROM usuario_sede us
                           INNER JOIN sede s ON s.id = us.sede_id AND s.empresa_id = ?
                           WHERE us.usuario_id = u.id
                         )
                       ) AS ya_vinculado
                  FROM usuario u
                  LEFT JOIN terceroidentificacion ti ON ti.id = u.terceroidentificacion_id
                  LEFT JOIN tercero t ON t.id = ti.tercero_id
                 WHERE (u.estado_id IS NULL OR u.estado_id = 1)
                   AND (u.username LIKE ?
                        OR ti.numero LIKE ?
                        OR t.nombres LIKE ?
                        OR t.apellidos LIKE ?
                        OR t.razon_social LIKE ?)
                   AND NOT EXISTS (
                     SELECT 1 FROM usuario_rol ur
                     WHERE ur.usuario_id = u.id AND ur.rol_id = 1 AND ur.estado_id = 1
                   )
                 ORDER BY u.username ASC
                 LIMIT 15";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$empresaId, $empresaId, $like, $like, $like, $like, $like]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $esSuperAdmin = !empty($_SESSION['es_super_admin'])
            || (int)($_SESSION['rol_id'] ?? 0) === 1;
        $currentUid = (int)($_SESSION['user_id'] ?? 0);

        if (!$esSuperAdmin && $currentUid > 0) {
            $rows = array_values(array_filter(
                $rows,
                fn ($r) => $this->usuarioVisibleAlLogueado($pdo, (int)$r['id'])
            ));
        }

        $this->jsonResponse(['success' => true, 'options' => $rows]);
    }

    private function handleUsuariosLink(PDO $pdo, int $empresaId): void
    {
        $usuarioId = (int)($_POST['usuario_id'] ?? 0);
        if ($usuarioId <= 0) {
            $this->jsonResponse(['success' => false, 'message' => 'Usuario no especificado.']);
            return;
        }

        $st = $pdo->prepare('SELECT id, estado_id FROM usuario WHERE id = ? LIMIT 1');
        $st->execute([$usuarioId]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if (!$u) {
            $this->jsonResponse(['success' => false, 'message' => 'Usuario no encontrado.']);
            return;
        }

        $st = $pdo->prepare(
            'SELECT 1 FROM usuario_rol WHERE usuario_id = ? AND rol_id = 1 AND estado_id = 1 LIMIT 1'
        );
        $st->execute([$usuarioId]);
        if ($st->fetchColumn()) {
            $this->jsonResponse(['success' => false, 'message' => 'No puede modificar cuentas de superadministrador.']);
            return;
        }

        $esSuperAdmin = !empty($_SESSION['es_super_admin'])
            || (int)($_SESSION['rol_id'] ?? 0) === 1;
        if (!$esSuperAdmin && !$this->usuarioVisibleAlLogueado($pdo, $usuarioId)) {
            $this->jsonResponse([
                'success' => false,
                'message' => 'Solo puede vincular usuarios que ya están en su alcance.',
            ]);
            return;
        }

        try {
            $st = $pdo->prepare(
                'INSERT INTO usuario_empresa (usuario_id, empresa_id, estado_id)
                 VALUES (?, ?, 1)
                 ON DUPLICATE KEY UPDATE estado_id = 1'
            );
            $st->execute([$usuarioId, $empresaId]);
        } catch (Throwable $e) {
            $this->jsonResponse(['success' => false, 'message' => 'No se pudo vincular: ' . $e->getMessage()]);
            return;
        }

        $this->jsonResponse([
            'success' => true,
            'message' => 'Usuario vinculado a la empresa.',
            'usuarios' => $this->fetchEmpresaUsuarios($pdo, $empresaId),
        ]);
    }

    private function handleUsuariosToggle(PDO $pdo, int $empresaId): void
    {
        $usuarioId = (int)($_POST['usuario_id'] ?? 0);
        if ($usuarioId <= 0) {
            $this->jsonResponse(['success' => false, 'message' => 'Usuario no especificado.']);
            return;
        }

        $currentUid = (int)($_SESSION['user_id'] ?? 0);
        $sessionEmpresaId = isset($_SESSION['empresa_id']) ? (int)$_SESSION['empresa_id'] : 0;
        $esSuperAdmin = !empty($_SESSION['es_super_admin'])
            || (int)($_SESSION['rol_id'] ?? 0) === 1;

        if ($usuarioId === $currentUid
            && $empresaId === $sessionEmpresaId
            && !$esSuperAdmin
        ) {
            $this->jsonResponse([
                'success' => false,
                'message' => 'No puede inactivarse a sí mismo en la empresa de su sesión actual.',
            ]);
            return;
        }

        $st = $pdo->prepare(
            'SELECT estado_id FROM usuario_empresa WHERE usuario_id = ? AND empresa_id = ? LIMIT 1'
        );
        $st->execute([$usuarioId, $empresaId]);
        $current = $st->fetchColumn();
        if ($current === false) {
            $this->jsonResponse(['success' => false, 'message' => 'El usuario no está vinculado a esta empresa.']);
            return;
        }

        $new = ((int)$current === 1) ? 2 : 1;

        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('UPDATE usuario_empresa SET estado_id = ? WHERE usuario_id = ? AND empresa_id = ?');
            $st->execute([$new, $usuarioId, $empresaId]);

            if ($new === 2) {
                $st = $pdo->prepare(
                    'UPDATE usuario_sede us
                     INNER JOIN sede s ON s.id = us.sede_id
                     SET us.estado_id = 2
                     WHERE us.usuario_id = ? AND s.empresa_id = ?'
                );
                $st->execute([$usuarioId, $empresaId]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            $this->jsonResponse(['success' => false, 'message' => 'No se pudo cambiar el estado: ' . $e->getMessage()]);
            return;
        }

        $this->jsonResponse([
            'success' => true,
            'message' => $new === 1 ? 'Vínculo activado.' : 'Vínculo inactivado (y sedes asociadas).',
            'usuarios' => $this->fetchEmpresaUsuarios($pdo, $empresaId),
        ]);
    }

    private function usuarioVisibleAlLogueado(PDO $pdo, int $targetUsuarioId): bool
    {
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid <= 0) {
            return false;
        }

        $st = $pdo->prepare(
            'SELECT 1
               FROM usuario_empresa ue_target
               INNER JOIN usuario_empresa ue_self
                 ON ue_self.empresa_id = ue_target.empresa_id
                AND ue_self.estado_id = 1
              WHERE ue_target.usuario_id = ? AND ue_target.estado_id = 1
                AND ue_self.usuario_id = ?
              LIMIT 1'
        );
        $st->execute([$targetUsuarioId, $uid]);

        return (bool)$st->fetchColumn();
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

        $database = new Database();
        $pdo = $database->connect();

        $stmt = $pdo->prepare(
            'SELECT e.id, t.razon_social
             FROM empresa e
             INNER JOIN tercero t ON t.id = e.tercero_id
             WHERE e.id = ? LIMIT 1'
        );
        $stmt->execute([$eid]);
        $empresa = $stmt->fetch(PDO::FETCH_ASSOC);

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
            $this->handlePost($pdo, $eid);
            return;
        }

        $sedes = $this->fetchSedes($pdo, $eid);

        require BASE_PATH . '/app/views/empresa/sedes_modal.php';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchSedes(PDO $pdo, int $empresaId): array
    {
        $stmt = $pdo->prepare(
            'SELECT id, nombre, direccion, telefono, codigo_interno, estado_id
             FROM sede
             WHERE empresa_id = ?
             ORDER BY estado_id DESC, nombre ASC, id ASC'
        );
        $stmt->execute([$empresaId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function handlePost(PDO $pdo, int $empresaId): void
    {
        $action = (string)($_POST['_action'] ?? '');

        if ($action === 'save') {
            $this->handleSave($pdo, $empresaId);
            return;
        }

        if ($action === 'toggle') {
            $this->handleToggle($pdo, $empresaId);
            return;
        }

        if ($action === 'delete') {
            $this->handleDelete($pdo, $empresaId);
            return;
        }

        $this->jsonResponse(['success' => false, 'message' => 'Acción inválida.']);
    }

    private function handleDelete(PDO $pdo, int $empresaId): void
    {
        if (!$this->isSuperAdmin()) {
            $this->jsonResponse([
                'success' => false,
                'message' => 'Solo un superadministrador puede eliminar sedes.',
            ]);
            return;
        }

        $sedeId = (int)($_POST['sede_id'] ?? 0);
        if ($sedeId <= 0) {
            $this->jsonResponse(['success' => false, 'message' => 'Sede no especificada.']);
            return;
        }

        $own = $pdo->prepare('SELECT id, nombre FROM sede WHERE id = ? AND empresa_id = ? LIMIT 1');
        $own->execute([$sedeId, $empresaId]);
        $row = $own->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $this->jsonResponse(['success' => false, 'message' => 'La sede no pertenece a esta empresa.']);
            return;
        }

        $links = $pdo->prepare('SELECT COUNT(*) FROM usuario_sede WHERE sede_id = ?');
        $links->execute([$sedeId]);
        $asignaciones = (int)$links->fetchColumn();

        $pdo->beginTransaction();
        try {
            if ($asignaciones > 0) {
                $st = $pdo->prepare('DELETE FROM usuario_sede WHERE sede_id = ?');
                $st->execute([$sedeId]);
            }

            $del = $pdo->prepare('DELETE FROM sede WHERE id = ? AND empresa_id = ?');
            $del->execute([$sedeId, $empresaId]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            $this->jsonResponse([
                'success' => false,
                'message' => 'No se pudo eliminar: ' . $e->getMessage(),
            ]);
            return;
        }

        $msg = 'Sede eliminada.';
        if ($asignaciones > 0) {
            $msg .= ' Se retiraron ' . $asignaciones . ' asignación(es) de usuario en esa sede.';
        }

        $this->jsonResponse([
            'success' => true,
            'message' => $msg,
            'sedes' => $this->fetchSedes($pdo, $empresaId),
        ]);
    }

    private function handleSave(PDO $pdo, int $empresaId): void
    {
        $sedeId = (int)($_POST['sede_id'] ?? 0);
        $nombre = trim((string)($_POST['nombre'] ?? ''));
        $direccion = trim((string)($_POST['direccion'] ?? ''));
        $telefono = trim((string)($_POST['telefono'] ?? ''));
        $codigoInterno = trim((string)($_POST['codigo_interno'] ?? ''));
        $estadoIdRaw = (int)($_POST['estado_id'] ?? 1);
        $estadoId = $estadoIdRaw === 2 ? 2 : 1;

        $errors = [];

        if ($nombre === '') {
            $errors['nombre'] = 'El nombre es obligatorio.';
        } elseif (mb_strlen($nombre) > 100) {
            $errors['nombre'] = 'Máximo 100 caracteres.';
        }

        if ($direccion !== '' && mb_strlen($direccion) > 150) {
            $errors['direccion'] = 'Máximo 150 caracteres.';
        }
        if ($telefono !== '' && mb_strlen($telefono) > 150) {
            $errors['telefono'] = 'Máximo 150 caracteres.';
        }
        if ($codigoInterno !== '' && mb_strlen($codigoInterno) > 150) {
            $errors['codigo_interno'] = 'Máximo 150 caracteres.';
        }

        if ($nombre !== '') {
            $sql = 'SELECT id FROM sede WHERE empresa_id = ? AND LOWER(nombre) = LOWER(?) LIMIT 1';
            $params = [$empresaId, $nombre];
            if ($sedeId > 0) {
                $sql = 'SELECT id FROM sede WHERE empresa_id = ? AND LOWER(nombre) = LOWER(?) AND id <> ? LIMIT 1';
                $params[] = $sedeId;
            }
            $check = $pdo->prepare($sql);
            $check->execute($params);
            if ($check->fetchColumn()) {
                $errors['nombre'] = 'Ya existe una sede con ese nombre en la empresa.';
            }
        }

        if ($sedeId > 0) {
            $own = $pdo->prepare('SELECT id FROM sede WHERE id = ? AND empresa_id = ? LIMIT 1');
            $own->execute([$sedeId, $empresaId]);
            if (!$own->fetchColumn()) {
                $this->jsonResponse(['success' => false, 'message' => 'La sede no pertenece a esta empresa.']);
                return;
            }
        }

        if ($errors !== []) {
            $this->jsonResponse([
                'success' => false,
                'message' => 'Revise los campos resaltados.',
                'errors' => $errors,
            ]);
            return;
        }

        try {
            if ($sedeId > 0) {
                $stmt = $pdo->prepare(
                    'UPDATE sede
                     SET nombre = ?, direccion = ?, telefono = ?, codigo_interno = ?, estado_id = ?
                     WHERE id = ? AND empresa_id = ?'
                );
                $stmt->execute([
                    $nombre,
                    $direccion !== '' ? $direccion : null,
                    $telefono !== '' ? $telefono : null,
                    $codigoInterno !== '' ? $codigoInterno : null,
                    $estadoId,
                    $sedeId,
                    $empresaId,
                ]);
                $savedId = $sedeId;
                $msg = 'Sede actualizada.';
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO sede (empresa_id, nombre, direccion, telefono, codigo_interno, estado_id)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $empresaId,
                    $nombre,
                    $direccion !== '' ? $direccion : null,
                    $telefono !== '' ? $telefono : null,
                    $codigoInterno !== '' ? $codigoInterno : null,
                    $estadoId,
                ]);
                $savedId = (int)$pdo->lastInsertId();
                $msg = 'Sede creada.';
            }
        } catch (Throwable $e) {
            $this->jsonResponse([
                'success' => false,
                'message' => 'No se pudo guardar: ' . $e->getMessage(),
            ]);
            return;
        }

        $this->jsonResponse([
            'success' => true,
            'message' => $msg,
            'sede_id' => $savedId,
            'sedes' => $this->fetchSedes($pdo, $empresaId),
        ]);
    }

    private function handleToggle(PDO $pdo, int $empresaId): void
    {
        $sedeId = (int)($_POST['sede_id'] ?? 0);
        if ($sedeId <= 0) {
            $this->jsonResponse(['success' => false, 'message' => 'Sede no especificada.']);
            return;
        }

        $stmt = $pdo->prepare('SELECT estado_id FROM sede WHERE id = ? AND empresa_id = ? LIMIT 1');
        $stmt->execute([$sedeId, $empresaId]);
        $current = $stmt->fetchColumn();
        if ($current === false) {
            $this->jsonResponse(['success' => false, 'message' => 'La sede no pertenece a esta empresa.']);
            return;
        }

        $new = ((int)$current === 1) ? 2 : 1;

        try {
            $upd = $pdo->prepare('UPDATE sede SET estado_id = ? WHERE id = ? AND empresa_id = ?');
            $upd->execute([$new, $sedeId, $empresaId]);
        } catch (Throwable $e) {
            $this->jsonResponse(['success' => false, 'message' => 'No se pudo cambiar el estado: ' . $e->getMessage()]);
            return;
        }

        $this->jsonResponse([
            'success' => true,
            'message' => $new === 1 ? 'Sede activada.' : 'Sede inactivada.',
            'sede_id' => $sedeId,
            'estado_id' => $new,
            'sedes' => $this->fetchSedes($pdo, $empresaId),
        ]);
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

        $database = new Database();
        $pdo = $database->connect();

        $stmt = $pdo->prepare(
            'SELECT e.id, t.razon_social
             FROM empresa e
             INNER JOIN tercero t ON t.id = e.tercero_id
             WHERE e.id = ? LIMIT 1'
        );
        $stmt->execute([$eid]);
        $empresa = $stmt->fetch(PDO::FETCH_ASSOC);

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

        $database = new Database();
        $pdo = $database->connect();

        $stmt = $pdo->prepare(
            'SELECT e.id, t.razon_social
             FROM empresa e
             INNER JOIN tercero t ON t.id = e.tercero_id
             WHERE e.id = ? LIMIT 1'
        );
        $stmt->execute([$eid]);
        $empresa = $stmt->fetch(PDO::FETCH_ASSOC);

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
