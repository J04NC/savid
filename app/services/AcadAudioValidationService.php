<?php

/**
 * Validación de archivos de audio (whitelist MIME real vía finfo, límite de
 * tamaño) compartida por AcadExerciseService (audio de referencia) y
 * AcadAttemptService (respuesta grabada del estudiante) — antes vivía
 * duplicada e independiente en cada clase, lo que dejó pasar el mismo hueco
 * (no reconocer "video/webm") en una sin corregirlo en la otra.
 *
 * "video/webm" está en la whitelist a propósito: MediaRecorder graba
 * contenedores WebM sin pista de video, pero el sniffing de finfo/libmagic
 * frecuentemente los detecta como "video/webm" en vez de "audio/webm" al no
 * poder distinguir la ausencia de pista de video solo por el encabezado EBML.
 */
class AcadAudioValidationService
{
    public const ALLOWED_MIME = [
        'audio/mpeg' => 'mp3',
        'audio/mp4' => 'm4a',
        'audio/x-m4a' => 'm4a',
        'audio/webm' => 'webm',
        'video/webm' => 'webm',
        'audio/ogg' => 'ogg',
        'application/ogg' => 'ogg',
        'audio/wav' => 'wav',
        'audio/x-wav' => 'wav',
    ];

    public const MAX_BYTES = 15 * 1024 * 1024;

    /**
     * @param array<string, mixed>|null $file elemento de $_FILES
     * @return array{success: bool, message?: string, ext?: string}
     */
    public static function validate(?array $file): array
    {
        if ($file === null || empty($file['tmp_name']) || !is_uploaded_file((string)$file['tmp_name'])) {
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

        return ['success' => true, 'ext' => self::ALLOWED_MIME[$mime]];
    }
}
