<?php

class SgdCcdService
{
    private SgdRepository $repo;
    private SgdScopeService $scope;
    private SgdCcdCodigoService $codigoCcd;
    private SgdDocumentoCodigoService $codigoDoc;

    public function __construct()
    {
        $this->repo = new SgdRepository();
        $this->scope = new SgdScopeService();
        $this->codigoCcd = new SgdCcdCodigoService();
        $this->codigoDoc = new SgdDocumentoCodigoService();
    }

    /**
     * @return array<string, mixed>
     */
    public function getPageData(array $query): array
    {
        $scope = $this->scope->buildScope($query);
        $empresaId = $scope['empresaId'] ?? null;
        $editId = isset($query['id']) && ctype_digit((string)$query['id']) ? (int)$query['id'] : 0;
        $filterDep = isset($query['dependencia_id']) && ctype_digit((string)$query['dependencia_id'])
            ? (int)$query['dependencia_id']
            : 0;

        $entradas = [];
        $total = 0;
        $edit = null;
        $config = null;
        $catalogos = [
            'dependencias' => [],
            'series' => [],
            'subseries' => [],
            'documentos' => [],
        ];

        if ($empresaId) {
            $config = $this->repo->findConfigByEmpresaId($empresaId);
            $catalogos['dependencias'] = $this->repo->listDependenciasByEmpresa($empresaId);
            $catalogos['series'] = $this->repo->listSeriesByEmpresa($empresaId);
            $catalogos['subseries'] = $this->repo->listSubseriesByEmpresa($empresaId);
            $catalogos['documentos'] = $this->repo->listDocumentosForSelect($empresaId);

            $entradas = $this->repo->listCcdEntradas(
                $empresaId,
                $filterDep > 0 ? $filterDep : null
            );
            $total = count($entradas);

            $docById = $this->indexDocumentosForCodigo($catalogos['documentos']);
            $this->codigoDoc->clearCache();
            foreach ($catalogos['documentos'] as &$doc) {
                $doc['codigo_display'] = $this->codigoDoc->buildForRow($doc, $docById);
            }
            unset($doc);

            $entradas = $this->attachEntradaDisplay($entradas, $docById);

            if ($editId > 0) {
                $edit = $this->repo->findCcdEntradaById($empresaId, $editId);
                if ($edit) {
                    $edit = $this->attachEntradaDisplay([$edit], $docById)[0];
                }
            } else {
                $edit = $this->buildNewForm($filterDep);
            }
        }

        return [
            'scope' => $scope,
            'empresaId' => $empresaId,
            'entradas' => $entradas,
            'total' => $total,
            'filterDependenciaId' => $filterDep,
            'edit' => $edit,
            'config' => $config,
            'catalogos' => $catalogos,
            'catalogosJson' => $this->buildCatalogosJson($catalogos),
            'selectedGridId' => $editId,
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
        $dependenciaId = $this->nullableInt($post['dependencia_id'] ?? null);
        $serieId = $this->nullableInt($post['serie_id'] ?? null);
        $subserieId = $this->nullableInt($post['subserie_id'] ?? null);
        $documentoId = $this->nullableInt($post['documento_id'] ?? null);
        $orden = isset($post['orden']) && ctype_digit((string)$post['orden']) ? (int)$post['orden'] : 0;
        $estadoId = isset($post['estado_id']) && ctype_digit((string)$post['estado_id'])
            ? (int)$post['estado_id']
            : 1;

        if ($dependenciaId === null) {
            return ['success' => false, 'message' => 'Seleccione el área (dependencia) del organigrama.'];
        }
        if ($serieId === null) {
            return ['success' => false, 'message' => 'Seleccione la serie documental.'];
        }

        if (!$this->repo->dependenciaBelongsToEmpresa($empresaId, $dependenciaId)) {
            return ['success' => false, 'message' => 'La dependencia no pertenece a esta empresa.'];
        }
        if (!$this->repo->serieBelongsToEmpresa($empresaId, $serieId)) {
            return ['success' => false, 'message' => 'La serie no pertenece a esta empresa.'];
        }
        if ($subserieId !== null && !$this->repo->subserieBelongsToSerie($empresaId, $serieId, $subserieId)) {
            return ['success' => false, 'message' => 'La subserie no corresponde a la serie seleccionada.'];
        }
        if ($documentoId !== null && $this->repo->findDocumentoById($empresaId, $documentoId) === null) {
            return ['success' => false, 'message' => 'El formato del listado maestro no existe.'];
        }

        if ($this->repo->ccdEntradaDuplicate(
            $empresaId,
            $dependenciaId,
            $serieId,
            $subserieId,
            $documentoId,
            $id > 0 ? $id : null
        )) {
            return [
                'success' => false,
                'message' => 'Ya existe una ubicación con la misma área, serie, subserie y vínculo al listado maestro.',
            ];
        }

        $extra = [];
        if ($this->repo->ccdEntradaHasColumn('codigo_carpeta')) {
            $dep = $this->repo->findDependenciaById($empresaId, $dependenciaId);
            $serie = $this->repo->findSerieById($empresaId, $serieId);
            $sub = $subserieId ? $this->repo->findSubserieById($empresaId, $subserieId) : null;
            $extra['codigo_carpeta'] = $this->codigoCcd->buildCarpetaCodigo(
                $dep['codigo'] ?? '',
                $serie['codigo'] ?? '',
                $sub['codigo'] ?? null
            );
        }
        if ($this->repo->ccdEntradaHasColumn('nombre_serie') && isset($serie['nombre'])) {
            $extra['nombre_serie'] = $serie['nombre'];
        }
        if ($this->repo->ccdEntradaHasColumn('nombre_subserie') && $sub !== null) {
            $extra['nombre_subserie'] = $sub['nombre'];
        }
        if ($this->repo->ccdEntradaHasColumn('codigo_calidad') && $documentoId) {
            $doc = $this->repo->findDocumentoById($empresaId, $documentoId);
            if ($doc) {
                $extra['codigo_calidad'] = $this->codigoDoc->buildForRow($doc);
            }
        }

        $savedId = $this->repo->saveCcdEntrada($empresaId, [
            'id' => $id,
            'dependencia_id' => $dependenciaId,
            'serie_id' => $serieId,
            'subserie_id' => $subserieId,
            'documento_id' => $documentoId,
            'orden' => $orden,
            'estado_id' => $estadoId,
        ] + $extra);

        return [
            'success' => true,
            'message' => $id > 0 ? 'Ubicación CCD actualizada.' : 'Ubicación CCD creada.',
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
            return ['success' => false, 'message' => 'Registro no válido.'];
        }

        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        if (!$this->repo->softDeleteCcdEntrada($empresaId, $id, $userId)) {
            return ['success' => false, 'message' => 'No se pudo eliminar la ubicación CCD.'];
        }

        return ['success' => true, 'message' => 'Ubicación CCD eliminada.'];
    }

    /**
     * @param list<array<string, mixed>> $entradas
     * @param array<int, array<string, mixed>> $docById
     * @return list<array<string, mixed>>
     */
    private function attachEntradaDisplay(array $entradas, array $docById): array
    {
        foreach ($entradas as &$row) {
            $row['codigo_carpeta_display'] = $this->codigoCcd->buildForEntradaRow($row);
            $row['ubicacion_label'] = $this->codigoCcd->buildUbicacionLabel($row);
            $docId = isset($row['documento_id']) ? (int)$row['documento_id'] : 0;
            if ($docId > 0 && isset($docById[$docId])) {
                $row['documento_codigo_display'] = $this->codigoDoc->buildForRow($docById[$docId], $docById);
                $row['documento_nombre'] = $docById[$docId]['nombre'] ?? '';
            } else {
                $row['documento_codigo_display'] = '';
                $row['documento_nombre'] = '';
            }
        }
        unset($row);

        return $entradas;
    }

    /**
     * @param list<array<string, mixed>> $documentos
     * @return array<int, array<string, mixed>>
     */
    private function indexDocumentosForCodigo(array $documentos): array
    {
        $out = [];
        foreach ($documentos as $doc) {
            $out[(int)$doc['id']] = $doc;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildNewForm(int $filterDep): array
    {
        return [
            'id' => '',
            'dependencia_id' => $filterDep > 0 ? (string)$filterDep : '',
            'serie_id' => '',
            'subserie_id' => '',
            'documento_id' => '',
            'orden' => '0',
            'estado_id' => '1',
            'codigo_carpeta_display' => '',
            'ubicacion_label' => '',
        ];
    }

    /**
     * @param array<string, list<array<string, mixed>>> $catalogos
     */
    private function buildCatalogosJson(array $catalogos): string
    {
        $payload = [
            'dependencias' => array_map(static fn($r) => [
                'id' => (int)$r['id'],
                'codigo' => (string)$r['codigo'],
                'nombre' => (string)$r['nombre'],
            ], $catalogos['dependencias']),
            'series' => array_map(static fn($r) => [
                'id' => (int)$r['id'],
                'codigo' => (string)$r['codigo'],
                'nombre' => (string)$r['nombre'],
            ], $catalogos['series']),
            'subseries' => array_map(static fn($r) => [
                'id' => (int)$r['id'],
                'serie_id' => (int)$r['serie_id'],
                'codigo' => (string)$r['codigo'],
                'nombre' => (string)$r['nombre'],
            ], $catalogos['subseries']),
            'documentos' => array_map(static fn($r) => [
                'id' => (int)$r['id'],
                'codigo_display' => (string)($r['codigo_display'] ?? ''),
                'nombre' => (string)($r['nombre'] ?? ''),
            ], $catalogos['documentos']),
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
