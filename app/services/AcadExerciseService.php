<?php

/**
 * Subida del audio de referencia de un ejercicio de speaking.
 * Calco de SgdElaboracionService::uploadMedia (validación MIME real,
 * whitelist, límite de tamaño, nombre aleatorio, StorageService).
 */
class AcadExerciseService
{
    private const ALLOWED_MIME = [
        'audio/mpeg' => 'mp3',
        'audio/mp4' => 'm4a',
        'audio/x-m4a' => 'm4a',
        'audio/webm' => 'webm',
        'audio/ogg' => 'ogg',
        'audio/wav' => 'wav',
        'audio/x-wav' => 'wav',
    ];

    private const MAX_BYTES = 15 * 1024 * 1024;

    private AcadRepository $repo;
    private AcadScopeService $scope;

    public function __construct()
    {
        $this->repo = new AcadRepository();
        $this->scope = new AcadScopeService();
    }

    /**
     * @param array<string, mixed> $files
     * @param array<string, mixed> $post
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function uploadReferenceAudio(array $files, array $post, array $query): array
    {
        try {
            $empresaId = $this->scope->requireEmpresaId($query);
        } catch (RuntimeException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        if (!$this->scope->canAccessEmpresa($empresaId, $query)) {
            return ['success' => false, 'message' => 'No access to this company.'];
        }

        $exerciseId = (int)($post['exercise_id'] ?? $query['exercise_id'] ?? 0);
        if ($exerciseId <= 0) {
            return ['success' => false, 'message' => 'Invalid exercise.'];
        }

        $exercise = $this->repo->findExercise($empresaId, $exerciseId);
        if ($exercise === null) {
            return ['success' => false, 'message' => 'Exercise not found.'];
        }

        $file = $files['archivo'] ?? null;
        if (!is_array($file) || empty($file['tmp_name']) || !is_uploaded_file((string)$file['tmp_name'])) {
            return ['success' => false, 'message' => 'Audio file required.'];
        }

        $uploadErr = (int)($file['error'] ?? 0);
        if ($uploadErr !== UPLOAD_ERR_OK) {
            $msg = match ($uploadErr) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Audio file too large (server limit).',
                UPLOAD_ERR_PARTIAL => 'Upload was interrupted.',
                UPLOAD_ERR_NO_FILE => 'Audio file required.',
                default => 'Error uploading audio file.',
            };

            return ['success' => false, 'message' => $msg];
        }

        $tmp = (string)$file['tmp_name'];
        $mime = '';
        if (class_exists('finfo')) {
            $info = new finfo(FILEINFO_MIME_TYPE);
            $mime = $info->file($tmp) ?: '';
        }
        if ($mime === '' && function_exists('mime_content_type')) {
            $mime = mime_content_type($tmp) ?: '';
        }

        if (!isset(self::ALLOWED_MIME[$mime])) {
            return ['success' => false, 'message' => 'Only MP3, M4A, WEBM, OGG or WAV audio is allowed.'];
        }

        clearstatcache(true, $tmp);
        if ((int)@filesize($tmp) > self::MAX_BYTES) {
            return ['success' => false, 'message' => 'Maximum 15 MB per audio file.'];
        }

        $ext = self::ALLOWED_MIME[$mime];
        $name = 'ref_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $path = StorageService::instance()->putUploadedFile(
            StorageService::ZONE_ACAD_MEDIA,
            $name,
            $file,
            $empresaId,
            $exerciseId
        );
        if ($path === null) {
            return ['success' => false, 'message' => 'Could not save the audio file.'];
        }

        $this->repo->setExerciseAudioReferencia($empresaId, $exerciseId, $path);

        return ['success' => true, 'message' => 'Reference audio uploaded.', 'path' => $path];
    }
}
