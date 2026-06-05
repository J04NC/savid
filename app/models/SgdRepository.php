<?php

class SgdRepository
{
    /** Estado documental VIGENTE (tabla estado, tipo DOCUMENTAL). */
    public const ESTADO_DOC_VIGENTE = 8;

    /** @var list<int> */
    private const ESTADO_DOC_IDS = [7, 8, 9, 10];

    private PDO $pdo;
    private ?string $documentoCodeColumn = null;

    public function __construct(?PDO $pdo = null)
    {
        if ($pdo === null) {
            $pdo = (new Database())->connect();
        }
        $this->pdo = $pdo;
    }

    public function findConfigByEmpresaId(int $empresaId): ?array
    {
        $nd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'sgd_empresa_config');

        $stmt = $this->pdo->prepare("
            SELECT *
            FROM sgd_empresa_config
            WHERE empresa_id = ?
            {$nd}
            LIMIT 1
        ");
        $stmt->execute([$empresaId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function upsertConfig(int $empresaId, array $data): void
    {
        $existing = $this->findConfigByEmpresaId($empresaId);

        if ($existing) {
            $stmt = $this->pdo->prepare('
                UPDATE sgd_empresa_config
                SET sgd_activo = ?, patron_documento = ?, patron_carpeta = ?,
                    ccd_vigencia = ?, ccd_anio = ?, config_json = ?, updated_at = NOW(3)
                WHERE id = ?
            ');
            $stmt->execute([
                (int)($data['sgd_activo'] ?? 0),
                $data['patron_documento'] ?? null,
                $data['patron_carpeta'] ?? null,
                $data['ccd_vigencia'] ?? null,
                $data['ccd_anio'] ?? null,
                $data['config_json'] ?? null,
                $existing['id'],
            ]);

            return;
        }

        $stmt = $this->pdo->prepare('
            INSERT INTO sgd_empresa_config (
                empresa_id, sgd_activo, patron_documento, patron_carpeta,
                ccd_vigencia, ccd_anio, config_json, estado_id, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW(3))
        ');
        $stmt->execute([
            $empresaId,
            (int)($data['sgd_activo'] ?? 0),
            $data['patron_documento'] ?? null,
            $data['patron_carpeta'] ?? null,
            $data['ccd_vigencia'] ?? null,
            $data['ccd_anio'] ?? null,
            $data['config_json'] ?? null,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listTiposByEmpresa(int $empresaId): array
    {
        $nd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'sgd_tipo_documental');
        $stmt = $this->pdo->prepare("
            SELECT * FROM sgd_tipo_documental
            WHERE empresa_id = ? {$nd}
            ORDER BY orden, codigo
        ");
        $stmt->execute([$empresaId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<string, array<string, mixed>> codigo => row
     */
    public function mapTiposByCodigo(int $empresaId): array
    {
        $map = [];
        foreach ($this->listTiposByEmpresa($empresaId) as $row) {
            $map[strtoupper((string)$row['codigo'])] = $row;
        }

        return $map;
    }

    public function findTipoDocumentalById(int $empresaId, int $id): ?array
    {
        $nd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'sgd_tipo_documental');
        $stmt = $this->pdo->prepare("
            SELECT id, empresa_id, codigo, nombre, modo, orden
            FROM sgd_tipo_documental
            WHERE empresa_id = ? AND id = ? {$nd}
            LIMIT 1
        ");
        $stmt->execute([$empresaId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @return list<int>
     */
    public function listTiposPadrePermitidosIds(int $empresaId, int $tipoDocumentalId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT tipo_padre_id
            FROM sgd_tipo_documental_padre
            WHERE empresa_id = ? AND tipo_documental_id = ? AND estado_id = 1
            ORDER BY tipo_padre_id
        ');
        $stmt->execute([$empresaId, $tipoDocumentalId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /**
     * @return array<int, list<int>> tipo hijo => tipos padre permitidos
     */
    public function listTiposPadrePermitidosMap(int $empresaId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT tipo_documental_id, tipo_padre_id
            FROM sgd_tipo_documental_padre
            WHERE empresa_id = ? AND estado_id = 1
            ORDER BY tipo_documental_id, tipo_padre_id
        ');
        $stmt->execute([$empresaId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $map = [];
        foreach ($rows as $row) {
            $hijo = (int)$row['tipo_documental_id'];
            $map[$hijo][] = (int)$row['tipo_padre_id'];
        }

        return $map;
    }

    public function isTipoPadrePermitidoForHijo(int $empresaId, int $tipoHijoId, int $tipoPadreId): bool
    {
        if ($tipoHijoId <= 0 || $tipoPadreId <= 0) {
            return false;
        }

        $allowed = $this->listTiposPadrePermitidosIds($empresaId, $tipoHijoId);

        return $allowed !== [] && in_array($tipoPadreId, $allowed, true);
    }

    /**
     * @param list<int> $padreTipoIds
     */
    public function replaceTiposPadrePermitidos(int $empresaId, int $tipoDocumentalId, array $padreTipoIds): void
    {
        $this->pdo->beginTransaction();
        try {
            $del = $this->pdo->prepare('
                DELETE FROM sgd_tipo_documental_padre
                WHERE empresa_id = ? AND tipo_documental_id = ?
            ');
            $del->execute([$empresaId, $tipoDocumentalId]);

            if ($padreTipoIds !== []) {
                $ins = $this->pdo->prepare('
                    INSERT INTO sgd_tipo_documental_padre
                        (empresa_id, tipo_documental_id, tipo_padre_id, estado_id, created_at, created_by)
                    VALUES (?, ?, ?, 1, NOW(3), ?)
                ');
                $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
                foreach ($padreTipoIds as $padreId) {
                    $ins->execute([$empresaId, $tipoDocumentalId, (int)$padreId, $userId]);
                }
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function findProcesoId(int $empresaId, string $codigo): ?int
    {
        $nd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'sgd_proceso');
        $stmt = $this->pdo->prepare("
            SELECT id FROM sgd_proceso
            WHERE empresa_id = ? AND codigo = ? {$nd}
            LIMIT 1
        ");
        $stmt->execute([$empresaId, $codigo]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int)$id : null;
    }

    public function findTipoprocesoIdByLabel(int $empresaId, ?string $label): ?int
    {
        $label = trim((string)$label);
        if ($label === '' || $empresaId < 1) {
            return null;
        }

        $norm = strtoupper(preg_replace('/\s+/', ' ', $label));
        $stmt = $this->pdo->prepare('
            SELECT id FROM tipoproceso
            WHERE empresa_id = ?
              AND (UPPER(TRIM(nombre)) = ? OR UPPER(TRIM(codigo)) = ?)
            LIMIT 1
        ');
        $stmt->execute([$empresaId, $norm, $norm]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int)$id : null;
    }

    public function insertProceso(
        int $empresaId,
        string $codigo,
        string $nombre,
        ?int $tipoprocesoId = null
    ): int {
        $stmt = $this->pdo->prepare('
            INSERT INTO sgd_proceso (empresa_id, codigo, nombre, tipoproceso_id, estado_id, created_at)
            VALUES (?, ?, ?, ?, 1, NOW(3))
        ');
        $stmt->execute([$empresaId, $codigo, $nombre, $tipoprocesoId]);

        return (int)$this->pdo->lastInsertId();
    }

    public function findDependenciaId(int $empresaId, string $codigo): ?int
    {
        $nd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'sgd_dependencia');
        $stmt = $this->pdo->prepare("
            SELECT id FROM sgd_dependencia
            WHERE empresa_id = ? AND codigo = ? {$nd}
            LIMIT 1
        ");
        $stmt->execute([$empresaId, $codigo]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int)$id : null;
    }

    public function insertDependencia(int $empresaId, string $codigo, string $nombre): int
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO sgd_dependencia (empresa_id, codigo, nombre, estado_id, created_at)
            VALUES (?, ?, ?, 1, NOW(3))
        ');
        $stmt->execute([$empresaId, $codigo, $nombre]);

        return (int)$this->pdo->lastInsertId();
    }

    public function findSerieId(int $empresaId, string $codigo): ?int
    {
        $nd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'sgd_serie');
        $stmt = $this->pdo->prepare("
            SELECT id FROM sgd_serie
            WHERE empresa_id = ? AND codigo = ? {$nd}
            LIMIT 1
        ");
        $stmt->execute([$empresaId, $codigo]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int)$id : null;
    }

    public function insertSerie(int $empresaId, string $codigo, string $nombre): int
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO sgd_serie (empresa_id, codigo, nombre, estado_id, created_at)
            VALUES (?, ?, ?, 1, NOW(3))
        ');
        $stmt->execute([$empresaId, $codigo, $nombre]);

        return (int)$this->pdo->lastInsertId();
    }

    public function updateSerieNombre(int $serieId, string $nombre): void
    {
        $stmt = $this->pdo->prepare('
            UPDATE sgd_serie SET nombre = ?, updated_at = NOW(3) WHERE id = ?
        ');
        $stmt->execute([$nombre, $serieId]);
    }

    public function updateSubserieNombre(int $subserieId, string $nombre): void
    {
        $stmt = $this->pdo->prepare('
            UPDATE sgd_subserie SET nombre = ?, updated_at = NOW(3) WHERE id = ?
        ');
        $stmt->execute([$nombre, $subserieId]);
    }

    public function findSubserieId(int $serieId, string $codigo): ?int
    {
        $nd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'sgd_subserie');
        $stmt = $this->pdo->prepare("
            SELECT id FROM sgd_subserie
            WHERE serie_id = ? AND codigo = ? {$nd}
            LIMIT 1
        ");
        $stmt->execute([$serieId, $codigo]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int)$id : null;
    }

    public function insertSubserie(int $empresaId, int $serieId, string $codigo, string $nombre): int
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO sgd_subserie (empresa_id, serie_id, codigo, nombre, estado_id, created_at)
            VALUES (?, ?, ?, ?, 1, NOW(3))
        ');
        $stmt->execute([$empresaId, $serieId, $codigo, $nombre]);

        return (int)$this->pdo->lastInsertId();
    }

    public function findDocumentoId(int $empresaId, string $codigo): ?int
    {
        $nd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'sgd_documento');
        $codeColumn = $this->getDocumentoCodeColumn();
        $stmt = $this->pdo->prepare("
            SELECT id FROM sgd_documento
            WHERE empresa_id = ? AND {$codeColumn} = ? {$nd}
            LIMIT 1
        ");
        $stmt->execute([$empresaId, $codigo]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int)$id : null;
    }

    /**
     * Resuelve código SGC del CCD (ej. GE-PD3-F1) al documento del listado maestro.
     */
    public function findDocumentoIdByCodigoCalidad(int $empresaId, string $codigoCalidad): ?int
    {
        $codigoCalidad = strtoupper(trim($codigoCalidad));
        if ($codigoCalidad === '') {
            return null;
        }

        $direct = $this->findDocumentoId($empresaId, $codigoCalidad);
        if ($direct) {
            return $direct;
        }

        $tipoCodigos = array_map(
            static fn(array $t) => (string)$t['codigo'],
            $this->listTiposByEmpresa($empresaId)
        );
        if ($tipoCodigos === []) {
            return null;
        }

        $parser = new SgdCodigoParserService();
        $parsed = $parser->parse($codigoCalidad, $tipoCodigos);
        if ($parsed['proceso'] === '' || $parsed['tipo'] === null || $parsed['numero'] === null) {
            return null;
        }

        $ndP = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'sgd_proceso', 'p');
        $stmt = $this->pdo->prepare("
            SELECT p.id FROM sgd_proceso p
            WHERE p.empresa_id = ? AND p.codigo = ? {$ndP}
            LIMIT 1
        ");
        $stmt->execute([$empresaId, $parsed['proceso']]);
        $procesoId = $stmt->fetchColumn();
        if ($procesoId === false) {
            return null;
        }
        $procesoId = (int)$procesoId;

        $tipoMap = $this->mapTiposByCodigo($empresaId);
        $tipoPadre = strtoupper($parsed['tipo']);
        if (!isset($tipoMap[$tipoPadre])) {
            return null;
        }
        $tipoPadreId = (int)$tipoMap[$tipoPadre]['id'];

        $ndD = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'sgd_documento', 'd');
        $stmt = $this->pdo->prepare("
            SELECT d.id FROM sgd_documento d
            WHERE d.empresa_id = ? AND d.proceso_id = ?
              AND d.tipo_documental_id = ? AND d.consecutivo = ?
              AND (d.documento_id IS NULL OR d.documento_id = 0)
            {$ndD}
            LIMIT 1
        ");
        $stmt->execute([$empresaId, $procesoId, $tipoPadreId, (string)$parsed['numero']]);
        $padreId = $stmt->fetchColumn();

        $sufijo = trim((string)($parsed['sufijo'] ?? ''));
        if ($sufijo !== '' && preg_match('/^([A-Z]+)(\d+)$/i', $sufijo, $sm)) {
            if ($padreId === false) {
                return null;
            }
            $childTipo = strtoupper($sm[1]);
            if (!isset($tipoMap[$childTipo])) {
                return null;
            }
            $stmt = $this->pdo->prepare("
                SELECT d.id FROM sgd_documento d
                WHERE d.empresa_id = ? AND d.proceso_id = ?
                  AND d.documento_id = ? AND d.tipo_documental_id = ?
                  AND d.consecutivo = ?
                {$ndD}
                LIMIT 1
            ");
            $stmt->execute([
                $empresaId,
                $procesoId,
                (int)$padreId,
                (int)$tipoMap[$childTipo]['id'],
                $sm[2],
            ]);
            $childId = $stmt->fetchColumn();

            return $childId !== false ? (int)$childId : null;
        }

        return $padreId !== false ? (int)$padreId : null;
    }

    public function upsertDocumento(int $empresaId, array $row): int
    {
        $existingId = $this->findDocumentoId($empresaId, $row['codigo']);
        $codeColumn = $this->getDocumentoCodeColumn();

        if ($existingId) {
            $stmt = $this->pdo->prepare('
                UPDATE sgd_documento
                SET nombre = ?, proceso_id = ?, tipo_documental_id = ?,
                    version_actual = ?, fecha_primera_aprobacion = ?,
                    fecha_ultima_aprobacion = ?, updated_at = NOW(3)
                WHERE id = ?
            ');
            $stmt->execute([
                $row['nombre'],
                $row['proceso_id'] ?? null,
                $row['tipo_documental_id'] ?? null,
                $row['version_actual'] ?? null,
                $row['fecha_primera_aprobacion'] ?? null,
                $row['fecha_ultima_aprobacion'] ?? null,
                $existingId,
            ]);

            return $existingId;
        }

        $stmt = $this->pdo->prepare('
            INSERT INTO sgd_documento (
                empresa_id, ' . $codeColumn . ', nombre, proceso_id, tipo_documental_id,
                version_actual, fecha_primera_aprobacion, fecha_ultima_aprobacion,
                estado_id, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(3))
        ');
        $stmt->execute([
            $empresaId,
            $row['codigo'],
            $row['nombre'],
            $row['proceso_id'] ?? null,
            $row['tipo_documental_id'] ?? null,
            $row['version_actual'] ?? null,
            $row['fecha_primera_aprobacion'] ?? null,
            $row['fecha_ultima_aprobacion'] ?? null,
            $this->normalizeEstadoDocumentalId(isset($row['estado_id']) ? (int)$row['estado_id'] : null),
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function linkDocumentoToCcdByCodigoCalidad(int $empresaId, string $codigoCalidad): int
    {
        $docId = $this->findDocumentoIdByCodigoCalidad($empresaId, $codigoCalidad);
        if (!$docId) {
            return 0;
        }

        if ($this->ccdEntradaHasColumn('codigo_calidad')) {
            $stmt = $this->pdo->prepare('
                UPDATE sgd_ccd_entrada
                SET documento_id = ?
                WHERE empresa_id = ? AND codigo_calidad = ? AND (documento_id IS NULL OR documento_id = 0)
            ');
            $stmt->execute([$docId, $empresaId, $codigoCalidad]);

            return $stmt->rowCount();
        }

        return 0;
    }

    public function linkAllDocumentosCcd(int $empresaId): int
    {
        if ($this->ccdEntradaHasColumn('codigo_calidad')) {
            $codeColumn = $this->getDocumentoCodeColumn();
            $stmt = $this->pdo->prepare('
                UPDATE sgd_ccd_entrada e
                INNER JOIN sgd_documento d
                    ON d.empresa_id = e.empresa_id
                   AND d.' . $codeColumn . ' = e.codigo_calidad
                   AND d.deleted_at IS NULL
                SET e.documento_id = d.id
                WHERE e.empresa_id = ?
                  AND e.codigo_calidad IS NOT NULL
                  AND TRIM(e.codigo_calidad) <> ""
                  AND (e.documento_id IS NULL OR e.documento_id = 0)
            ');
            $stmt->execute([$empresaId]);

            return $stmt->rowCount();
        }

        return 0;
    }

    private function getDocumentoCodeColumn(): string
    {
        if ($this->documentoCodeColumn !== null) {
            return $this->documentoCodeColumn;
        }

        $stmt = $this->pdo->query("SHOW COLUMNS FROM sgd_documento LIKE 'consecutivo'");
        $hasConsecutivo = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
        $this->documentoCodeColumn = $hasConsecutivo ? 'consecutivo' : 'codigo';

        return $this->documentoCodeColumn;
    }

    public function insertCcdEntrada(int $empresaId, array $row): int
    {
        $data = [
            'dependencia_id' => (int)$row['dependencia_id'],
            'serie_id' => $row['serie_id'] ?? null,
            'subserie_id' => $row['subserie_id'] ?? null,
            'documento_id' => $row['documento_id'] ?? null,
            'orden' => (int)($row['orden'] ?? 0),
            'estado_id' => (int)($row['estado_id'] ?? 1),
        ];
        foreach (['codigo_carpeta', 'nombre_serie', 'nombre_subserie', 'soporte_formato', 'codigo_calidad', 'ccd_vigencia', 'ccd_anio'] as $col) {
            if (array_key_exists($col, $row)) {
                $data[$col] = $row[$col];
            }
        }

        return $this->saveCcdEntrada($empresaId, $data);
    }

    public function ccdEntradaExists(int $empresaId, string $codigoCarpeta, ?string $codigoCalidad, ?string $nombreSubserie): bool
    {
        if ($this->ccdEntradaHasColumn('codigo_carpeta')) {
            $nd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'sgd_ccd_entrada');
            $stmt = $this->pdo->prepare("
                SELECT 1 FROM sgd_ccd_entrada
                WHERE empresa_id = ? AND codigo_carpeta = ?
                  AND COALESCE(codigo_calidad, '') = COALESCE(?, '')
                  AND COALESCE(nombre_subserie, '') = COALESCE(?, '')
                {$nd}
                LIMIT 1
            ");
            $stmt->execute([$empresaId, $codigoCarpeta, $codigoCalidad, $nombreSubserie]);

            return (bool)$stmt->fetchColumn();
        }

        return false;
    }

    /**
     * @return list<string> nombres de archivo CCD por dependencia (excluye GENERAL y listado maestro).
     */
    public function listCcdDependenciaFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $out = [];
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            if (!in_array($ext, ['xls', 'xlsx'], true)) {
                continue;
            }
            $upper = strtoupper($f);
            if (!str_contains($upper, 'CCD')) {
                continue;
            }
            if (str_contains($upper, 'GENERAL') || str_contains($upper, 'LISTADO MAESTRO')) {
                continue;
            }
            $out[] = $f;
        }
        sort($out);

        return $out;
    }

    public function insertTipoDocumental(int $empresaId, array $tipo): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO sgd_tipo_documental (empresa_id, codigo, nombre, modo, orden, estado_id, created_at)
            VALUES (?, ?, ?, ?, ?, 1, NOW(3))
            ON DUPLICATE KEY UPDATE
                nombre = VALUES(nombre),
                modo = VALUES(modo),
                orden = VALUES(orden),
                deleted_at = NULL,
                deleted_by = NULL,
                updated_at = NOW(3)
        ');
        $stmt->execute([
            $empresaId,
            $tipo['codigo'],
            $tipo['nombre'],
            $tipo['modo'] ?? 'dinamico',
            (int)($tipo['orden'] ?? 0),
        ]);
    }

    public function insertImportLog(int $empresaId, string $tipo, ?string $archivo, array $resumen): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO sgd_import_log (empresa_id, tipo, archivo, resumen_json, usuario_id)
            VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $empresaId,
            $tipo,
            $archivo,
            json_encode($resumen, JSON_UNESCAPED_UNICODE),
            isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null,
        ]);
    }

    /**
     * @return array{procesos: int, dependencias: int, series: int, subseries: int, documentos: int, ccd: int}
     */
    /**
     * @return list<array<string, mixed>>
     */
    public function listProcesosByEmpresa(int $empresaId): array
    {
        $nd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'sgd_proceso', 'p');
        $stmt = $this->pdo->prepare("
            SELECT p.id, p.codigo, p.nombre, p.tipoproceso_id, tp.nombre AS tipoproceso_nombre
            FROM sgd_proceso p
            LEFT JOIN tipoproceso tp ON tp.id = p.tipoproceso_id
            WHERE p.empresa_id = ? {$nd}
            ORDER BY p.orden, p.codigo
        ");
        $stmt->execute([$empresaId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listLineasDocumentales(int $empresaId): array
    {
        $nd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'sgd_linea_documental');
        $stmt = $this->pdo->prepare("
            SELECT id, codigo, nombre
            FROM sgd_linea_documental
            WHERE empresa_id = ? {$nd}
            ORDER BY orden, codigo
        ");
        $stmt->execute([$empresaId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listDocumentosForSelect(int $empresaId, ?int $excludeId = null): array
    {
        $nd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'sgd_documento', 'd');
        $sql = "
            SELECT
                d.id,
                d.documento_id,
                d.proceso_id,
                d.tipo_documental_id,
                d.linea_documental_id,
                d.consecutivo,
                d.nombre,
                p.codigo AS proceso_codigo,
                t.codigo AS tipo_codigo,
                ld.codigo AS linea_codigo
            FROM sgd_documento d
            LEFT JOIN sgd_proceso p ON p.id = d.proceso_id
            LEFT JOIN sgd_tipo_documental t ON t.id = d.tipo_documental_id
            LEFT JOIN sgd_linea_documental ld ON ld.id = d.linea_documental_id
            WHERE d.empresa_id = ? {$nd}
        ";
        $params = [$empresaId];
        if ($excludeId !== null && $excludeId > 0) {
            $sql .= ' AND d.id <> ?';
            $params[] = $excludeId;
        }
        $sql .= ' ORDER BY p.codigo, t.codigo, d.consecutivo, d.nombre';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listDocumentos(int $empresaId, ?string $search = null, int $limit = 1000, int $offset = 0): array
    {
        $nd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'sgd_documento', 'd');
        $where = "d.empresa_id = ? {$nd}";
        $params = [$empresaId];

        if ($search !== null && trim($search) !== '') {
            $where .= ' AND (d.nombre LIKE ? OR d.consecutivo LIKE ? OR p.codigo LIKE ? OR t.codigo LIKE ? OR ld.codigo LIKE ?)';
            $like = '%' . trim($search) . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }

        $sql = "
            SELECT
                d.id,
                d.empresa_id,
                d.proceso_id,
                d.documento_id,
                d.tipo_documental_id,
                d.linea_documental_id,
                d.consecutivo,
                d.nombre,
                d.modo,
                d.version_actual,
                d.estado_id,
                e.nombre AS estado_nombre,
                p.codigo AS proceso_codigo,
                p.nombre AS proceso_nombre,
                t.codigo AS tipo_codigo,
                t.nombre AS tipo_nombre,
                ld.codigo AS linea_codigo,
                ld.nombre AS linea_nombre,
                pad.consecutivo AS padre_consecutivo,
                pad.nombre AS padre_nombre
            FROM sgd_documento d
            LEFT JOIN estado e ON e.id = d.estado_id
            LEFT JOIN sgd_proceso p ON p.id = d.proceso_id
            LEFT JOIN sgd_tipo_documental t ON t.id = d.tipo_documental_id
            LEFT JOIN sgd_linea_documental ld ON ld.id = d.linea_documental_id
            LEFT JOIN sgd_documento pad ON pad.id = d.documento_id
            WHERE {$where}
            ORDER BY d.id ASC
            LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function countDocumentos(int $empresaId, ?string $search = null): int
    {
        $nd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'sgd_documento', 'd');
        $where = "d.empresa_id = ? {$nd}";
        $params = [$empresaId];

        if ($search !== null && trim($search) !== '') {
            $where .= ' AND (d.nombre LIKE ? OR d.consecutivo LIKE ? OR p.codigo LIKE ? OR t.codigo LIKE ? OR ld.codigo LIKE ?)';
            $like = '%' . trim($search) . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }

        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM sgd_documento d
            LEFT JOIN sgd_proceso p ON p.id = d.proceso_id
            LEFT JOIN sgd_tipo_documental t ON t.id = d.tipo_documental_id
            LEFT JOIN sgd_linea_documental ld ON ld.id = d.linea_documental_id
            WHERE {$where}
        ");
        $stmt->execute($params);

        return (int)$stmt->fetchColumn();
    }

    public function findDocumentoById(int $empresaId, int $id): ?array
    {
        $nd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'sgd_documento', 'd');
        $stmt = $this->pdo->prepare("
            SELECT
                d.*,
                p.codigo AS proceso_codigo,
                p.nombre AS proceso_nombre,
                t.codigo AS tipo_codigo,
                t.nombre AS tipo_nombre,
                ld.codigo AS linea_codigo,
                ld.nombre AS linea_nombre
            FROM sgd_documento d
            LEFT JOIN sgd_proceso p ON p.id = d.proceso_id
            LEFT JOIN sgd_tipo_documental t ON t.id = d.tipo_documental_id
            LEFT JOIN sgd_linea_documental ld ON ld.id = d.linea_documental_id
            WHERE d.empresa_id = ? AND d.id = ? {$nd}
            LIMIT 1
        ");
        $stmt->execute([$empresaId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function documentoUniqueExists(
        int $empresaId,
        ?int $procesoId,
        ?int $documentoId,
        ?int $tipoDocumentalId,
        string $consecutivo,
        ?int $excludeId = null
    ): bool {
        $nd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'sgd_documento');
        $sql = "
            SELECT 1 FROM sgd_documento
            WHERE empresa_id = ?
              AND COALESCE(proceso_id, 0) = COALESCE(?, 0)
              AND COALESCE(documento_id, 0) = COALESCE(?, 0)
              AND COALESCE(tipo_documental_id, 0) = COALESCE(?, 0)
              AND consecutivo = ?
              {$nd}
        ";
        $params = [$empresaId, $procesoId, $documentoId, $tipoDocumentalId, $consecutivo];
        if ($excludeId !== null && $excludeId > 0) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeId;
        }
        $sql .= ' LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (bool)$stmt->fetchColumn();
    }

    /**
     * Clave de ámbito para consecutivo (proceso|padre|tipo|línea).
     */
    public function makeConsecutivoScopeKey(?int $procesoId, ?int $documentoId, ?int $tipoId, ?int $lineaId): string
    {
        return implode('|', [
            (int)($procesoId ?? 0),
            (int)($documentoId ?? 0),
            (int)($tipoId ?? 0),
            (int)($lineaId ?? 0),
        ]);
    }

    /**
     * @return array<string, int> máximo consecutivo numérico por ámbito
     */
    public function buildConsecutivoMaxIndex(int $empresaId): array
    {
        $nd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'sgd_documento', 'd');
        $stmt = $this->pdo->prepare("
            SELECT d.proceso_id, d.documento_id, d.tipo_documental_id, d.linea_documental_id, d.consecutivo
            FROM sgd_documento d
            WHERE d.empresa_id = ? {$nd}
        ");
        $stmt->execute([$empresaId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $index = [];
        foreach ($rows as $row) {
            $num = $this->parseNumericConsecutivo((string)($row['consecutivo'] ?? ''));
            if ($num === null) {
                continue;
            }
            $key = $this->makeConsecutivoScopeKey(
                isset($row['proceso_id']) ? (int)$row['proceso_id'] : null,
                isset($row['documento_id']) && $row['documento_id'] !== null ? (int)$row['documento_id'] : null,
                isset($row['tipo_documental_id']) ? (int)$row['tipo_documental_id'] : null,
                isset($row['linea_documental_id']) && $row['linea_documental_id'] !== null ? (int)$row['linea_documental_id'] : null
            );
            if ($num > ($index[$key] ?? 0)) {
                $index[$key] = $num;
            }
        }

        return $index;
    }

    public function getNextConsecutivo(
        int $empresaId,
        ?int $procesoId,
        ?int $documentoId,
        ?int $tipoId,
        ?int $lineaId,
        ?array $index = null
    ): string {
        $key = $this->makeConsecutivoScopeKey($procesoId, $documentoId, $tipoId, $lineaId);
        if ($index === null) {
            $index = $this->buildConsecutivoMaxIndex($empresaId);
        }

        return (string)(($index[$key] ?? 0) + 1);
    }

    private function parseNumericConsecutivo(string $raw): ?int
    {
        $raw = trim($raw);
        if ($raw === '' || !ctype_digit($raw)) {
            return null;
        }

        return (int)$raw;
    }

    public function saveDocumento(int $empresaId, array $data): int
    {
        $id = (int)($data['id'] ?? 0);
        $fields = [
            'proceso_id' => $data['proceso_id'] ?? null,
            'documento_id' => $data['documento_id'] ?? null,
            'tipo_documental_id' => $data['tipo_documental_id'] ?? null,
            'linea_documental_id' => $data['linea_documental_id'] ?? null,
            'consecutivo' => trim((string)($data['consecutivo'] ?? '')),
            'nombre' => trim((string)($data['nombre'] ?? '')),
            'modo' => $data['modo'] ?? null,
            'version_actual' => $data['version_actual'] ?? null,
            'fecha_primera_aprobacion' => $data['fecha_primera_aprobacion'] ?? null,
            'fecha_ultima_aprobacion' => $data['fecha_ultima_aprobacion'] ?? null,
            'estado_id' => $this->normalizeEstadoDocumentalId(
                isset($data['estado_id']) ? (int)$data['estado_id'] : null
            ),
        ];

        if ($id > 0) {
            $stmt = $this->pdo->prepare('
                UPDATE sgd_documento
                SET proceso_id = ?, documento_id = ?, tipo_documental_id = ?, linea_documental_id = ?,
                    consecutivo = ?, nombre = ?, modo = ?, version_actual = ?,
                    fecha_primera_aprobacion = ?, fecha_ultima_aprobacion = ?,
                    estado_id = ?, updated_at = NOW(3)
                WHERE id = ? AND empresa_id = ?
            ');
            $stmt->execute([
                $fields['proceso_id'],
                $fields['documento_id'],
                $fields['tipo_documental_id'],
                $fields['linea_documental_id'],
                $fields['consecutivo'],
                $fields['nombre'],
                $fields['modo'],
                $fields['version_actual'],
                $fields['fecha_primera_aprobacion'],
                $fields['fecha_ultima_aprobacion'],
                $fields['estado_id'],
                $id,
                $empresaId,
            ]);

            return $id;
        }

        $stmt = $this->pdo->prepare('
            INSERT INTO sgd_documento (
                empresa_id, proceso_id, documento_id, tipo_documental_id, linea_documental_id,
                consecutivo, nombre, modo, version_actual,
                fecha_primera_aprobacion, fecha_ultima_aprobacion,
                estado_id, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(3))
        ');
        $stmt->execute([
            $empresaId,
            $fields['proceso_id'],
            $fields['documento_id'],
            $fields['tipo_documental_id'],
            $fields['linea_documental_id'],
            $fields['consecutivo'],
            $fields['nombre'],
            $fields['modo'],
            $fields['version_actual'],
            $fields['fecha_primera_aprobacion'],
            $fields['fecha_ultima_aprobacion'],
            $fields['estado_id'],
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * @return list<array{id: int, nombre: string}>
     */
    public function listEstadosDocumentales(): array
    {
        $ids = implode(',', self::ESTADO_DOC_IDS);
        $stmt = $this->pdo->query("
            SELECT id, nombre
            FROM estado
            WHERE id IN ({$ids})
            ORDER BY id ASC
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function normalizeEstadoDocumentalId(?int $estadoId): int
    {
        if ($estadoId !== null && in_array($estadoId, self::ESTADO_DOC_IDS, true)) {
            return $estadoId;
        }

        return self::ESTADO_DOC_VIGENTE;
    }

    public function softDeleteDocumento(int $empresaId, int $id, ?int $userId = null): bool
    {
        if (!SoftDeleteService::supports($this->pdo, 'sgd_documento')) {
            $stmt = $this->pdo->prepare('DELETE FROM sgd_documento WHERE id = ? AND empresa_id = ?');

            return $stmt->execute([$id, $empresaId]);
        }

        $stmt = $this->pdo->prepare('
            UPDATE sgd_documento
            SET deleted_at = NOW(3), deleted_by = ?
            WHERE id = ? AND empresa_id = ? AND deleted_at IS NULL
        ');

        return $stmt->execute([$userId, $id, $empresaId]);
    }

    public function countDocumentoHijos(int $empresaId, int $documentoId): int
    {
        $nd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'sgd_documento');
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM sgd_documento
            WHERE empresa_id = ? AND documento_id = ? {$nd}
        ");
        $stmt->execute([$empresaId, $documentoId]);

        return (int)$stmt->fetchColumn();
    }

    public function countCatalog(int $empresaId): array
    {
        $tables = [
            'procesos' => 'sgd_proceso',
            'dependencias' => 'sgd_dependencia',
            'documentos' => 'sgd_documento',
            'ccd' => 'sgd_ccd_entrada',
        ];
        $out = ['series' => 0, 'subseries' => 0];

        foreach ($tables as $key => $table) {
            $nd = SoftDeleteService::sqlAndNotDeleted($this->pdo, $table);
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE empresa_id = ? {$nd}");
            $stmt->execute([$empresaId]);
            $out[$key] = (int)$stmt->fetchColumn();
        }

        $nd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'sgd_serie');
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM sgd_serie WHERE empresa_id = ? {$nd}");
        $stmt->execute([$empresaId]);
        $out['series'] = (int)$stmt->fetchColumn();

        $nd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'sgd_subserie');
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM sgd_subserie WHERE empresa_id = ? {$nd}");
        $stmt->execute([$empresaId]);
        $out['subseries'] = (int)$stmt->fetchColumn();

        return $out;
    }

    /** @var list<string>|null */
    private ?array $ccdEntradaColumns = null;

    public function ccdEntradaHasColumn(string $column): bool
    {
        if ($this->ccdEntradaColumns === null) {
            $stmt = $this->pdo->query('SHOW COLUMNS FROM sgd_ccd_entrada');
            $this->ccdEntradaColumns = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
        }

        return in_array($column, $this->ccdEntradaColumns, true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listDependenciasByEmpresa(int $empresaId): array
    {
        $nd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'sgd_dependencia');
        $stmt = $this->pdo->prepare("
            SELECT id, codigo, nombre
            FROM sgd_dependencia
            WHERE empresa_id = ? {$nd}
            ORDER BY codigo
        ");
        $stmt->execute([$empresaId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function findDependenciaById(int $empresaId, int $id): ?array
    {
        $nd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'sgd_dependencia');
        $stmt = $this->pdo->prepare("
            SELECT id, codigo, nombre FROM sgd_dependencia
            WHERE empresa_id = ? AND id = ? {$nd}
            LIMIT 1
        ");
        $stmt->execute([$empresaId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function dependenciaBelongsToEmpresa(int $empresaId, int $dependenciaId): bool
    {
        return $this->findDependenciaById($empresaId, $dependenciaId) !== null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listSeriesByEmpresa(int $empresaId): array
    {
        $nd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'sgd_serie');
        $stmt = $this->pdo->prepare("
            SELECT id, codigo, nombre
            FROM sgd_serie
            WHERE empresa_id = ? {$nd}
            ORDER BY codigo
        ");
        $stmt->execute([$empresaId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function findSerieById(int $empresaId, int $id): ?array
    {
        $nd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'sgd_serie');
        $stmt = $this->pdo->prepare("
            SELECT id, codigo, nombre FROM sgd_serie
            WHERE empresa_id = ? AND id = ? {$nd}
            LIMIT 1
        ");
        $stmt->execute([$empresaId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function serieBelongsToEmpresa(int $empresaId, int $serieId): bool
    {
        return $this->findSerieById($empresaId, $serieId) !== null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listSubseriesByEmpresa(int $empresaId): array
    {
        $nd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'sgd_subserie', 'ss');
        $stmt = $this->pdo->prepare("
            SELECT ss.id, ss.serie_id, ss.codigo, ss.nombre
            FROM sgd_subserie ss
            INNER JOIN sgd_serie s ON s.id = ss.serie_id AND s.empresa_id = ?
            WHERE ss.empresa_id = ? {$nd}
            ORDER BY ss.serie_id, ss.codigo
        ");
        $stmt->execute([$empresaId, $empresaId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function findSubserieById(int $empresaId, int $id): ?array
    {
        $nd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'sgd_subserie', 'ss');
        $stmt = $this->pdo->prepare("
            SELECT ss.id, ss.serie_id, ss.codigo, ss.nombre
            FROM sgd_subserie ss
            INNER JOIN sgd_serie s ON s.id = ss.serie_id AND s.empresa_id = ?
            WHERE ss.empresa_id = ? AND ss.id = ? {$nd}
            LIMIT 1
        ");
        $stmt->execute([$empresaId, $empresaId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function subserieBelongsToSerie(int $empresaId, int $serieId, int $subserieId): bool
    {
        $row = $this->findSubserieById($empresaId, $subserieId);

        return $row !== null && (int)$row['serie_id'] === $serieId;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listCcdEntradas(int $empresaId, ?int $dependenciaId = null): array
    {
        $nd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'sgd_ccd_entrada', 'e');
        $where = 'e.empresa_id = ?' . $nd;
        $params = [$empresaId];
        if ($dependenciaId !== null && $dependenciaId > 0) {
            $where .= ' AND e.dependencia_id = ?';
            $params[] = $dependenciaId;
        }

        $stmt = $this->pdo->prepare("
            SELECT
                e.id,
                e.empresa_id,
                e.dependencia_id,
                e.serie_id,
                e.subserie_id,
                e.documento_id,
                e.orden,
                e.estado_id,
                d.codigo AS dependencia_codigo,
                d.nombre AS dependencia_nombre,
                s.codigo AS serie_codigo,
                s.nombre AS serie_nombre,
                ss.codigo AS subserie_codigo,
                ss.nombre AS subserie_nombre
            FROM sgd_ccd_entrada e
            INNER JOIN sgd_dependencia d ON d.id = e.dependencia_id
            LEFT JOIN sgd_serie s ON s.id = e.serie_id
            LEFT JOIN sgd_subserie ss ON ss.id = e.subserie_id
            WHERE {$where}
            ORDER BY d.codigo, s.codigo, ss.codigo, e.orden, e.id
        ");
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function findCcdEntradaById(int $empresaId, int $id): ?array
    {
        $nd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'sgd_ccd_entrada', 'e');
        $stmt = $this->pdo->prepare("
            SELECT
                e.id,
                e.empresa_id,
                e.dependencia_id,
                e.serie_id,
                e.subserie_id,
                e.documento_id,
                e.orden,
                e.estado_id,
                d.codigo AS dependencia_codigo,
                d.nombre AS dependencia_nombre,
                s.codigo AS serie_codigo,
                s.nombre AS serie_nombre,
                ss.codigo AS subserie_codigo,
                ss.nombre AS subserie_nombre
            FROM sgd_ccd_entrada e
            INNER JOIN sgd_dependencia d ON d.id = e.dependencia_id
            LEFT JOIN sgd_serie s ON s.id = e.serie_id
            LEFT JOIN sgd_subserie ss ON ss.id = e.subserie_id
            WHERE e.empresa_id = ? AND e.id = ? {$nd}
            LIMIT 1
        ");
        $stmt->execute([$empresaId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function ccdEntradaDuplicate(
        int $empresaId,
        int $dependenciaId,
        int $serieId,
        ?int $subserieId,
        ?int $documentoId,
        ?int $excludeId = null
    ): bool {
        $nd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'sgd_ccd_entrada');
        $sql = "
            SELECT 1 FROM sgd_ccd_entrada
            WHERE empresa_id = ?
              AND dependencia_id = ?
              AND serie_id = ?
              AND COALESCE(subserie_id, 0) = COALESCE(?, 0)
              AND COALESCE(documento_id, 0) = COALESCE(?, 0)
              {$nd}
        ";
        $params = [$empresaId, $dependenciaId, $serieId, $subserieId, $documentoId];
        if ($excludeId !== null && $excludeId > 0) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeId;
        }
        $sql .= ' LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (bool)$stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function saveCcdEntrada(int $empresaId, array $data): int
    {
        $id = isset($data['id']) ? (int)$data['id'] : 0;
        $base = [
            'dependencia_id' => (int)$data['dependencia_id'],
            'serie_id' => isset($data['serie_id']) ? (int)$data['serie_id'] : null,
            'subserie_id' => $data['subserie_id'] ?? null,
            'documento_id' => $data['documento_id'] ?? null,
            'orden' => (int)($data['orden'] ?? 0),
            'estado_id' => (int)($data['estado_id'] ?? 1),
        ];

        $optional = ['codigo_carpeta', 'nombre_serie', 'nombre_subserie', 'soporte_formato', 'codigo_calidad', 'ccd_vigencia', 'ccd_anio'];
        foreach ($optional as $col) {
            if ($this->ccdEntradaHasColumn($col) && array_key_exists($col, $data)) {
                $base[$col] = $data[$col];
            }
        }

        if ($id > 0) {
            $sets = [];
            $params = [];
            foreach ($base as $col => $val) {
                $sets[] = "`{$col}` = ?";
                $params[] = $val;
            }
            if ($this->ccdEntradaHasColumn('updated_at')) {
                $sets[] = 'updated_at = NOW(3)';
            }
            $params[] = $id;
            $params[] = $empresaId;
            $stmt = $this->pdo->prepare('
                UPDATE sgd_ccd_entrada SET ' . implode(', ', $sets) . '
                WHERE id = ? AND empresa_id = ?
            ');
            $stmt->execute($params);

            return $id;
        }

        $cols = ['empresa_id'];
        $placeholders = ['?'];
        $params = [$empresaId];
        foreach ($base as $col => $val) {
            $cols[] = $col;
            $placeholders[] = '?';
            $params[] = $val;
        }
        if ($this->ccdEntradaHasColumn('created_at')) {
            $cols[] = 'created_at';
            $placeholders[] = 'NOW(3)';
        }

        $stmt = $this->pdo->prepare('
            INSERT INTO sgd_ccd_entrada (`' . implode('`,`', $cols) . '`)
            VALUES (' . implode(',', $placeholders) . ')
        ');
        $stmt->execute($params);

        return (int)$this->pdo->lastInsertId();
    }

    public function softDeleteCcdEntrada(int $empresaId, int $id, ?int $userId = null): bool
    {
        if (!SoftDeleteService::supports($this->pdo, 'sgd_ccd_entrada')) {
            $stmt = $this->pdo->prepare('DELETE FROM sgd_ccd_entrada WHERE id = ? AND empresa_id = ?');

            return $stmt->execute([$id, $empresaId]);
        }

        $stmt = $this->pdo->prepare('
            UPDATE sgd_ccd_entrada
            SET deleted_at = NOW(3), deleted_by = ?
            WHERE id = ? AND empresa_id = ? AND deleted_at IS NULL
        ');

        return $stmt->execute([$userId, $id, $empresaId]);
    }
}
