<?php

/**
 * Intentos de ejercicio: reading se autocalifica al guardar; writing y
 * speaking quedan pendientes de calificación manual por el docente.
 */
class AcadAttemptService
{
    private const ALLOWED_AUDIO_MIME = [
        'audio/mpeg' => 'mp3',
        'audio/mp4' => 'm4a',
        'audio/x-m4a' => 'm4a',
        'audio/webm' => 'webm',
        'audio/ogg' => 'ogg',
        'audio/wav' => 'wav',
        'audio/x-wav' => 'wav',
    ];

    private const MAX_AUDIO_BYTES = 15 * 1024 * 1024;

    private AcadRepository $repo;
    private AcadScopeService $scope;

    public function __construct()
    {
        $this->repo = new AcadRepository();
        $this->scope = new AcadScopeService();
    }

    /**
     * @return array<string, mixed>
     */
    public function submit(array $post, array $query, int $usuarioId): array
    {
        try {
            $empresaId = $this->scope->requireEmpresaId($query);
        } catch (RuntimeException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        $exerciseId = (int)($post['exercise_id'] ?? 0);
        if ($exerciseId <= 0) {
            return ['success' => false, 'message' => 'Invalid exercise.'];
        }

        $exercise = $this->repo->findExercise($empresaId, $exerciseId);
        if ($exercise === null) {
            return ['success' => false, 'message' => 'Exercise not found.'];
        }

        return match ($exercise['exercise_type_codigo']) {
            'MULTIPLE_CHOICE' => $this->submitReading($empresaId, $usuarioId, $exercise, $post),
            'OPEN_TEXT' => $this->submitWriting($empresaId, $usuarioId, $exercise, $post),
            default => ['success' => false, 'message' => 'Use the audio upload endpoint for this exercise type.'],
        };
    }

    /**
     * @param array<string, mixed> $exercise
     */
    private function submitReading(int $empresaId, int $usuarioId, array $exercise, array $post): array
    {
        $selectedOptionId = (int)($post['selected_option_id'] ?? 0);
        if ($selectedOptionId <= 0) {
            return ['success' => false, 'message' => 'Select an option.'];
        }

        $option = $this->repo->findOption($selectedOptionId);
        if ($option === null || (int)$option['exercise_id'] !== (int)$exercise['id']) {
            return ['success' => false, 'message' => 'Invalid option for this exercise.'];
        }

        $esCorrecta = (bool)$option['es_correcta'];
        $score = $esCorrecta ? (float)$exercise['max_score'] : 0.0;
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s.v');

        $attemptId = $this->repo->insertAttempt([
            'empresa_id' => $empresaId,
            'exercise_id' => (int)$exercise['id'],
            'usuario_id' => $usuarioId,
            'iniciado_at' => $now,
            'enviado_at' => $now,
            'selected_option_id' => $selectedOptionId,
            'score' => $score,
            'calificado_at' => $now,
            'estado_id' => AcadRepository::ESTADO_AUTO_CALIFICADO,
        ]);

        return [
            'success' => true,
            'message' => $esCorrecta ? 'Correct!' : 'Not quite.',
            'attempt_id' => $attemptId,
            'correct' => $esCorrecta,
            'score' => $score,
            'max_score' => (float)$exercise['max_score'],
        ];
    }

    /**
     * @param array<string, mixed> $exercise
     */
    private function submitWriting(int $empresaId, int $usuarioId, array $exercise, array $post): array
    {
        $respuesta = trim((string)($post['respuesta_texto'] ?? ''));
        if ($respuesta === '') {
            return ['success' => false, 'message' => 'Write your answer before submitting.'];
        }

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s.v');
        $attemptId = $this->repo->insertAttempt([
            'empresa_id' => $empresaId,
            'exercise_id' => (int)$exercise['id'],
            'usuario_id' => $usuarioId,
            'iniciado_at' => $now,
            'enviado_at' => $now,
            'respuesta_texto' => mb_substr($respuesta, 0, 20000),
            'estado_id' => AcadRepository::ESTADO_PENDIENTE,
        ]);

        return ['success' => true, 'message' => 'Answer submitted for grading.', 'attempt_id' => $attemptId];
    }

    /**
     * @param array<string, mixed> $files
     * @return array<string, mixed>
     */
    public function submitSpeakingAudio(array $files, array $post, array $query, int $usuarioId): array
    {
        try {
            $empresaId = $this->scope->requireEmpresaId($query);
        } catch (RuntimeException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        $exerciseId = (int)($post['exercise_id'] ?? $query['exercise_id'] ?? 0);
        if ($exerciseId <= 0) {
            return ['success' => false, 'message' => 'Invalid exercise.'];
        }

        $exercise = $this->repo->findExercise($empresaId, $exerciseId);
        if ($exercise === null) {
            return ['success' => false, 'message' => 'Exercise not found.'];
        }

        if ($exercise['exercise_type_codigo'] !== 'AUDIO_RESPONSE') {
            return ['success' => false, 'message' => 'This exercise does not accept audio answers.'];
        }

        $file = $files['archivo'] ?? null;
        if (!is_array($file) || empty($file['tmp_name']) || !is_uploaded_file((string)$file['tmp_name'])) {
            return ['success' => false, 'message' => 'Audio recording required.'];
        }

        $uploadErr = (int)($file['error'] ?? 0);
        if ($uploadErr !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => 'Error uploading the audio recording.'];
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

        if (!isset(self::ALLOWED_AUDIO_MIME[$mime])) {
            return ['success' => false, 'message' => 'Only MP3, M4A, WEBM, OGG or WAV audio is allowed.'];
        }

        clearstatcache(true, $tmp);
        if ((int)@filesize($tmp) > self::MAX_AUDIO_BYTES) {
            return ['success' => false, 'message' => 'Maximum 15 MB per audio recording.'];
        }

        $ext = self::ALLOWED_AUDIO_MIME[$mime];
        $name = 'attempt_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $path = StorageService::instance()->putUploadedFile(
            StorageService::ZONE_ACAD_MEDIA,
            $name,
            $file,
            $empresaId,
            $exerciseId,
            $usuarioId
        );
        if ($path === null) {
            return ['success' => false, 'message' => 'Could not save the audio recording.'];
        }

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s.v');
        $attemptId = $this->repo->insertAttempt([
            'empresa_id' => $empresaId,
            'exercise_id' => $exerciseId,
            'usuario_id' => $usuarioId,
            'iniciado_at' => $now,
            'enviado_at' => $now,
            'audio_ruta' => $path,
            'estado_id' => AcadRepository::ESTADO_PENDIENTE,
        ]);

        return ['success' => true, 'message' => 'Recording submitted for grading.', 'attempt_id' => $attemptId, 'path' => $path];
    }

    /**
     * @return array<string, mixed>
     */
    public function grade(int $attemptId, array $post, array $query, int $calificadorId): array
    {
        try {
            $empresaId = $this->scope->requireEmpresaId($query);
        } catch (RuntimeException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        $attempt = $this->repo->findAttempt($empresaId, $attemptId);
        if ($attempt === null) {
            return ['success' => false, 'message' => 'Attempt not found.'];
        }

        if ((int)$attempt['estado_id'] !== AcadRepository::ESTADO_PENDIENTE) {
            return ['success' => false, 'message' => 'This attempt is not pending review.'];
        }

        $exercise = $this->repo->findExercise($empresaId, (int)$attempt['exercise_id']);
        $maxScore = $exercise !== null ? (float)$exercise['max_score'] : 100.0;

        $score = (float)($post['score'] ?? -1);
        if ($score < 0 || $score > $maxScore) {
            return ['success' => false, 'message' => "Score must be between 0 and {$maxScore}."];
        }

        $feedback = trim((string)($post['feedback_en'] ?? ''));

        $this->repo->gradeAttempt($attemptId, $score, $feedback !== '' ? mb_substr($feedback, 0, 5000) : null, $calificadorId);

        return ['success' => true, 'message' => 'Attempt graded.'];
    }
}
