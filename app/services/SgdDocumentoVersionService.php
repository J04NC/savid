<?php

class SgdDocumentoVersionService
{
    private SgdRepository $repo;
    private SgdScopeService $scope;
    private SgdElaboracionService $elabService;
    private SgdPdfGenerationService $pdfService;

    public function __construct()
    {
        $this->repo = new SgdRepository();
        $this->scope = new SgdScopeService();
        $this->elabService = new SgdElaboracionService();
        $this->pdfService = new SgdPdfGenerationService();
    }

    /**
     * @return array{success: bool, message: string, id?: int}
     */
    public function create(array $post, array $query): array
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
            : 0;
        if ($documentoId <= 0) {
            return ['success' => false, 'message' => 'Documento no válido.'];
        }

        $doc = $this->repo->findDocumentoById($empresaId, $documentoId);
        if ($doc === null) {
            return ['success' => false, 'message' => 'Documento no encontrado.'];
        }

        $doc = $this->elabService->resolveModoDocumento($empresaId, $doc);
        $esOperativo = ($doc['modo_efectivo'] ?? '') !== 'maestro';

        $numero = trim((string)($post['numero'] ?? ''));
        $notas = trim((string)($post['notas'] ?? '')) ?: null;
        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $fechaAprobacion = $this->parseFechaAprobacion($post['fecha_aprobacion'] ?? null);

        if ($esOperativo) {
            if ($numero !== '' && $this->repo->documentoVersionNumeroExists($empresaId, $documentoId, $numero)) {
                return ['success' => false, 'message' => 'Ya existe la versión «' . $numero . '» para este documento.'];
            }

            $bundleService = new SgdVersionBundleService();
            try {
                $bundle = $bundleService->createBorradorBundle(
                    $empresaId,
                    $documentoId,
                    $userId,
                    false,
                    $notas,
                    $numero !== '' ? $numero : null
                );
            } catch (RuntimeException $e) {
                return ['success' => false, 'message' => $e->getMessage()];
            }

            $versionId = (int)($bundle['documento_version']['id'] ?? 0);
            if ($fechaAprobacion !== null && $versionId > 0) {
                $this->repo->updateDocumentoVersionFecha($empresaId, $versionId, $fechaAprobacion, $userId);
            }

            $numeroCreado = (string)($bundle['documento_version']['numero'] ?? '');
            $message = !empty($bundle['alreadyOpen'])
                ? 'La versión ' . $numeroCreado . ' ya estaba en borrador (plantilla + archivo).'
                : 'Versión ' . $numeroCreado . ' creada en borrador (plantilla + archivo).';

            return [
                'success' => true,
                'message' => $message,
                'id' => $versionId,
            ];
        }

        if ($numero === '') {
            $numero = $this->repo->suggestNextVersionNumero($empresaId, $documentoId);
        }

        if ($this->repo->documentoVersionNumeroExists($empresaId, $documentoId, $numero)) {
            return ['success' => false, 'message' => 'Ya existe la versión «' . $numero . '» para este documento.'];
        }

        $deletedRow = $this->repo->findDocumentoVersionByDocumentoNumeroAny($empresaId, $documentoId, $numero);
        if ($deletedRow !== null && !empty($deletedRow['deleted_at'])) {
            $versionId = (int)$deletedRow['id'];
            $this->repo->reactivateDocumentoVersionBorrador(
                $empresaId,
                $versionId,
                (int)($deletedRow['formulario_version_id'] ?? 0),
                $userId,
                trim((string)($post['notas'] ?? '')) ?: 'Esqueleto de plantilla operativa'
            );
            if ($fechaAprobacion !== null) {
                $this->repo->updateDocumentoVersionFecha($empresaId, $versionId, $fechaAprobacion, $userId);
            }

            return [
                'success' => true,
                'message' => 'Versión ' . $numero . ' reactivada en borrador.',
                'id' => $versionId,
            ];
        }

        $versionId = $this->repo->saveDocumentoVersion($empresaId, [
            'documento_id' => $documentoId,
            'numero' => $numero,
            'notas' => trim((string)($post['notas'] ?? '')) ?: null,
            'fecha_aprobacion' => $fechaAprobacion,
            'estado_id' => SgdRepository::ESTADO_DOC_BORRADOR,
            'created_by' => $userId,
        ]);

        return [
            'success' => true,
            'message' => 'Versión ' . $numero . ' creada en borrador.',
            'id' => $versionId,
        ];
    }

    /**
     * @return array{success: bool, message: string, path?: string, warning?: string}
     */
    public function uploadPdf(array $files, array $post, array $query): array
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

        $version = $this->repo->findDocumentoVersionById($empresaId, $versionId);
        if ($version === null) {
            return ['success' => false, 'message' => 'Versión no encontrada.'];
        }

        if ((int)$version['estado_id'] !== SgdRepository::ESTADO_DOC_BORRADOR) {
            return ['success' => false, 'message' => 'Solo se puede subir archivo a versiones en borrador.'];
        }

        $documentoId = (int)$version['documento_id'];
        $documento = $this->repo->findDocumentoById($empresaId, $documentoId);
        $documento = $documento ? $this->elabService->resolveModoDocumento($empresaId, $documento) : null;
        $allowedExtensions = ['pdf'];
        if ($documento && ($documento['modo_efectivo'] ?? '') !== 'maestro') {
            $meta = (new SgdFormularioEsqueletoService())->resolveMetaForDocumento($empresaId, $documentoId);
            $allowedExtensions = $meta['upload_extensions'] ?? ['pdf'];
        }

        $file = $files['archivo'] ?? null;
        if ($file === null && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            return [
                'success' => false,
                'message' => 'No llegó el archivo (puede superar el límite de subida del servidor). Pruebe un PDF más pequeño.',
            ];
        }

        $uploadName = is_array($file) ? (string)($file['name'] ?? '') : '';
        $uploadExt = strtolower(pathinfo($uploadName, PATHINFO_EXTENSION));
        $warning = null;
        if (in_array($uploadExt, ['docx', 'doc'], true) && is_array($file) && !empty($file['tmp_name'])) {
            $empresaConfig = $this->repo->findConfigByEmpresaId($empresaId);
            $configExtra = SgdConfigService::parseConfigJson($empresaConfig);
            $archivoSettings = SgdConfigService::archivoOficialSettings($configExtra);
            $wordCheck = (new SgdWordInspectionService())->validateUpload(
                (string)$file['tmp_name'],
                $uploadName,
                $archivoSettings['word_proteccion_escritura_obligatoria']
            );
            if (empty($wordCheck['allowed'])) {
                return [
                    'success' => false,
                    'message' => (string)($wordCheck['message'] ?? 'El documento Word no cumple la política de protección.'),
                ];
            }
            if (!empty($wordCheck['warning'])) {
                $warning = (string)$wordCheck['warning'];
            }
        }

        $path = $this->storeArchivoUpload($empresaId, $documentoId, $versionId, $file, $allowedExtensions);
        if ($path === null) {
            $uploadErr = is_array($file) ? (int)($file['error'] ?? 0) : UPLOAD_ERR_NO_FILE;
            $extHint = implode(', ', array_map(static fn($e) => '.' . strtoupper($e), $allowedExtensions));
            $msg = match ($uploadErr) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Archivo demasiado grande (límite del servidor).',
                UPLOAD_ERR_PARTIAL => 'La subida quedó incompleta; intente de nuevo.',
                default => 'Archivo no válido (' . $extHint . ') o no se pudo guardar.',
            };

            return ['success' => false, 'message' => $msg];
        }

        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $this->repo->updateDocumentoVersionArchivo($empresaId, $versionId, $path, $userId);

        $result = [
            'success' => true,
            'message' => 'Archivo almacenado.',
            'path' => $path,
        ];
        if ($warning !== null) {
            $result['warning'] = $warning;
        }

        return $result;
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

        $version = $this->repo->findDocumentoVersionById($empresaId, $versionId);
        if ($version === null) {
            return ['success' => false, 'message' => 'Versión no encontrada.'];
        }

        if ((int)$version['estado_id'] !== SgdRepository::ESTADO_DOC_BORRADOR) {
            return ['success' => false, 'message' => 'Solo se pueden publicar versiones en borrador.'];
        }

        $fechaAprobacion = $this->parseFechaAprobacion($post['fecha_aprobacion'] ?? null, date('Y-m-d'));
        if ($fechaAprobacion === null) {
            return ['success' => false, 'message' => 'Indique una fecha de aprobación válida.'];
        }

        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $documentoId = (int)$version['documento_id'];
        $archivoRuta = trim((string)($version['archivo_ruta'] ?? ''));

        $documento = $this->repo->findDocumentoById($empresaId, $documentoId);
        $documento = $documento ? $this->elabService->resolveModoDocumento($empresaId, $documento) : null;
        $esOperativo = $documento && ($documento['modo_efectivo'] ?? '') !== 'maestro';

        if ($archivoRuta === '' && $this->elabService->canAutoGeneratePdf($empresaId, $documentoId)) {
            $versionForPdf = $version;
            $versionForPdf['fecha_aprobacion'] = $fechaAprobacion;

            $validation = $this->elabService->validateForPublish($empresaId, $documentoId);
            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'message' => implode(' ', $validation['errors']),
                ];
            }

            $generated = $this->pdfService->generateForVersion($empresaId, $documentoId, $versionId, $versionForPdf);
            if (!$generated['success']) {
                return $generated;
            }

            $archivoRuta = (string)($generated['path'] ?? '');
            $this->repo->updateDocumentoVersionArchivo($empresaId, $versionId, $archivoRuta, $userId);
            if (!empty($generated['snapshot']) && is_array($generated['snapshot'])) {
                $this->repo->saveDocumentoVersionContenido($empresaId, $versionId, $generated['snapshot']);
            }
        }

        if ($archivoRuta === '' && $esOperativo) {
            $esqueleto = new SgdFormularioEsqueletoService();
            $meta = $esqueleto->resolveMetaForDocumento($empresaId, $documentoId);
            if (!empty($meta['can_auto_pdf'])) {
                $formularioVersionId = (int)($version['formulario_version_id'] ?? 0);
                if ($formularioVersionId <= 0) {
                    $formulario = $this->repo->findFormularioByDocumento($empresaId, $documentoId, 'operativo');
                    if ($formulario) {
                        $fv = $this->repo->findFormularioBorradorVersion($empresaId, (int)$formulario['id']);
                        if ($fv && trim((string)($fv['numero'] ?? '')) === trim((string)($version['numero'] ?? ''))) {
                            $formularioVersionId = (int)$fv['id'];
                        }
                    }
                }
                if ($formularioVersionId > 0) {
                    $preview = new SgdFormularioPreviewService();
                    $generated = $preview->saveSkeletonPdf(
                        $empresaId,
                        $documentoId,
                        $versionId,
                        $formularioVersionId
                    );
                    if (!$generated['success']) {
                        return $generated;
                    }
                    $archivoRuta = (string)($generated['path'] ?? '');
                }
            }
        }

        if ($archivoRuta === '') {
            return ['success' => false, 'message' => 'Suba el archivo oficial o complete la elaboración / plantilla antes de publicar.'];
        }

        if ($esOperativo) {
            $formularioVersionId = (int)($version['formulario_version_id'] ?? 0);
            if ($formularioVersionId <= 0) {
                $formulario = $this->repo->findFormularioByDocumento($empresaId, $documentoId, 'operativo');
                if ($formulario) {
                    $fv = $this->repo->findFormularioBorradorVersion($empresaId, (int)$formulario['id']);
                    if ($fv && trim((string)($fv['numero'] ?? '')) === trim((string)($version['numero'] ?? ''))) {
                        $formularioVersionId = (int)$fv['id'];
                    }
                }
            }
            $tienePlantilla = false;
            if ($formularioVersionId > 0) {
                $fv = $this->repo->findFormularioVersionById($empresaId, $formularioVersionId);
                if ($fv !== null) {
                    $formularioService = new SgdFormularioService();
                    $esquema = $formularioService->decodeEsquemaJson($fv['esquema_json'] ?? null);
                    $tienePlantilla = $formularioService->esquemaTieneContenido($esquema);
                }
            }
            if ($formularioVersionId > 0 && $tienePlantilla) {
                try {
                    $this->repo->publishOperativoPlantillaBundle(
                        $empresaId,
                        $formularioVersionId,
                        $versionId,
                        $userId,
                        $fechaAprobacion
                    );
                } catch (Throwable $e) {
                    return ['success' => false, 'message' => 'No se pudo publicar: ' . $e->getMessage()];
                }

                return [
                    'success' => true,
                    'message' => 'Versión ' . $version['numero'] . ' publicada con plantilla operativa vigente.',
                ];
            }
        }

        $this->repo->publishDocumentoVersion($empresaId, $versionId, $userId, $fechaAprobacion);

        return [
            'success' => true,
            'message' => 'Versión ' . $version['numero'] . ' publicada. El documento quedó vigente.',
        ];
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function saveFechaAprobacion(array $post, array $query): array
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

        $version = $this->repo->findDocumentoVersionById($empresaId, $versionId);
        if ($version === null) {
            return ['success' => false, 'message' => 'Versión no encontrada.'];
        }

        $estadoId = (int)($version['estado_id'] ?? 0);
        if (!in_array($estadoId, [SgdRepository::ESTADO_DOC_BORRADOR, SgdRepository::ESTADO_DOC_VIGENTE], true)) {
            return ['success' => false, 'message' => 'Solo se puede editar la fecha en versiones borrador o vigentes.'];
        }

        $fechaAprobacion = $this->parseFechaAprobacion($post['fecha_aprobacion'] ?? null);
        if ($fechaAprobacion === null) {
            return ['success' => false, 'message' => 'Indique una fecha de aprobación válida.'];
        }

        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $this->repo->updateDocumentoVersionFecha($empresaId, $versionId, $fechaAprobacion, $userId);

        return ['success' => true, 'message' => 'Fecha de aprobación actualizada.'];
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function deleteVersion(array $post, array $query): array
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

        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

        try {
            $version = $this->repo->findDocumentoVersionById($empresaId, $versionId);
            if ($version !== null && (int)($version['estado_id'] ?? 0) === SgdRepository::ESTADO_DOC_BORRADOR) {
                (new SgdVersionBundleService())->deleteBorradorBundle($empresaId, $versionId, $userId);
            } else {
                $this->repo->softDeleteDocumentoVersion($empresaId, $versionId, $userId);
            }
        } catch (RuntimeException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        return ['success' => true, 'message' => 'Versión eliminada (plantilla y archivo oficial).'];
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function revertVigenteVersion(array $post, array $query): array
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
            : 0;
        $versionId = isset($post['version_id']) && ctype_digit((string)$post['version_id'])
            ? (int)$post['version_id']
            : 0;
        if ($documentoId <= 0 || $versionId <= 0) {
            return ['success' => false, 'message' => 'Versión o documento no válido.'];
        }

        $doc = $this->repo->findDocumentoById($empresaId, $documentoId);
        if ($doc === null) {
            return ['success' => false, 'message' => 'Documento no encontrado.'];
        }

        $version = $this->repo->findDocumentoVersionById($empresaId, $versionId);
        if ($version === null || (int)($version['documento_id'] ?? 0) !== $documentoId) {
            return ['success' => false, 'message' => 'Versión no encontrada.'];
        }

        $codigoService = new SgdDocumentoCodigoService();
        $expectedCodigo = strtoupper(trim($codigoService->buildForDocument($empresaId, $doc, $this->repo)));
        $confirmCodigo = strtoupper(trim((string)($post['confirm_codigo'] ?? '')));
        if ($confirmCodigo === '' || $confirmCodigo !== $expectedCodigo) {
            return ['success' => false, 'message' => 'El código del documento no coincide.'];
        }

        $confirmVersion = trim((string)($post['confirm_version'] ?? ''));
        $versionNumero = trim((string)($version['numero'] ?? ''));
        if ($confirmVersion === '' || $confirmVersion !== $versionNumero) {
            return ['success' => false, 'message' => 'El número de versión no coincide.'];
        }

        $password = (string)($post['confirm_password'] ?? '');
        if ($password === '') {
            return ['success' => false, 'message' => 'Indique su contraseña para confirmar.'];
        }

        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
        if ($userId <= 0 || !AuthService::verifyPasswordForUserId($userId, $password)) {
            return ['success' => false, 'message' => 'Contraseña incorrecta.'];
        }

        try {
            $archivoRuta = $this->repo->revertVigentePublication($empresaId, $versionId, $userId);
            if ($archivoRuta !== null && $archivoRuta !== '') {
                try {
                    StorageService::instance()->delete($archivoRuta);
                } catch (Throwable $e) {
                    error_log('SGD revert vigente: no se pudo borrar archivo ' . $archivoRuta . ' — ' . $e->getMessage());
                }
            }
        } catch (RuntimeException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        return [
            'success' => true,
            'message' => 'Publicación revertida. La versión volvió a borrador; puede editarla en el diseñador.',
        ];
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function obsoleteDocument(array $post, array $query): array
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
            : 0;
        if ($documentoId <= 0) {
            return ['success' => false, 'message' => 'Documento no válido.'];
        }

        $doc = $this->repo->findDocumentoById($empresaId, $documentoId);
        if ($doc === null) {
            return ['success' => false, 'message' => 'Documento no encontrado.'];
        }

        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $this->repo->obsoleteDocumentoMaestro($empresaId, $documentoId, $userId);

        return ['success' => true, 'message' => 'Documento marcado como obsoleto.'];
    }

    /**
     * @param array<string, mixed>|null $file
     * @param list<string> $allowedExtensions
     */
    private function storeArchivoUpload(
        int $empresaId,
        int $documentoId,
        int $versionId,
        mixed $file,
        array $allowedExtensions = ['pdf']
    ): ?string {
        if (!is_array($file) || empty($file['tmp_name']) || !is_uploaded_file((string)$file['tmp_name'])) {
            return null;
        }

        if ((int)($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
            return null;
        }

        $name = (string)($file['name'] ?? '');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, $allowedExtensions, true)) {
            return null;
        }

        $mime = '';
        if (class_exists('finfo')) {
            $info = new finfo(FILEINFO_MIME_TYPE);
            $mime = $info->file((string)$file['tmp_name']) ?: '';
        }
        $allowedMimes = [
            'pdf' => ['application/pdf'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            'xls' => ['application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'doc' => ['application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        ];
        if ($mime !== '' && isset($allowedMimes[$ext]) && !in_array($mime, $allowedMimes[$ext], true)) {
            return null;
        }

        $filename = 'v' . $versionId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;

        return StorageService::instance()->putUploadedFile(
            StorageService::ZONE_SGD,
            $filename,
            $file,
            $empresaId,
            $documentoId
        );
    }

    private function parseFechaAprobacion(mixed $raw, ?string $default = null): ?string
    {
        $value = trim((string)($raw ?? ''));
        if ($value === '') {
            $value = trim((string)($default ?? ''));
        }
        if ($value === '') {
            return null;
        }

        $dt = DateTime::createFromFormat('Y-m-d', $value);

        return $dt && $dt->format('Y-m-d') === $value ? $value : null;
    }
}
