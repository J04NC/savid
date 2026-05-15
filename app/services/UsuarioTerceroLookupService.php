<?php

/**
 * Consultas de tercero / usuario para el formulario CRUD usuario (autocompletado y modales).
 */
class UsuarioTerceroLookupService
{
    private PDO $pdo;
    private UsuarioFormValidationService $validation;

    public function __construct(?PDO $pdo = null)
    {
        if ($pdo !== null) {
            $this->pdo = $pdo;
        } else {
            $database = new Database();
            $this->pdo = $database->connect();
        }

        $this->validation = new UsuarioFormValidationService($this->pdo);
    }

    /**
     * Tipo + número de documento (identificación exacta).
     *
     * @return array<string, mixed>
     */
    public function lookupByTipoAndNumero(int $tipodocumentoId, string $numero, ?int $excludeUsuarioId = null): array
    {
        $numero = trim($numero);
        if ($tipodocumentoId <= 0 || $numero === '') {
            return ['status' => 'none'];
        }

        if (!$this->tableExists('terceroidentificacion') || !$this->tableExists('tercero')) {
            return ['status' => 'none'];
        }

        $row = $this->fetchIdentificacionRow($tipodocumentoId, $numero);
        if ($row === null) {
            return ['status' => 'none'];
        }

        $terceroId = (int)($row['tercero_id'] ?? 0);
        if ($terceroId <= 0) {
            return ['status' => 'none'];
        }

        $estadoTercero = $this->validation->getTerceroEstadoId($terceroId);
        if ($estadoTercero !== null && $estadoTercero !== 1) {
            return [
                'status' => 'inactive_tercero',
                'blocked' => true,
                'message' => 'El tercero con este documento está inactivo. Reactive el tercero antes de continuar.',
                'tercero_id' => $terceroId,
            ];
        }

        $tercero = $this->buildTerceroPayload($terceroId, $row);

        $inactiveUser = $this->validation->findInactiveUsuarioByTercero($terceroId, $excludeUsuarioId);
        if ($inactiveUser !== null && ($excludeUsuarioId === null || $excludeUsuarioId <= 0)) {
            return [
                'status' => 'inactive_usuario',
                'blocked' => true,
                'message' => 'Existe una cuenta de usuario inactiva para este tercero. Reactive esa cuenta; no se permite crear otra.',
                'usuario' => $this->validation->publicUsuarioRow($inactiveUser),
                'tercero' => $tercero,
                'tercero_id' => $terceroId,
            ];
        }

        $usuarios = $this->findUsuariosByTerceroId($terceroId, $excludeUsuarioId, true);

        if ($usuarios === []) {
            return [
                'status' => 'tercero_only',
                'message' => 'Ya existe esta identificación en un tercero. Se completaron los datos comunes.',
                'tercero' => $tercero,
                'tercero_id' => $terceroId,
            ];
        }

        $usuario = $usuarios[0];
        $usuarioId = (int)($usuario['id'] ?? 0);

        if (!$this->validation->isSuperAdmin() && !$this->validation->usuarioVisibleInSessionScope($usuarioId)) {
            return [
                'status' => 'out_of_scope',
                'blocked' => true,
                'message' => 'Ya existe un usuario con este documento en otra empresa/sede. No tiene permiso para vincularlo desde aquí.',
                'tercero' => $tercero,
                'tercero_id' => $terceroId,
                'usuario' => $usuario,
            ];
        }

        $linkedEmpresa = $this->validation->usuarioLinkedToSessionEmpresa($usuarioId);
        $empresaSession = $_SESSION['empresa_id'] ?? null;
        $isNewForm = $excludeUsuarioId === null || $excludeUsuarioId <= 0;

        $msg = count($usuarios) > 1
            ? 'Documento con varias cuentas. Se cargó la primera.'
            : 'Ya existe cuenta para este tercero. Use el mismo nombre de usuario (' . ($usuario['username'] ?? '') . ') para vincular su empresa al guardar.';

        if ($isNewForm) {
            $msg .= ' No puede crear una segunda cuenta para la misma persona.';
        } elseif (!$linkedEmpresa && $empresaSession !== null && $empresaSession !== '') {
            $msg .= ' Al guardar se vinculará a su empresa/sede.';
        }

        return [
            'status' => 'both',
            'blocked' => $isNewForm,
            'message' => $msg,
            'tercero' => $tercero,
            'tercero_id' => $terceroId,
            'usuario' => $usuario,
            'usuarios' => $usuarios,
            'linked_empresa' => $linkedEmpresa,
            'will_link_empresa_on_save' => !$linkedEmpresa && $empresaSession !== null && $empresaSession !== '',
        ];
    }

    /**
     * Solo número (puede haber varios terceros / tipos).
     *
     * @return array<string, mixed>
     */
    public function lookupByNumeroOnly(string $numero, ?int $excludeUsuarioId = null): array
    {
        $numero = trim($numero);
        if ($numero === '' || !$this->tableExists('terceroidentificacion')) {
            return ['status' => 'none'];
        }

        $matches = $this->fetchTercerosByNumero($numero, $excludeUsuarioId);

        if ($matches === []) {
            return ['status' => 'none'];
        }

        if (count($matches) === 1) {
            $m = $matches[0];

            return [
                'status' => 'single',
                'tercero' => $m['tercero'],
                'tercero_id' => $m['tercero_id'],
                'tipodocumento_id' => $m['tipodocumento_id'] ?? null,
            ];
        }

        return [
            'status' => 'multiple',
            'message' => 'Varios terceros usan este número de documento. Elija uno o cree un tercero nuevo.',
            'options' => $matches,
        ];
    }

    /**
     * Correo en tabla tercero.
     *
     * @return array<string, mixed>
     */
    public function lookupByEmail(string $email, ?int $excludeUsuarioId = null): array
    {
        $email = trim($email);
        if ($email === '' || !$this->tableExists('tercero') || !in_array('email', $this->getTableColumnNames('tercero'), true)) {
            return ['status' => 'none'];
        }

        $stmt = $this->pdo->prepare('
            SELECT t.id AS tercero_id, t.nombres, t.apellidos, t.email,
                   t.foto_ruta, t.firma_ruta
            FROM tercero t
            WHERE LOWER(TRIM(t.email)) = LOWER(TRIM(?))
            AND (t.estado_id IS NULL OR t.estado_id = 1)
            ORDER BY t.id ASC
            LIMIT 20
        ');
        $stmt->execute([$email]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($rows === []) {
            return ['status' => 'none'];
        }

        $options = [];
        foreach ($rows as $r) {
            $tid = (int)($r['tercero_id'] ?? 0);
            if ($tid <= 0) {
                continue;
            }
            $ident = $this->fetchPrincipalIdentificacion($tid);
            $usuarios = $this->findUsuariosByTerceroId($tid, $excludeUsuarioId);
            $options[] = [
                'tercero_id' => $tid,
                'tercero' => $this->buildTerceroPayload($tid, $ident),
                'tipodocumento_id' => $ident['tipodocumento_id'] ?? null,
                'usuarios_count' => count($usuarios),
                'label' => $this->formatTerceroLabel($r, $ident),
            ];
        }

        if ($options === []) {
            return ['status' => 'none'];
        }

        if (count($options) === 1) {
            return [
                'status' => 'single',
                'tercero' => $options[0]['tercero'],
                'tercero_id' => $options[0]['tercero_id'],
                'tipodocumento_id' => $options[0]['tipodocumento_id'] ?? null,
            ];
        }

        return [
            'status' => 'multiple',
            'message' => 'Varios terceros comparten este correo. Elija uno o cree un tercero nuevo.',
            'options' => $options,
        ];
    }

    /**
     * Vincula usuario a empresa/sede de sesión (idempotente).
     */
    public function linkUsuarioToSessionScope(int $usuarioId): void
    {
        $eid = $_SESSION['empresa_id'] ?? null;
        $sid = $_SESSION['sede_id'] ?? null;

        if ($eid !== null && $eid !== '' && $this->tableExists('usuario_empresa')) {
            $stmt = $this->pdo->prepare('
                INSERT IGNORE INTO usuario_empresa (usuario_id, empresa_id, estado_id)
                VALUES (?, ?, 1)
            ');
            $stmt->execute([$usuarioId, (int)$eid]);
        }

        if ($sid !== null && $sid !== '' && $this->tableExists('usuario_sede')) {
            $stmt = $this->pdo->prepare('
                INSERT IGNORE INTO usuario_sede (usuario_id, sede_id, estado_id)
                VALUES (?, ?, 1)
            ');
            $stmt->execute([$usuarioId, (int)$sid]);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchIdentificacionRow(int $tipodocumentoId, string $numero): ?array
    {
        $sql = '
            SELECT ti.tercero_id, ti.tipodocumento_id, ti.numero, ti.dv
            FROM terceroidentificacion ti
            WHERE ti.tipodocumento_id = ?
            AND TRIM(ti.numero) = TRIM(?)
        ';
        if (in_array('estado_id', $this->getTableColumnNames('terceroidentificacion'), true)) {
            $sql .= ' AND (ti.estado_id IS NULL OR ti.estado_id = 1)';
        }
        $sql .= ' ORDER BY ti.principal DESC, ti.id ASC LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$tipodocumentoId, $numero]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchTercerosByNumero(string $numero, ?int $excludeUsuarioId): array
    {
        $sql = '
            SELECT ti.tercero_id, ti.tipodocumento_id, ti.numero, ti.dv,
                   t.nombres, t.apellidos, t.email, t.foto_ruta, t.firma_ruta
            FROM terceroidentificacion ti
            INNER JOIN tercero t ON t.id = ti.tercero_id
            WHERE TRIM(ti.numero) = TRIM(?)
        ';
        if (in_array('estado_id', $this->getTableColumnNames('terceroidentificacion'), true)) {
            $sql .= ' AND (ti.estado_id IS NULL OR ti.estado_id = 1)';
        }
        $sql .= ' ORDER BY ti.principal DESC, ti.tercero_id ASC LIMIT 25';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$numero]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        $seen = [];
        foreach ($rows as $r) {
            $tid = (int)($r['tercero_id'] ?? 0);
            if ($tid <= 0 || isset($seen[$tid])) {
                continue;
            }
            $seen[$tid] = true;
            $tipoNombre = null;
            if (!empty($r['tipodocumento_id']) && $this->tableExists('tipodocumento')) {
                $st = $this->pdo->prepare('SELECT nombre FROM tipodocumento WHERE id = ? LIMIT 1');
                $st->execute([(int)$r['tipodocumento_id']]);
                $tipoNombre = $st->fetchColumn() ?: null;
            }
            $out[] = [
                'tercero_id' => $tid,
                'tipodocumento_id' => isset($r['tipodocumento_id']) ? (int)$r['tipodocumento_id'] : null,
                'tercero' => $this->buildTerceroPayload($tid, $r),
                'label' => $this->formatTerceroLabel($r, $r) . ($tipoNombre ? ' · ' . $tipoNombre : ''),
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed>|null $ident
     * @return array<string, mixed>
     */
    private function buildTerceroPayload(int $terceroId, ?array $ident): array
    {
        $tCols = $this->getTableColumnNames('tercero');
        $fields = ['nombres', 'apellidos', 'email', 'foto_ruta', 'firma_ruta'];
        $select = ['t.id'];
        foreach ($fields as $f) {
            if (in_array($f, $tCols, true)) {
                $select[] = 't.`' . str_replace('`', '', $f) . '`';
            }
        }

        $stmt = $this->pdo->prepare('SELECT ' . implode(', ', $select) . ' FROM tercero t WHERE t.id = ? LIMIT 1');
        $stmt->execute([$terceroId]);
        $t = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        if ($ident === null) {
            $ident = $this->fetchPrincipalIdentificacion($terceroId);
        }

        return [
            'tercero_id' => $terceroId,
            'nombres' => $t['nombres'] ?? '',
            'apellidos' => $t['apellidos'] ?? '',
            'email' => $t['email'] ?? '',
            'foto_ruta' => $t['foto_ruta'] ?? '',
            'firma_ruta' => $t['firma_ruta'] ?? '',
            'tipodocumento_id' => $ident['tipodocumento_id'] ?? null,
            'numero_documento' => $ident['numero'] ?? '',
            'documento_dv' => isset($ident['dv']) && $ident['dv'] !== null && $ident['dv'] !== ''
                ? (string)$ident['dv']
                : '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchPrincipalIdentificacion(int $terceroId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT tipodocumento_id, numero, dv
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
     * @return list<array<string, mixed>>
     */
    /**
     * @return list<array<string, mixed>>
     */
    private function findUsuariosByTerceroId(int $terceroId, ?int $excludeUsuarioId, bool $activeOnly = true): array
    {
        if (!$this->tableExists('usuario')) {
            return [];
        }

        $sql = '
            SELECT u.id, u.username, u.tercero_id, u.estado_id, u.sesion_idle_minutos
            FROM usuario u
            WHERE u.tercero_id = ?
        ';
        if ($activeOnly) {
            $sql .= ' AND (u.estado_id IS NULL OR u.estado_id = 1)';
        }
        $params = [$terceroId];
        if ($excludeUsuarioId !== null && $excludeUsuarioId > 0) {
            $sql .= ' AND u.id <> ?';
            $params[] = $excludeUsuarioId;
        }
        $sql .= ' ORDER BY u.id ASC LIMIT 10';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array<string, mixed> $t
     * @param array<string, mixed> $ident
     */
    private function formatTerceroLabel(array $t, array $ident): string
    {
        $nom = trim((string)($t['nombres'] ?? '') . ' ' . (string)($t['apellidos'] ?? ''));
        $doc = trim((string)($ident['numero'] ?? ''));
        $parts = array_filter([$nom !== '' ? $nom : null, $doc !== '' ? 'Doc. ' . $doc : null]);

        return $parts !== [] ? implode(' — ', $parts) : ('Tercero #' . (int)($t['tercero_id'] ?? $ident['tercero_id'] ?? 0));
    }

    private function tableExists(string $table): bool
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
    private function getTableColumnNames(string $table): array
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
