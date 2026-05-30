<?php

class SgdDocumentoService
{
    private SgdRepository $repo;
    private SgdScopeService $scope;
    private SgdDocumentoCodigoService $codigoService;

    public function __construct()
    {
        $this->repo = new SgdRepository();
        $this->scope = new SgdScopeService();
        $this->codigoService = new SgdDocumentoCodigoService();
    }

    /**
     * @return array<string, mixed>
     */
    public function getPageData(array $query): array
    {
        $scope = $this->scope->buildScope($query);
        $empresaId = $scope['empresaId'] ?? null;
        $search = trim((string)($query['q'] ?? ''));
        $editId = isset($query['id']) && ctype_digit((string)$query['id']) ? (int)$query['id'] : 0;

        $documentos = [];
        $total = 0;
        $edit = null;
        $catalogos = [
            'procesos' => [],
            'tipos' => [],
            'lineas' => [],
            'padres' => [],
        ];

        if ($empresaId) {
            $documentos = $this->repo->listDocumentos($empresaId, $search !== '' ? $search : null);
            $total = $this->repo->countDocumentos($empresaId, $search !== '' ? $search : null);
            $catalogos['procesos'] = $this->repo->listProcesosByEmpresa($empresaId);
            $catalogos['tipos'] = $this->repo->listTiposByEmpresa($empresaId);
            $catalogos['lineas'] = $this->repo->listLineasDocumentales($empresaId);
            $catalogos['padres'] = $this->repo->listDocumentosForSelect($empresaId, $editId > 0 ? $editId : null);

            if ($editId > 0) {
                $edit = $this->repo->findDocumentoById($empresaId, $editId);
            }
        }

        $allById = $this->indexRowsById($documentos);
        foreach ($catalogos['padres'] as $padre) {
            $allById[(int)$padre['id']] = $padre;
        }

        $this->codigoService->clearCache();
        $documentos = $this->attachCodigos($documentos, $allById);
        $catalogos['padres'] = $this->attachCodigos($catalogos['padres'], $allById);

        if ($edit) {
            $edit['codigo_display'] = $this->codigoService->buildForRow($edit, $allById);
        }

        return [
            'scope' => $scope,
            'empresaId' => $empresaId,
            'documentos' => $documentos,
            'total' => $total,
            'search' => $search,
            'edit' => $edit,
            'catalogos' => $catalogos,
            'catalogosJson' => $this->buildCatalogosJson($catalogos),
        ];
    }

    /**
     * @return array{success: bool, message: string, id?: int}
     */
    public function save(array $post, array $query): array
    {
        try {
            $empresaId = $this->scope->requireEmpresaId($query);
        } catch (RuntimeException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        if (!$this->scope->canAccessEmpresa($empresaId, $query)) {
            return ['success' => false, 'message' => 'Sin permiso para esta empresa.'];
        }

        $id = isset($post['id']) && ctype_digit((string)$post['id']) ? (int)$post['id'] : 0;
        $procesoId = $this->nullableInt($post['proceso_id'] ?? null);
        $documentoPadreId = $this->nullableInt($post['documento_id'] ?? null);
        $tipoId = $this->nullableInt($post['tipo_documental_id'] ?? null);
        $lineaId = $this->nullableInt($post['linea_documental_id'] ?? null);
        $consecutivo = trim((string)($post['consecutivo'] ?? ''));
        $nombre = trim((string)($post['nombre'] ?? ''));

        if ($nombre === '') {
            return ['success' => false, 'message' => 'Indique el nombre del documento.'];
        }
        if ($procesoId === null) {
            return ['success' => false, 'message' => 'Seleccione el proceso.'];
        }
        if ($tipoId === null) {
            return ['success' => false, 'message' => 'Seleccione el tipo documental.'];
        }
        if ($consecutivo === '') {
            return ['success' => false, 'message' => 'Indique el consecutivo.'];
        }

        $tipos = $this->repo->mapTiposByCodigo($empresaId);
        $tipoCodigo = null;
        foreach ($tipos as $codigo => $tipo) {
            if ((int)$tipo['id'] === $tipoId) {
                $tipoCodigo = strtoupper($codigo);
                break;
            }
        }

        if ($documentoPadreId !== null && $documentoPadreId === $id) {
            return ['success' => false, 'message' => 'Un documento no puede ser padre de sí mismo.'];
        }

        if ($documentoPadreId !== null) {
            $lineaId = null;
        } elseif ($tipoCodigo === 'TA' && $lineaId === null) {
            return ['success' => false, 'message' => 'Para tipo TA seleccione la línea documental.'];
        } elseif ($tipoCodigo !== 'TA') {
            $lineaId = null;
        }

        if ($this->repo->documentoUniqueExists(
            $empresaId,
            $procesoId,
            $documentoPadreId,
            $tipoId,
            $consecutivo,
            $id > 0 ? $id : null
        )) {
            return ['success' => false, 'message' => 'Ya existe un documento con la misma combinación de proceso, padre, tipo y consecutivo.'];
        }

        $modo = trim((string)($post['modo'] ?? ''));
        $savedId = $this->repo->saveDocumento($empresaId, [
            'id' => $id,
            'proceso_id' => $procesoId,
            'documento_id' => $documentoPadreId,
            'tipo_documental_id' => $tipoId,
            'linea_documental_id' => $lineaId,
            'consecutivo' => $consecutivo,
            'nombre' => $nombre,
            'modo' => $modo !== '' ? $modo : null,
            'version_actual' => trim((string)($post['version_actual'] ?? '')) ?: null,
            'fecha_primera_aprobacion' => trim((string)($post['fecha_primera_aprobacion'] ?? '')) ?: null,
            'fecha_ultima_aprobacion' => trim((string)($post['fecha_ultima_aprobacion'] ?? '')) ?: null,
            'estado_documental' => trim((string)($post['estado_documental'] ?? 'vigente')) ?: 'vigente',
        ]);

        return [
            'success' => true,
            'message' => $id > 0 ? 'Documento actualizado.' : 'Documento creado.',
            'id' => $savedId,
        ];
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function delete(array $query, array $post): array
    {
        try {
            $empresaId = $this->scope->requireEmpresaId($query);
        } catch (RuntimeException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        if (!$this->scope->canAccessEmpresa($empresaId, $query)) {
            return ['success' => false, 'message' => 'Sin permiso para esta empresa.'];
        }

        $id = isset($post['id']) && ctype_digit((string)$post['id']) ? (int)$post['id'] : 0;
        if ($id <= 0) {
            return ['success' => false, 'message' => 'Documento no válido.'];
        }

        if ($this->repo->countDocumentoHijos($empresaId, $id) > 0) {
            return ['success' => false, 'message' => 'No puede eliminar un documento que tiene formatos o anexos vinculados.'];
        }

        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $this->repo->softDeleteDocumento($empresaId, $id, $userId);

        return ['success' => true, 'message' => 'Documento eliminado.'];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function attachCodigos(array $rows, array $allById): array
    {
        foreach ($rows as &$row) {
            $row['codigo_display'] = $this->codigoService->buildForRow($row, $allById);
        }
        unset($row);

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function indexRowsById(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[(int)$row['id']] = $row;
        }

        return $out;
    }

    /**
     * @param array<string, list<array<string, mixed>>> $catalogos
     */
    private function buildCatalogosJson(array $catalogos): string
    {
        $padresById = [];
        foreach ($catalogos['padres'] as $p) {
            $padresById[(int)$p['id']] = [
                'id' => (int)$p['id'],
                'codigo_display' => $p['codigo_display'] ?? '',
                'proceso_codigo' => $p['proceso_codigo'] ?? '',
                'tipo_codigo' => $p['tipo_codigo'] ?? '',
                'linea_codigo' => $p['linea_codigo'] ?? '',
                'consecutivo' => $p['consecutivo'] ?? '',
                'nombre' => $p['nombre'] ?? '',
            ];
        }

        $payload = [
            'procesos' => array_map(static fn($r) => [
                'id' => (int)$r['id'],
                'codigo' => (string)$r['codigo'],
                'nombre' => (string)$r['nombre'],
            ], $catalogos['procesos']),
            'tipos' => array_map(static fn($r) => [
                'id' => (int)$r['id'],
                'codigo' => (string)$r['codigo'],
                'nombre' => (string)$r['nombre'],
            ], $catalogos['tipos']),
            'lineas' => array_map(static fn($r) => [
                'id' => (int)$r['id'],
                'codigo' => (string)$r['codigo'],
                'nombre' => (string)$r['nombre'],
            ], $catalogos['lineas']),
            'padres' => array_values($padresById),
        ];

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int)$value;
    }
}
