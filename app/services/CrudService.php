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

        if ($tabla === 'empresa') {
            return $this->getTableDataEmpresa();
        }

        $columns = $this->getColumns($tabla);

        $fields = array_column($columns, 'Field');

        $sql = "SELECT * FROM $tabla WHERE 1=1";
        $params = [];

        if (SoftDeleteService::supports($this->pdo, $tabla)) {
            $sql .= SoftDeleteService::sqlAndNotDeleted($this->pdo, $tabla);
        }

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
     * Listado de empresas restringido a las asociadas al usuario logueado (no-superadmin).
     * Superadmin ve todas. Incluye `nit` derivado de terceroidentificacion para el form/grilla.
     */
    private function getTableDataEmpresa(): array
    {
        $esSuperAdmin = !empty($_SESSION['es_super_admin'])
            || (int)($_SESSION['rol_id'] ?? 0) === 1;

        $select = 'e.*, ti.numero AS nit, ti.dv AS documento_dv,
            t.razon_social, t.email, t.telefono, t.celular, t.direccion,
            t.pais_id, t.departamento_id, t.municipio_id, t.zona_id,
            t.comuna_id, t.corregimiento_id, t.barrio_id, t.vereda_id,
            ri.tipodocumento_id AS rep_tipodocumento_id,
            ri.numero AS rep_numero_documento,
            tr.nombres AS rep_nombres,
            tr.apellidos AS rep_apellidos';
        $joins = ' INNER JOIN tercero t ON t.id = e.tercero_id
            INNER JOIN terceroidentificacion ti ON ti.id = e.terceroidentificacion_id
            LEFT JOIN terceroidentificacion ri ON ri.id = e.representante_terceroidentificacion_id
            LEFT JOIN tercero tr ON tr.id = ri.tercero_id';

        $deletedFilter = SoftDeleteService::supports($this->pdo, 'empresa')
            ? SoftDeleteService::sqlAndNotDeleted($this->pdo, 'empresa', 'e')
            : '';
        if ($deletedFilter !== '' && !str_contains($deletedFilter, 'WHERE')) {
            $deletedFilter = ' WHERE 1=1' . $deletedFilter;
        }

        if ($esSuperAdmin) {
            $sql = "SELECT $select FROM empresa e $joins{$deletedFilter} ORDER BY e.id DESC";
            $stmt = $this->pdo->query($sql);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid <= 0) {
            return [];
        }

        $sql = "SELECT $select FROM empresa e
                INNER JOIN usuario_empresa ue
                    ON ue.empresa_id = e.id AND ue.estado_id = 1
                $joins
                WHERE ue.usuario_id = ?";
        if (SoftDeleteService::supports($this->pdo, 'empresa')) {
            $sql .= SoftDeleteService::sqlAndNotDeleted($this->pdo, 'empresa', 'e');
        }
        $sql .= ' ORDER BY e.id DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$uid]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Columnas sintéticas (no en la tabla `empresa`) que se inyectan en el form CRUD.
     *
     * @return list<array{Field: string, Type: string, IS_NULLABLE: string, COLUMN_COMMENT: string}>
     */
    public function getEmpresaPersonaSyntheticColumns(): array
    {
        $syn = [
            ['Field' => 'nit', 'Type' => 'varchar', 'IS_NULLABLE' => 'NO',
                'COLUMN_COMMENT' => 'type:text|order:5|label:NIT|placeholder:Número de NIT|show:form,table'],
            ['Field' => 'documento_dv', 'Type' => 'varchar', 'IS_NULLABLE' => 'YES',
                'COLUMN_COMMENT' => 'type:text|order:8|label:DV|show:form,table|title:Dígito de verificación NIT'],
            ['Field' => 'razon_social', 'Type' => 'varchar', 'IS_NULLABLE' => 'NO',
                'COLUMN_COMMENT' => 'type:text|order:10|label:Razón social|show:form,table'],
            ['Field' => 'pais_id', 'Type' => 'smallint', 'IS_NULLABLE' => 'YES',
                'COLUMN_COMMENT' => 'type:text|order:20|relmode:autocomplete|label:País|show:form,table'],
            ['Field' => 'departamento_id', 'Type' => 'smallint', 'IS_NULLABLE' => 'YES',
                'COLUMN_COMMENT' => 'type:text|order:25|relmode:autocomplete|label:Departamento|show:form,table'],
            ['Field' => 'municipio_id', 'Type' => 'int', 'IS_NULLABLE' => 'YES',
                'COLUMN_COMMENT' => 'type:text|order:30|relmode:autocomplete|label:Municipio|show:form,table'],
            ['Field' => 'zona_id', 'Type' => 'int', 'IS_NULLABLE' => 'YES',
                'COLUMN_COMMENT' => 'type:text|order:35|relmode:autocomplete|label:Zona|show:form'],
            ['Field' => 'comuna_id', 'Type' => 'int', 'IS_NULLABLE' => 'YES',
                'COLUMN_COMMENT' => 'type:text|order:40|relmode:autocomplete|label:Comuna|show:form'],
            ['Field' => 'corregimiento_id', 'Type' => 'int', 'IS_NULLABLE' => 'YES',
                'COLUMN_COMMENT' => 'type:text|order:45|relmode:autocomplete|label:Corregimiento|show:form'],
            ['Field' => 'barrio_id', 'Type' => 'int', 'IS_NULLABLE' => 'YES',
                'COLUMN_COMMENT' => 'type:text|order:50|relmode:autocomplete|label:Barrio|show:form'],
            ['Field' => 'vereda_id', 'Type' => 'int', 'IS_NULLABLE' => 'YES',
                'COLUMN_COMMENT' => 'type:text|order:55|relmode:autocomplete|label:Vereda|show:form'],
            ['Field' => 'telefono', 'Type' => 'varchar', 'IS_NULLABLE' => 'YES',
                'COLUMN_COMMENT' => 'type:text|order:60|label:Teléfono|show:form'],
            ['Field' => 'celular', 'Type' => 'varchar', 'IS_NULLABLE' => 'YES',
                'COLUMN_COMMENT' => 'type:text|order:65|label:Celular|show:form'],
            ['Field' => 'direccion', 'Type' => 'varchar', 'IS_NULLABLE' => 'YES',
                'COLUMN_COMMENT' => 'type:text|order:70|label:Dirección|show:form'],
            ['Field' => 'email', 'Type' => 'varchar', 'IS_NULLABLE' => 'YES',
                'COLUMN_COMMENT' => 'type:text|order:75|label:Email|show:form'],
            ['Field' => 'rep_tipodocumento_id', 'Type' => 'smallint', 'IS_NULLABLE' => 'YES',
                'COLUMN_COMMENT' => 'type:text|order:82|relmode:select|label:Tipo doc. representante|show:form'],
            ['Field' => 'rep_numero_documento', 'Type' => 'varchar', 'IS_NULLABLE' => 'YES',
                'COLUMN_COMMENT' => 'type:text|order:84|label:Número doc. representante|show:form'],
            ['Field' => 'rep_nombres', 'Type' => 'varchar', 'IS_NULLABLE' => 'YES',
                'COLUMN_COMMENT' => 'type:text|order:86|label:Nombres representante|show:form|uppercase'],
            ['Field' => 'rep_apellidos', 'Type' => 'varchar', 'IS_NULLABLE' => 'YES',
                'COLUMN_COMMENT' => 'type:text|order:88|label:Apellidos representante|show:form|uppercase'],
        ];

        return $syn;
    }

    /**
     * Campos del formulario empresa que no son columnas físicas de `empresa`.
     *
     * @return list<string>
     */
    public function getEmpresaNonTableFormFields(): array
    {
        return array_merge(
            array_column($this->getEmpresaPersonaSyntheticColumns(), 'Field'),
            ['tercero_id', 'terceroidentificacion_id']
        );
    }

    /**
     * FK de ubicación en formulario empresa (columnas de `tercero`, no de `empresa`).
     *
     * @return array<string, string> campo => tabla referenciada
     */
    public function getEmpresaUbicacionFkMap(): array
    {
        return [
            'pais_id' => 'pais',
            'departamento_id' => 'departamento',
            'municipio_id' => 'municipio',
            'zona_id' => 'zona',
            'comuna_id' => 'comuna',
            'corregimiento_id' => 'corregimiento',
            'barrio_id' => 'barrio',
            'vereda_id' => 'vereda',
        ];
    }

    /**
     * Tabla de contexto para catálogo/autocomplete (FK en schema).
     */
    public function resolveCatalogContextTable(string $formContextTable, string $fkColumn): string
    {
        if ($formContextTable === 'empresa' && array_key_exists($fkColumn, $this->getEmpresaUbicacionFkMap())) {
            return 'tercero';
        }

        return $formContextTable;
    }

    /**
     * @param list<array<string, mixed>> $columns
     * @return list<array<string, mixed>>
     */
    public function applyEmpresaCrudColumnPresentation(array $columns): array
    {
        $ubicacionRelmode = [
            'pais_id' => 'relmode:autocomplete',
            'departamento_id' => 'relmode:autocomplete',
            'municipio_id' => 'relmode:autocomplete',
            'zona_id' => 'relmode:autocomplete',
            'comuna_id' => 'relmode:autocomplete',
            'corregimiento_id' => 'relmode:autocomplete',
            'barrio_id' => 'relmode:autocomplete',
            'vereda_id' => 'relmode:autocomplete',
        ];

        $inject = [
            'nit' => 'label:NIT|order:5',
            'documento_dv' => 'label:DV|order:8',
            'razon_social' => 'label:Razón social|order:10',
            'sitio_web' => 'label:Sitio web|order:76|show:form',
            'logo' => 'type:upload|subtype:image|order:900|span:full|label:Logo|show:form',
            'logo2' => 'type:upload|subtype:image|order:901|span:full|label:Logo 2|show:form',
            'fecha_registro' => 'label:Fecha de registro|order:120|show:form',
            'estado_id' => 'label:Estado|order:130|show:form,table',
            'tercero_id' => 'show:none|order:9999',
            'terceroidentificacion_id' => 'show:none|order:9999',
            'representante_terceroidentificacion_id' => 'show:none|order:9999',
            'created_at' => 'show:none|order:9999',
            'created_by' => 'show:none|order:9999',
            'updated_at' => 'show:none|order:9999',
            'updated_by' => 'show:none|order:9999',
        ];

        foreach ($columns as &$col) {
            $f = $col['Field'] ?? '';
            if (isset($ubicacionRelmode[$f])) {
                $existing = (string)($col['COLUMN_COMMENT'] ?? '');
                if (!str_contains($existing, 'relmode:')) {
                    $col['COLUMN_COMMENT'] = trim($existing . '|' . $ubicacionRelmode[$f], '|');
                }
            }
            if (isset($inject[$f])) {
                $existing = (string)($col['COLUMN_COMMENT'] ?? '');
                if ($existing === '') {
                    $col['COLUMN_COMMENT'] = $inject[$f];
                } elseif ($f === 'sitio_web') {
                    $existing = preg_replace('/\|?order:\d+/', '', $existing) ?? $existing;
                    $col['COLUMN_COMMENT'] = trim($existing . '|' . $inject[$f], '|');
                } elseif (!str_contains($existing, 'order:') && str_contains($inject[$f], 'order:')) {
                    $col['COLUMN_COMMENT'] = $existing . '|' . $inject[$f];
                }
            }
            if (!in_array($f, ['nit', 'razon_social', 'email', 'telefono', 'estado_id'], true)
                && $f !== 'id'
                && !str_contains((string)($col['COLUMN_COMMENT'] ?? ''), 'show:')
            ) {
                $col['COLUMN_COMMENT'] = trim((string)($col['COLUMN_COMMENT'] ?? '') . '|show:form', '|');
            }
        }
        unset($col);

        return $this->applyEmpresaCrudTableVisibility($columns);
    }

    /**
     * Grilla empresa: NIT, DV, razón social, país, departamento, municipio, estado.
     *
     * @param list<array<string, mixed>> $columns
     * @return list<array<string, mixed>>
     */
    public function applyEmpresaCrudTableVisibility(array $columns): array
    {
        $tableFields = [
            'nit',
            'documento_dv',
            'razon_social',
            'pais_id',
            'departamento_id',
            'municipio_id',
            'estado_id',
        ];

        foreach ($columns as &$col) {
            $f = $col['Field'] ?? '';
            if ($f === 'id') {
                continue;
            }
            $comment = (string)($col['COLUMN_COMMENT'] ?? '');
            if (!in_array($f, $tableFields, true)) {
                $comment = preg_replace('/\|?show:table\|?/', '|', $comment) ?? $comment;
                if (!str_contains($comment, 'show:form') && !str_contains($comment, 'show:none')) {
                    $comment = trim($comment . '|show:form', '|');
                }
            } else {
                if (!str_contains($comment, 'show:form')) {
                    $comment = trim($comment . '|show:form', '|');
                }
                if (!str_contains($comment, 'show:table')) {
                    $comment = trim($comment . '|show:table', '|');
                }
            }
            $col['COLUMN_COMMENT'] = $comment;
        }
        unset($col);

        return $columns;
    }

    public function applyEmpresaLogoUploadPresentation(array $columns): array
    {
        foreach ($columns as &$col) {
            $f = $col['Field'] ?? '';
            if ($f === 'logo' || $f === 'logo2') {
                $col['Type'] = 'upload';
                $sub = $f === 'logo' ? 'Logo principal' : 'Logo secundario';
                $ord = $f === 'logo' ? 900 : 901;
                $col['COLUMN_COMMENT'] = 'type:upload|subtype:image|order:' . $ord . '|span:full|label:' . $sub
                    . '|show:form|title:PNG/JPG/WebP, máx. 3 MB';
            }
        }
        unset($col);

        return $columns;
    }

    /**
     * Indica si el usuario logueado puede gestionar (editar) una empresa por id.
     * Superadmin: siempre. No-superadmin: solo si está en su usuario_empresa.
     */
    public function userCanManageEmpresa(int $empresaId): bool
    {
        if ($empresaId <= 0) {
            return false;
        }

        $esSuperAdmin = !empty($_SESSION['es_super_admin'])
            || (int)($_SESSION['rol_id'] ?? 0) === 1;
        if ($esSuperAdmin) {
            return true;
        }

        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid <= 0) {
            return false;
        }

        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM usuario_empresa
             WHERE usuario_id = ? AND empresa_id = ? AND estado_id = 1
             LIMIT 1'
        );
        $stmt->execute([$uid, $empresaId]);

        return (bool)$stmt->fetchColumn();
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

        if (SoftDeleteService::supports($this->pdo, 'usuario')) {
            $sql .= SoftDeleteService::sqlAndNotDeleted($this->pdo, 'usuario', 'u');
        }

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
            'estado_id' => 'label:Estado|reltipo:GENERAL|order:130|title:Estado de la cuenta de acceso (activo/inactivo).',
            'tercero_id' => 'show:none|order:9999',
            'terceroidentificacion_id' => 'show:none|order:9999',
            'created_at' => 'show:none|order:9999',
            'created_by' => 'show:none|order:9999',
            'updated_at' => 'show:none|order:9999',
            'updated_by' => 'show:none|order:9999',
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
                if (in_array($f, ['tercero_id', 'terceroidentificacion_id', 'created_at', 'created_by', 'updated_at', 'updated_by', 'deleted_at', 'deleted_by'], true)) {
                    $col['COLUMN_COMMENT'] = $this->mergeColumnCommentShow($base, 'none');
                } else {
                    $col['COLUMN_COMMENT'] = $this->mergeColumnCommentShow($base, 'form');
                }
            }
        }
        unset($col);

        return $columns;
    }

    /**
     * Columnas permitidas en la grilla del ítem usuario (el formulario no se filtra aquí).
     *
     * @return list<string>
     */
    public function getUsuarioCrudTableColumnFields(): array
    {
        return [
            'tipodocumento_id',
            'numero_documento',
            'documento_dv',
            'nombres',
            'apellidos',
            'username',
            'sesion_idle_minutos',
            'email',
            'estado_id',
        ];
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

    private function tableHasColumn(string $table, string $column): bool
    {
        $t = preg_replace('/[^A-Za-z0-9_]/', '', $table);
        $c = preg_replace('/[^A-Za-z0-9_]/', '', $column);

        $stmt = $this->pdo->prepare('
            SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
            LIMIT 1
        ');
        $stmt->execute([$t, $c]);

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
        $accNd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'accion', 'a');

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
            {$accNd}
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
        $empresaTx = ($tabla === 'empresa');

        $usuarioSaveCtx = null;

        if ($empresaTx) {
            $this->pdo->beginTransaction();
        }

        if ($usuarioTx) {
            $modeResolver = new UsuarioSaveModeResolver($this->pdo);
            $usuarioSaveCtx = $modeResolver->resolve($data);
            $id = $usuarioSaveCtx->usuarioId;

            $this->validateUsuarioPasswordConfirm($data, $id);
            $validator = new UsuarioFormValidationService($this->pdo);
            $validator->validateForMode($data, $usuarioSaveCtx, $columnNames);

            if ($usuarioSaveCtx->isAlta()) {
                $this->assertUsuarioCreateAllowedInSessionScope();
            }

            $this->pdo->beginTransaction();
        }

        try {

            if ($usuarioTx && $usuarioSaveCtx !== null && $usuarioSaveCtx->isLinkOnly() && $id) {
                $this->linkNewUsuarioToSessionScope((int)$id);
                    $this->pdo->commit();
                $_SESSION['flash_notice'] = UsuarioSaveMessages::linkOnlyNotice();

                return true;
            }

            if ($usuarioTx && $usuarioSaveCtx !== null
                && $this->usuarioRequiresTerceroPersonaSave($columnNames)
                && $this->tableExists('tercero')
            ) {
                (new UsuarioPersonaResolver($this->pdo))->resolveAndPersist($data, $usuarioSaveCtx, $columnNames);
                $validator->validateAfterTerceroResolved($data, $id, $columnNames);
            }

            $empresaTableCols = $empresaTx
                ? array_flip($this->getTableColumnNames('empresa'))
                : [];

            if ($empresaTx) {
                $this->resolveEmpresaTerceroForSave($data, $id);
                $this->syncTerceroFromEmpresaForm($data);
                $this->resolveRepresentanteLegalForSave($data);
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
                    $_SESSION['flash_notice'] = UsuarioSaveMessages::usernameLinkNotice();

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

                if (in_array($name, ['id', 'created_at', 'created_by', 'updated_at', 'updated_by', 'deleted_at', 'deleted_by'])) {
                    continue;
                }

                if ($empresaTx && !isset($empresaTableCols[$name])) {
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

            if ($ok && $id && $tabla === 'usuario' && $stmt->rowCount() === 0) {
                UsuarioSaveMessages::throwJson([
                    'general' => 'No se encontró el usuario a actualizar. Pulse Limpiar y complete el formulario de nuevo.',
                ]);
            }

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

            if ($usuarioTx || $empresaTx) {
                if ($ok) {
                    $this->pdo->commit();
                } elseif ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
            }

            return $ok;

        } catch (Throwable $e) {
            if (($usuarioTx || $empresaTx) && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Resuelve `tercero_id` y `terceroidentificacion_id` para una empresa al guardar,
     * validando unicidad de NIT y reusando terceros existentes cuando aplica.
     *
     * @param array<string, mixed> $data
     */
    private function resolveEmpresaTerceroForSave(array &$data, $id): void
    {
        $nit = trim((string)($data['nit'] ?? ''));
        if ($nit === '') {
            throw new Exception(json_encode([
                'nit' => 'El NIT es obligatorio.',
            ], JSON_UNESCAPED_UNICODE));
        }

        $tipoNit = EmpresaTerceroLookupService::TIPODOCUMENTO_NIT_ID;
        $tipoJur = EmpresaTerceroLookupService::TIPOPERSONA_JURIDICA_ID;

        if ($id) {
            $st = $this->pdo->prepare('SELECT tercero_id, terceroidentificacion_id FROM empresa WHERE id = ? LIMIT 1');
            $st->execute([(int)$id]);
            $cur = $st->fetch(PDO::FETCH_ASSOC);

            if (!$cur) {
                throw new Exception(json_encode([
                    'general' => 'Empresa no encontrada.',
                ], JSON_UNESCAPED_UNICODE));
            }

            $curTerceroId = (int)$cur['tercero_id'];
            $curTiId = (int)$cur['terceroidentificacion_id'];

            $st = $this->pdo->prepare('SELECT TRIM(numero) FROM terceroidentificacion WHERE id = ? LIMIT 1');
            $st->execute([$curTiId]);
            $curNit = trim((string)$st->fetchColumn());

            if ($curNit === $nit) {
                $data['tercero_id'] = $curTerceroId;
                $data['terceroidentificacion_id'] = $curTiId;
                $this->updateEmpresaNitDv($curTiId, $nit, $data);
                return;
            }

            $st = $this->pdo->prepare(
                'SELECT e.id FROM empresa e
                 INNER JOIN terceroidentificacion ti ON ti.id = e.terceroidentificacion_id
                 WHERE TRIM(ti.numero) = TRIM(?) AND ti.tipodocumento_id = ? AND e.id <> ?
                 LIMIT 1'
            );
            $st->execute([$nit, $tipoNit, (int)$id]);
            if ($st->fetchColumn()) {
                throw new Exception(json_encode([
                    'nit' => 'Ya existe otra empresa con este NIT.',
                ], JSON_UNESCAPED_UNICODE));
            }

            $st = $this->pdo->prepare(
                'SELECT id, tercero_id FROM terceroidentificacion
                 WHERE tipodocumento_id = ? AND TRIM(numero) = TRIM(?)
                 LIMIT 1'
            );
            $st->execute([$tipoNit, $nit]);
            $ti = $st->fetch(PDO::FETCH_ASSOC);

            if ($ti) {
                $data['tercero_id'] = (int)$ti['tercero_id'];
                $data['terceroidentificacion_id'] = (int)$ti['id'];
                $this->updateEmpresaNitDv((int)$ti['id'], $nit, $data);
                return;
            }

            $st = $this->pdo->prepare('UPDATE terceroidentificacion SET numero = ? WHERE id = ?');
            $st->execute([$nit, $curTiId]);
            $data['tercero_id'] = $curTerceroId;
            $data['terceroidentificacion_id'] = $curTiId;
            $this->updateEmpresaNitDv($curTiId, $nit, $data);
            return;
        }

        $st = $this->pdo->prepare(
            'SELECT e.id FROM empresa e
             INNER JOIN terceroidentificacion ti ON ti.id = e.terceroidentificacion_id
             WHERE TRIM(ti.numero) = TRIM(?) AND ti.tipodocumento_id = ?
             LIMIT 1'
        );
        $st->execute([$nit, $tipoNit]);
        if ($st->fetchColumn()) {
            throw new Exception(json_encode([
                'nit' => 'Ya existe una empresa con este NIT.',
            ], JSON_UNESCAPED_UNICODE));
        }

        $st = $this->pdo->prepare(
            'SELECT id, tercero_id FROM terceroidentificacion
             WHERE tipodocumento_id = ? AND TRIM(numero) = TRIM(?)
             LIMIT 1'
        );
        $st->execute([$tipoNit, $nit]);
        $existing = $st->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $data['tercero_id'] = (int)$existing['tercero_id'];
            $data['terceroidentificacion_id'] = (int)$existing['id'];
            $this->updateEmpresaNitDv((int)$existing['id'], $nit, $data);
            return;
        }

        $st = $this->pdo->prepare(
            'INSERT INTO tercero (tipopersona_id, razon_social, estado_id) VALUES (?, ?, 1)'
        );
        $st->execute([
            $tipoJur,
            (string)($data['razon_social'] ?? ''),
        ]);
        $newTerceroId = (int)$this->pdo->lastInsertId();

        $dv = self::colombianNitDvFromNumber($nit);
        $data['documento_dv'] = (string)$dv;

        $st = $this->pdo->prepare(
            'INSERT INTO terceroidentificacion (tercero_id, tipodocumento_id, numero, dv, principal, estado_id)
             VALUES (?, ?, ?, ?, 1, 1)'
        );
        $st->execute([$newTerceroId, $tipoNit, $nit, $dv]);
        $newTiId = (int)$this->pdo->lastInsertId();

        $data['tercero_id'] = $newTerceroId;
        $data['terceroidentificacion_id'] = $newTiId;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function updateEmpresaNitDv(int $identificacionId, string $nit, array &$data): void
    {
        if ($identificacionId <= 0) {
            return;
        }
        $dv = self::colombianNitDvFromNumber($nit);
        $data['documento_dv'] = (string)$dv;
        $st = $this->pdo->prepare('UPDATE terceroidentificacion SET dv = ? WHERE id = ?');
        $st->execute([$dv, $identificacionId]);
    }

    /**
     * Persiste en `tercero` los datos del formulario empresa (fuente única).
     *
     * @param array<string, mixed> $data
     */
    private function syncTerceroFromEmpresaForm(array $data): void
    {
        $terceroId = (int)($data['tercero_id'] ?? 0);
        if ($terceroId <= 0) {
            return;
        }

        $tCols = $this->getTableColumnNames('tercero');
        $map = [
            'razon_social' => (string)($data['razon_social'] ?? ''),
            'email' => (($data['email'] ?? '') !== '') ? (string)$data['email'] : null,
            'telefono' => (($data['telefono'] ?? '') !== '') ? (string)$data['telefono'] : null,
            'celular' => (($data['celular'] ?? '') !== '') ? (string)$data['celular'] : null,
            'direccion' => (($data['direccion'] ?? '') !== '') ? (string)$data['direccion'] : null,
            'pais_id' => $this->nullableInt($data['pais_id'] ?? null),
            'departamento_id' => $this->nullableInt($data['departamento_id'] ?? null),
            'municipio_id' => $this->nullableInt($data['municipio_id'] ?? null),
            'zona_id' => $this->nullableInt($data['zona_id'] ?? null),
            'comuna_id' => $this->nullableInt($data['comuna_id'] ?? null),
            'corregimiento_id' => $this->nullableInt($data['corregimiento_id'] ?? null),
            'barrio_id' => $this->nullableInt($data['barrio_id'] ?? null),
            'vereda_id' => $this->nullableInt($data['vereda_id'] ?? null),
        ];

        $sets = [];
        $params = [];
        foreach ($map as $field => $val) {
            if (!in_array($field, $tCols, true)) {
                continue;
            }
            $sets[] = '`' . str_replace('`', '', $field) . '`=?';
            $params[] = $val;
        }

        if ($sets === []) {
            return;
        }

        $params[] = $terceroId;
        $st = $this->pdo->prepare('UPDATE tercero SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $st->execute($params);

        $tiId = (int)($data['terceroidentificacion_id'] ?? 0);
        $nit = trim((string)($data['nit'] ?? ''));
        if ($tiId > 0 && $nit !== '') {
            $this->updateEmpresaNitDv($tiId, $nit, $data);
        }
    }

    /**
     * Representante legal → tercero (natural) + terceroidentificacion; FK en empresa.
     *
     * @param array<string, mixed> $data
     */
    private function resolveRepresentanteLegalForSave(array &$data): void
    {
        $tipoDoc = $this->nullableInt($data['rep_tipodocumento_id'] ?? null);
        $numero = trim((string)($data['rep_numero_documento'] ?? ''));
        $nombres = trim((string)($data['rep_nombres'] ?? ''));
        $apellidos = trim((string)($data['rep_apellidos'] ?? ''));

        if ($numero === '' && $nombres === '' && $apellidos === '') {
            $data['representante_terceroidentificacion_id'] = null;
            return;
        }

        if ($tipoDoc === null || $tipoDoc <= 0) {
            throw new Exception(json_encode([
                'rep_tipodocumento_id' => 'Indique el tipo de documento del representante legal.',
            ], JSON_UNESCAPED_UNICODE));
        }

        if ($numero === '') {
            throw new Exception(json_encode([
                'rep_numero_documento' => 'Indique el número de documento del representante legal.',
            ], JSON_UNESCAPED_UNICODE));
        }

        $tipoNatural = 1;
        $tiId = 0;

        $st = $this->pdo->prepare(
            'SELECT ti.id, ti.tercero_id FROM terceroidentificacion ti
             WHERE ti.tipodocumento_id = ? AND TRIM(ti.numero) = TRIM(?)
             LIMIT 1'
        );
        $st->execute([$tipoDoc, $numero]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $tiId = (int)$row['id'];
            $tId = (int)$row['tercero_id'];
        } else {
            $st = $this->pdo->prepare(
                'INSERT INTO tercero (tipopersona_id, nombres, apellidos, estado_id) VALUES (?, ?, ?, 1)'
            );
            $st->execute([
                $tipoNatural,
                $nombres !== '' ? $nombres : null,
                $apellidos !== '' ? $apellidos : null,
            ]);
            $tId = (int)$this->pdo->lastInsertId();
            $st = $this->pdo->prepare(
                'INSERT INTO terceroidentificacion (tercero_id, tipodocumento_id, numero, principal, estado_id)
                 VALUES (?, ?, ?, 1, 1)'
            );
            $st->execute([$tId, $tipoDoc, $numero]);
            $tiId = (int)$this->pdo->lastInsertId();
        }

        $tCols = $this->getTableColumnNames('tercero');
        $sets = [];
        $params = [];
        if (in_array('nombres', $tCols, true)) {
            $sets[] = 'nombres=?';
            $params[] = $nombres !== '' ? $nombres : null;
        }
        if (in_array('apellidos', $tCols, true)) {
            $sets[] = 'apellidos=?';
            $params[] = $apellidos !== '' ? $apellidos : null;
        }
        if (in_array('tipopersona_id', $tCols, true)) {
            $sets[] = 'tipopersona_id=?';
            $params[] = $tipoNatural;
        }
        if ($sets !== []) {
            $params[] = $tId;
            $st = $this->pdo->prepare('UPDATE tercero SET ' . implode(', ', $sets) . ' WHERE id = ?');
            $st->execute($params);
        }

        $data['representante_terceroidentificacion_id'] = $tiId;
    }

    /**
     * @param mixed $v
     */
    private function nullableInt($v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }

        return (int)$v;
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

    /** Comentario estándar para FK empresa_id (valor = empresa.id, etiqueta = tercero.razon_social). */
    public const EMPRESA_ID_REL_COMMENT = 'rel:tercero|label:razon_social|title:Razón social de la empresa';

    /**
     * Limpia referencias huérfanas del POST tras error de guardado.
     *
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    public function sanitizeUsuarioFormPostForDisplay(array $post): array
    {
        return (new UsuarioSaveModeResolver($this->pdo))->sanitizeFormPostForDisplay($post);
    }

    /**
     * Tabla referenciada en COLUMN_COMMENT: rel:sgd_dependencia
     */
    public function extractRelTableFromComment(?string $comment): ?string
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
            if (str_starts_with(strtolower($part), 'rel:')) {
                $table = trim(substr($part, strlen('rel:')));
                $table = preg_replace('/[^A-Za-z0-9_]/', '', $table);

                return $table !== '' ? $table : null;
            }
        }

        return null;
    }

    /**
     * Columna a mostrar en combos FK: label:codigo | label:nombre
     */
    public function extractRelLabelColumnFromComment(?string $comment): ?string
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
            if (str_starts_with(strtolower($part), 'label:')) {
                $col = trim(substr($part, strlen('label:')));
                $col = preg_replace('/[^A-Za-z0-9_]/', '', $col);

                return $col !== '' ? $col : null;
            }
        }

        return null;
    }

    public function getRelations($tabla)
    {
        $tablaSql = $this->sqlIdentifierTable($tabla);
        $columns = $this->getColumns($tablaSql);

            $relations = [];

        $fks = $this->getOutgoingForeignKeys($tablaSql);
            foreach ($fks as $fk) {
                $relations[$fk['column']] = $fk['referenced_table'];
            }

        foreach ($columns as $col) {
            $field = $col['Field'];

            if (!str_ends_with($field, '_id')) {
                continue;
            }

            $fromComment = $this->extractRelTableFromComment($col['COLUMN_COMMENT'] ?? null);
            if ($fromComment === null && $field === 'empresa_id') {
                $fromComment = 'tercero';
            }
            if ($fromComment !== null) {
                $relations[$field] = $fromComment;
                continue;
            }

            if (!isset($relations[$field])) {
                $relations[$field] = str_replace('_id', '', $field);
            }
        }

        return $relations;
    }

    /**
     * FK internas de empresa (no se cargan como selects del CRUD genérico).
     *
     * @return list<string>
     */
    public function getEmpresaInternalFkFields(): array
    {
        return [
            'tercero_id',
            'terceroidentificacion_id',
            'representante_terceroidentificacion_id',
        ];
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
        $contextTable = $this->resolveCatalogContextTable($contextTable, $fkColumn);
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
        $skipParentNames = ['estado_id', 'empresa_id', 'sede_id', 'created_at', 'created_by', 'updated_at', 'updated_by', 'deleted_at', 'deleted_by'];

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
        $contextTable = $this->resolveCatalogContextTable($contextTable, $fkColumn);
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
        $contextTable = $this->resolveCatalogContextTable($contextTable, $fkColumn);
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

        $fkComment = $this->getTableColumnComment($contextTable, $fkColumn);
        $relTable = $this->extractRelTableFromComment($fkComment) ?? $refTable;
        $bridge = $this->resolveRelationBridge($fkColumn, $relTable, $fkComment);
        if ($bridge !== null) {
            return $this->searchBridgedCatalogOptions($bridge, $fkComment, $qTrim, $limit);
        }

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

        $this->appendEstadoRelTipoFilter($refTable, $fkComment, $where, $params);

        SoftDeleteService::pushWhereNotDeleted($this->pdo, $refTable, $where);

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
     * Filtra opciones de la tabla estado por tipo (estado_tipo.codigo).
     * Ej.: reltipo:GENERAL → solo ACTIVO/INACTIVO; reltipo:PERMISO → PERMITIR/DENEGAR.
     */
    private function extractRelTipoFromColumnComment(?string $comment): ?string
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
            if (str_starts_with(strtolower($part), 'reltipo:')) {
                $codigo = strtoupper(trim(substr($part, strlen('reltipo:'))));
                if ($codigo !== '' && preg_match('/^[A-Z0-9_]+$/', $codigo)) {
                    return $codigo;
                }
            }
        }

        return null;
    }

    /** @var array<string, list<int>> */
    private const RELTIPO_ESTADO_IDS = [
        'GENERAL' => [1, 2],
        'CONTABLE' => [3, 4],
        'PERMISO' => [5, 6],
        'DOCUMENTAL' => [7, 8, 9, 10],
    ];

    /** @var array{table: string, column: string}|false|null */
    private $estadoTipoRelation = null;

    /**
     * @return array{table: string, column: string}|null
     */
    private function resolveEstadoTipoRelation(): ?array
    {
        if ($this->estadoTipoRelation !== null) {
            return $this->estadoTipoRelation === false ? null : $this->estadoTipoRelation;
        }

        $candidates = [
            ['tipoestado', 'tipoestado_id'],
            ['estado_tipo', 'estado_tipo_id'],
        ];

        foreach ($candidates as [$table, $column]) {
            $tableOk = (int)$this->pdo->query("
                SELECT COUNT(*) FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $this->pdo->quote($table) . '
            ')->fetchColumn();

            if ($tableOk === 0) {
                continue;
            }

            $colOk = (int)$this->pdo->query("
                SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'estado'
                  AND COLUMN_NAME = " . $this->pdo->quote($column) . '
            ')->fetchColumn();

            if ($colOk > 0) {
                return $this->estadoTipoRelation = ['table' => $table, 'column' => $column];
            }
        }

        $this->estadoTipoRelation = false;

        return null;
    }

    private function estadoTipoSchemaReady(): bool
    {
        return $this->resolveEstadoTipoRelation() !== null;
    }

    /**
     * @param list<string> $where
     * @param list<scalar> $params
     */
    private function appendEstadoRelTipoFilter(string $refTable, ?string $columnComment, array &$where, array &$params): void
    {
        if ($this->sqlIdentifierTable($refTable) !== 'estado') {
            return;
        }

        $tipoCodigo = $this->extractRelTipoFromColumnComment($columnComment);
        if ($tipoCodigo === null) {
            return;
        }

        $rel = $this->resolveEstadoTipoRelation();
        if ($rel !== null) {
            $t = $this->sqlIdentifierTable($rel['table']);
            $c = $this->sqlIdentifierTable($rel['column']);
            $where[] = '`' . $c . '` = (SELECT `id` FROM `' . $t . '` WHERE `codigo` = ? LIMIT 1)';
            $params[] = $tipoCodigo;

            return;
        }

        $ids = self::RELTIPO_ESTADO_IDS[$tipoCodigo] ?? [];
        if ($ids !== []) {
            $where[] = '`id` IN (' . implode(',', array_map('intval', $ids)) . ')';
        }
    }

    /**
     * @param list<scalar> $params
     */
    private function sqlEstadoRelTipoFilter(?string $tipoCodigo, array &$params): string
    {
        if ($tipoCodigo === null) {
            return '';
        }

        $rel = $this->resolveEstadoTipoRelation();
        if ($rel !== null) {
            $params[] = $tipoCodigo;
            $t = $this->sqlIdentifierTable($rel['table']);
            $c = $this->sqlIdentifierTable($rel['column']);

            return ' AND `' . $c . '` = (SELECT `id` FROM `' . $t . '` WHERE `codigo` = ? LIMIT 1)';
        }

        $ids = self::RELTIPO_ESTADO_IDS[$tipoCodigo] ?? [];
        if ($ids === []) {
            return '';
        }

        return ' AND `id` IN (' . implode(',', array_map('intval', $ids)) . ')';
    }

    private function getTableColumnComment(string $table, string $column): ?string
    {
        $stmt = $this->pdo->prepare('
            SELECT COLUMN_COMMENT
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
            LIMIT 1
        ');
        $stmt->execute([$table, $column]);
        $comment = $stmt->fetchColumn();

        return $comment !== false && $comment !== '' ? (string)$comment : null;
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

    /**
     * FK almacena id de source_table; la etiqueta se lee en display_table vía link_column.
     *
     * @return array{source_table: string, link_column: string, display_table: string}|null
     */
    private function resolveRelationBridge(?string $fkColumn, string $displayTable, ?string $comment): ?array
    {
        if ($fkColumn === null || $fkColumn === '') {
            return null;
        }

        $displayTable = $this->sqlIdentifierTable($displayTable);
        $relFromComment = $this->extractRelTableFromComment($comment);
        if ($relFromComment !== null) {
            $displayTable = $relFromComment;
        }

        $sourceTable = $this->sqlIdentifierTable(str_replace('_id', '', $fkColumn));
        if ($sourceTable === '' || $sourceTable === $displayTable) {
            return null;
        }

        $linkColumn = $displayTable . '_id';
        if (!$this->tableHasColumn($sourceTable, $linkColumn)) {
            return null;
        }

        return [
            'source_table' => $sourceTable,
            'link_column' => $linkColumn,
            'display_table' => $displayTable,
        ];
    }

    /**
     * @return array{displayExpr: string, fieldNames: list<string>}
     */
    private function buildTableDisplayMeta(string $table, ?string $comment, bool $useDispAlias = false): array
    {
        $tableSql = $this->sqlIdentifierTable($table);
        $stmt = $this->pdo->query('SHOW COLUMNS FROM `' . $tableSql . '`');
        $columns = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $fieldNames = array_column($columns, 'Field');

        $displayColumn = $this->extractRelLabelColumnFromComment($comment);
        $preferred = ['nombre', 'razon_social', 'descripcion', 'titulo', 'username', 'email', 'codigo'];

        if ($displayColumn === null) {
        foreach ($preferred as $pref) {
                if (in_array($pref, $fieldNames, true)) {
                    $displayColumn = $pref;
                    break;
                }
            }
        } elseif (!in_array($displayColumn, $fieldNames, true)) {
            $displayColumn = null;
            foreach ($preferred as $pref) {
                if (in_array($pref, $fieldNames, true)) {
                    $displayColumn = $pref;
                    break;
                }
            }
        }

        if ($displayColumn === null) {
            foreach ($fieldNames as $fn) {
                if ($fn !== 'id') {
                    $displayColumn = $fn;
                    break;
                }
            }
        }

        if ($displayColumn === null) {
            return ['displayExpr' => "''", 'fieldNames' => $fieldNames];
        }

        $pfx = $useDispAlias ? 'disp.' : '';
        if ($table === 'tercero' && in_array('nombres', $fieldNames, true) && in_array('apellidos', $fieldNames, true)) {
            $displayExpr = "COALESCE(NULLIF(TRIM(CONCAT_WS(' ', NULLIF(TRIM(COALESCE({$pfx}`nombres`,'')), ''), NULLIF(TRIM(COALESCE({$pfx}`apellidos`,'')), ''))), ''), NULLIF(TRIM(COALESCE({$pfx}`razon_social`,'')), ''), CONCAT('Tercero #', {$pfx}`id`))";
        } else {
            $col = str_replace('`', '', $displayColumn);
            $displayExpr = "{$pfx}`{$col}`";
        }

        return ['displayExpr' => $displayExpr, 'fieldNames' => $fieldNames];
    }

    /**
     * @param array{source_table: string, link_column: string, display_table: string} $bridge
     * @return list<array<string, mixed>>
     */
    private function fetchBridgedRelationData(array $bridge, ?string $comment): array
    {
        $src = $this->sqlIdentifierTable($bridge['source_table']);
        $disp = $this->sqlIdentifierTable($bridge['display_table']);
        $link = preg_replace('/[^A-Za-z0-9_]/', '', $bridge['link_column']);

        $meta = $this->buildTableDisplayMeta($disp, $comment, true);
        $displayExpr = $meta['displayExpr'];

        $sql = "SELECT src.`id` AS id, ({$displayExpr}) AS nombre
            FROM `{$src}` src
            INNER JOIN `{$disp}` disp ON disp.`id` = src.`{$link}`";
        $params = [];

        $filterList = $this->extractRelFilterListFromColumnComment($comment);
        $where = ['1=1'];

        if ($filterList !== null && $filterList !== '') {
            $this->appendRelFilterConditions($filterList, $displayExpr, $where, $params);
        }

        SoftDeleteService::pushWhereNotDeleted($this->pdo, $src, $where, 'src');
        SoftDeleteService::pushWhereNotDeleted($this->pdo, $disp, $where, 'disp');

        $sql .= ' WHERE ' . implode(' AND ', $where) . ' ORDER BY nombre';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array{source_table: string, link_column: string, display_table: string} $bridge
     * @return list<array{id: int, nombre: string}>
     */
    private function searchBridgedCatalogOptions(array $bridge, ?string $comment, string $qTrim, int $limit): array
    {
        $src = $this->sqlIdentifierTable($bridge['source_table']);
        $disp = $this->sqlIdentifierTable($bridge['display_table']);
        $link = preg_replace('/[^A-Za-z0-9_]/', '', $bridge['link_column']);

        $meta = $this->buildTableDisplayMeta($disp, $comment, true);
        $displayExpr = $meta['displayExpr'];

        $where = ['1=1'];
        $params = [];

        if ($qTrim !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $qTrim) . '%';
            $where[] = '(' . $displayExpr . ' LIKE ?)';
            $params[] = $like;
        }

        if ($disp === 'tercero' && $this->tableHasColumn($src, 'estado_id')) {
            $where[] = 'src.`estado_id` = 1';
        }

        SoftDeleteService::pushWhereNotDeleted($this->pdo, $src, $where, 'src');
        SoftDeleteService::pushWhereNotDeleted($this->pdo, $disp, $where, 'disp');

        $sql = "SELECT src.`id` AS id, ({$displayExpr}) AS nombre
            FROM `{$src}` src
            INNER JOIN `{$disp}` disp ON disp.`id` = src.`{$link}`
            WHERE " . implode(' AND ', $where) . "
            ORDER BY ({$displayExpr}) ASC
            LIMIT " . (int)$limit;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = [
                'id' => (int)$row['id'],
                'nombre' => (string)($row['nombre'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @param list<string> $where
     * @param list<scalar> $params
     */
    private function appendRelFilterConditions(string $filterList, string $displayExpr, array &$where, array &$params): void
    {
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
            } elseif ($isExclude) {
                $excludeNames[] = $item;
            } else {
                $includeNames[] = $item;
            }
        }

        $conditions = [];
        if ($includeIds !== []) {
            $conditions[] = 'src.`id` IN (' . implode(',', array_fill(0, count($includeIds), '?')) . ')';
            $params = array_merge($params, $includeIds);
        }
        if ($includeNames !== []) {
            $conditions[] = "({$displayExpr}) IN (" . implode(',', array_fill(0, count($includeNames), '?')) . ')';
            $params = array_merge($params, $includeNames);
        }
        if ($excludeIds !== []) {
            $conditions[] = 'src.`id` NOT IN (' . implode(',', array_fill(0, count($excludeIds), '?')) . ')';
            $params = array_merge($params, $excludeIds);
        }
        if ($excludeNames !== []) {
            $conditions[] = "({$displayExpr}) NOT IN (" . implode(',', array_fill(0, count($excludeNames), '?')) . ')';
            $params = array_merge($params, $excludeNames);
        }

        if ($conditions !== []) {
            $where[] = implode(' AND ', $conditions);
        }
    }

    public function getRelationData($tabla, $column = null, $comment = null)
    {
        $bridge = $this->resolveRelationBridge($column, $tabla, $comment);
        if ($bridge !== null) {
            return $this->fetchBridgedRelationData($bridge, $comment);
        }

        $tablaSql = '`' . $this->sqlIdentifierTable($tabla) . '`';

        $meta = $this->buildTableDisplayMeta($tabla, $comment);
        if ($meta['displayExpr'] === "''") {
            return [];
        }

        $displayExpr = $meta['displayExpr'];
        $fieldNames = $meta['fieldNames'];

        $includeTipo = ($this->sqlIdentifierTable($tabla) === 'zona') && in_array('tipo', $fieldNames, true);
        $tipoSql = $includeTipo ? ', `tipo`' : '';

        $includeCodigoTipodoc = ($this->sqlIdentifierTable($tabla) === 'tipodocumento')
            && in_array('codigo', $fieldNames, true);
        $codigoSql = $includeCodigoTipodoc ? ', `codigo`' : '';

        $sql = "SELECT `id`{$tipoSql}{$codigoSql}, ({$displayExpr}) AS nombre FROM $tablaSql";
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
                $sql .= ' WHERE ' . implode(' AND ', $conditions);
            } else {
                $sql .= ' WHERE 1=1';
            }
        } else {
            $sql .= ' WHERE 1=1';
        }

        $estadoTipoParams = [];
        $estadoTipoSql = '';
        if ($this->sqlIdentifierTable($tabla) === 'estado') {
            $tipoCodigo = $this->extractRelTipoFromColumnComment($comment);
            $estadoTipoSql = $this->sqlEstadoRelTipoFilter($tipoCodigo, $estadoTipoParams);
        }

        if (SoftDeleteService::supports($this->pdo, $tabla)) {
            $sql .= SoftDeleteService::sqlAndNotDeleted($this->pdo, $tabla);
        }

        $sql .= $estadoTipoSql;
        $params = array_merge($params, $estadoTipoParams);

        $sql .= ' ORDER BY nombre';

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

    /**
     * Operadores sin alcance global deben tener empresa en sesión antes de crear usuarios.
     *
     * @throws Exception
     */
    private function assertUsuarioCreateAllowedInSessionScope(): void
    {
        $validator = new UsuarioFormValidationService($this->pdo);
        if ($validator->isSuperAdminViewer()) {
            return;
        }

        $eid = $_SESSION['empresa_id'] ?? null;
        if ($eid === null || $eid === '') {
            UsuarioSaveMessages::throwJson([
                'general' => 'Seleccione empresa y sede en el contexto (arriba a la derecha) antes de crear usuarios.',
            ]);
        }
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