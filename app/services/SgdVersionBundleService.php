<?php

/**
 * Versión operativa = par enlazado (sgd_formulario_version + sgd_documento_version).
 */
class SgdVersionBundleService
{
    private SgdRepository $repo;
    private SgdFormularioService $formularioService;

    public function __construct()
    {
        $this->repo = new SgdRepository();
        $this->formularioService = new SgdFormularioService();
    }

    public function suggestNextNumero(int $empresaId, int $documentoId): string
    {
        return $this->repo->suggestNextVersionNumero($empresaId, $documentoId);
    }

    /**
     * La vigente tiene plantilla web con contenido (no solo archivo físico legacy).
     */
    public function vigenteOperativoHasPlantilla(int $empresaId, int $documentoId): bool
    {
        $vigenteDoc = $this->repo->findDocumentoVersionVigente($empresaId, $documentoId);
        if ($vigenteDoc !== null) {
            $formularioVersionId = (int)($vigenteDoc['formulario_version_id'] ?? 0);
            if ($formularioVersionId <= 0) {
                return false;
            }

            return $this->formularioVersionHasPlantilla($empresaId, $formularioVersionId);
        }

        $formulario = $this->repo->findFormularioByDocumento($empresaId, $documentoId, 'operativo');
        if ($formulario === null) {
            return false;
        }

        $vigenteForm = $this->repo->findFormularioVersionVigente($empresaId, (int)$formulario['id']);

        return $vigenteForm !== null
            && $this->formularioVersionHasPlantilla($empresaId, (int)$vigenteForm['id']);
    }

    /**
     * @return array{
     *   formulario_version: array<string, mixed>,
     *   documento_version: array<string, mixed>,
     *   clonedFromVigente: bool,
     *   vigenteNumero: string|null
     * }
     */
    public function createBorradorBundle(
        int $empresaId,
        int $documentoId,
        ?int $userId = null,
        bool $cloneFromVigente = false,
        ?string $notas = null,
        ?string $numero = null
    ): array {
        $documento = $this->repo->findDocumentoById($empresaId, $documentoId);
        if ($documento === null) {
            throw new RuntimeException('Documento no encontrado.');
        }

        $formulario = $this->repo->findFormularioByDocumento($empresaId, $documentoId, 'operativo');
        if ($formulario === null) {
            $fid = $this->repo->createFormulario($empresaId, $documentoId, 'operativo', $userId);
            $formulario = $this->repo->findFormularioByDocumento($empresaId, $documentoId, 'operativo')
                ?: ['id' => $fid, 'documento_id' => $documentoId, 'proposito' => 'operativo'];
        }

        $formularioId = (int)$formulario['id'];
        $existingBorrador = $this->repo->findFormularioBorradorVersion($empresaId, $formularioId);
        if ($existingBorrador !== null) {
            $docVersion = $this->ensureDocumentoVersionForFormulario(
                $empresaId,
                $documentoId,
                $existingBorrador,
                $userId,
                $notas
            );

            return [
                'formulario_version' => $existingBorrador,
                'documento_version' => $docVersion,
                'clonedFromVigente' => false,
                'vigenteNumero' => null,
            ];
        }

        $numero = trim((string)($numero ?? ''));
        if ($numero === '') {
            $numero = $this->suggestNextNumero($empresaId, $documentoId);
        }
        if ($this->repo->documentoVersionNumeroExists($empresaId, $documentoId, $numero)) {
            throw new RuntimeException('Ya existe la versión «' . $numero . '» para este documento.');
        }

        $esquemaSeed = ['version' => 2, 'arquetipo' => 'libre', 'bloques' => [], 'campos' => []];
        $vigenteNumero = null;
        if ($cloneFromVigente) {
            $vigenteForm = $this->repo->findFormularioVersionVigente($empresaId, $formularioId);
            if ($vigenteForm !== null && $this->formularioVersionHasPlantilla($empresaId, (int)$vigenteForm['id'])) {
                $esquemaSeed = $this->formularioService->decodeEsquemaJson($vigenteForm['esquema_json'] ?? null);
                $vigenteNumero = trim((string)($vigenteForm['numero'] ?? ''));
            }
        }

        $formularioVersionId = $this->repo->createFormularioVersion($empresaId, $formularioId, [
            'numero' => $numero,
            'estado_id' => SgdRepository::ESTADO_DOC_BORRADOR,
            'esquema_json' => $esquemaSeed,
            'created_by' => $userId,
        ]);

        $documentoVersionId = $this->createOrReactivateDocumentoVersion(
            $empresaId,
            $documentoId,
            $numero,
            $formularioVersionId,
            $userId,
            $notas
        );

        $formularioVersion = $this->repo->findFormularioVersionById($empresaId, $formularioVersionId);
        $documentoVersion = $this->repo->findDocumentoVersionById($empresaId, $documentoVersionId);

        if ($formularioVersion === null || $documentoVersion === null) {
            throw new RuntimeException('No se pudo crear el par de versiones.');
        }

        return [
            'formulario_version' => $formularioVersion,
            'documento_version' => $documentoVersion,
            'clonedFromVigente' => $cloneFromVigente && $vigenteNumero !== null,
            'vigenteNumero' => $vigenteNumero !== '' ? $vigenteNumero : null,
        ];
    }

    /**
     * Asegura borrador para el diseñador según reglas de negocio acordadas.
     *
     * @return array{
     *   formulario_version: array<string, mixed>|null,
     *   documento_version: array<string, mixed>|null,
     *   clonedFromVigente: bool,
     *   vigenteNumero: string|null,
     *   needsNewVersionConfirm: bool
     * }
     */
    public function ensureDesignerBorrador(int $empresaId, int $documentoId, array $query, ?int $userId = null): array
    {
        $formulario = $this->repo->findFormularioByDocumento($empresaId, $documentoId, 'operativo');
        if ($formulario === null) {
            $bundle = $this->createBorradorBundle($empresaId, $documentoId, $userId, false);

            return $this->designerResult($bundle, false);
        }

        $formularioId = (int)$formulario['id'];
        $borradorForm = $this->repo->findFormularioBorradorVersion($empresaId, $formularioId);
        if ($borradorForm !== null) {
            $docVersion = $this->ensureDocumentoVersionForFormulario(
                $empresaId,
                $documentoId,
                $borradorForm,
                $userId,
                null
            );

            return [
                'formulario_version' => $borradorForm,
                'documento_version' => $docVersion,
                'clonedFromVigente' => false,
                'vigenteNumero' => null,
                'needsNewVersionConfirm' => false,
            ];
        }

        $docBorrador = $this->repo->findDocumentoBorradorVersion($empresaId, $documentoId);
        if ($docBorrador !== null) {
            $numero = trim((string)($docBorrador['numero'] ?? ''));
            $esquemaSeed = ['version' => 2, 'arquetipo' => 'libre', 'bloques' => [], 'campos' => []];
            $formularioVersionId = $this->repo->createFormularioVersion($empresaId, $formularioId, [
                'numero' => $numero !== '' ? $numero : $this->suggestNextNumero($empresaId, $documentoId),
                'estado_id' => SgdRepository::ESTADO_DOC_BORRADOR,
                'esquema_json' => $esquemaSeed,
                'created_by' => $userId,
            ]);
            $this->repo->linkDocumentoVersionFormulario(
                $empresaId,
                (int)$docBorrador['id'],
                $formularioVersionId,
                $userId
            );
            $formularioVersion = $this->repo->findFormularioVersionById($empresaId, $formularioVersionId);
            $documentoVersion = $this->repo->findDocumentoVersionById($empresaId, (int)$docBorrador['id']);
            if ($formularioVersion && $documentoVersion) {
                return [
                    'formulario_version' => $formularioVersion,
                    'documento_version' => $documentoVersion,
                    'clonedFromVigente' => false,
                    'vigenteNumero' => null,
                    'needsNewVersionConfirm' => false,
                ];
            }
        }

        $hasVigente = $this->repo->findDocumentoVersionVigente($empresaId, $documentoId) !== null
            || $this->repo->findFormularioVersionVigente($empresaId, $formularioId) !== null;

        if (!$hasVigente) {
            $bundle = $this->createBorradorBundle($empresaId, $documentoId, $userId, false);

            return $this->designerResult($bundle, false);
        }

        $confirmed = filter_var($query['nueva_version'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || (string)($query['nueva_version'] ?? '') === '1';

        $hasPlantilla = $this->vigenteOperativoHasPlantilla($empresaId, $documentoId);
        if ($hasPlantilla && !$confirmed) {
            $vigenteNumero = $this->resolveVigenteNumeroLabel($empresaId, $documentoId, $formularioId);

            return [
                'formulario_version' => null,
                'documento_version' => null,
                'clonedFromVigente' => false,
                'vigenteNumero' => $vigenteNumero,
                'needsNewVersionConfirm' => true,
            ];
        }

        $bundle = $this->createBorradorBundle($empresaId, $documentoId, $userId, $hasPlantilla);

        return $this->designerResult($bundle, false);
    }

    public function deleteBorradorBundle(int $empresaId, int $documentoVersionId, ?int $userId = null): void
    {
        $version = $this->repo->findDocumentoVersionById($empresaId, $documentoVersionId);
        if ($version === null) {
            throw new RuntimeException('Versión no encontrada.');
        }

        $formularioVersionId = (int)($version['formulario_version_id'] ?? 0);

        $this->repo->softDeleteDocumentoVersion($empresaId, $documentoVersionId, $userId);

        if ($formularioVersionId > 0) {
            $this->repo->softDeleteFormularioVersion($empresaId, $formularioVersionId, $userId);
        }
    }

    /**
     * @param array<string, mixed> $borradorForm
     * @return array<string, mixed>
     */
    private function ensureDocumentoVersionForFormulario(
        int $empresaId,
        int $documentoId,
        array $borradorForm,
        ?int $userId,
        ?string $notas
    ): array {
        $formularioVersionId = (int)($borradorForm['id'] ?? 0);
        $numero = trim((string)($borradorForm['numero'] ?? '1'));

        $linked = $this->repo->findDocumentoVersionByFormularioVersionId($empresaId, $formularioVersionId);
        if ($linked !== null) {
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

        $documentoVersionId = $this->createOrReactivateDocumentoVersion(
            $empresaId,
            $documentoId,
            $numero,
            $formularioVersionId,
            $userId,
            $notas
        );
        $created = $this->repo->findDocumentoVersionById($empresaId, $documentoVersionId);

        return $created ?? [
            'id' => $documentoVersionId,
            'documento_id' => $documentoId,
            'numero' => $numero,
            'formulario_version_id' => $formularioVersionId,
        ];
    }

    private function createOrReactivateDocumentoVersion(
        int $empresaId,
        int $documentoId,
        string $numero,
        int $formularioVersionId,
        ?int $userId,
        ?string $notas
    ): int {
        $anyNumero = $this->repo->findDocumentoVersionByDocumentoNumeroAny($empresaId, $documentoId, $numero);
        if ($anyNumero !== null && !empty($anyNumero['deleted_at'])) {
            $versionId = (int)$anyNumero['id'];
            $this->repo->reactivateDocumentoVersionBorrador(
                $empresaId,
                $versionId,
                $formularioVersionId,
                $userId,
                $notas ?? 'Versión operativa (plantilla + archivo)'
            );

            return $versionId;
        }

        return $this->repo->saveDocumentoVersion($empresaId, [
            'documento_id' => $documentoId,
            'numero' => $numero,
            'notas' => $notas ?? 'Versión operativa (plantilla + archivo)',
            'estado_id' => SgdRepository::ESTADO_DOC_BORRADOR,
            'formulario_version_id' => $formularioVersionId,
            'created_by' => $userId,
        ]);
    }

    private function formularioVersionHasPlantilla(int $empresaId, int $formularioVersionId): bool
    {
        $version = $this->repo->findFormularioVersionById($empresaId, $formularioVersionId);
        if ($version === null) {
            return false;
        }

        $esquema = $this->formularioService->decodeEsquemaJson($version['esquema_json'] ?? null);

        return $this->formularioService->esquemaTieneContenido($esquema);
    }

    private function resolveVigenteNumeroLabel(int $empresaId, int $documentoId, int $formularioId): ?string
    {
        $vigenteDoc = $this->repo->findDocumentoVersionVigente($empresaId, $documentoId);
        if ($vigenteDoc !== null) {
            $n = trim((string)($vigenteDoc['numero'] ?? ''));

            return $n !== '' ? $n : null;
        }

        $vigenteForm = $this->repo->findFormularioVersionVigente($empresaId, $formularioId);
        if ($vigenteForm !== null) {
            $n = trim((string)($vigenteForm['numero'] ?? ''));

            return $n !== '' ? $n : null;
        }

        return null;
    }

    /**
     * @param array{
     *   formulario_version: array<string, mixed>,
     *   documento_version: array<string, mixed>,
     *   clonedFromVigente: bool,
     *   vigenteNumero: string|null
     * } $bundle
     * @return array{
     *   formulario_version: array<string, mixed>,
     *   documento_version: array<string, mixed>,
     *   clonedFromVigente: bool,
     *   vigenteNumero: string|null,
     *   needsNewVersionConfirm: bool
     * }
     */
    private function designerResult(array $bundle, bool $needsConfirm): array
    {
        return [
            'formulario_version' => $bundle['formulario_version'],
            'documento_version' => $bundle['documento_version'],
            'clonedFromVigente' => !empty($bundle['clonedFromVigente']),
            'vigenteNumero' => $bundle['vigenteNumero'] ?? null,
            'needsNewVersionConfirm' => $needsConfirm,
        ];
    }
}
