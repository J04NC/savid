<?php

/**
 * Intentos de ejercicio: reading se autocalifica al guardar; writing y
 * speaking quedan pendientes de calificación manual por el docente.
 */
class AcadAttemptService
{
    private AcadRepository $repo;
    private AcadScopeService $scope;

    public function __construct()
    {
        $this->repo = new AcadRepository();
        $this->scope = new AcadScopeService();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listPending(int $empresaId): array
    {
        return $empresaId > 0 ? $this->repo->findPendingAttempts($empresaId) : [];
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
            'FILL_BLANKS' => $this->submitFillBlanks($empresaId, $usuarioId, $exercise, $post),
            'SENTENCE_ORDER' => $this->submitSentenceOrder($empresaId, $usuarioId, $exercise, $post),
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
     * "Fill in the blanks": compara la palabra que el estudiante ubicó en
     * cada hueco contra la palabra de acad_exercise_option cuyo blank_index
     * coincide (las que tienen blank_index NULL son señuelos, nunca
     * cuentan). Se autocalifica al instante, igual que Multiple Choice.
     *
     * @param array<string, mixed> $exercise
     */
    private function submitFillBlanks(int $empresaId, int $usuarioId, array $exercise, array $post): array
    {
        $submitted = json_decode((string)($post['blanks'] ?? '{}'), true);
        if (!is_array($submitted)) {
            $submitted = [];
        }

        $correctByBlank = [];
        foreach ($this->repo->getOptionsByExercise((int)$exercise['id']) as $option) {
            if ($option['blank_index'] !== null) {
                $correctByBlank[(int)$option['blank_index']] = mb_strtolower(trim((string)$option['texto_en']));
            }
        }

        $totalBlanks = count($correctByBlank);
        if ($totalBlanks === 0) {
            return ['success' => false, 'message' => 'This exercise has no word bank configured yet.'];
        }

        $correctCount = 0;
        foreach ($correctByBlank as $blankIndex => $correctWord) {
            $given = mb_strtolower(trim((string)($submitted[(string)$blankIndex] ?? $submitted[$blankIndex] ?? '')));
            if ($given !== '' && $given === $correctWord) {
                $correctCount++;
            }
        }

        $maxScore = (float)$exercise['max_score'];
        $score = round(($correctCount / $totalBlanks) * $maxScore, 2);
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s.v');

        $attemptId = $this->repo->insertAttempt([
            'empresa_id' => $empresaId,
            'exercise_id' => (int)$exercise['id'],
            'usuario_id' => $usuarioId,
            'iniciado_at' => $now,
            'enviado_at' => $now,
            'respuesta_texto' => json_encode($submitted, JSON_UNESCAPED_UNICODE),
            'score' => $score,
            'calificado_at' => $now,
            'estado_id' => AcadRepository::ESTADO_AUTO_CALIFICADO,
        ]);

        return [
            'success' => true,
            'message' => $correctCount === $totalBlanks ? 'All correct!' : "{$correctCount} of {$totalBlanks} correct.",
            'attempt_id' => $attemptId,
            'correct' => $correctCount === $totalBlanks,
            'correct_count' => $correctCount,
            'total_blanks' => $totalBlanks,
            'score' => $score,
            'max_score' => $maxScore,
        ];
    }

    /**
     * "Sentence order": compara la secuencia de ids de opción que el
     * estudiante ubicó en cada oración contra el orden correcto real (las
     * opciones de esa oración, es decir mismo blank_index, ordenadas por
     * `orden`). Comparación por id (no por texto) para no confundirse si hay
     * palabras repetidas entre una oración y los señuelos. Autocalifica al
     * instante, igual que Multiple Choice y Fill in the blanks.
     *
     * @param array<string, mixed> $exercise
     */
    private function submitSentenceOrder(int $empresaId, int $usuarioId, array $exercise, array $post): array
    {
        $submitted = json_decode((string)($post['order_answers'] ?? '{}'), true);
        if (!is_array($submitted)) {
            $submitted = [];
        }

        $correctBySentence = [];
        foreach ($this->repo->getOptionsByExercise((int)$exercise['id']) as $option) {
            if ($option['blank_index'] !== null) {
                $correctBySentence[(int)$option['blank_index']][(int)$option['orden']] = (int)$option['id'];
            }
        }

        $totalSentences = count($correctBySentence);
        if ($totalSentences === 0) {
            return ['success' => false, 'message' => 'This exercise has no sentences configured yet.'];
        }

        $correctCount = 0;
        foreach ($correctBySentence as $sentenceIndex => $wordsByPosition) {
            ksort($wordsByPosition);
            $correctIds = array_values($wordsByPosition);
            $givenIds = $submitted[(string)$sentenceIndex] ?? $submitted[$sentenceIndex] ?? [];
            $givenIds = is_array($givenIds) ? array_map('intval', $givenIds) : [];
            if ($givenIds === $correctIds) {
                $correctCount++;
            }
        }

        $maxScore = (float)$exercise['max_score'];
        $score = round(($correctCount / $totalSentences) * $maxScore, 2);
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s.v');

        $attemptId = $this->repo->insertAttempt([
            'empresa_id' => $empresaId,
            'exercise_id' => (int)$exercise['id'],
            'usuario_id' => $usuarioId,
            'iniciado_at' => $now,
            'enviado_at' => $now,
            'respuesta_texto' => json_encode($submitted, JSON_UNESCAPED_UNICODE),
            'score' => $score,
            'calificado_at' => $now,
            'estado_id' => AcadRepository::ESTADO_AUTO_CALIFICADO,
        ]);

        return [
            'success' => true,
            'message' => $correctCount === $totalSentences ? 'All correct!' : "{$correctCount} of {$totalSentences} sentences correct.",
            'attempt_id' => $attemptId,
            'correct' => $correctCount === $totalSentences,
            'correct_count' => $correctCount,
            'total_sentences' => $totalSentences,
            'score' => $score,
            'max_score' => $maxScore,
        ];
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

        $isTongueTwister = $exercise['exercise_type_codigo'] === 'TONGUE_TWISTER';
        $acceptsAudio = in_array($exercise['exercise_type_codigo'], ['AUDIO_RESPONSE', 'TONGUE_TWISTER', 'DIALOGUE'], true);
        if (!$acceptsAudio) {
            return ['success' => false, 'message' => 'This exercise does not accept audio answers.'];
        }

        $referenceAudioId = (int)($post['reference_audio_id'] ?? 0);
        if ($referenceAudioId > 0) {
            if ($this->repo->findReferenceAudio($exerciseId, $referenceAudioId) === null) {
                return ['success' => false, 'message' => 'Reference audio not found.'];
            }
            // TONGUE_TWISTER no bloquea: el estudiante puede volver a grabar
            // tantas veces como quiera para ver su progresión de velocidad;
            // cada toma queda como una fila propia (ver
            // findAttemptsHistoryForReferenceAudio()). AUDIO_RESPONSE y
            // DIALOGUE (una toma confirmada por turno) sí bloquean.
            if (!$isTongueTwister && $this->repo->findAttemptForReferenceAudio($empresaId, $exerciseId, $referenceAudioId, $usuarioId) !== null) {
                return ['success' => false, 'message' => 'This recording is already confirmed.'];
            }
        }

        $file = $files['archivo'] ?? null;
        $validated = AcadAudioValidationService::validate(is_array($file) ? $file : null);
        if (!$validated['success']) {
            return $validated;
        }

        $name = 'attempt_' . bin2hex(random_bytes(8)) . '.' . $validated['ext'];
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

        // Duración medida por el navegador (fin - inicio de grabación), solo
        // informativa/motivacional — nunca se usa para calificar. Se acepta
        // un rango amplio pero acotado (hasta 2 minutos) para descartar
        // valores corruptos sin pretender validar precisión real.
        $metaJson = null;
        if ($isTongueTwister) {
            $durationMs = (int)($post['duration_ms'] ?? 0);
            if ($durationMs > 0 && $durationMs < 120000) {
                $metaJson = json_encode(['duration_ms' => $durationMs], JSON_UNESCAPED_UNICODE);
            }
        }

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s.v');
        $attemptId = $this->repo->insertAttempt([
            'empresa_id' => $empresaId,
            'exercise_id' => $exerciseId,
            'reference_audio_id' => $referenceAudioId > 0 ? $referenceAudioId : null,
            'usuario_id' => $usuarioId,
            'iniciado_at' => $now,
            'enviado_at' => $now,
            'audio_ruta' => $path,
            'meta_json' => $metaJson,
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
