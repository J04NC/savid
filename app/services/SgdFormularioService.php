<?php

class SgdFormularioService
{
    /** @var list<string> */
    public const TIPOS_CAMPO = ['texto', 'textarea', 'numero', 'fecha', 'lista', 'firma'];

    private SgdRepository $repo;
    private SgdScopeService $scope;
    private SgdDocumentoCodigoService $codigoService;
    private SgdFormularioEsquemaService $esquemaService;

    public function __construct()
    {
        $this->repo = new SgdRepository();
        $this->scope = new SgdScopeService();
        $this->codigoService = new SgdDocumentoCodigoService();
        $this->esquemaService = new SgdFormularioEsquemaService();
    }

    /**
     * @return array<string, mixed>
     */
    public function getPageData(array $query): array
    {
        $scope = $this->scope->buildScope($query);
        $empresaId = $scope['empresaId'] ?? null;
        $documentoId = isset($query['documento_id']) && ctype_digit((string)$query['documento_id'])
            ? (int)$query['documento_id']
            : 0;

        $documento = null;
        $formulario = null;
        $version = null;
        $versiones = [];
        $esquema = ['version' => 3, 'arquetipo' => 'libre', 'elementos' => []];
        $proposito = 'operativo';
        $codigoDisplay = '';
        $borradorDesdeVigente = false;
        $versionVigenteNumero = null;
        $needsNewVersionConfirm = false;
        $documentoVersion = null;

        if ($empresaId && $documentoId > 0) {
            $documento = $this->repo->findDocumentoById($empresaId, $documentoId);
            if ($documento) {
                $documento = $this->enrichDocumentoModo($empresaId, $documento);
                $proposito = $this->resolveProposito($documento);
                $codigoDisplay = $this->codigoService->buildForDocument($empresaId, $documento, $this->repo);
                $formulario = $this->repo->findFormularioByDocumento($empresaId, $documentoId, $proposito);
                $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

                if ($proposito === 'operativo') {
                    $bundleService = new SgdVersionBundleService();
                    $designerMeta = $bundleService->ensureDesignerBorrador($empresaId, $documentoId, $query, $userId);
                    $needsNewVersionConfirm = !empty($designerMeta['needsNewVersionConfirm']);
                    $version = $designerMeta['formulario_version'];
                    $documentoVersion = $designerMeta['documento_version'];
                    $borradorDesdeVigente = !empty($designerMeta['clonedFromVigente']);
                    $versionVigenteNumero = $designerMeta['vigenteNumero'] ?? null;
                    if ($version) {
                        $formularioId = (int)($version['formulario_id'] ?? 0);
                        if ($formularioId > 0) {
                            $formulario = $this->repo->findFormularioByDocumento($empresaId, $documentoId, 'operativo')
                                ?: ['id' => $formularioId, 'documento_id' => $documentoId, 'proposito' => 'operativo'];
                        }
                    }
                } else {
                    if ($formulario === null) {
                        $fid = $this->repo->createFormulario($empresaId, $documentoId, $proposito, $userId);
                        $formulario = $this->repo->findFormularioByDocumento($empresaId, $documentoId, $proposito)
                            ?: ['id' => $fid, 'documento_id' => $documentoId, 'proposito' => $proposito];
                    }
                    $formularioId = (int)$formulario['id'];
                    $borradorMeta = $this->ensureFormularioBorradorVersion($empresaId, $formularioId);
                    $version = $borradorMeta['version'];
                    $borradorDesdeVigente = !empty($borradorMeta['clonedFromVigente']);
                    $versionVigenteNumero = $borradorMeta['vigenteNumero'] ?? null;
                }

                if ($version) {
                    $esquema = $this->decodeEsquema($version['esquema_json'] ?? null);
                }
                $documento['codigo_display'] = $codigoDisplay;
            }
        }

        $previewMeta = ['canPreview' => false, 'previewPdfUrl' => ''];
        if ($empresaId && $documentoId > 0 && $proposito === 'operativo') {
            $previewMeta = $this->getPreviewMeta(
                $empresaId,
                $documentoId,
                $version ? (int)($version['id'] ?? 0) : null,
                $empresaId ? '&empresa_id=' . (int)$empresaId : ''
            );
        }

        return [
            'scope' => $scope,
            'empresaId' => $empresaId,
            'documentoId' => $documentoId,
            'documento' => $documento,
            'codigoDisplay' => $codigoDisplay,
            'proposito' => $proposito,
            'propositoLabel' => $proposito === 'elaboracion' ? 'Elaboración (maestro)' : 'Operativo (registro)',
            'formulario' => $formulario,
            'version' => $version,
            'versiones' => $versiones,
            'esquema' => $esquema,
            'esquemaJson' => json_encode($esquema, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP),
            'tiposCampo' => self::TIPOS_CAMPO,
            'arquetipos' => SgdArquetipoOperativoService::listArquetipos(),
            'arquetipoPiloto' => SgdArquetipoOperativoService::ARQUETIPO_PILOTO,
            'canPreviewPlantilla' => !empty($previewMeta['canPreview']),
            'previewPdfUrl' => (string)($previewMeta['previewPdfUrl'] ?? ''),
            'borradorDesdeVigente' => $borradorDesdeVigente ?? false,
            'versionVigenteNumero' => $versionVigenteNumero ?? null,
            'needsNewVersionConfirm' => $needsNewVersionConfirm ?? false,
            'documentoVersion' => $documentoVersion,
        ];
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function saveEsquema(array $post, array $query): array
    {
        try {
            $empresaId = $this->scope->requireEmpresaId($query);
        } catch (RuntimeException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        if (!$this->scope->canAccessEmpresa($empresaId, $query)) {
            return ['success' => false, 'message' => 'Sin permiso para esta empresa.'];
        }

        $versionId = isset($post['version_id']) && ctype_digit((string)$post['version_id'])
            ? (int)$post['version_id']
            : 0;
        if ($versionId <= 0) {
            return ['success' => false, 'message' => 'Versión de formulario no válida.'];
        }

        $version = $this->repo->findFormularioVersionById($empresaId, $versionId);
        if ($version === null) {
            return ['success' => false, 'message' => 'Versión no encontrada.'];
        }

        if ((int)$version['estado_id'] !== SgdRepository::ESTADO_DOC_BORRADOR) {
            return ['success' => false, 'message' => 'Solo puede editar versiones en borrador.'];
        }

        $raw = (string)($post['esquema_json'] ?? '');
        $esquema = json_decode($raw, true);
        if (!is_array($esquema)) {
            return ['success' => false, 'message' => 'Esquema de formulario no válido.'];
        }
        if (!isset($esquema['elementos']) && !isset($esquema['campos']) && !isset($esquema['bloques'])) {
            return ['success' => false, 'message' => 'Esquema de formulario no válido.'];
        }

        $normalized = $this->esquemaService->normalize($esquema);
        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $this->repo->saveFormularioVersionEsquema($empresaId, $versionId, $normalized, $userId);

        return ['success' => true, 'message' => 'Plantilla guardada.'];
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function createBorradorFromVigente(array $query, array $post = []): array
    {
        try {
            $empresaId = $this->scope->requireEmpresaId($query);
        } catch (RuntimeException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        if (!$this->scope->canAccessEmpresa($empresaId, $query)) {
            return ['success' => false, 'message' => 'Sin permiso para esta empresa.'];
        }

        $documentoId = isset($post['documento_id']) && ctype_digit((string)$post['documento_id'])
            ? (int)$post['documento_id']
            : (isset($query['documento_id']) && ctype_digit((string)$query['documento_id'])
                ? (int)$query['documento_id']
                : 0);
        if ($documentoId <= 0) {
            return ['success' => false, 'message' => 'Documento no válido.'];
        }

        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $query['nueva_version'] = '1';
        $bundleService = new SgdVersionBundleService();
        $bundleService->ensureDesignerBorrador($empresaId, $documentoId, $query, $userId);

        return [
            'success' => true,
            'message' => 'Nuevo borrador creado. Puede editar la plantilla; publique desde Versiones y archivo oficial.',
        ];
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function publish(array $post, array $query): array
    {
        return [
            'success' => false,
            'message' => 'Publique la versión desde el documento: Versiones y archivo oficial.',
        ];
    }

    /**
     * @return array{version: int, arquetipo?: string, bloques?: list<array<string, mixed>>, campos: list<array<string, mixed>>}
     */
    public function decodeEsquemaJson(mixed $raw): array
    {
        return $this->esquemaService->normalize($this->decodeEsquemaRaw($raw));
    }

    /**
     * @param array{version?: int, arquetipo?: string, elementos?: list<array<string, mixed>>} $esquema
     */
    public function esquemaTieneContenido(array $esquema): bool
    {
        return $this->esquemaService->tieneContenido($esquema);
    }

    /**
     * @return array{version: int, arquetipo?: string, elementos: list<array<string, mixed>>}
     */
    private function decodeEsquema(mixed $raw): array
    {
        return $this->esquemaService->normalize($this->decodeEsquemaRaw($raw));
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeEsquemaRaw(mixed $raw): array
    {
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        if (is_array($raw)) {
            return $raw;
        }

        return ['version' => 3, 'arquetipo' => 'libre', 'elementos' => []];
    }

    /**
     * @param array{version?: int, arquetipo?: string, bloques?: list<array<string, mixed>>, campos?: list<array<string, mixed>>} $esquema
     */
    private function esquemaHasContent(array $esquema): bool
    {
        return $this->esquemaService->tieneContenido($esquema);
    }

    /** @deprecated use SgdFormularioEsquemaService */
    private function normalizeEsquema(array $esquema): array
    {
        return $this->esquemaService->normalize($esquema);
    }

    /** @deprecated use SgdFormularioEsquemaService */
    private function normalizeEsquemaV2(array $esquema): array
    {
        return $this->esquemaService->normalize($esquema);
    }

    /** @deprecated use SgdFormularioEsquemaService */
    private function normalizeEsquemaV1(array $esquema): array
    {
        $norm = $this->esquemaService->normalize($esquema);

        return ['campos' => array_values(array_filter($norm['elementos'], static fn($e) => ($e['tipo'] ?? '') === 'campo'))];
    }

    /**
     * @return array{success: bool, message: string, canPreview?: bool, previewPdfUrl?: string}
     */
    public function getPreviewMeta(int $empresaId, int $documentoId, ?int $formularioVersionId = null, string $empresaQuery = ''): array
    {
        $preview = new SgdFormularioPreviewService();
        $prepared = $preview->preparePreview($empresaId, $documentoId, $formularioVersionId);
        if (!$prepared['success']) {
            return [
                'success' => false,
                'message' => (string)($prepared['message'] ?? ''),
                'canPreview' => false,
            ];
        }

        $versionId = (int)($prepared['version']['id'] ?? 0);
        $url = '?url=sgd/formularioPreviewPdf' . $empresaQuery
            . '&documento_id=' . $documentoId
            . ($versionId > 0 ? '&formulario_version_id=' . $versionId : '');

        return [
            'success' => true,
            'message' => 'Listo para vista previa.',
            'canPreview' => true,
            'previewPdfUrl' => $url,
        ];
    }

    /**
     * Crea borrador de plantilla; si hay versión vigente, copia su esquema.
     *
     * @return array{version: array<string, mixed>|null, clonedFromVigente: bool, vigenteNumero: string|null}
     */
    private function ensureFormularioBorradorVersion(int $empresaId, int $formularioId): array
    {
        $borrador = $this->repo->findFormularioBorradorVersion($empresaId, $formularioId);
        if ($borrador !== null) {
            return [
                'version' => $borrador,
                'clonedFromVigente' => false,
                'vigenteNumero' => null,
            ];
        }

        $vigente = $this->repo->findFormularioVersionVigente($empresaId, $formularioId);
        $esquemaSeed = ['version' => 3, 'arquetipo' => 'libre', 'elementos' => []];
        $vigenteNumero = null;
        if ($vigente !== null) {
            $esquemaSeed = $this->decodeEsquema($vigente['esquema_json'] ?? null);
            $vigenteNumero = trim((string)($vigente['numero'] ?? ''));
        }

        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $vid = $this->repo->createFormularioVersion($empresaId, $formularioId, [
            'numero' => $this->repo->suggestNextFormularioVersionNumero($empresaId, $formularioId),
            'estado_id' => SgdRepository::ESTADO_DOC_BORRADOR,
            'esquema_json' => $esquemaSeed,
            'created_by' => $userId,
        ]);

        return [
            'version' => $this->repo->findFormularioVersionById($empresaId, $vid),
            'clonedFromVigente' => $vigente !== null,
            'vigenteNumero' => $vigenteNumero !== '' ? $vigenteNumero : null,
        ];
    }

    /**
     * @param array<string, mixed> $documento
     * @return array<string, mixed>
     */
    private function enrichDocumentoModo(int $empresaId, array $documento): array
    {
        if (trim((string)($documento['modo'] ?? '')) !== '') {
            return $documento;
        }

        $tipoId = (int)($documento['tipo_documental_id'] ?? 0);
        if ($tipoId <= 0) {
            return $documento;
        }

        foreach ($this->repo->listTiposByEmpresa($empresaId) as $tipo) {
            if ((int)$tipo['id'] === $tipoId) {
                $documento['modo'] = $tipo['modo'] ?? 'dinamico';
                break;
            }
        }

        return $documento;
    }

    /**
     * @param array<string, mixed> $documento
     */
    private function resolveProposito(array $documento): string
    {
        $modo = trim((string)($documento['modo'] ?? ''));
        if ($modo === 'maestro') {
            return 'elaboracion';
        }
        if ($modo === 'dinamico' || $modo === 'hibrido') {
            return 'operativo';
        }

        return 'operativo';
    }
}
