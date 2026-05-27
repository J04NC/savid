<?php

class SgdRepository
{
    private PDO $pdo;

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
        $stmt = $this->pdo->prepare("
            SELECT id FROM sgd_documento
            WHERE empresa_id = ? AND codigo = ? {$nd}
            LIMIT 1
        ");
        $stmt->execute([$empresaId, $codigo]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int)$id : null;
    }

    public function upsertDocumento(int $empresaId, array $row): int
    {
        $existingId = $this->findDocumentoId($empresaId, $row['codigo']);

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
                empresa_id, codigo, nombre, proceso_id, tipo_documental_id,
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
        $stmt = $this->pdo->prepare('
            UPDATE sgd_ccd_entrada e
            INNER JOIN sgd_documento d
                ON d.empresa_id = e.empresa_id
               AND d.codigo = e.codigo_calidad
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
