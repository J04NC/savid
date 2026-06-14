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

        $numero = trim((string)($post['numero'] ?? ''));
        if ($numero === '') {
            $numero = $this->repo->suggestNextVersionNumero($empresaId, $documentoId);
        }

        if ($this->repo->documentoVersionNumeroExists($empresaId, $documentoId, $numero)) {
            return ['success' => false, 'message' => 'Ya existe la versión «' . $numero . '» para este documento.'];
        }

        $fechaAprobacion = $this->parseFechaAprobacion($post['fecha_aprobacion'] ?? null);

        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
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
     * @return array{success: bool, message: string, path?: string}
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
            return ['success' => false, 'message' => 'Solo se puede subir PDF a versiones en borrador.'];
        }

        $file = $files['archivo'] ?? null;
        if ($file === null && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            return [
                'success' => false,
                'message' => 'No llegó el archivo (puede superar el límite de subida del servidor). Pruebe un PDF más pequeño.',
            ];
        }

        $path = $this->storePdfUpload($empresaId, (int)$version['documento_id'], $versionId, $file);
        if ($path === null) {
            $uploadErr = is_array($file) ? (int)($file['error'] ?? 0) : UPLOAD_ERR_NO_FILE;
            $msg = match ($uploadErr) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'PDF demasiado grande (límite del servidor).',
                UPLOAD_ERR_PARTIAL => 'La subida quedó incompleta; intente de nuevo.',
                default => 'Archivo PDF no válido o no se pudo guardar.',
            };

            return ['success' => false, 'message' => $msg];
        }

        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $this->repo->updateDocumentoVersionArchivo($empresaId, $versionId, $path, $userId);

        return [
            'success' => true,
            'message' => 'PDF almacenado.',
            'path' => $path,
        ];
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

        if ($archivoRuta === '') {
            return ['success' => false, 'message' => 'Suba el PDF oficial o complete la elaboración en el sistema antes de publicar.'];
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
     */
    private function storePdfUpload(int $empresaId, int $documentoId, int $versionId, mixed $file): ?string
    {
        if (!is_array($file) || empty($file['tmp_name']) || !is_uploaded_file((string)$file['tmp_name'])) {
            return null;
        }

        if ((int)($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
            return null;
        }

        $name = (string)($file['name'] ?? '');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext !== 'pdf') {
            return null;
        }

        $mime = '';
        if (class_exists('finfo')) {
            $info = new finfo(FILEINFO_MIME_TYPE);
            $mime = $info->file((string)$file['tmp_name']) ?: '';
        }
        if ($mime !== '' && $mime !== 'application/pdf') {
            return null;
        }

        $dir = BASE_PATH . '/public/uploads/sgd/' . $empresaId . '/' . $documentoId;
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return null;
        }

        $filename = 'v' . $versionId . '_' . bin2hex(random_bytes(8)) . '.pdf';
        $dest = $dir . '/' . $filename;
        if (!move_uploaded_file((string)$file['tmp_name'], $dest)) {
            return null;
        }

        return '/uploads/sgd/' . $empresaId . '/' . $documentoId . '/' . $filename;
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
