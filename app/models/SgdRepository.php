<?php

class SgdRepository
{
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

    public function insertProceso(int $empresaId, string $codigo, string $nombre, ?string $tipoProceso = null): int
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO sgd_proceso (empresa_id, codigo, nombre, tipo_proceso, estado_id, created_at)
            VALUES (?, ?, ?, ?, 1, NOW(3))
        ');
        $stmt->execute([$empresaId, $codigo, $nombre, $tipoProceso]);

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

    public function findSerieId(int $dependenciaId, string $codigo): ?int
    {
        $nd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'sgd_serie');
        $stmt = $this->pdo->prepare("
            SELECT id FROM sgd_serie
            WHERE dependencia_id = ? AND codigo = ? {$nd}
            LIMIT 1
        ");
        $stmt->execute([$dependenciaId, $codigo]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int)$id : null;
    }

    public function insertSerie(int $empresaId, int $dependenciaId, string $codigo, string $nombre): int
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO sgd_serie (empresa_id, dependencia_id, codigo, nombre, estado_id, created_at)
            VALUES (?, ?, ?, ?, 1, NOW(3))
        ');
        $stmt->execute([$empresaId, $dependenciaId, $codigo, $nombre]);

        return (int)$this->pdo->lastInsertId();
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
                estado_documental, estado_id, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(3))
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
            $row['estado_documental'] ?? 'vigente',
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function linkDocumentoToCcdByCodigoCalidad(int $empresaId, string $codigoCalidad): int
    {
        $docId = $this->findDocumentoId($empresaId, $codigoCalidad);
        if (!$docId) {
            return 0;
        }

        $stmt = $this->pdo->prepare('
            UPDATE sgd_ccd_entrada
            SET documento_id = ?
            WHERE empresa_id = ? AND codigo_calidad = ? AND (documento_id IS NULL OR documento_id = 0)
        ');
        $stmt->execute([$docId, $empresaId, $codigoCalidad]);

        return $stmt->rowCount();
    }

    public function linkAllDocumentosCcd(int $empresaId): int
    {
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
        $stmt = $this->pdo->prepare('
            INSERT INTO sgd_ccd_entrada (
                empresa_id, dependencia_id, serie_id, subserie_id,
                codigo_carpeta, nombre_serie, nombre_subserie, soporte_formato,
                codigo_calidad, documento_id, ccd_vigencia, ccd_anio, estado_id, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(3))
        ');
        $stmt->execute([
            $empresaId,
            $row['dependencia_id'],
            $row['serie_id'] ?? null,
            $row['subserie_id'] ?? null,
            $row['codigo_carpeta'],
            $row['nombre_serie'] ?? null,
            $row['nombre_subserie'] ?? null,
            $row['soporte_formato'] ?? null,
            $row['codigo_calidad'] ?? null,
            $row['documento_id'] ?? null,
            $row['ccd_vigencia'] ?? null,
            $row['ccd_anio'] ?? null,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function ccdEntradaExists(int $empresaId, string $codigoCarpeta, ?string $codigoCalidad, ?string $nombreSubserie): bool
    {
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
        $nd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'sgd_proceso');
        $stmt = $this->pdo->prepare("
            SELECT id, codigo, nombre, tipo_proceso
            FROM sgd_proceso
            WHERE empresa_id = ? {$nd}
            ORDER BY orden, codigo
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
              AND d.documento_id IS NULL
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
                d.estado_documental,
                d.estado_id,
                p.codigo AS proceso_codigo,
                p.nombre AS proceso_nombre,
                t.codigo AS tipo_codigo,
                t.nombre AS tipo_nombre,
                ld.codigo AS linea_codigo,
                ld.nombre AS linea_nombre,
                pad.consecutivo AS padre_consecutivo,
                pad.nombre AS padre_nombre
            FROM sgd_documento d
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
            'estado_documental' => $data['estado_documental'] ?? 'vigente',
        ];

        if ($id > 0) {
            $stmt = $this->pdo->prepare('
                UPDATE sgd_documento
                SET proceso_id = ?, documento_id = ?, tipo_documental_id = ?, linea_documental_id = ?,
                    consecutivo = ?, nombre = ?, modo = ?, version_actual = ?,
                    fecha_primera_aprobacion = ?, fecha_ultima_aprobacion = ?,
                    estado_documental = ?, updated_at = NOW(3)
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
                $fields['estado_documental'],
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
                estado_documental, estado_id, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(3))
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
            $fields['estado_documental'],
        ]);

        return (int)$this->pdo->lastInsertId();
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
}
