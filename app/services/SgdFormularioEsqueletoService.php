<?php

/**
 * Sincroniza publicación de plantilla operativa con versión documento + archivo oficial (esqueleto).
 */
class SgdFormularioEsqueletoService
{
    private SgdRepository $repo;
    private SgdFormularioPreviewService $previewService;
    private SgdFormularioService $formularioService;

    public function __construct()
    {
        $this->repo = new SgdRepository();
        $this->previewService = new SgdFormularioPreviewService();
        $this->formularioService = new SgdFormularioService();
    }

    /**
     * @param array{version: int, arquetipo?: string, bloques?: list<array<string, mixed>>, campos?: list<array<string, mixed>>} $esquema
     * @return array{success: bool, message: string}
     */
    public function publishWithArchivoOficial(
        int $empresaId,
        int $formularioVersionId,
        array $esquema,
        ?int $userId = null
    ): array {
        $formVersion = $this->repo->findFormularioVersionById($empresaId, $formularioVersionId);
        if ($formVersion === null) {
            return ['success' => false, 'message' => 'Versión de plantilla no encontrada.'];
        }

        $documentoId = (int)($formVersion['documento_id'] ?? 0);
        if ($documentoId <= 0) {
            return ['success' => false, 'message' => 'Documento no válido.'];
        }

        $arquetipo = (string)($esquema['arquetipo'] ?? 'libre');
        $formatoArchivo = SgdArquetipoOperativoService::resolveFormatoArchivo($arquetipo);
        $numero = trim((string)($formVersion['numero'] ?? '1'));

        $docVersion = $this->resolveDocumentoVersionBorrador(
            $empresaId,
            $documentoId,
            $numero,
            $formularioVersionId,
            $userId
        );

        $archivoResult = $this->ensureArchivoOficial(
            $empresaId,
            $documentoId,
            (int)$docVersion['id'],
            $formularioVersionId,
            $formatoArchivo,
            $userId
        );
        if (!$archivoResult['success']) {
            return $archivoResult;
        }

        try {
            $this->repo->publishOperativoPlantillaBundle(
                $empresaId,
                $formularioVersionId,
                (int)$docVersion['id'],
                $userId
            );
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'No se pudo publicar la plantilla: ' . $e->getMessage()];
        }

        $tipoLabel = SgdArquetipoOperativoService::archivoTipoLabel(
            (string)($archivoResult['archivo_tipo'] ?? 'pdf')
        );

        return [
            'success' => true,
            'message' => 'Plantilla publicada. Versión ' . $numero . ' vigente con archivo ' . $tipoLabel . ' en el listado maestro.',
        ];
    }

    /**
     * @return array{arquetipo: string, formato_archivo: string, can_auto_pdf: bool, upload_extensions: list<string>}
     */
    public function resolveMetaForDocumento(int $empresaId, int $documentoId): array
    {
        $arquetipo = $this->resolveArquetipoForDocumento($empresaId, $documentoId);
        $formato = SgdArquetipoOperativoService::resolveFormatoArchivo($arquetipo);

        return [
            'arquetipo' => $arquetipo,
            'formato_archivo' => $formato,
            'can_auto_pdf' => SgdArquetipoOperativoService::canAutoGeneratePdfSkeleton($arquetipo),
            'upload_extensions' => SgdArquetipoOperativoService::allowedUploadExtensions($arquetipo),
        ];
    }

    public function resolveArquetipoForDocumento(int $empresaId, int $documentoId): string
    {
        $formulario = $this->repo->findFormularioByDocumento($empresaId, $documentoId, 'operativo');
        if ($formulario === null) {
            return 'libre';
        }

        $formularioId = (int)$formulario['id'];
        $borrador = $this->repo->findFormularioBorradorVersion($empresaId, $formularioId);
        if ($borrador !== null) {
            $esquema = $this->formularioService->decodeEsquemaJson($borrador['esquema_json'] ?? null);

            return (string)($esquema['arquetipo'] ?? 'libre');
        }

        foreach ($this->repo->listFormularioVersiones($empresaId, $formularioId) as $ver) {
            if ((int)($ver['es_vigente'] ?? 0) === 1) {
                $full = $this->repo->findFormularioVersionById($empresaId, (int)$ver['id']);
                if ($full !== null) {
                    $esquema = $this->formularioService->decodeEsquemaJson($full['esquema_json'] ?? null);

                    return (string)($esquema['arquetipo'] ?? 'libre');
                }
            }
        }

        return 'libre';
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveDocumentoVersionBorrador(
        int $empresaId,
        int $documentoId,
        string $numero,
        int $formularioVersionId,
        ?int $userId
    ): array {
        $linked = $this->repo->findDocumentoVersionByFormularioVersionId($empresaId, $formularioVersionId);
        if ($linked !== null && (int)($linked['estado_id'] ?? 0) === SgdRepository::ESTADO_DOC_BORRADOR) {
            return $linked;
        }

        $byNumero = $this->repo->findDocumentoVersionByDocumentoNumero(
            $empresaId,
            $documentoId,
            $numero,
            SgdRepository::ESTADO_DOC_BORRADOR
        );
        if ($byNumero !== null) {
            $this->repo->linkDocumentoVersionFormulario(
                $empresaId,
                (int)$byNumero['id'],
                $formularioVersionId,
                $userId
            );
            $byNumero['formulario_version_id'] = $formularioVersionId;

            return $byNumero;
        }

        $anyNumero = $this->repo->findDocumentoVersionByDocumentoNumeroAny($empresaId, $documentoId, $numero);
        if ($anyNumero !== null && !empty($anyNumero['deleted_at'])) {
            $versionId = (int)$anyNumero['id'];
            $this->repo->reactivateDocumentoVersionBorrador(
                $empresaId,
                $versionId,
                $formularioVersionId,
                $userId,
                'Esqueleto de plantilla operativa'
            );
            $reactivated = $this->repo->findDocumentoVersionById($empresaId, $versionId);

            return $reactivated ?? $anyNumero;
        }

        $versionId = $this->repo->saveDocumentoVersion($empresaId, [
            'documento_id' => $documentoId,
            'numero' => $numero,
            'notas' => 'Esqueleto de plantilla operativa',
            'estado_id' => SgdRepository::ESTADO_DOC_BORRADOR,
            'formulario_version_id' => $formularioVersionId,
            'created_by' => $userId,
        ]);

        $created = $this->repo->findDocumentoVersionById($empresaId, $versionId);

        return $created ?? [
            'id' => $versionId,
            'documento_id' => $documentoId,
            'numero' => $numero,
        ];
    }

    /**
     * @return array{success: bool, message: string, archivo_tipo?: string}
     */
    private function ensureArchivoOficial(
        int $empresaId,
        int $documentoId,
        int $documentoVersionId,
        int $formularioVersionId,
        string $formatoArchivo,
        ?int $userId
    ): array {
        $docVersion = $this->repo->findDocumentoVersionById($empresaId, $documentoVersionId);
        if ($docVersion === null) {
            return ['success' => false, 'message' => 'Versión documental no encontrada.'];
        }

        $archivoRuta = trim((string)($docVersion['archivo_ruta'] ?? ''));
        $archivoTipo = trim((string)($docVersion['archivo_tipo'] ?? ''));

        if ($archivoRuta !== '') {
            if ($formatoArchivo === 'xlsx_upload' && !in_array($archivoTipo, ['xlsx', 'xls'], true)) {
                $archivoTipo = SgdArquetipoOperativoService::detectArchivoTipo($archivoRuta);
                if (!in_array($archivoTipo, ['xlsx', 'xls'], true)) {
                    return [
                        'success' => false,
                        'message' => 'Este formato requiere plantilla Excel (.xlsx). Suba el archivo en «Versiones y archivo oficial» del documento.',
                    ];
                }
            }
            if ($formatoArchivo === 'docx_upload' && !in_array($archivoTipo, ['docx', 'doc'], true)) {
                $archivoTipo = SgdArquetipoOperativoService::detectArchivoTipo($archivoRuta);
                if (!in_array($archivoTipo, ['docx', 'doc'], true)) {
                    return [
                        'success' => false,
                        'message' => 'Este formato requiere plantilla Word (.docx). Suba el archivo en «Versiones y archivo oficial» del documento.',
                    ];
                }
            }

            if ($archivoTipo === '') {
                $archivoTipo = SgdArquetipoOperativoService::detectArchivoTipo($archivoRuta);
                $this->repo->updateDocumentoVersionArchivo($empresaId, $documentoVersionId, $archivoRuta, $userId, $archivoTipo);
            }

            return ['success' => true, 'archivo_tipo' => $archivoTipo];
        }

        if ($formatoArchivo === 'xlsx_upload' || $formatoArchivo === 'docx_upload' || $formatoArchivo === 'upload') {
            $hint = match ($formatoArchivo) {
                'xlsx_upload' => 'Suba la plantilla Excel (.xlsx) en «Versiones y archivo oficial» del documento antes de publicar.',
                'docx_upload' => 'Suba la plantilla Word (.docx) en «Versiones y archivo oficial» del documento antes de publicar.',
                default => 'Suba el archivo de referencia (PDF, Word o Excel) en «Versiones y archivo oficial» antes de publicar.',
            };

            return ['success' => false, 'message' => $hint];
        }

        $generated = $this->previewService->saveSkeletonPdf(
            $empresaId,
            $documentoId,
            $documentoVersionId,
            $formularioVersionId
        );
        if (!$generated['success']) {
            return $generated;
        }

        return [
            'success' => true,
            'archivo_tipo' => 'pdf',
        ];
    }
}
