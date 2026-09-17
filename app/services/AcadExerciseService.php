<?php

/**
 * Audios de referencia de un ejercicio (N por ejercicio, grabados en el
 * navegador por el admin/docente o subidos como archivo). Calco de
 * SgdElaboracionService::uploadMedia para la validación (MIME real,
 * whitelist, límite de tamaño, nombre aleatorio, StorageService).
 */
class AcadExerciseService
{
    /** Voz neuronal usada para generar audio de referencia con texto a voz. */
    private const TTS_VOICE = 'en-US-JennyNeural';

    private const TTS_BIN = BASE_PATH . '/scripts/.tts-venv/bin/edge-tts';

    private const TTS_TIMEOUT_SECONDS = 20;

    private const TTS_MAX_TEXT_LENGTH = 500;

    /**
     * Subconjunto curado de las voces neuronales en-US disponibles en el
     * servidor (edge-tts), para que el admin elija una voz por rol en un
     * ejercicio DIALOGUE y suene a dos hablantes distintos. Se excluyen las
     * variantes "Multilingual" (redundantes con su versión base en inglés).
     */
    public const DIALOGUE_VOICES = [
        'en-US-JennyNeural' => 'Jenny (female)',
        'en-US-AriaNeural' => 'Aria (female)',
        'en-US-MichelleNeural' => 'Michelle (female)',
        'en-US-AnaNeural' => 'Ana (female, child-like)',
        'en-US-GuyNeural' => 'Guy (male)',
        'en-US-AndrewNeural' => 'Andrew (male)',
        'en-US-BrianNeural' => 'Brian (male)',
        'en-US-ChristopherNeural' => 'Christopher (male)',
        'en-US-EricNeural' => 'Eric (male)',
        'en-US-RogerNeural' => 'Roger (male)',
        'en-US-SteffanNeural' => 'Steffan (male)',
    ];

    private AcadRepository $repo;
    private AcadScopeService $scope;

    public function __construct()
    {
        $this->repo = new AcadRepository();
        $this->scope = new AcadScopeService();
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function listReferenceAudios(array $query, int $exerciseId): array
    {
        try {
            $empresaId = $this->scope->requireEmpresaId($query);
        } catch (RuntimeException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        if ($exerciseId <= 0 || $this->repo->findExercise($empresaId, $exerciseId) === null) {
            return ['success' => false, 'message' => 'Exercise not found.'];
        }

        return ['success' => true, 'audios' => $this->repo->getReferenceAudiosByExercise($exerciseId)];
    }

    /**
     * @param array<string, mixed> $files
     * @param array<string, mixed> $post
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function addReferenceAudio(array $files, array $post, array $query): array
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
        if ($exerciseId <= 0 || $this->repo->findExercise($empresaId, $exerciseId) === null) {
            return ['success' => false, 'message' => 'Exercise not found.'];
        }

        $file = $files['archivo'] ?? null;
        $validated = AcadAudioValidationService::validate(is_array($file) ? $file : null);
        if (!$validated['success']) {
            return $validated;
        }

        $name = 'ref_' . bin2hex(random_bytes(8)) . '.' . $validated['ext'];
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

        $textoEn = trim((string)($post['texto_en'] ?? ''));
        $id = $this->appendReferenceAudio($exerciseId, $textoEn !== '' ? $textoEn : null, $path);

        return ['success' => true, 'message' => 'Reference audio added.', 'id' => $id, 'path' => $path];
    }

    /**
     * Genera un audio de referencia con texto a voz (voz neuronal, sin API
     * key) en vez de grabarlo/subirlo — mismo destino final (StorageService +
     * acad_exercise_reference_audio) que addReferenceAudio().
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function generateReferenceAudio(array $post, array $query): array
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
        if ($exerciseId <= 0 || $this->repo->findExercise($empresaId, $exerciseId) === null) {
            return ['success' => false, 'message' => 'Exercise not found.'];
        }

        $text = trim((string)($post['generate_text'] ?? ''));
        if ($text === '') {
            return ['success' => false, 'message' => 'Enter the text to synthesize.'];
        }
        if (mb_strlen($text) > self::TTS_MAX_TEXT_LENGTH) {
            return ['success' => false, 'message' => 'Text is too long (max ' . self::TTS_MAX_TEXT_LENGTH . ' characters).'];
        }

        $path = $this->synthesizeToStorage($empresaId, $exerciseId, $text, self::TTS_VOICE);
        if ($path === null) {
            return ['success' => false, 'message' => 'Speech synthesis failed.'];
        }

        $id = $this->appendReferenceAudio($exerciseId, $text, $path);

        return ['success' => true, 'message' => 'Reference audio generated.', 'id' => $id, 'path' => $path];
    }

    /**
     * Sintetiza un texto con la voz indicada y lo sube al storage del
     * ejercicio. Extraído de generateReferenceAudio() para reutilizarlo
     * también en generateDialogueAudio(), que sintetiza N turnos con DOS
     * voces distintas (una por rol) en vez de una sola.
     */
    private function synthesizeToStorage(int $empresaId, int $exerciseId, string $text, string $voice): ?string
    {
        if (!is_executable(self::TTS_BIN)) {
            return null;
        }

        $tmpPath = sys_get_temp_dir() . '/acad_tts_' . bin2hex(random_bytes(8)) . '.mp3';

        $process = proc_open(
            ['timeout', (string)self::TTS_TIMEOUT_SECONDS, self::TTS_BIN, '--voice', $voice, '--text', $text, '--write-media', $tmpPath],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            return null;
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        if (!is_readable($tmpPath) || filesize($tmpPath) === 0) {
            @unlink($tmpPath);

            return null;
        }

        $name = 'tts_' . bin2hex(random_bytes(8)) . '.mp3';
        $path = StorageService::instance()->putLocalFile(
            StorageService::ZONE_ACAD_MEDIA,
            $name,
            $tmpPath,
            true,
            $empresaId,
            $exerciseId
        );
        @unlink($tmpPath);

        return $path;
    }

    /**
     * Inserta un audio de referencia al final del orden actual del ejercicio
     * (mismo criterio "siguiente múltiplo de 10" usado por addReferenceAudio
     * y generateReferenceAudio).
     */
    private function appendReferenceAudio(int $exerciseId, ?string $textoEn, string $path): int
    {
        $existing = $this->repo->getReferenceAudiosByExercise($exerciseId);
        $orden = 10;
        foreach ($existing as $ra) {
            $orden = max($orden, (int)$ra['orden'] + 10);
        }

        return $this->repo->insertReferenceAudio($exerciseId, $textoEn, $path, $orden);
    }

    /**
     * "Dialogue": sintetiza TODOS los turnos guardados de un ejercicio
     * DIALOGUE de una vez, usando una voz distinta por rol (para que suene a
     * conversación entre dos personas, no un solo narrador). Reemplaza
     * cualquier audio de referencia anterior del ejercicio — se regenera
     * completo cada vez, no turno por turno. El `orden` del audio generado
     * queda igual al `orden` (número de turno) de la opción que narra, para
     * poder emparejar turno↔audio en la vista sin columna nueva.
     *
     * @return array<string, mixed>
     */
    public function generateDialogueAudio(array $post, array $query): array
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
        $exercise = $exerciseId > 0 ? $this->repo->findExercise($empresaId, $exerciseId) : null;
        if ($exercise === null) {
            return ['success' => false, 'message' => 'Exercise not found.'];
        }
        if ($exercise['exercise_type_codigo'] !== 'DIALOGUE') {
            return ['success' => false, 'message' => 'This exercise is not a dialogue.'];
        }

        $voiceRole1 = (string)($post['voice_role1'] ?? '');
        $voiceRole2 = (string)($post['voice_role2'] ?? '');
        if (!isset(self::DIALOGUE_VOICES[$voiceRole1]) || !isset(self::DIALOGUE_VOICES[$voiceRole2])) {
            return ['success' => false, 'message' => 'Choose a voice for each role.'];
        }

        if (!is_executable(self::TTS_BIN)) {
            return ['success' => false, 'message' => 'Text-to-speech engine is not installed on the server.'];
        }

        $turns = $this->repo->getOptionsByExercise($exerciseId);
        if ($turns === []) {
            return ['success' => false, 'message' => 'Save the dialogue turns first.'];
        }

        foreach ($this->repo->getReferenceAudiosByExercise($exerciseId) as $ra) {
            StorageService::instance()->delete($ra['ruta']);
            $this->repo->deleteReferenceAudio((int)$ra['id']);
        }

        $generated = 0;
        foreach ($turns as $turn) {
            $voice = (int)$turn['blank_index'] === 1 ? $voiceRole1 : $voiceRole2;
            $path = $this->synthesizeToStorage($empresaId, $exerciseId, (string)$turn['texto_en'], $voice);
            if ($path === null) {
                return [
                    'success' => false,
                    'message' => "Speech synthesis failed on turn {$turn['orden']}. {$generated} turn(s) were generated before this failure.",
                ];
            }
            $this->repo->insertReferenceAudio($exerciseId, (string)$turn['texto_en'], $path, (int)$turn['orden']);
            $generated++;
        }

        return ['success' => true, 'message' => "Generated {$generated} turn audios."];
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function deleteReferenceAudio(array $post, array $query): array
    {
        try {
            $empresaId = $this->scope->requireEmpresaId($query);
        } catch (RuntimeException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        $exerciseId = (int)($post['exercise_id'] ?? $query['exercise_id'] ?? 0);
        $referenceAudioId = (int)($post['delete_id'] ?? 0);
        if ($exerciseId <= 0 || $referenceAudioId <= 0 || $this->repo->findExercise($empresaId, $exerciseId) === null) {
            return ['success' => false, 'message' => 'Exercise not found.'];
        }

        $audio = $this->repo->findReferenceAudio($exerciseId, $referenceAudioId);
        if ($audio === null) {
            return ['success' => false, 'message' => 'Reference audio not found.'];
        }

        StorageService::instance()->delete($audio['ruta']);
        $this->repo->deleteReferenceAudio($referenceAudioId);

        return ['success' => true, 'message' => 'Reference audio deleted.'];
    }
}
