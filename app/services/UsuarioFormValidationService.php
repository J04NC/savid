<?php

/**
 * Validaciones y consultas del formulario CRUD usuario (tercero, ámbito, políticas).
 */
class UsuarioFormValidationService
{
    private const PASSWORD_MIN_LENGTH = 8;

    /** Rol Super Admin (global). */
    public const SUPER_ADMIN_ROL_ID = 1;

    private PDO $pdo;
    private UsuarioPersonaLinkService $personaLink;

    public function __construct(?PDO $pdo = null)
    {
        if ($pdo !== null) {
            $this->pdo = $pdo;
        } else {
            $database = new Database();
            $this->pdo = $database->connect();
        }

        $this->personaLink = new UsuarioPersonaLinkService($this->pdo);
    }

    public function lookupUsername(string $username, ?int $personaLinkId, ?int $excludeUsuarioId = null): array
    {
        $username = trim($username);
        if ($username === '') {
            return ['status' => 'none'];
        }

        $existing = $this->findUsuarioByUsername($username);
        if ($existing === null) {
            return ['status' => 'none'];
        }

        $existingId = (int)($existing['id'] ?? 0);
        if ($excludeUsuarioId !== null && $excludeUsuarioId > 0 && $existingId === $excludeUsuarioId) {
            return ['status' => 'none'];
        }

        $uCols = $this->getTableColumnNames('usuario');
        $linkColumn = $this->personaLink->personaLinkColumn($uCols);
        $linkExisting = $this->personaLink->linkValueFromRow($existing, $linkColumn);
        $linkForm = $personaLinkId !== null && $personaLinkId > 0 ? $personaLinkId : null;

        if ($linkExisting !== $linkForm) {
            return [
                'status' => 'other_tercero',
                'blocked' => true,
                'message' => $linkColumn === 'terceroidentificacion_id'
                    ? 'Este nombre de usuario ya está en uso con otra identificación (documento distinto).'
                    : 'Este nombre de usuario ya está en uso por otra persona (tercero distinto).',
                'usuario' => $this->publicUsuarioRow($existing, $uCols),
            ];
        }

        $estado = (int)($existing['estado_id'] ?? 1);
        if ($estado !== 1) {
            return [
                'status' => 'inactive',
                'blocked' => true,
                'message' => 'Existe una cuenta inactiva con este usuario para la misma identificación. Reactive esa cuenta; no se permite crear otra.',
                'usuario' => $this->publicUsuarioRow($existing, $uCols),
            ];
        }

        $inScope = $this->isSuperAdmin() || $this->usuarioVisibleInSessionScope($existingId);
        $linked = $this->usuarioLinkedToSessionEmpresa($existingId);
        $empresaSession = $_SESSION['empresa_id'] ?? null;
        $hasEmpresaSession = $empresaSession !== null && $empresaSession !== '';

        $msg = 'Ya hay cuenta con este usuario para la misma identificación. Al guardar solo se vinculará a su empresa/sede (si aún no está asociada).';
        if (!$inScope) {
            $msg = 'Ya existe un usuario con este nombre fuera de su empresa. Al guardar solo se asociará a la empresa y sede en sesión (no se modificarán sus datos).';
        }

        return [
            'status' => 'same_tercero',
            'blocked' => false,
            'message' => $msg,
            'usuario' => $this->publicUsuarioRow($existing, $uCols),
            'linked_empresa' => $linked,
            'will_link_on_save' => !$linked && $hasEmpresaSession,
            'link_only_existing' => !$inScope,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function lookupEmailForTercero(int $terceroId, string $email): array
    {
        $email = trim($email);
        if ($terceroId <= 0 || $email === '') {
            return ['status' => 'none'];
        }

        $current = $this->getTerceroEmail($terceroId);
        if ($current === null || trim($current) === '') {
            return ['status' => 'none'];
        }

        if (strcasecmp(trim($current), $email) === 0) {
            return ['status' => 'none'];
        }

        return [
            'status' => 'confirm_overwrite',
            'message' => 'El correo es distinto al registrado en el tercero (' . $current . '). ¿Desea actualizar el correo del tercero al guardar?',
            'tercero_email_actual' => $current,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function lookupIdentificacionChange(int $terceroId, int $tipodocumentoId, string $numero): array
    {
        $numero = trim($numero);
        if ($terceroId <= 0 || $tipodocumentoId <= 0 || $numero === '') {
            return ['status' => 'none'];
        }

        $principal = $this->fetchPrincipalIdentificacion($terceroId);
        if ($principal === []) {
            return ['status' => 'none'];
        }

        $sameTipo = (int)($principal['tipodocumento_id'] ?? 0) === $tipodocumentoId;
        $sameNum = trim((string)($principal['numero'] ?? '')) === $numero;

        if ($sameTipo && $sameNum) {
            return ['status' => 'none'];
        }

        return [
            'status' => 'confirm_change',
            'message' => 'El tipo o número difiere de la identificación principal del tercero. ¿Actualizar la principal o crear una nueva fila de identificación?',
            'principal' => [
                'tipodocumento_id' => $principal['tipodocumento_id'] ?? null,
                'numero' => $principal['numero'] ?? '',
            ],
        ];
    }

    /**
     * Validaciones antes de persistir usuario (lanza Exception JSON por campo).
     *
     * @param array<string, mixed> $data
     * @param list<string> $columnNames
     */
    public function validateBeforeSave(array &$data, $id, array $columnNames): void
    {
        $this->validatePasswordPolicy($data, $id);

        if (in_array('username', $columnNames, true)) {
            $this->validateUsernameOnSave($data, $id);
        }
    }

    /**
     * Validaciones según modo resuelto en servidor (spec §5).
     *
     * @param array<string, mixed> $data
     * @param list<string> $columnNames
     */
    public function validateForMode(array &$data, UsuarioSaveContext $ctx, array $columnNames): void
    {
        if ($ctx->isLinkOnly()) {
            return;
        }

        $id = $ctx->usuarioId;

        if ($ctx->isEdicion() && $id !== null) {
            $this->assertUsuarioGestionableEnSesion($id);
        }

        $this->validatePersonaDocumentoFields($data);
        $this->validateBeforeSave($data, $id, $columnNames);
    }

    /**
     * S10/S11: el POST debe traer tipo válido si hay número de documento.
     *
     * @param array<string, mixed> $data
     */
    private function validatePersonaDocumentoFields(array $data): void
    {
        $numero = trim((string)($data['numero_documento'] ?? ''));
        if ($numero === '') {
            return;
        }

        $tipoRaw = $data['tipodocumento_id'] ?? '';
        if ($tipoRaw === '' || $tipoRaw === null || (int)$tipoRaw <= 0) {
            throw new Exception(json_encode([
                'tipodocumento_id' => 'Seleccione el tipo de documento (ej. Cédula de Ciudadanía). El formulario no envió ese valor al guardar.',
            ], JSON_UNESCAPED_UNICODE));
        }

        $tipoId = (int)$tipoRaw;
        if (!$this->tipodocumentoRowExists($tipoId)) {
            throw new Exception(json_encode([
                'tipodocumento_id' => 'El tipo de documento no es válido. Vuelva a elegir «Cédula de Ciudadanía» en la lista.',
            ], JSON_UNESCAPED_UNICODE));
        }
    }

    private function tipodocumentoRowExists(int $tipodocumentoId): bool
    {
        if ($tipodocumentoId <= 0 || !$this->tableExists('tipodocumento')) {
            return false;
        }

        $stmt = $this->pdo->prepare('SELECT 1 FROM tipodocumento WHERE id = ? LIMIT 1');
        $stmt->execute([$tipodocumentoId]);

        return (bool)$stmt->fetchColumn();
    }

    /**
     * Tras resolver/crear tercero e identificación (ensureTercero).
     *
     * @param array<string, mixed> $data
     * @param list<string> $columnNames
     */
    public function validateAfterTerceroResolved(array &$data, $id, array $columnNames): void
    {
        $tid = $this->personaLink->resolveTerceroIdFromData($data, $columnNames) ?? 0;

        if ($tid <= 0) {
            return;
        }

        $this->validateTerceroActivo($tid);
        $this->validateOneUsuarioPerPersonaLink($data, $id, $columnNames);
        $this->validateEmailOverwriteOnSave($data, $tid);
        $this->validateIdentificacionActionOnSave($data, $tid);
    }

    public function usuarioVisibleInSessionScope(int $usuarioId): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $empresaSession = $_SESSION['empresa_id'] ?? null;
        if ($empresaSession === null || $empresaSession === '' || !$this->tableExists('usuario_empresa')) {
            return true;
        }

        $stmt = $this->pdo->prepare('
            SELECT 1 FROM usuario_empresa
            WHERE usuario_id = ? AND empresa_id = ? AND estado_id = 1
            LIMIT 1
        ');
        $stmt->execute([$usuarioId, (int)$empresaSession]);

        if ($stmt->fetchColumn()) {
            return true;
        }

        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid <= 0) {
            return false;
        }

        $stmt2 = $this->pdo->prepare('
            SELECT 1
            FROM usuario_empresa ue_target
            INNER JOIN usuario_empresa ue_self
                ON ue_self.empresa_id = ue_target.empresa_id AND ue_self.estado_id = 1
            WHERE ue_target.usuario_id = ? AND ue_target.estado_id = 1
            AND ue_self.usuario_id = ?
            LIMIT 1
        ');
        $stmt2->execute([$usuarioId, $uid]);

        return (bool)$stmt2->fetchColumn();
    }

    public function usuarioLinkedToSessionEmpresa(int $usuarioId): bool
    {
        $empresaSession = $_SESSION['empresa_id'] ?? null;
        if ($empresaSession === null || $empresaSession === '') {
            return true;
        }

        if (!$this->tableExists('usuario_empresa')) {
            return true;
        }

        $stmt = $this->pdo->prepare('
            SELECT 1 FROM usuario_empresa
            WHERE usuario_id = ? AND empresa_id = ? AND estado_id = 1
            LIMIT 1
        ');
        $stmt->execute([$usuarioId, (int)$empresaSession]);

        return (bool)$stmt->fetchColumn();
    }

    public function countActiveUsuariosByTercero(int $terceroId, ?int $excludeUsuarioId = null): int
    {
        if (!$this->tableExists('usuario')) {
            return 0;
        }

        $uCols = $this->getTableColumnNames('usuario');
        $linkColumn = $this->personaLink->personaLinkColumn($uCols);
        if ($linkColumn === null) {
            return 0;
        }

        if ($linkColumn === 'terceroidentificacion_id') {
            $identIds = $this->fetchIdentificacionIdsByTercero($terceroId);
            if ($identIds === []) {
                return 0;
            }

            $placeholders = implode(',', array_fill(0, count($identIds), '?'));
            $sql = "SELECT COUNT(*) FROM usuario WHERE terceroidentificacion_id IN ($placeholders) AND estado_id = 1";
            $params = $identIds;
        } else {
            $sql = 'SELECT COUNT(*) FROM usuario WHERE tercero_id = ? AND estado_id = 1';
            $params = [$terceroId];
        }

        if ($excludeUsuarioId !== null && $excludeUsuarioId > 0) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeUsuarioId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int)$stmt->fetchColumn();
    }

    public function countActiveUsuariosByPersonaLink(int $personaLinkId, array $usuarioColumnNames, ?int $excludeUsuarioId = null): int
    {
        if (!$this->tableExists('usuario') || $personaLinkId <= 0) {
            return 0;
        }

        $linkColumn = $this->personaLink->personaLinkColumn($usuarioColumnNames);
        if ($linkColumn === null) {
            return 0;
        }

        $sql = "SELECT COUNT(*) FROM usuario WHERE `$linkColumn` = ? AND estado_id = 1";
        $params = [$personaLinkId];
        if ($excludeUsuarioId !== null && $excludeUsuarioId > 0) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeUsuarioId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int)$stmt->fetchColumn();
    }

    public function findInactiveUsuarioByTercero(int $terceroId, ?int $excludeUsuarioId = null): ?array
    {
        if (!$this->tableExists('usuario')) {
            return null;
        }

        $uCols = $this->getTableColumnNames('usuario');
        $linkColumn = $this->personaLink->personaLinkColumn($uCols);
        if ($linkColumn === null) {
            return null;
        }

        $select = 'id, username, estado_id';
        if (in_array($linkColumn, $uCols, true)) {
            $select .= ', `' . $linkColumn . '`';
        }

        if ($linkColumn === 'terceroidentificacion_id') {
            $identIds = $this->fetchIdentificacionIdsByTercero($terceroId);
            if ($identIds === []) {
                return null;
            }
            $placeholders = implode(',', array_fill(0, count($identIds), '?'));
            $sql = "SELECT $select FROM usuario WHERE terceroidentificacion_id IN ($placeholders) AND estado_id <> 1";
            $params = $identIds;
        } else {
            $sql = "SELECT $select FROM usuario WHERE tercero_id = ? AND estado_id <> 1";
            $params = [$terceroId];
        }

        if ($excludeUsuarioId !== null && $excludeUsuarioId > 0) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeUsuarioId;
        }
        $sql .= ' ORDER BY id ASC LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function findInactiveUsuarioByPersonaLink(int $personaLinkId, array $usuarioColumnNames, ?int $excludeUsuarioId = null): ?array
    {
        if (!$this->tableExists('usuario') || $personaLinkId <= 0) {
            return null;
        }

        $linkColumn = $this->personaLink->personaLinkColumn($usuarioColumnNames);
        if ($linkColumn === null) {
            return null;
        }

        $select = 'id, username, estado_id';
        if (in_array($linkColumn, $usuarioColumnNames, true)) {
            $select .= ', `' . $linkColumn . '`';
        }

        $sql = "SELECT $select FROM usuario WHERE `$linkColumn` = ? AND estado_id <> 1";
        $params = [$personaLinkId];
        if ($excludeUsuarioId !== null && $excludeUsuarioId > 0) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeUsuarioId;
        }
        $sql .= ' ORDER BY id ASC LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function getTerceroEstadoId(int $terceroId): ?int
    {
        if (!$this->tableExists('tercero') || !in_array('estado_id', $this->getTableColumnNames('tercero'), true)) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT estado_id FROM tercero WHERE id = ? LIMIT 1');
        $stmt->execute([$terceroId]);

        $v = $stmt->fetchColumn();

        return $v !== false && $v !== null ? (int)$v : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchPrincipalIdentificacion(int $terceroId): array
    {
        if (!$this->tableExists('terceroidentificacion')) {
            return [];
        }

        $stmt = $this->pdo->prepare('
            SELECT id, tipodocumento_id, numero, dv
            FROM terceroidentificacion
            WHERE tercero_id = ?
            ORDER BY principal DESC, id ASC
            LIMIT 1
        ');
        $stmt->execute([$terceroId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: [];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function validatePasswordPolicy(array $data, $id): void
    {
        $pwd = trim((string)($data['password'] ?? ''));
        if ($pwd === '') {
            if (!$id) {
                throw new Exception(json_encode([
                    'password' => 'La contraseña es obligatoria al crear el usuario.',
                ], JSON_UNESCAPED_UNICODE));
            }

            return;
        }

        $errors = [];
        if (strlen($pwd) < self::PASSWORD_MIN_LENGTH) {
            $errors[] = 'mínimo ' . self::PASSWORD_MIN_LENGTH . ' caracteres';
        }
        if (!preg_match('/[a-záéíóúñ]/iu', $pwd)) {
            $errors[] = 'al menos una letra minúscula';
        }
        if (!preg_match('/[A-ZÁÉÍÓÚÑ]/u', $pwd)) {
            $errors[] = 'al menos una letra mayúscula';
        }
        if (!preg_match('/\d/', $pwd)) {
            $errors[] = 'al menos un número';
        }

        if ($errors !== []) {
            throw new Exception(json_encode([
                'password' => 'Contraseña débil: ' . implode(', ', $errors) . '.',
            ], JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function validateUsernameOnSave(array $data, $id): void
    {
        $username = trim((string)($data['username'] ?? ''));
        if ($username === '') {
            return;
        }

        $existing = $this->findUsuarioByUsername($username);
        if ($existing === null) {
            return;
        }

        $existingId = (int)($existing['id'] ?? 0);
        if ($id && (int)$id === $existingId) {
            return;
        }

        $uCols = $this->getTableColumnNames('usuario');
        $linkColumn = $this->personaLink->personaLinkColumn($uCols);
        $linkExisting = $this->personaLink->linkValueFromRow($existing, $linkColumn);
        $linkForm = $this->personaLink->linkValueFromData($data, $linkColumn);

        if ($linkExisting !== $linkForm) {
            throw new Exception(json_encode([
                'username' => $linkColumn === 'terceroidentificacion_id'
                    ? 'Este nombre de usuario ya está en uso con otra identificación (documento distinto).'
                    : 'Este nombre de usuario ya está en uso por otra persona (tercero distinto).',
            ], JSON_UNESCAPED_UNICODE));
        }

        if ((int)($existing['estado_id'] ?? 1) !== 1) {
            throw new Exception(json_encode([
                'username' => 'Existe una cuenta inactiva con este usuario. Reactive esa cuenta en lugar de crear otra.',
            ], JSON_UNESCAPED_UNICODE));
        }

        if (!$id && !$this->isSuperAdmin() && !$this->usuarioVisibleInSessionScope($existingId)) {
            throw new Exception(json_encode([
                'username' => 'Este usuario existe fuera de su empresa/sede. No puede vincularlo desde aquí.',
            ], JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $columnNames
     */
    private function validateOneUsuarioPerPersonaLink(array $data, $id, array $columnNames): void
    {
        if ($id) {
            return;
        }

        $linkColumn = $this->personaLink->personaLinkColumn($columnNames);
        $linkValue = $this->personaLink->linkValueFromData($data, $linkColumn);
        if ($linkColumn === null || $linkValue === null) {
            return;
        }

        $username = trim((string)($data['username'] ?? ''));
        $existingByUser = $username !== '' ? $this->findUsuarioByUsername($username) : null;
        if ($existingByUser !== null
            && $this->personaLink->linkValueFromRow($existingByUser, $linkColumn) === $linkValue
            && (int)($existingByUser['estado_id'] ?? 1) === 1) {
            return;
        }

        $count = $this->countActiveUsuariosByPersonaLink($linkValue, $columnNames, null);
        if ($count > 0) {
            throw new Exception(json_encode([
                'username' => 'Esta identificación ya tiene una cuenta de usuario activa. Use el mismo nombre de usuario para vincular la empresa o reactive la cuenta existente.',
            ], JSON_UNESCAPED_UNICODE));
        }

        $inactive = $this->findInactiveUsuarioByPersonaLink($linkValue, $columnNames, null);
        if ($inactive !== null) {
            throw new Exception(json_encode([
                'username' => 'Hay una cuenta inactiva para esta identificación. No se permite crear otra; reactive la existente.',
            ], JSON_UNESCAPED_UNICODE));
        }
    }

    private function validateTerceroActivo(int $terceroId): void
    {
        $estado = $this->getTerceroEstadoId($terceroId);
        if ($estado !== null && $estado !== 1) {
            throw new Exception(json_encode([
                'numero_documento' => 'El tercero asociado está inactivo. Reactive el tercero antes de crear o editar usuarios.',
            ], JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function validateEmailOverwriteOnSave(array $data, int $terceroId): void
    {
        $email = trim((string)($data['email'] ?? ''));
        if ($email === '') {
            return;
        }

        $current = $this->getTerceroEmail($terceroId);
        if ($current === null || trim($current) === '' || strcasecmp(trim($current), $email) === 0) {
            return;
        }

        $ok = trim((string)($data['usuario_email_overwrite_ok'] ?? '')) === '1';
        if (!$ok) {
            throw new Exception(json_encode([
                'email' => 'Confirme la actualización del correo del tercero (distinto al registrado: ' . $current . ').',
            ], JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function validateIdentificacionActionOnSave(array $data, int $terceroId): void
    {
        $tipo = isset($data['tipodocumento_id']) && $data['tipodocumento_id'] !== ''
            ? (int)$data['tipodocumento_id']
            : 0;
        $numero = trim((string)($data['numero_documento'] ?? ''));
        if ($tipo <= 0 || $numero === '') {
            return;
        }

        $principal = $this->fetchPrincipalIdentificacion($terceroId);
        if ($principal === []) {
            return;
        }

        $sameTipo = (int)($principal['tipodocumento_id'] ?? 0) === $tipo;
        $sameNum = trim((string)($principal['numero'] ?? '')) === $numero;
        if ($sameTipo && $sameNum) {
            return;
        }

        $accion = trim((string)($data['usuario_identificacion_accion'] ?? ''));
        if (!in_array($accion, ['update_principal', 'new_row'], true)) {
            throw new Exception(json_encode([
                'numero_documento' => 'Indique si actualiza la identificación principal o crea una nueva fila (confirme en el formulario).',
            ], JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findUsuarioByUsername(string $username): ?array
    {
        if (!$this->tableExists('usuario')) {
            return null;
        }

        $uCols = $this->getTableColumnNames('usuario');
        $linkColumn = $this->personaLink->personaLinkColumn($uCols);
        $linkSelect = $linkColumn !== null ? ', `' . $linkColumn . '`' : '';

        $stmt = $this->pdo->prepare("
            SELECT id, username, estado_id, sesion_idle_minutos$linkSelect
            FROM usuario
            WHERE LOWER(TRIM(username)) = LOWER(TRIM(?))
            LIMIT 1
        ");
        $stmt->execute([trim($username)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string>|null $usuarioColumnNames
     * @return array<string, mixed>
     */
    public function publicUsuarioRow(array $row, ?array $usuarioColumnNames = null): array
    {
        if ($usuarioColumnNames === null) {
            $usuarioColumnNames = $this->getTableColumnNames('usuario');
        }

        $linkColumn = $this->personaLink->personaLinkColumn($usuarioColumnNames);
        $linkValue = $this->personaLink->linkValueFromRow($row, $linkColumn);
        $terceroId = $this->personaLink->resolveTerceroId($linkValue, $linkColumn);
        $identId = $this->personaLink->resolveIdentificacionId($linkValue, $linkColumn);

        $out = [
            'id' => (int)($row['id'] ?? 0),
            'username' => (string)($row['username'] ?? ''),
            'estado_id' => isset($row['estado_id']) ? (int)$row['estado_id'] : null,
            'sesion_idle_minutos' => $row['sesion_idle_minutos'] ?? null,
        ];

        if ($linkColumn === 'terceroidentificacion_id') {
            $out['terceroidentificacion_id'] = $identId;
        }
        if ($terceroId !== null) {
            $out['tercero_id'] = $terceroId;
        }

        return $out;
    }

    /**
     * @return list<int>
     */
    private function fetchIdentificacionIdsByTercero(int $terceroId): array
    {
        if ($terceroId <= 0 || !$this->tableExists('terceroidentificacion')) {
            return [];
        }

        $stmt = $this->pdo->prepare('SELECT id FROM terceroidentificacion WHERE tercero_id = ?');
        $stmt->execute([$terceroId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    public function getTerceroEmail(int $terceroId): ?string
    {
        if (!$this->tableExists('tercero') || !in_array('email', $this->getTableColumnNames('tercero'), true)) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT email FROM tercero WHERE id = ? LIMIT 1');
        $stmt->execute([$terceroId]);
        $v = $stmt->fetchColumn();

        return $v !== false && $v !== null ? (string)$v : null;
    }

    public function isSuperAdmin(): bool
    {
        return $this->isSuperAdminViewer();
    }

    /**
     * Usuario en sesión con acceso global (no aplica filtros de empresa en listados).
     */
    public function isSuperAdminViewer(): bool
    {
        return !empty($_SESSION['es_super_admin'])
            || (int)($_SESSION['rol_id'] ?? 0) === self::SUPER_ADMIN_ROL_ID;
    }

    /**
     * Cuenta objetivo con rol Super Admin activo.
     */
    public function isSuperAdminUsuario(int $usuarioId): bool
    {
        if ($usuarioId <= 0 || !$this->tableExists('usuario_rol')) {
            return false;
        }

        $stmt = $this->pdo->prepare('
            SELECT 1 FROM usuario_rol
            WHERE usuario_id = ? AND rol_id = ? AND estado_id = 1
            LIMIT 1
        ');
        $stmt->execute([$usuarioId, self::SUPER_ADMIN_ROL_ID]);

        return (bool)$stmt->fetchColumn();
    }

    /**
     * Condición SQL: excluir cuentas con rol Super Admin (para listados de usuarios operativos).
     */
    public function sqlExcludeSuperAdminUsuarios(string $userAlias = 'u'): string
    {
        $alias = preg_replace('/[^a-zA-Z0-9_]/', '', $userAlias) ?: 'u';

        return ' AND NOT EXISTS (
            SELECT 1 FROM usuario_rol ur_sa
            WHERE ur_sa.usuario_id = ' . $alias . '.id
            AND ur_sa.estado_id = 1
            AND ur_sa.rol_id = ' . self::SUPER_ADMIN_ROL_ID . '
        ) ';
    }

    /**
     * Restringe listado/edición de usuarios al ámbito de la sesión (empresa logueada).
     *
     * @param list<int|float|string> $params
     */
    public function appendUsuarioListScopeSql(string $userAlias, string &$sql, array &$params): void
    {
        if ($this->isSuperAdminViewer()) {
            return;
        }

        $alias = preg_replace('/[^a-zA-Z0-9_]/', '', $userAlias) ?: 'u';
        $sql .= $this->sqlExcludeSuperAdminUsuarios($alias);

        $empresaSession = $_SESSION['empresa_id'] ?? null;
        $hasEmpresaSession = $empresaSession !== null && $empresaSession !== '';

        if ($hasEmpresaSession && $this->tableExists('usuario_empresa')) {
            $sql .= ' AND EXISTS (
                SELECT 1 FROM usuario_empresa ue
                WHERE ue.usuario_id = ' . $alias . '.id AND ue.empresa_id = ? AND ue.estado_id = 1
            )';
            $params[] = (int)$empresaSession;

            return;
        }

        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid > 0 && $this->tableExists('usuario_empresa')) {
            $sql .= ' AND EXISTS (
                SELECT 1 FROM usuario_empresa ue_target
                INNER JOIN usuario_empresa ue_self
                    ON ue_self.empresa_id = ue_target.empresa_id AND ue_self.estado_id = 1
                WHERE ue_target.usuario_id = ' . $alias . '.id AND ue_target.estado_id = 1
                AND ue_self.usuario_id = ?
            )';
            $params[] = $uid;
        }
    }

    /**
     * @throws Exception
     */
    public function assertUsuarioGestionableEnSesion(int $usuarioId): void
    {
        if ($usuarioId <= 0) {
            return;
        }

        if ($this->isSuperAdminViewer()) {
            return;
        }

        if ($this->isSuperAdminUsuario($usuarioId)) {
            throw new Exception(json_encode([
                'general' => 'No puede modificar cuentas de superadministrador.',
            ], JSON_UNESCAPED_UNICODE));
        }

        if (!$this->usuarioVisibleInSessionScope($usuarioId)) {
            throw new Exception(json_encode([
                'general' => 'Este usuario no pertenece a su empresa o no tiene permiso para gestionarlo.',
            ], JSON_UNESCAPED_UNICODE));
        }
    }

    public function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare('
            SELECT 1 FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
            LIMIT 1
        ');
        $stmt->execute([$table]);

        return (bool)$stmt->fetchColumn();
    }

    /**
     * @return list<string>
     */
    public function getTableColumnNames(string $table): array
    {
        static $cache = [];
        if (isset($cache[$table])) {
            return $cache[$table];
        }

        $stmt = $this->pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`');
        $cache[$table] = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');

        return $cache[$table];
    }
}
