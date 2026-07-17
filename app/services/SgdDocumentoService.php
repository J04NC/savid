<?php

class SgdDocumentoService
{
    private SgdRepository $repo;
    private SgdScopeService $scope;
    private SgdDocumentoCodigoService $codigoService;
    private SgdDocumentoVersionService $versionService;
    private SgdElaboracionService $elabService;

    public function __construct()
    {
        $this->repo = new SgdRepository();
        $this->scope = new SgdScopeService();
        $this->codigoService = new SgdDocumentoCodigoService();
        $this->versionService = new SgdDocumentoVersionService();
        $this->elabService = new SgdElaboracionService();
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
        $padreIdFromQuery = isset($query['padre_id']) && ctype_digit((string)$query['padre_id'])
            ? (int)$query['padre_id']
            : 0;

        $documentos = [];
        $total = 0;
        $edit = null;
        $versiones = [];
        $suggestedVersionNumero = '1';
        $canAutoGeneratePdf = false;
        $consecutivoIndex = [];
        $padresPermitidosPorTipo = [];
        $catalogos = [
            'procesos' => [],
            'tipos' => [],
            'lineas' => [],
            'padres' => [],
            'padresPermitidosPorTipo' => [],
            'estadosDocumentales' => [],
        ];

        if ($empresaId) {
            $consecutivoIndex = $this->repo->buildConsecutivoMaxIndex($empresaId);
            $padresPermitidosPorTipo = $this->repo->listTiposPadrePermitidosMap($empresaId);
            $catalogos['padresPermitidosPorTipo'] = $padresPermitidosPorTipo;
            $documentos = $this->repo->listDocumentos($empresaId, $search !== '' ? $search : null);
            $total = $this->repo->countDocumentos($empresaId, $search !== '' ? $search : null);
            $catalogos['procesos'] = $this->repo->listProcesosByEmpresa($empresaId);
            $catalogos['tipos'] = $this->repo->listTiposByEmpresa($empresaId);
            $catalogos['lineas'] = $this->repo->listLineasDocumentales($empresaId);
            $catalogos['padres'] = $this->repo->listDocumentosForSelect($empresaId, $editId > 0 ? $editId : null);
            $catalogos['estadosDocumentales'] = $this->repo->listEstadosDocumentales();

            if ($editId > 0) {
                $edit = $this->repo->findDocumentoById($empresaId, $editId);
                if ($edit) {
                    $versiones = $this->repo->listDocumentoVersiones($empresaId, $editId);
                    $suggestedVersionNumero = $this->repo->suggestNextVersionNumero($empresaId, $editId);
                    $canAutoGeneratePdf = $this->elabService->canAutoGeneratePdf($empresaId, $editId);
                }
            } elseif ($padreIdFromQuery > 0) {
                $edit = $this->buildNewDocumentoForm($empresaId, $padreIdFromQuery);
            } else {
                $edit = $this->buildNewDocumentoForm($empresaId, null);
            }

            if ($edit && empty($edit['id'])) {
                $edit['consecutivo'] = $this->suggestConsecutivoForForm($empresaId, $edit, $consecutivoIndex);
            }
        }

        $allById = $this->indexRowsById($documentos);
        foreach ($catalogos['padres'] as $padre) {
            $allById[(int)$padre['id']] = $padre;
        }

        if ($empresaId) {
            $this->codigoService->clearCache();
            $documentos = $this->attachCodigos($documentos, $empresaId);
            $catalogos['padres'] = $this->attachCodigos($catalogos['padres'], $empresaId);

            if ($edit) {
                $edit['codigo_display'] = $this->codigoService->buildForDocument($empresaId, $edit, $this->repo);
            }
        }

        $canPreviewPlantilla = false;
        $previewPlantillaUrl = '';
        $esOperativo = false;
        $formatoArchivoEsperado = 'pdf_auto';
        $canAutoGenerateEsqueleto = false;
        $uploadExtensions = ['pdf'];
        $arquetipoOperativo = 'libre';
        if ($edit && !empty($edit['id']) && $empresaId) {
            $editModo = $this->elabService->resolveModoDocumento($empresaId, $edit);
            if (($editModo['modo_efectivo'] ?? '') !== 'maestro') {
                $esOperativo = true;
                $esqueleto = new SgdFormularioEsqueletoService();
                $opMeta = $esqueleto->resolveMetaForDocumento($empresaId, (int)$edit['id']);
                $arquetipoOperativo = (string)($opMeta['arquetipo'] ?? 'libre');
                $formatoArchivoEsperado = (string)($opMeta['formato_archivo'] ?? 'pdf_auto');
                $canAutoGenerateEsqueleto = !empty($opMeta['can_auto_pdf']);
                $uploadExtensions = $opMeta['upload_extensions'] ?? ['pdf'];

                $formService = new SgdFormularioService();
                $previewMeta = $formService->getPreviewMeta(
                    $empresaId,
                    (int)$edit['id'],
                    null,
                    '&empresa_id=' . (int)$empresaId
                );
                $canPreviewPlantilla = !empty($previewMeta['canPreview']);
                $previewPlantillaUrl = (string)($previewMeta['previewPdfUrl'] ?? '');
            }
        }

        return [
            'scope' => $scope,
            'empresaId' => $empresaId,
            'documentos' => $documentos,
            'total' => $total,
            'search' => $search,
            'edit' => $edit,
            'catalogos' => $catalogos,
            'catalogosJson' => $this->buildCatalogosJson($catalogos, $consecutivoIndex, $padresPermitidosPorTipo),
            'selectedGridId' => $editId > 0 ? $editId : $padreIdFromQuery,
            'versiones' => $versiones,
            'suggestedVersionNumero' => $suggestedVersionNumero,
            'canAutoGeneratePdf' => $canAutoGeneratePdf,
            'canPreviewPlantilla' => $canPreviewPlantilla,
            'previewPlantillaUrl' => $previewPlantillaUrl,
            'esOperativo' => $esOperativo,
            'formatoArchivoEsperado' => $formatoArchivoEsperado,
            'canAutoGenerateEsqueleto' => $canAutoGenerateEsqueleto,
            'uploadExtensions' => $uploadExtensions,
            'arquetipoOperativo' => $arquetipoOperativo,
        ];
    }

    public function getVersionService(): SgdDocumentoVersionService
    {
        return $this->versionService;
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

        if ($documentoPadreId !== null) {
            $lineaId = null;
        }

        if ($consecutivo === '' && $id === 0) {
            $consecutivo = $this->repo->getNextConsecutivo(
                $empresaId,
                $procesoId,
                $documentoPadreId,
                $tipoId,
                $lineaId
            );
        }
        if ($consecutivo === '') {
            return ['success' => false, 'message' => 'Indique el consecutivo.'];
        }

        if ($documentoPadreId !== null && $documentoPadreId === $id) {
            return ['success' => false, 'message' => 'Un documento no puede ser padre de sí mismo.'];
        }

        if ($documentoPadreId !== null && $documentoPadreId > 0) {
            $padreRow = $this->repo->findDocumentoById($empresaId, $documentoPadreId);
            if ($padreRow === null) {
                return ['success' => false, 'message' => 'El documento padre seleccionado no existe.'];
            }
            $padreTipoId = (int)($padreRow['tipo_documental_id'] ?? 0);
            if (!$this->repo->isTipoPadrePermitidoForHijo($empresaId, $tipoId, $padreTipoId)) {
                return [
                    'success' => false,
                    'message' => 'El documento padre no corresponde a un tipo permitido para este tipo documental. Revise la configuración en Tipos documentales → Padres permitidos.',
                ];
            }
            $padreProcesoId = (int)($padreRow['proceso_id'] ?? 0);
            if ($padreProcesoId !== $procesoId) {
                return [
                    'success' => false,
                    'message' => 'El documento padre debe pertenecer al mismo proceso seleccionado.',
                ];
            }
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
            'estado_id' => isset($post['estado_id']) && ctype_digit((string)$post['estado_id'])
                ? (int)$post['estado_id']
                : SgdRepository::ESTADO_DOC_VIGENTE,
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
    private function attachCodigos(array $rows, int $empresaId): array
    {
        foreach ($rows as &$row) {
            $row['codigo_display'] = $this->codigoService->buildForDocument($empresaId, $row, $this->repo);
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
     * @param array<string, int> $consecutivoIndex
     * @param array<int, list<int>> $padresPermitidosPorTipo
     */
    private function buildCatalogosJson(array $catalogos, array $consecutivoIndex = [], array $padresPermitidosPorTipo = []): string
    {
        if ($padresPermitidosPorTipo === [] && !empty($catalogos['padresPermitidosPorTipo'])) {
            $padresPermitidosPorTipo = $catalogos['padresPermitidosPorTipo'];
        }
        $padresById = [];
        foreach ($catalogos['padres'] as $p) {
            $padresById[(int)$p['id']] = [
                'id' => (int)$p['id'],
                'proceso_id' => (int)($p['proceso_id'] ?? 0),
                'tipo_documental_id' => (int)($p['tipo_documental_id'] ?? 0),
                'codigo_display' => $p['codigo_display'] ?? '',
                'proceso_codigo' => $p['proceso_codigo'] ?? '',
                'tipo_codigo' => $p['tipo_codigo'] ?? '',
                'linea_codigo' => $p['linea_codigo'] ?? '',
                'consecutivo' => $p['consecutivo'] ?? '',
                'nombre' => $p['nombre'] ?? '',
            ];
        }

        $payload = [
            'consecutivoIndex' => $consecutivoIndex,
            'padresPermitidosPorTipo' => (object)$padresPermitidosPorTipo,
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

    /**
     * @return array<string, mixed>
     */
    private function buildNewDocumentoForm(int $empresaId, ?int $padreId): array
    {
        $form = [
            'id' => '',
            'proceso_id' => '',
            'documento_id' => '',
            'tipo_documental_id' => '',
            'linea_documental_id' => '',
            'consecutivo' => '',
            'nombre' => '',
            'modo' => '',
            'version_actual' => '',
            'fecha_primera_aprobacion' => '',
            'fecha_ultima_aprobacion' => '',
            'estado_id' => (string)SgdRepository::ESTADO_DOC_VIGENTE,
            'codigo_display' => '',
        ];

        if ($padreId === null || $padreId <= 0) {
            return $form;
        }

        $padre = $this->repo->findDocumentoById($empresaId, $padreId);
        if ($padre === null) {
            return $form;
        }

        $form['documento_id'] = (string)$padreId;
        $form['proceso_id'] = (string)($padre['proceso_id'] ?? '');

        return $form;
    }

    /**
     * @param array<string, mixed> $form
     * @param array<string, int> $consecutivoIndex
     */
    private function suggestConsecutivoForForm(int $empresaId, array $form, array $consecutivoIndex): string
    {
        $procesoId = $this->nullableInt($form['proceso_id'] ?? null);
        $tipoId = $this->nullableInt($form['tipo_documental_id'] ?? null);
        if ($procesoId === null || $tipoId === null) {
            return '';
        }

        $padreId = $this->nullableInt($form['documento_id'] ?? null);
        $lineaId = $this->nullableInt($form['linea_documental_id'] ?? null);
        if ($padreId !== null) {
            $lineaId = null;
        }

        return $this->repo->getNextConsecutivo(
            $empresaId,
            $procesoId,
            $padreId,
            $tipoId,
            $lineaId,
            $consecutivoIndex
        );
    }
}
