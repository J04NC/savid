<?php

class CrudService
{

    private $pdo;

    public function __construct()
    {
        $database = new Database();
        $this->pdo = $database->connect();
    }

    public function getTableData($tabla)
    {
        if ($tabla === 'usuario') {
            return $this->getTableDataUsuario();
        }

        $columns = $this->getColumns($tabla);

        $fields = array_column($columns, 'Field');

        $sql = "SELECT * FROM $tabla WHERE 1=1";
        $params = [];

        // 🔥 FILTRO EMPRESA
        if (in_array('empresa_id', $fields) && isset($_SESSION['empresa_id'])) {
            $sql .= " AND empresa_id = ?";
            $params[] = $_SESSION['empresa_id'];
        }

        // 🔥 FILTRO SEDE
        if (in_array('sede_id', $fields) && isset($_SESSION['sede_id'])) {
            $sql .= " AND sede_id = ?";
            $params[] = $_SESSION['sede_id'];
        }

        $sql .= " ORDER BY id DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Ámbito por usuario_empresa / usuario_sede (columnas empresa_id/sede_id eliminadas de usuario).
     */
    private function getTableDataUsuario(): array
    {
        if ((int)($_SESSION['rol_id'] ?? 0) === 1 || !empty($_SESSION['es_super_admin'])) {
            $stmt = $this->pdo->query('SELECT * FROM usuario ORDER BY id DESC');

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $sql = 'SELECT DISTINCT u.* FROM usuario u WHERE 1=1';
        $params = [];

        $empresaSession = $_SESSION['empresa_id'] ?? null;
        $sedeSession = $_SESSION['sede_id'] ?? null;
        $hasEmpresaSession = $empresaSession !== null && $empresaSession !== '';
        $hasSedeSession = $sedeSession !== null && $sedeSession !== '';

        if ($hasEmpresaSession) {
            $sql .= ' AND EXISTS (
                SELECT 1 FROM usuario_empresa ue
                WHERE ue.usuario_id = u.id AND ue.empresa_id = ? AND ue.estado_id = 1
            )';
            $params[] = (int)$empresaSession;
        } else {
            $uid = (int)($_SESSION['user_id'] ?? 0);
            $sql .= ' AND EXISTS (
                SELECT 1 FROM usuario_empresa ue_target
                INNER JOIN usuario_empresa ue_self
                    ON ue_self.empresa_id = ue_target.empresa_id AND ue_self.estado_id = 1
                WHERE ue_target.usuario_id = u.id AND ue_target.estado_id = 1
                AND ue_self.usuario_id = ?
            )';
            $params[] = $uid;
        }

        if ($hasSedeSession) {
            $sql .= ' AND EXISTS (
                SELECT 1 FROM usuario_sede us
                WHERE us.usuario_id = u.id AND us.sede_id = ? AND us.estado_id = 1
            )';
            $params[] = (int)$sedeSession;
        }

        $sql .= ' ORDER BY u.id DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getColumns($tabla)
    {
        $sql = "SELECT 
                COLUMN_NAME as Field,
                DATA_TYPE as Type,
                IS_NULLABLE,
                COLUMN_COMMENT
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                ORDER BY ORDINAL_POSITION";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$tabla]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /*
    =========================
    ACCIONES DEL ITEM
    =========================
    */

    public function getAcciones($itemId)
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                ia.id as item_accion_id,
                a.nombre,
                a.icono,
                a.codigo,
                a.accion_codigo
            FROM item_accion ia
            INNER JOIN accion a ON (ia.accion_id=a.id)
            WHERE ia.item_id = ?
            AND ia.estado_id = 1
            ORDER BY a.orden
        ");

        $stmt->execute([$itemId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /*
    =========================
    VALIDAR PERMISO
    =========================
    */

    public function hasPermission($usuarioId, $itemAccionId)
    {
        $stmt = $this->pdo->prepare("
            SELECT permitido
            FROM permiso
            WHERE usuario_id = ?
            AND item_accion_id = ?
            LIMIT 1
        ");

        $stmt->execute([$usuarioId, $itemAccionId]);

        return $stmt->fetchColumn() == 1;
    }

    /*
    =========================
    SAVE
    =========================
    */

    public function save($tabla, $data)
    {
        $columns = $this->getColumns($tabla);

        $fields = [];
        $values = [];
        $placeholders = [];

        $errors = [];

        $id = $data['id'] ?? null;

        $columnNames = array_column($columns, 'Field');

        /*
        =========================
        USUARIO: username + tercero_id (vincular empresa si mismo tercero)
        =========================
        */

        if (!$id && $tabla === 'usuario') {
            if ($this->usuarioDuplicateSameTerceroLinkOrThrow($data, $columnNames)) {
                return true;
            }
        }

        /*
        =========================
        AUTO EMPRESA / SEDE
        =========================
        */


        if (in_array('empresa_id', $columnNames) && empty($data['empresa_id'])) {
            $data['empresa_id'] = $_SESSION['empresa_id'] ?? null;
        }

        if (in_array('sede_id', $columnNames) && empty($data['sede_id'])) {
            $data['sede_id'] = $_SESSION['sede_id'] ?? null;
        }

        /*
        =========================
        ARMAR CAMPOS
        =========================
        */

        foreach ($columns as $col) {

            $name = $col['Field'];
            $nullable = $col['IS_NULLABLE'];

            if (in_array($name, ['id', 'created_at', 'updated_at', 'deleted_at'])) {
                continue;
            }

            if (array_key_exists($name, $data)) {

                $value = trim((string)$data[$name]);
                $isPassword = $this->columnCommentIsPasswordType($col['COLUMN_COMMENT'] ?? '');

                if ($isPassword) {

                    if ($id) {

                        if ($value === '') {
                            continue;
                        }

                        $value = password_hash($value, PASSWORD_DEFAULT);

                    } else {

                        if ($value === '') {

                            if ($nullable == 'NO') {
                                $errors[$name] = "Este campo es obligatorio";
                            }

                            $value = null;

                        } else {

                            $value = password_hash($value, PASSWORD_DEFAULT);

                        }
                    }

                } else {

                    if ($nullable == 'NO' && $value === '') {
                        $errors[$name] = "Este campo es obligatorio";
                    }

                    if ($value === '') {
                        $value = null;
                    }

                }

                $fields[] = $name;
                $values[] = $value;
                $placeholders[] = "$name=?";
            }
        }

        if (!empty($errors)) {
            throw new Exception(json_encode($errors));
        }

        /*
        =========================
        INSERT / UPDATE
        =========================
        */

        if ($id) {

            $sql = "UPDATE $tabla SET " . implode(',', $placeholders) . " WHERE id=?";
            $values[] = $id;

        } else {

            $sql = "INSERT INTO $tabla (" . implode(',', $fields) . ")
                    VALUES (" . implode(',', array_fill(0, count($fields), '?')) . ")";
        }

        $stmt = $this->pdo->prepare($sql);
        $ok = $stmt->execute($values);

        /*
        =========================
        SI CREA EMPRESA:
        CREAR SEDE PRINCIPAL AUTOMÁTICA
        =========================
        */

        if ($ok && !$id && $tabla === 'empresa') {

            $empresaId = $this->pdo->lastInsertId();

            $stmtSede = $this->pdo->prepare("
                INSERT INTO sede (
                    empresa_id,
                    nombre,
                    direccion,
                    estado_id
                ) VALUES (?, ?, ?, ?)
            ");

            $stmtSede->execute([
                $empresaId,
                'Sede Principal',
                $data['direccion'] ?? null,
                1
            ]);
        }

        if ($ok && !$id && $tabla === 'usuario') {
            $newId = (int)$this->pdo->lastInsertId();
            if ($newId > 0) {
                $this->linkNewUsuarioToSessionScope($newId);
            }
        }

        return $ok;
    }

    public function getRelations($tabla)
    {
        $stmt = $this->pdo->query("SHOW COLUMNS FROM $tabla");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $relations = [];

        foreach ($columns as $col) {

            $field = $col['Field'];

            if (str_ends_with($field, '_id')) {
                $relations[$field] = str_replace('_id', '', $field);
            }
        }

        return $relations;
    }

    /**
     * Lista opcional de filtro para opciones del SELECT de un *_id.
     * Solo se aplica si en COLUMN_COMMENT (partes separadas por |) existe relfilter:...
     * Ej.: type:text|relfilter:1,2,3  →  solo ids 1,2,3
     * Sin relfilter:, el comentario no filtra (antes se interpretaba todo el texto y el combo quedaba vacío).
     */
    private function extractRelFilterListFromColumnComment(?string $comment): ?string
    {
        $comment = trim((string)$comment);
        if ($comment === '') {
            return null;
        }

        foreach (explode('|', $comment) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $lower = strtolower($part);
            if (str_starts_with($lower, 'relfilter:')) {
                return trim(substr($part, strlen('relfilter:')));
            }
        }

        return null;
    }

    /**
     * Identificador de tabla seguro para interpolar en SQL (solo alfanumérico y guión bajo).
     */
    private function sqlIdentifierTable(string $tabla): string
    {
        return preg_replace('/[^A-Za-z0-9_]/', '', $tabla) ?: 'invalid_table';
    }

    public function getRelationData($tabla, $column = null, $comment = null)
    {
        $tablaSql = '`' . $this->sqlIdentifierTable($tabla) . '`';

        $stmt = $this->pdo->query("SHOW COLUMNS FROM $tablaSql");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $displayColumn = null;

        $preferred = ['nombre', 'razon_social', 'descripcion', 'titulo', 'username', 'email'];

        foreach ($preferred as $pref) {
            foreach ($columns as $col) {
                if ($col['Field'] === $pref) {
                    $displayColumn = $pref;
                    break 2;
                }
            }
        }

        if (!$displayColumn) {
            foreach ($columns as $col) {
                if ($col['Field'] !== 'id') {
                    $displayColumn = $col['Field'];
                    break;
                }
            }
        }

        if (!$displayColumn) {
            return [];
        }

        $isTercero = ($tabla === 'tercero');
        $hasNombres = false;
        $hasApellidos = false;
        foreach ($columns as $col) {
            if ($col['Field'] === 'nombres') {
                $hasNombres = true;
            }
            if ($col['Field'] === 'apellidos') {
                $hasApellidos = true;
            }
        }

        if ($isTercero && $hasNombres && $hasApellidos) {
            $displayExpr = "COALESCE(NULLIF(TRIM(CONCAT_WS(' ', NULLIF(TRIM(COALESCE(`nombres`,'')), ''), NULLIF(TRIM(COALESCE(`apellidos`,'')), ''))), ''), NULLIF(TRIM(COALESCE(`razon_social`,'')), ''), CONCAT('Tercero #', `id`))";
        } else {
            $displayExpr = '`' . str_replace('`', '', $displayColumn) . '`';
        }

        $sql = "SELECT `id`, ($displayExpr) AS nombre FROM $tablaSql";
        $params = [];

        $filterList = $this->extractRelFilterListFromColumnComment($comment);

        if ($filterList !== null && $filterList !== '') {

            $items = array_map('trim', explode(',', $filterList));

            $includeIds = [];
            $excludeIds = [];
            $includeNames = [];
            $excludeNames = [];

            foreach ($items as $item) {

                if ($item === '') {
                    continue;
                }

                $isExclude = str_starts_with($item, '!');

                if ($isExclude) {
                    $item = substr($item, 1);
                }

                if (is_numeric($item)) {

                    if ($isExclude) {
                        $excludeIds[] = $item;
                    } else {
                        $includeIds[] = $item;
                    }

                } else {

                    if ($isExclude) {
                        $excludeNames[] = $item;
                    } else {
                        $includeNames[] = $item;
                    }
                }
            }

            $conditions = [];

            if (!empty($includeIds)) {
                $conditions[] = "`id` IN (" . implode(',', array_fill(0, count($includeIds), '?')) . ")";
                $params = array_merge($params, $includeIds);
            }

            if (!empty($includeNames)) {
                $conditions[] = "($displayExpr) IN (" . implode(',', array_fill(0, count($includeNames), '?')) . ")";
                $params = array_merge($params, $includeNames);
            }

            if (!empty($excludeIds)) {
                $conditions[] = "`id` NOT IN (" . implode(',', array_fill(0, count($excludeIds), '?')) . ")";
                $params = array_merge($params, $excludeIds);
            }

            if (!empty($excludeNames)) {
                $conditions[] = "($displayExpr) NOT IN (" . implode(',', array_fill(0, count($excludeNames), '?')) . ")";
                $params = array_merge($params, $excludeNames);
            }

            if (!empty($conditions)) {
                $sql .= " WHERE " . implode(' AND ', $conditions);
            }
        }

        $sql .= " ORDER BY nombre";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Columnas con comentario type:password se tratan con password_hash al guardar.
     * En UPDATE, valor vacio no actualiza el hash (ver save()).
     */
    private function columnCommentIsPasswordType($comment)
    {
        $comment = (string) $comment;

        foreach (explode('|', $comment) as $part) {

            $part = trim($part);

            if (str_starts_with($part, 'type:')) {
                return str_replace('type:', '', $part) === 'password';
            }
        }

        return false;
    }

    /**
     * Tras crear un usuario, enlazar empresa/sede del contexto actual en las tablas puente.
     */
    /**
     * Creación de usuario: si el username ya existe, solo se permite cuando el
     * tercero_id coincide; entonces se enlaza empresa/sede de sesión sin duplicar fila.
     * Si el tercero no coincide → error en campo username.
     *
     * @return bool true si ya se enlazó y no debe ejecutarse INSERT
     */
    private function usuarioDuplicateSameTerceroLinkOrThrow(array $data, array $columnNames): bool
    {
        if (!in_array('tercero_id', $columnNames, true)) {
            return false;
        }

        $username = trim((string)($data['username'] ?? ''));
        if ($username === '') {
            return false;
        }

        $terceroNew = isset($data['tercero_id']) && $data['tercero_id'] !== ''
            ? (int)$data['tercero_id']
            : null;

        $stmt = $this->pdo->prepare('
            SELECT id, tercero_id
            FROM usuario
            WHERE LOWER(TRIM(username)) = LOWER(TRIM(?))
            AND estado_id = 1
            LIMIT 1
        ');
        $stmt->execute([$username]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            return false;
        }

        $terceroOld = isset($existing['tercero_id']) && $existing['tercero_id'] !== '' && $existing['tercero_id'] !== null
            ? (int)$existing['tercero_id']
            : null;

        if ($terceroOld !== $terceroNew) {
            throw new Exception(json_encode([
                'username' => 'Este nombre de usuario no está disponible porque ya existe para otro tercero.',
            ], JSON_UNESCAPED_UNICODE));
        }

        $this->linkNewUsuarioToSessionScope((int)$existing['id']);

        return true;
    }

    private function linkNewUsuarioToSessionScope(int $usuarioId): void
    {
        $eid = $_SESSION['empresa_id'] ?? null;
        $sid = $_SESSION['sede_id'] ?? null;

        if ($eid !== null && $eid !== '') {
            $stmt = $this->pdo->prepare('
                INSERT IGNORE INTO usuario_empresa (usuario_id, empresa_id, estado_id)
                VALUES (?, ?, 1)
            ');
            $stmt->execute([$usuarioId, (int)$eid]);
        }

        if ($sid !== null && $sid !== '') {
            $stmt = $this->pdo->prepare('
                INSERT IGNORE INTO usuario_sede (usuario_id, sede_id, estado_id)
                VALUES (?, ?, 1)
            ');
            $stmt->execute([$usuarioId, (int)$sid]);
        }
    }

}