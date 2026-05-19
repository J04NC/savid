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
        $fromSql = $this->buildUsuarioListFromSql();
        $scope = new UsuarioFormValidationService($this->pdo);

        $sql = 'SELECT DISTINCT u.*' . $fromSql['selectSuffix'] . ' FROM usuario u' . $fromSql['joins'] . ' WHERE 1=1';
        $params = [];

        $scope->appendUsuarioListScopeSql('u', $sql, $params);

        $sql .= ' ORDER BY u.id DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $this->applyUsuarioListRowRemap($stmt->fetchAll(PDO::FETCH_ASSOC), $fromSql['rowRemap'] ?? []);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, string> $rowRemap temp alias => campo destino (evita colisión con columnas de u.*)
     * @return array<int, array<string, mixed>>
     */
    private function applyUsuarioListRowRemap(array $rows, array $rowRemap): array
    {
        if ($rowRemap === []) {
            return $rows;
        }

        foreach ($rows as &$row) {
            foreach ($rowRemap as $tmp => $final) {
                if (!array_key_exists($tmp, $row)) {
                    continue;
                }
                $row[$final] = $row[$tmp];
                unset($row[$tmp]);
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * LEFT JOIN tercero + identificación principal (alias alineados al formulario usuario).
     *
     * @return array{selectSuffix: string, joins: string, rowRemap: array<string, string>}
     */
    private function buildUsuarioListFromSql(): array
    {
        if (!$this->tableExists('tercero')) {
            return ['selectSuffix' => '', 'joins' => '', 'rowRemap' => []];
        }

        $uCols = $this->getTableColumnNames('usuario');
        $tCols = $this->getTableColumnNames('tercero');
        $select = [];
        $rowRemap = [];
        if (in_array('terceroidentificacion_id', $uCols, true) && $this->tableExists('terceroidentificacion')) {
            $joins = ' LEFT JOIN `terceroidentificacion` ti ON ti.id = u.terceroidentificacion_id ';
            $joins .= ' LEFT JOIN `tercero` t ON t.id = ti.tercero_id ';
        } else {
            $joins = ' LEFT JOIN `tercero` t ON t.id = u.tercero_id ';
        }

        $pushExpr = function (string $expr, string $logicalAlias) use (&$select, &$rowRemap, $uCols): void {
            if (in_array($logicalAlias, $uCols, true)) {
                $tmp = '__u_li_' . $logicalAlias;
                $select[] = $expr . ' AS `' . str_replace('`', '', $tmp) . '`';
                $rowRemap[$tmp] = $logicalAlias;
            } else {
                $select[] = $expr . ' AS `' . str_replace('`', '', $logicalAlias) . '`';
            }
        };

        if (in_array('nombres', $tCols, true)) {
            $pushExpr('t.nombres', 'nombres');
        }
        if (in_array('apellidos', $tCols, true)) {
            $pushExpr('t.apellidos', 'apellidos');
        }
        if (in_array('email', $tCols, true)) {
            $pushExpr('t.email', 'email');
        }
        if (in_array('foto_ruta', $tCols, true)) {
            $pushExpr('t.foto_ruta', 'foto_ruta');
        }
        if (in_array('firma_ruta', $tCols, true)) {
            $pushExpr('t.firma_ruta', 'firma_ruta');
        }

        if ($this->tableExists('terceroidentificacion')) {
            $tiCols = $this->getTableColumnNames('terceroidentificacion');
            if (!in_array('terceroidentificacion_id', $uCols, true)) {
                $joins .= ' LEFT JOIN `terceroidentificacion` ti ON ti.tercero_id = t.id AND ti.principal = 1 ';
            }
            if (in_array('tipodocumento_id', $tiCols, true)) {
                $pushExpr('ti.tipodocumento_id', 'tipodocumento_id');
            }
            if (in_array('numero', $tiCols, true)) {
                $pushExpr('ti.numero', 'numero_documento');
            }
            if (in_array('dv', $tiCols, true)) {
                $pushExpr('ti.dv', 'documento_dv');
            }
            if (in_array('terceroidentificacion_id', $uCols, true)) {
                $pushExpr('ti.tercero_id', 'tercero_id');
            }
        }

        $suffix = $select === [] ? '' : (', ' . implode(', ', $select));

        return ['selectSuffix' => $suffix, 'joins' => $joins, 'rowRemap' => $rowRemap];
    }

    /**
     * Definiciones de columnas solo-UI para el CRUD usuario (persona vía tercero / terceroidentificacion).
     * Se fusionan en ModuleService si la tabla `usuario` no las tiene ya.
     *
     * @return list<array{Field: string, Type: string, IS_NULLABLE: string, COLUMN_COMMENT: string}>
     */
    public function getUsuarioPersonaSyntheticColumns(): array
    {
        return [
            [
                'Field' => 'tipodocumento_id',
                'Type' => 'smallint',
                'IS_NULLABLE' => 'YES',
                'COLUMN_COMMENT' => 'type:text|order:10|relmode:select|placeholder:Tipo de documento|title:Catálogo de tipos de identificación',
            ],
            [
                'Field' => 'numero_documento',
                'Type' => 'varchar',
                'IS_NULLABLE' => 'YES',
                'COLUMN_COMMENT' => 'type:text|order:20|placeholder:Número de documento|title:Número sin puntos ni DV; para NIT el DV se calcula solo',
            ],
            [
                'Field' => 'documento_dv',
                'Type' => 'varchar',
                'IS_NULLABLE' => 'YES',
                'COLUMN_COMMENT' => 'type:text|order:25|label:DV|placeholder:DV|show:form,table|title:Dígito de verificación (NIT Colombia). Se calcula automáticamente cuando el tipo es NIT',
            ],
            [
                'Field' => 'nombres',
                'Type' => 'varchar',
                'IS_NULLABLE' => 'YES',
                'COLUMN_COMMENT' => 'type:text|order:40|placeholder:Nombres|title:Datos en tercero',
            ],
            [
                'Field' => 'apellidos',
                'Type' => 'varchar',
                'IS_NULLABLE' => 'YES',
                'COLUMN_COMMENT' => 'type:text|order:50|placeholder:Apellidos|title:Datos en tercero',
            ],
            [
                'Field' => 'password_confirm',
                'Type' => 'varchar',
                'IS_NULLABLE' => 'YES',
                'COLUMN_COMMENT' => 'type:password|order:80|show:form|placeholder:Confirmar contraseña|title:Debe coincidir con el campo contraseña',
            ],
            [
                'Field' => 'email',
                'Type' => 'varchar',
                'IS_NULLABLE' => 'YES',
                'COLUMN_COMMENT' => 'type:text|order:100|placeholder:correo@ejemplo.com|title:Correo de contacto (tercero), distinto del usuario de login',
            ],
            [
                'Field' => 'foto_ruta',
                'Type' => 'upload',
                'IS_NULLABLE' => 'YES',
                'COLUMN_COMMENT' => 'type:upload|subtype:image|order:1|span:full|label:Foto|show:form|title:Puede subir archivo o tomar foto con la cámara (PNG/JPG/WebP, máx. 3 MB)',
            ],
            [
                'Field' => 'firma_ruta',
                'Type' => 'upload',
                'IS_NULLABLE' => 'YES',
                'COLUMN_COMMENT' => 'type:upload|subtype:signature|order:2|span:full|label:Firma|show:form|title:Imagen de firma o escaneo (PNG/JPG/WebP, máx. 3 MB)',
            ],
        ];
    }

    /**
     * Fuerza widget de subida en foto/firma del ítem usuario (p. ej. si la columna existe en BD con otro comentario).
     *
     * @param list<array<string, mixed>> $columns
     * @return list<array<string, mixed>>
     */
    public function applyUsuarioFotoFirmaUploadPresentation(array $columns): array
    {
        $defs = [
            'foto_ruta' => 'type:upload|subtype:image|order:1|span:full|label:Foto|show:form|title:Puede subir archivo o tomar foto con la cámara (PNG/JPG/WebP, máx. 3 MB)',
            'firma_ruta' => 'type:upload|subtype:signature|order:2|span:full|label:Firma|show:form|title:Imagen de firma o escaneo (PNG/JPG/WebP, máx. 3 MB)',
        ];

        foreach ($columns as &$col) {
            $f = $col['Field'] ?? '';

            if (isset($defs[$f])) {
                $col['Type'] = 'upload';
                $col['COLUMN_COMMENT'] = $defs[$f];
            }
        }
        unset($col);

        return $columns;
    }

    /**
     * Ajusta orden, títulos y visibilidad del formulario usuario (convive con comentarios en BD).
     *
     * @param list<array<string, mixed>> $columns
     * @return list<array<string, mixed>>
     */
    public function applyUsuarioCrudColumnPresentation(array $columns): array
    {
        $inject = [
            'tipodocumento_id' => 'label:Tipo de documento',
            'numero_documento' => 'label:Número de documento',
            'documento_dv' => 'label:DV|order:25|title:Solo aplica para NIT; se calcula automáticamente',
            'nombres' => 'label:Nombres',
            'apellidos' => 'label:Apellidos',
            'email' => 'label:Email',
            'username' => 'order:60|title:Código único para iniciar sesión.|placeholder:Usuario',
            'password' => 'order:70|show:form|title:Al editar, deje vacío para no cambiar la contraseña.|placeholder:Contraseña',
            'sesion_idle_minutos' => 'order:90|title:Minutos de inactividad sin usar el sistema antes de cerrar la sesión automáticamente. Vacío = sin cierre por inactividad en el navegador. Ejemplo: 30|placeholder:Ej. 30',
            'estado_id' => 'order:130|title:Estado de la cuenta de acceso (activo/inactivo).',
            'tercero_id' => 'show:none|order:9999',
            'terceroidentificacion_id' => 'show:none|order:9999',
            'created_at' => 'show:none|order:9999',
            'updated_at' => 'show:none|order:9999',
        ];

        foreach ($columns as &$col) {
            $f = $col['Field'] ?? '';

            if (isset($inject[$f])) {
                $base = trim((string)($col['COLUMN_COMMENT'] ?? ''));
                $col['COLUMN_COMMENT'] = trim($base . '|' . $inject[$f], '|');
            }
        }
        unset($col);

        return $columns;
    }

    /**
     * Columnas visibles en la grilla del CRUD usuario (resto solo formulario).
     *
     * @param list<array<string, mixed>> $columns
     * @return list<array<string, mixed>>
     */
    public function applyUsuarioCrudTableVisibility(array $columns): array
    {
        $tableFields = [
            'tipodocumento_id' => 'label:Tipo de documento',
            'numero_documento' => 'label:Número de documento',
            'documento_dv' => 'label:DV|order:25',
            'nombres' => 'label:Nombres',
            'apellidos' => 'label:Apellidos',
            'username' => 'label:Usuario',
            'email' => 'label:Email',
            'sesion_idle_minutos' => 'label:Tiempo sesión',
            'estado_id' => 'label:Estado',
        ];

        foreach ($columns as &$col) {
            $f = $col['Field'] ?? '';
            $base = trim((string)($col['COLUMN_COMMENT'] ?? ''));

            if ($f === 'id') {
                continue;
            }

            if (isset($tableFields[$f])) {
                $extra = $tableFields[$f];
                $col['COLUMN_COMMENT'] = $this->mergeColumnCommentShow(
                    trim($base . '|' . $extra, '|'),
                    'form,table'
                );
            } else {
                if (in_array($f, ['tercero_id', 'terceroidentificacion_id', 'created_at', 'updated_at', 'deleted_at'], true)) {
                    $col['COLUMN_COMMENT'] = $this->mergeColumnCommentShow($base, 'none');
                } else {
                    $col['COLUMN_COMMENT'] = $this->mergeColumnCommentShow($base, 'form');
                }
            }
        }
        unset($col);

        return $columns;
    }

    private function mergeColumnCommentShow(string $comment, string $show): string
    {
        $parts = [];
        foreach (explode('|', $comment) as $part) {
            $part = trim($part);
            if ($part === '' || str_starts_with(strtolower($part), 'show:')) {
                continue;
            }
            $parts[] = $part;
        }
        $parts[] = 'show:' . $show;

        return implode('|', $parts);
    }

    /**
     * Metadatos de tipodocumento para el formulario usuario (DV NIT, visibilidad DV).
     *
     * @return array<int, array{codigo: string, nombre: string}>
     */
    public function getTipodocumentoMetaById(): array
    {
        if (!$this->tableExists('tipodocumento')) {
            return [];
        }

        $stmt = $this->pdo->query('
            SELECT id, UPPER(TRIM(codigo)) AS codigo, nombre
            FROM tipodocumento
            WHERE estado_id = 1
        ');

        $out = [];

        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $id = (int)($r['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $out[$id] = [
                'codigo' => (string)($r['codigo'] ?? ''),
                'nombre' => (string)($r['nombre'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * DV NIT Colombia (algoritmo DIAN con factores 3,7,13,…).
     */
    public static function colombianNitDvFromNumber(string $rawNumero): int
    {
        $nit = preg_replace('/\D/', '', $rawNumero);

        if ($nit === '') {
            return 0;
        }

        $factors = [3, 7, 13, 17, 19, 23, 29, 37, 41, 43, 47, 53, 59, 67, 71];
        $sum = 0;
        $len = strlen($nit);

        for ($i = 0; $i < $len; $i++) {
            $sum += (int)$nit[$len - 1 - $i] * $factors[$i % count($factors)];
        }

        $r = $sum % 11;

        return $r > 1 ? 11 - $r : $r;
    }

    private function tableExists(string $table): bool
    {
        $t = preg_replace('/[^A-Za-z0-9_]/', '', $table);

        $stmt = $this->pdo->prepare('
            SELECT 1 FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
            LIMIT 1
        ');
        $stmt->execute([$t]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * @return list<string>
     */
    private function getTableColumnNames(string $table): array
    {
        $t = $this->sqlIdentifierTable($table);
        $stmt = $this->pdo->prepare('
            SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
            ORDER BY ORDINAL_POSITION
        ');
        $stmt->execute([$t]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
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

        $usuarioTx = ($tabla === 'usuario');

        $usuarioLinkOnlyExisting = false;

        if ($usuarioTx) {
            $this->validateUsuarioPasswordConfirm($data, $id);
            $validator = new UsuarioFormValidationService($this->pdo);
            if ($id) {
                /*
                 * Si el usuario destino existe pero está fuera del ámbito del operador
                 * (no comparte empresa con la sesión y no es super admin) NO bloqueamos
                 * el guardado. En su lugar entramos en modo "link-only": no se actualizan
                 * sus datos, solo se crea la relación con la empresa/sede en sesión.
                 */
                if (!$validator->isSuperAdminViewer()
                    && !$validator->isSuperAdminUsuario((int)$id)
                    && !$validator->usuarioVisibleInSessionScope((int)$id)
                ) {
                    $usuarioLinkOnlyExisting = true;
                } else {
                    $validator->assertUsuarioGestionableEnSesion((int)$id);
                }
            }
            if (!$usuarioLinkOnlyExisting) {
                $validator->validateBeforeSave($data, $id, $columnNames);
            }
            $this->pdo->beginTransaction();
        }

        try {

            if ($usuarioLinkOnlyExisting && $id) {
                $this->linkNewUsuarioToSessionScope((int)$id);
                if ($usuarioTx) {
                    $this->pdo->commit();
                }
                return true;
            }

            if ($usuarioTx && $this->usuarioRequiresTerceroPersonaSave($columnNames) && $this->tableExists('tercero')) {
                $this->ensureTerceroAndPersonaForUsuarioSave($data, $id, $columnNames);
                $validator->validateAfterTerceroResolved($data, $id, $columnNames);
            }

            /*
            =========================
            USUARIO: username + vínculo persona (vincular empresa si misma identificación)
            =========================
            */

            if (!$id && $tabla === 'usuario') {
                if ($this->usuarioDuplicateSameTerceroLinkOrThrow($data, $columnNames)) {
                    if ($usuarioTx) {
                        $this->pdo->commit();
                    }

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

                        if ($this->columnCommentIsUppercaseOnly($col['COLUMN_COMMENT'] ?? '') && $value !== '') {
                            $value = function_exists('mb_strtoupper')
                                ? mb_strtoupper($value, 'UTF-8')
                                : strtoupper($value);
                        }

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

            if ($ok && $tabla === 'usuario') {
                $uidScope = $id ? (int)$id : (int)$this->pdo->lastInsertId();
                if ($uidScope > 0) {
                    $this->linkNewUsuarioToSessionScope($uidScope);
                }
            }

            if ($usuarioTx) {
                if ($ok) {
                    $this->pdo->commit();
                } elseif ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
            }

            return $ok;

        } catch (Throwable $e) {
            if ($usuarioTx && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function validateUsuarioPasswordConfirm(array $data, $id): void
    {
        $pwd = trim((string)($data['password'] ?? ''));
        $pwd2 = trim((string)($data['password_confirm'] ?? ''));

        if ($pwd === '' && $id) {
            return;
        }

        if ($pwd !== $pwd2) {
            throw new Exception(json_encode([
                'password_confirm' => 'No coincide con la contraseña.',
            ], JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * @param list<string> $columnNames
     */
    private function usuarioRequiresTerceroPersonaSave(array $columnNames): bool
    {
        return in_array('terceroidentificacion_id', $columnNames, true)
            || in_array('tercero_id', $columnNames, true);
    }

    /**
     * Crea o actualiza `tercero` y la identificación en `terceroidentificacion`;
     * asigna `terceroidentificacion_id` (o `tercero_id` legado) en $data.
     *
     * @param array<string, mixed> $data
     * @param list<string> $columnNames
     */
    private function ensureTerceroAndPersonaForUsuarioSave(array &$data, $id, array $columnNames): void
    {
        $nombres = trim((string)($data['nombres'] ?? ''));
        $apellidos = trim((string)($data['apellidos'] ?? ''));
        $email = trim((string)($data['email'] ?? ''));
        $foto = trim((string)($data['foto_ruta'] ?? ''));
        $firma = trim((string)($data['firma_ruta'] ?? ''));
        $tipoDoc = isset($data['tipodocumento_id']) && $data['tipodocumento_id'] !== ''
            ? (int)$data['tipodocumento_id']
            : null;
        $numero = trim((string)($data['numero_documento'] ?? ''));
        $dvRaw = trim((string)($data['documento_dv'] ?? ''));
        $dv = $dvRaw === '' ? null : (int)$dvRaw;

        $tipoCodigo = null;
        $tipoNombre = null;

        if ($tipoDoc !== null && $tipoDoc > 0 && $this->tableExists('tipodocumento')) {
            $stc = $this->pdo->prepare('SELECT UPPER(TRIM(codigo)), nombre FROM tipodocumento WHERE id = ? LIMIT 1');
            $stc->execute([$tipoDoc]);
            $rowTipo = $stc->fetch(PDO::FETCH_NUM);
            if ($rowTipo) {
                $tipoCodigo = $rowTipo[0] !== null && $rowTipo[0] !== '' ? (string)$rowTipo[0] : null;
                $tipoNombre = isset($rowTipo[1]) ? (string)$rowTipo[1] : null;
            }
        }

        $esNit = ($tipoCodigo === 'NIT')
            || ($tipoNombre !== null && stripos($tipoNombre, 'NIT') !== false);

        if ($esNit && $numero !== '') {
            $dvCalc = self::colombianNitDvFromNumber($numero);
            $data['documento_dv'] = (string)$dvCalc;
            $dv = $dvCalc;
        } elseif ($tipoDoc !== null && $tipoDoc > 0 && !$esNit) {
            $data['documento_dv'] = '';
            $dv = null;
        }

        $personaLink = new UsuarioPersonaLinkService($this->pdo);
        $tid = 0;
        if (isset($data['tercero_id']) && $data['tercero_id'] !== '' && $data['tercero_id'] !== null) {
            $tid = (int)$data['tercero_id'];
        }
        if ($tid <= 0) {
            $resolved = $personaLink->resolveTerceroIdFromData($data, $columnNames);
            $tid = $resolved ?? 0;
        }

        $tCols = $this->getTableColumnNames('tercero');

        if ($tid <= 0) {
            $nombreFallback = trim((string)($data['username'] ?? ''));
            if ($nombreFallback === '') {
                $nombreFallback = 'Usuario';
            }
            if ($nombres === '') {
                $nombres = $nombreFallback;
            }

            $insertCols = ['tipopersona_id', 'estado_id'];
            $insertVals = [1, 1];

            if (in_array('nombres', $tCols, true)) {
                $insertCols[] = 'nombres';
                $insertVals[] = $nombres !== '' ? $nombres : null;
            }
            if (in_array('apellidos', $tCols, true)) {
                $insertCols[] = 'apellidos';
                $insertVals[] = $apellidos !== '' ? $apellidos : null;
            }
            if (in_array('email', $tCols, true)) {
                $insertCols[] = 'email';
                $insertVals[] = $email !== '' ? $email : null;
            }
            if (in_array('foto_ruta', $tCols, true)) {
                $insertCols[] = 'foto_ruta';
                $insertVals[] = $foto !== '' ? $foto : null;
            }
            if (in_array('firma_ruta', $tCols, true)) {
                $insertCols[] = 'firma_ruta';
                $insertVals[] = $firma !== '' ? $firma : null;
            }

            $quoted = array_map(fn ($c) => '`' . str_replace('`', '', $c) . '`', $insertCols);
            $sqlIns = 'INSERT INTO `tercero` (' . implode(',', $quoted) . ') VALUES (' . implode(',', array_fill(0, count($insertVals), '?')) . ')';
            $st = $this->pdo->prepare($sqlIns);
            $st->execute($insertVals);
            $tid = (int)$this->pdo->lastInsertId();
            $data['tercero_id'] = $tid;
        } else {
            $sets = [];
            $updParams = [];

            if (in_array('nombres', $tCols, true)) {
                $sets[] = '`nombres`=?';
                $updParams[] = $nombres !== '' ? $nombres : null;
            }
            if (in_array('apellidos', $tCols, true)) {
                $sets[] = '`apellidos`=?';
                $updParams[] = $apellidos !== '' ? $apellidos : null;
            }
            if (in_array('email', $tCols, true)) {
                $overwriteOk = trim((string)($data['usuario_email_overwrite_ok'] ?? '')) === '1';
                $currentEmail = null;
                if ($this->tableExists('tercero')) {
                    $stEm = $this->pdo->prepare('SELECT email FROM tercero WHERE id = ? LIMIT 1');
                    $stEm->execute([$tid]);
                    $currentEmail = $stEm->fetchColumn();
                    $currentEmail = $currentEmail !== false && $currentEmail !== null ? trim((string)$currentEmail) : '';
                }
                $maySetEmail = $email === ''
                    || $currentEmail === ''
                    || strcasecmp($currentEmail, $email) === 0
                    || $overwriteOk;
                if ($maySetEmail) {
                    $sets[] = '`email`=?';
                    $updParams[] = $email !== '' ? $email : null;
                }
            }
            if (in_array('foto_ruta', $tCols, true)) {
                $sets[] = '`foto_ruta`=?';
                $updParams[] = $foto !== '' ? $foto : null;
            }
            if (in_array('firma_ruta', $tCols, true)) {
                $sets[] = '`firma_ruta`=?';
                $updParams[] = $firma !== '' ? $firma : null;
            }

            if ($sets !== []) {
                $updParams[] = $tid;
                $this->pdo->prepare('UPDATE `tercero` SET ' . implode(',', $sets) . ' WHERE `id`=?')->execute($updParams);
            }
        }

        $resolvedIdentId = $this->syncTerceroIdentificacionForUsuario($data, $tid, $tipoDoc, $numero, $dv);

        if ($resolvedIdentId > 0) {
            $personaLink = new UsuarioPersonaLinkService($this->pdo);
            $personaLink->assignPersonaLink($data, $resolvedIdentId, $columnNames);
        }
    }

    /**
     * Crea o actualiza la fila de identificación y devuelve su id.
     *
     * @param array<string, mixed> $data
     */
    private function syncTerceroIdentificacionForUsuario(
        array $data,
        int $terceroId,
        ?int $tipoDoc,
        string $numero,
        ?int $dv
    ): int {
        if (!$this->tableExists('terceroidentificacion')) {
            return 0;
        }

        $tiCols = $this->getTableColumnNames('terceroidentificacion');
        if (!in_array('tercero_id', $tiCols, true) || !in_array('tipodocumento_id', $tiCols, true) || !in_array('numero', $tiCols, true)) {
            return 0;
        }

        if ($tipoDoc === null || $tipoDoc <= 0) {
            if ($numero !== '') {
                throw new Exception(json_encode([
                    'tipodocumento_id' => 'Indique el tipo de documento si informa el número.',
                ], JSON_UNESCAPED_UNICODE));
            }

            return 0;
        }

        $tipoVal = $tipoDoc;
        $numVal = $numero !== '' ? $numero : null;

        $identId = 0;
        if ($numVal !== null) {
            $stMatch = $this->pdo->prepare('
                SELECT `id` FROM `terceroidentificacion`
                WHERE `tercero_id`=? AND `tipodocumento_id`=? AND TRIM(`numero`)=TRIM(?)
                ORDER BY `principal` DESC, `id` ASC
                LIMIT 1
            ');
            $stMatch->execute([$terceroId, $tipoVal, $numVal]);
            $identId = (int)($stMatch->fetchColumn() ?: 0);
        }

        if ($identId <= 0) {
            $stFind = $this->pdo->prepare('SELECT `id` FROM `terceroidentificacion` WHERE `tercero_id`=? AND `principal`=1 LIMIT 1');
            $stFind->execute([$terceroId]);
            $identId = (int)($stFind->fetchColumn() ?: 0);
        }

        $identAccion = trim((string)($data['usuario_identificacion_accion'] ?? 'update_principal'));

        if ($identId > 0 && $identAccion === 'new_row') {
            if (in_array('principal', $tiCols, true)) {
                $this->pdo->prepare('UPDATE `terceroidentificacion` SET `principal`=0 WHERE `tercero_id`=?')->execute([$terceroId]);
            }
            $insC = ['tercero_id', 'tipodocumento_id', 'numero', 'principal', 'estado_id'];
            $insV = [$terceroId, $tipoVal, $numVal, 1, 1];
            if (in_array('dv', $tiCols, true)) {
                $insC[] = 'dv';
                $insV[] = $dv;
            }
            $qc = array_map(fn ($c) => '`' . str_replace('`', '', $c) . '`', $insC);
            $this->pdo->prepare(
                'INSERT INTO `terceroidentificacion` (' . implode(',', $qc) . ') VALUES (' . implode(',', array_fill(0, count($insV), '?')) . ')'
            )->execute($insV);

            return (int)$this->pdo->lastInsertId();
        }

        if ($identId > 0) {
            $uSets = ['`tipodocumento_id`=?', '`numero`=?'];
            $uPar = [$tipoVal, $numVal];
            if (in_array('dv', $tiCols, true)) {
                $uSets[] = '`dv`=?';
                $uPar[] = $dv;
            }
            $uPar[] = $identId;
            $this->pdo->prepare('UPDATE `terceroidentificacion` SET ' . implode(',', $uSets) . ' WHERE `id`=?')->execute($uPar);

            return $identId;
        }

        if ($numVal === null) {
            return 0;
        }

        $insC = ['tercero_id', 'tipodocumento_id', 'numero', 'principal', 'estado_id'];
        $insV = [$terceroId, $tipoVal, $numVal, 1, 1];
        if (in_array('dv', $tiCols, true)) {
            $insC[] = 'dv';
            $insV[] = $dv;
        }
        $qc = array_map(fn ($c) => '`' . str_replace('`', '', $c) . '`', $insC);
        $this->pdo->prepare(
            'INSERT INTO `terceroidentificacion` (' . implode(',', $qc) . ') VALUES (' . implode(',', array_fill(0, count($insV), '?')) . ')'
        )->execute($insV);

        return (int)$this->pdo->lastInsertId();
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
     * FKs salientes desde una tabla (INFORMATION_SCHEMA).
     *
     * @return array<int, array{column: string, referenced_table: string, referenced_column: string}>
     */
    public function getOutgoingForeignKeys(string $table): array
    {
        $table = $this->sqlIdentifierTable($table);
        $stmt = $this->pdo->prepare('
            SELECT COLUMN_NAME AS `column`,
                   REFERENCED_TABLE_NAME AS referenced_table,
                   REFERENCED_COLUMN_NAME AS referenced_column
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND REFERENCED_TABLE_NAME IS NOT NULL
            AND REFERENCED_COLUMN_NAME IS NOT NULL
            ORDER BY COLUMN_NAME
        ');
        $stmt->execute([$table]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $byColumn = [];

        foreach ($rows as $row) {
            $col = $row['column'];
            if (!isset($byColumn[$col])) {
                $byColumn[$col] = $row;
            }
        }

        return array_values($byColumn);
    }

    /**
     * FK de contexto.campo → tabla referenciada (solo si existe en INFORMATION_SCHEMA).
     *
     * @return array{column: string, referenced_table: string, referenced_column: string}|null
     */
    public function getForeignKeyForColumn(string $contextTable, string $fkColumn): ?array
    {
        foreach ($this->getOutgoingForeignKeys($contextTable) as $fk) {
            if ($fk['column'] === $fkColumn) {
                return $fk;
            }
        }

        return null;
    }

    /**
     * Columnas en la tabla referenciada que filtran por el mismo nombre de campo en el formulario (context).
     * Convención: si `departamento` tiene `pais_id` y `tercero` tiene `pais_id`, el combo departamento se filtra por tercero.pais_id.
     *
     * @return list<string> nombres de campo (coinciden en context y en tabla hija)
     */
    public function getCatalogParentFieldsForFk(string $contextTable, string $fkColumn): array
    {
        $fk = $this->getForeignKeyForColumn($contextTable, $fkColumn);

        if ($fk === null) {
            return [];
        }

        $ref = $this->sqlIdentifierTable($fk['referenced_table']);
        $ctxCols = [];
        $ctxSafe = $this->sqlIdentifierTable($contextTable);

        foreach ($this->pdo->query('SHOW COLUMNS FROM `' . $ctxSafe . '`')->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $ctxCols[$c['Field']] = true;
        }

        $parents = [];
        /*
         * No encadenar por columnas de “vida”/ámbito: suelen existir en ambas tablas (estado_id)
         * pero no son jerarquía de catálogo; si las incluimos, el API exige parent_estado_id y el
         * formulario a veces no tiene ese campo → búsqueda siempre vacía.
         */
        $skipParentNames = ['estado_id', 'empresa_id', 'sede_id', 'created_at', 'updated_at'];

        foreach ($this->getOutgoingForeignKeys($ref) as $childFk) {
            $col = $childFk['column'];
            if (in_array($col, $skipParentNames, true)) {
                continue;
            }
            if (isset($ctxCols[$col])) {
                $parents[] = $col;
            }
        }

        return array_values(array_unique($parents));
    }

    /**
     * Estimación de filas (rápido) para relmode:auto.
     */
    public function getApproxTableRows(string $table): int
    {
        $t = $this->sqlIdentifierTable($table);
        $stmt = $this->pdo->prepare('
            SELECT COALESCE(TABLE_ROWS, 0) AS n
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            LIMIT 1
        ');
        $stmt->execute([$t]);
        $n = (int)$stmt->fetchColumn();

        return max(0, $n);
    }

    /**
     * Metadatos para UI autocomplete + API catalogSearch.
     *
     * @return array{referenced_table: string, referenced_column: string, parent_fields: string[]}|null
     */
    public function buildCatalogMetaForFk(string $contextTable, string $fkColumn): ?array
    {
        $fk = $this->getForeignKeyForColumn($contextTable, $fkColumn);

        if ($fk === null) {
            return null;
        }

        return [
            'referenced_table' => $fk['referenced_table'],
            'referenced_column' => $fk['referenced_column'],
            'parent_fields' => $this->getCatalogParentFieldsForFk($contextTable, $fkColumn),
        ];
    }

    /**
     * Búsqueda server-side para autocomplete CRUD. Solo tablas/columnas validadas por INFORMATION_SCHEMA.
     *
     * @param array<string, scalar> $parentValues nombre campo contexto => id
     * @return list<array{id: int, nombre: string}>
     */
    public function searchCatalogOptions(
        string $contextTable,
        string $fkColumn,
        string $q,
        array $parentValues,
        int $limit = 25
    ): array {
        $meta = $this->buildCatalogMetaForFk($contextTable, $fkColumn);

        if ($meta === null) {
            return [];
        }

        $qTrim = trim($q);

        /*
         * Sin texto de búsqueda: exigir todos los padres de jerarquía (listado inicial acotado).
         * Con texto: aplicar solo los padres que vengan informados (evita lista vacía si falta un combo previo).
         */
        if ($qTrim === '') {
            foreach ($meta['parent_fields'] as $pf) {
                if (!array_key_exists($pf, $parentValues)) {
                    return [];
                }
                $pv = $parentValues[$pf];
                if ($pv === '' || $pv === null) {
                    return [];
                }
            }
        }

        $refTable = $this->sqlIdentifierTable($meta['referenced_table']);
        $refCol = $this->sqlIdentifierTable($meta['referenced_column']);
        $limit = max(1, min(100, $limit));

        $stmtCols = $this->pdo->query('SHOW COLUMNS FROM `' . $refTable . '`');
        $refColumns = $stmtCols ? $stmtCols->fetchAll(PDO::FETCH_ASSOC) : [];
        $refFieldNames = array_column($refColumns, 'Field');

        $displayColumn = null;
        $preferred = ['nombre', 'razon_social', 'descripcion', 'titulo', 'username', 'email'];

        foreach ($preferred as $pref) {
            if (in_array($pref, $refFieldNames, true)) {
                $displayColumn = $pref;
                break;
            }
        }

        if ($displayColumn === null) {
            foreach ($refFieldNames as $fn) {
                if ($fn !== $refCol) {
                    $displayColumn = $fn;
                    break;
                }
            }
        }

        if ($displayColumn === null) {
            return [];
        }

        $displaySafe = str_replace('`', '', $displayColumn);

        $isTercero = ($refTable === 'tercero');
        $hasNombres = in_array('nombres', $refFieldNames, true);
        $hasApellidos = in_array('apellidos', $refFieldNames, true);

        $pkQuoted = '`' . $refCol . '`';

        if ($isTercero && $hasNombres && $hasApellidos) {
            $displayExpr = "COALESCE(NULLIF(TRIM(CONCAT_WS(' ', NULLIF(TRIM(COALESCE(`nombres`,'')), ''), NULLIF(TRIM(COALESCE(`apellidos`,'')), ''))), ''), NULLIF(TRIM(COALESCE(`razon_social`,'')), ''), CONCAT('Tercero #', " . $pkQuoted . '))';
        } else {
            $displayExpr = '`' . $displaySafe . '`';
        }

        $allowedParents = array_flip($meta['parent_fields']);
        $where = ['1=1'];
        $params = [];

        foreach ($parentValues as $pname => $pval) {
            if (!isset($allowedParents[$pname])) {
                continue;
            }
            $pc = $this->sqlIdentifierTable((string)$pname);
            if (!in_array($pc, $refFieldNames, true)) {
                continue;
            }
            if ($pval === '' || $pval === null) {
                continue;
            }
            $where[] = '`' . $pc . '` = ?';
            $params[] = (int)$pval;
        }

        if ($qTrim !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $qTrim) . '%';
            $where[] = '(' . $displayExpr . ' LIKE ?)';
            $params[] = $like;
        }

        if (in_array('estado_id', $refFieldNames, true)) {
            $where[] = '`estado_id` = 1';
        }

        $includeCatalogTipo = ($refTable === 'zona') && in_array('tipo', $refFieldNames, true);
        $tipoSelectSql = $includeCatalogTipo ? ', `tipo`' : '';

        $sql = 'SELECT ' . $pkQuoted . ' AS id' . $tipoSelectSql . ', (' . $displayExpr . ') AS nombre FROM `' . $refTable . '` WHERE ' . implode(' AND ', $where)
            . ' ORDER BY (' . $displayExpr . ') ASC LIMIT ' . (int)$limit;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $out = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $item = [
                'id' => (int)$row['id'],
                'nombre' => (string)($row['nombre'] ?? ''),
            ];
            if ($includeCatalogTipo) {
                $item['tipo'] = isset($row['tipo']) ? (string)$row['tipo'] : '';
            }
            $out[] = $item;
        }

        return $out;
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
     * Modo de widget para FK en comentario de columna: relmode:select|autocomplete|auto
     * Vacío = select completo (comportamiento histórico).
     */
    public function extractRelModeFromComment(?string $comment): string
    {
        $comment = trim((string)$comment);
        if ($comment === '') {
            return '';
        }

        foreach (explode('|', $comment) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (str_starts_with(strtolower($part), 'relmode:')) {
                $v = strtolower(trim(substr($part, strlen('relmode:'))));
                if (in_array($v, ['select', 'autocomplete', 'auto'], true)) {
                    return $v;
                }
            }
        }

        return '';
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

        $includeTipo = ($this->sqlIdentifierTable($tabla) === 'zona')
            && in_array('tipo', array_column($columns, 'Field'), true);
        $tipoSql = $includeTipo ? ', `tipo`' : '';

        $includeCodigoTipodoc = ($this->sqlIdentifierTable($tabla) === 'tipodocumento')
            && in_array('codigo', array_column($columns, 'Field'), true);
        $codigoSql = $includeCodigoTipodoc ? ', `codigo`' : '';

        $sql = "SELECT `id`{$tipoSql}{$codigoSql}, ($displayExpr) AS nombre FROM $tablaSql";
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
     * Comentario con trozo exacto "uppercase" → texto solo en mayúsculas (formulario + persistencia).
     */
    private function columnCommentIsUppercaseOnly(string $comment): bool
    {
        foreach (explode('|', $comment) as $part) {
            if (trim($part) === 'uppercase') {
                return true;
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
        $personaLink = new UsuarioPersonaLinkService($this->pdo);
        $linkColumn = $personaLink->personaLinkColumn($columnNames);
        if ($linkColumn === null) {
            return false;
        }

        $username = trim((string)($data['username'] ?? ''));
        if ($username === '') {
            return false;
        }

        $linkNew = $personaLink->linkValueFromData($data, $linkColumn);

        $validator = new UsuarioFormValidationService($this->pdo);
        $existing = $validator->findUsuarioByUsername($username);

        if (!$existing) {
            return false;
        }

        if ((int)($existing['estado_id'] ?? 1) !== 1) {
            throw new Exception(json_encode([
                'username' => 'Existe una cuenta inactiva con este usuario. Reactive esa cuenta en lugar de crear otra.',
            ], JSON_UNESCAPED_UNICODE));
        }

        $linkOld = $personaLink->linkValueFromRow($existing, $linkColumn);

        if ($linkOld !== $linkNew) {
            throw new Exception(json_encode([
                'username' => $linkColumn === 'terceroidentificacion_id'
                    ? 'Este nombre de usuario no está disponible porque ya existe para otra identificación.'
                    : 'Este nombre de usuario no está disponible porque ya existe para otro tercero.',
            ], JSON_UNESCAPED_UNICODE));
        }

        $existingId = (int)$existing['id'];
        if (!$validator->isSuperAdmin() && !$validator->usuarioVisibleInSessionScope($existingId)) {
            throw new Exception(json_encode([
                'username' => 'Este usuario existe fuera de su empresa/sede. No puede vincularlo desde aquí.',
            ], JSON_UNESCAPED_UNICODE));
        }

        $this->linkNewUsuarioToSessionScope($existingId);

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