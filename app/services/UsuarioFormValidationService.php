<?php

/**
 * Validaciones y consultas del formulario CRUD usuario (tercero, ámbito, políticas).
 */
class UsuarioFormValidationService
{
    private const PASSWORD_MIN_LENGTH = 8;

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        if ($pdo !== null) {
            $this->pdo = $pdo;

            return;
        }

        $database = new Database();
        $this->pdo = $database->connect();
    }

    public function lookupUsername(string $username, ?int $terceroId, ?int $excludeUsuarioId = null): array
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

        $tidExisting = isset($existing['tercero_id']) && $existing['tercero_id'] !== '' && $existing['tercero_id'] !== null
            ? (int)$existing['tercero_id']
            : null;
        $tidForm = $terceroId !== null && $terceroId > 0 ? $terceroId : null;

        if ($tidExisting !== $tidForm) {
            return [
                'status' => 'other_tercero',
                'blocked' => true,
                'message' => 'Este nombre de usuario ya está en uso por otra persona (tercero distinto).',
                'usuario' => $this->publicUsuarioRow($existing),
            ];
        }

        $estado = (int)($existing['estado_id'] ?? 1);
        if ($estado !== 1) {
            return [
                'status' => 'inactive',
                'blocked' => true,
                'message' => 'Existe una cuenta inactiva con este usuario para el mismo tercero. Reactive esa cuenta; no se permite crear otra.',
                'usuario' => $this->publicUsuarioRow($existing),
            ];
        }

        if (!$this->isSuperAdmin() && !$this->usuarioVisibleInSessionScope($existingId)) {
            return [
                'status' => 'out_of_scope',
                'blocked' => true,
                'message' => 'Este usuario existe pero no pertenece a su empresa/sede. No tiene permiso para vincularlo desde aquí.',
                'usuario' => $this->publicUsuarioRow($existing),
            ];
        }

        $linked = $this->usuarioLinkedToSessionEmpresa($existingId);
        $empresaSession = $_SESSION['empresa_id'] ?? null;

        return [
            'status' => 'same_tercero',
            'blocked' => false,
            'message' => 'Ya hay cuenta con este usuario para el mismo tercero. Al guardar solo se vinculará a su empresa/sede (si aún no está asociada).',
            'usuario' => $this->publicUsuarioRow($existing),
            'linked_empresa' => $linked,
            'will_link_on_save' => !$linked && $empresaSession !== null && $empresaSession !== '',
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
     * Tras resolver/crear tercero_id (ensureTercero).
     *
     * @param array<string, mixed> $data
     * @param list<string> $columnNames
     */
    public function validateAfterTerceroResolved(array &$data, $id, array $columnNames): void
    {
        $tid = isset($data['tercero_id']) && $data['tercero_id'] !== '' && $data['tercero_id'] !== null
            ? (int)$data['tercero_id']
            : 0;

        if ($tid <= 0) {
            return;
        }

        $this->validateTerceroActivo($tid);
        $this->validateOneUsuarioPerTercero($data, $id, $tid);
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

        $sql = 'SELECT COUNT(*) FROM usuario WHERE tercero_id = ? AND estado_id = 1';
        $params = [$terceroId];
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

        $sql = 'SELECT id, username, tercero_id, estado_id FROM usuario WHERE tercero_id = ? AND estado_id <> 1';
        $params = [$terceroId];
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

        $tidExisting = isset($existing['tercero_id']) && $existing['tercero_id'] !== '' && $existing['tercero_id'] !== null
            ? (int)$existing['tercero_id']
            : null;
        $tidForm = isset($data['tercero_id']) && $data['tercero_id'] !== '' && $data['tercero_id'] !== null
            ? (int)$data['tercero_id']
            : null;

        if ($tidExisting !== $tidForm) {
            throw new Exception(json_encode([
                'username' => 'Este nombre de usuario ya está en uso por otra persona (tercero distinto).',
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
     */
    private function validateOneUsuarioPerTercero(array $data, $id, int $terceroId): void
    {
        if ($id) {
            return;
        }

        $username = trim((string)($data['username'] ?? ''));
        $existingByUser = $username !== '' ? $this->findUsuarioByUsername($username) : null;
        if ($existingByUser !== null
            && (int)($existingByUser['tercero_id'] ?? 0) === $terceroId
            && (int)($existingByUser['estado_id'] ?? 1) === 1) {
            return;
        }

        $count = $this->countActiveUsuariosByTercero($terceroId, null);
        if ($count > 0) {
            throw new Exception(json_encode([
                'username' => 'Este tercero ya tiene una cuenta de usuario activa. Use el mismo nombre de usuario para vincular la empresa o reactive la cuenta existente.',
            ], JSON_UNESCAPED_UNICODE));
        }

        $inactive = $this->findInactiveUsuarioByTercero($terceroId, null);
        if ($inactive !== null) {
            throw new Exception(json_encode([
                'username' => 'Hay una cuenta inactiva para este tercero. No se permite crear otra; reactive la existente.',
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

        $stmt = $this->pdo->prepare('
            SELECT id, username, tercero_id, estado_id, sesion_idle_minutos
            FROM usuario
            WHERE LOWER(TRIM(username)) = LOWER(TRIM(?))
            LIMIT 1
        ');
        $stmt->execute([trim($username)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function publicUsuarioRow(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'username' => (string)($row['username'] ?? ''),
            'tercero_id' => isset($row['tercero_id']) ? (int)$row['tercero_id'] : null,
            'estado_id' => isset($row['estado_id']) ? (int)$row['estado_id'] : null,
            'sesion_idle_minutos' => $row['sesion_idle_minutos'] ?? null,
        ];
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
        return (int)($_SESSION['rol_id'] ?? 0) === 1 || !empty($_SESSION['es_super_admin']);
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
