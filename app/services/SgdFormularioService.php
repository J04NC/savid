<?php

class SgdFormularioService
{
    /** @var list<string> */
    public const TIPOS_CAMPO = ['texto', 'textarea', 'numero', 'fecha', 'lista', 'firma'];

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
        $documentoId = isset($query['documento_id']) && ctype_digit((string)$query['documento_id'])
            ? (int)$query['documento_id']
            : 0;

        $documento = null;
        $formulario = null;
        $version = null;
        $versiones = [];
        $esquema = ['version' => 1, 'campos' => []];
        $proposito = 'operativo';
        $codigoDisplay = '';

        if ($empresaId && $documentoId > 0) {
            $documento = $this->repo->findDocumentoById($empresaId, $documentoId);
            if ($documento) {
                $documento = $this->enrichDocumentoModo($empresaId, $documento);
                $proposito = $this->resolveProposito($documento);
                $codigoDisplay = $this->codigoService->buildForRow($documento, [$documentoId => $documento]);
                $formulario = $this->repo->findFormularioByDocumento($empresaId, $documentoId, $proposito);
                if ($formulario === null) {
                    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
                    $fid = $this->repo->createFormulario($empresaId, $documentoId, $proposito, $userId);
                    $formulario = $this->repo->findFormularioByDocumento($empresaId, $documentoId, $proposito)
                        ?: ['id' => $fid, 'documento_id' => $documentoId, 'proposito' => $proposito];
                }

                $formularioId = (int)$formulario['id'];
                $versiones = $this->repo->listFormularioVersiones($empresaId, $formularioId);
                $version = $this->repo->findFormularioBorradorVersion($empresaId, $formularioId);
                if ($version === null) {
                    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
                    $vid = $this->repo->createFormularioVersion($empresaId, $formularioId, [
                        'numero' => $this->repo->suggestNextFormularioVersionNumero($empresaId, $formularioId),
                        'estado_id' => SgdRepository::ESTADO_DOC_BORRADOR,
                        'esquema_json' => ['version' => 1, 'campos' => []],
                        'created_by' => $userId,
                    ]);
                    $version = $this->repo->findFormularioVersionById($empresaId, $vid);
                }

                if ($version) {
                    $esquema = $this->decodeEsquema($version['esquema_json'] ?? null);
                }
            }
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
        if (!is_array($esquema) || !isset($esquema['campos']) || !is_array($esquema['campos'])) {
            return ['success' => false, 'message' => 'Esquema de formulario no válido.'];
        }

        $normalized = $this->normalizeEsquema($esquema);
        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $this->repo->saveFormularioVersionEsquema($empresaId, $versionId, $normalized, $userId);

        return ['success' => true, 'message' => 'Plantilla guardada.'];
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function publish(array $post, array $query): array
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
            return ['success' => false, 'message' => 'Versión no válida.'];
        }

        $version = $this->repo->findFormularioVersionById($empresaId, $versionId);
        if ($version === null) {
            return ['success' => false, 'message' => 'Versión no encontrada.'];
        }

        if ((int)$version['estado_id'] !== SgdRepository::ESTADO_DOC_BORRADOR) {
            return ['success' => false, 'message' => 'Solo se pueden publicar versiones en borrador.'];
        }

        if (trim((string)($post['esquema_json'] ?? '')) !== '') {
            $saved = $this->saveEsquema($post, $query);
            if (!$saved['success']) {
                return $saved;
            }
            $version = $this->repo->findFormularioVersionById($empresaId, $versionId);
        }

        $esquema = $this->decodeEsquema($version['esquema_json'] ?? null);
        if ($esquema['campos'] === []) {
            return ['success' => false, 'message' => 'Agregue al menos un campo antes de publicar.'];
        }

        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $this->repo->publishFormularioVersion($empresaId, $versionId, $userId);

        return ['success' => true, 'message' => 'Plantilla publicada.'];
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

    /**
     * @return array{version: int, campos: list<array<string, mixed>>}
     */
    private function decodeEsquema(mixed $raw): array
    {
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $this->normalizeEsquema($decoded);
            }
        }
        if (is_array($raw)) {
            return $this->normalizeEsquema($raw);
        }

        return ['version' => 1, 'campos' => []];
    }

    /**
     * @param array<string, mixed> $esquema
     * @return array{version: int, campos: list<array<string, mixed>>}
     */
    private function normalizeEsquema(array $esquema): array
    {
        $campos = [];
        $orden = 10;
        foreach ($esquema['campos'] as $campo) {
            if (!is_array($campo)) {
                continue;
            }
            $id = $this->slugCampoId((string)($campo['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $tipo = strtolower(trim((string)($campo['tipo'] ?? 'texto')));
            if (!in_array($tipo, self::TIPOS_CAMPO, true)) {
                $tipo = 'texto';
            }
            $item = [
                'id' => $id,
                'tipo' => $tipo,
                'label' => trim((string)($campo['label'] ?? $id)),
                'requerido' => !empty($campo['requerido']),
                'orden' => (int)($campo['orden'] ?? $orden),
            ];
            if ($tipo === 'lista' && !empty($campo['opciones']) && is_array($campo['opciones'])) {
                $item['opciones'] = array_values(array_filter(array_map('strval', $campo['opciones'])));
            }
            $campos[] = $item;
            $orden += 10;
        }

        usort($campos, static fn($a, $b) => ($a['orden'] ?? 0) <=> ($b['orden'] ?? 0));

        return ['version' => 1, 'campos' => $campos];
    }

    private function slugCampoId(string $raw): string
    {
        $raw = strtolower(trim($raw));
        $raw = preg_replace('/[^a-z0-9_]+/', '_', $raw) ?? '';
        $raw = trim($raw, '_');

        return $raw;
    }
}
