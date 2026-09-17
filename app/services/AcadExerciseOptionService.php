<?php

/**
 * Gestiona la sublista de opciones (acad_exercise_option) de un ejercicio de
 * opción múltiple, desde la acción especial "configurar" embebida en el CRUD
 * genérico de acad_exercise.
 */
class AcadExerciseOptionService
{
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
    public function listOptions(int $exerciseId, array $query = []): array
    {
        try {
            $empresaId = $this->scope->requireEmpresaId($query);
        } catch (RuntimeException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        $exercise = $this->repo->findExercise($empresaId, $exerciseId);
        if ($exercise === null) {
            return ['success' => false, 'message' => 'Exercise not found.'];
        }

        $options = array_map(static fn (array $o) => [
            'id' => (int)$o['id'],
            'texto_en' => (string)$o['texto_en'],
            'es_correcta' => (bool)$o['es_correcta'],
            'orden' => (int)$o['orden'],
        ], $this->repo->getOptionsByExercise($exerciseId));

        return ['success' => true, 'options' => $options];
    }

    /**
     * @param list<array<string, mixed>> $options
     * @return array<string, mixed>
     */
    public function saveOptions(int $exerciseId, array $options, array $query = []): array
    {
        try {
            $empresaId = $this->scope->requireEmpresaId($query);
        } catch (RuntimeException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        $exercise = $this->repo->findExercise($empresaId, $exerciseId);
        if ($exercise === null) {
            return ['success' => false, 'message' => 'Exercise not found.'];
        }

        $clean = [];
        foreach ($options as $option) {
            if (!is_array($option)) {
                continue;
            }
            $texto = trim((string)($option['texto_en'] ?? ''));
            if ($texto === '') {
                continue;
            }
            $clean[] = [
                'texto_en' => mb_substr($texto, 0, 500),
                'es_correcta' => !empty($option['es_correcta']),
            ];
        }

        if (count($clean) < 2) {
            return ['success' => false, 'message' => 'At least 2 options are required.'];
        }

        if (!array_filter($clean, static fn ($o) => $o['es_correcta'])) {
            return ['success' => false, 'message' => 'Mark at least one option as correct.'];
        }

        $this->repo->deleteOptionsByExercise($exerciseId);
        $orden = 10;
        foreach ($clean as $option) {
            $this->repo->insertOption($exerciseId, $option['texto_en'], $option['es_correcta'], $orden);
            $orden += 10;
        }

        return ['success' => true, 'message' => 'Options saved.'];
    }

    /**
     * Bolsa de palabras de un ejercicio "Fill in the blanks" — misma tabla
     * que Multiple Choice (acad_exercise_option), pero cada palabra apunta a
     * un hueco (blank_index) del prompt en vez de un flag "es_correcta"
     * único. NULL = palabra señuelo, no resuelve ningún hueco.
     *
     * @return array<string, mixed>
     */
    public function listWordBank(int $exerciseId, array $query = []): array
    {
        try {
            $empresaId = $this->scope->requireEmpresaId($query);
        } catch (RuntimeException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        $exercise = $this->repo->findExercise($empresaId, $exerciseId);
        if ($exercise === null) {
            return ['success' => false, 'message' => 'Exercise not found.'];
        }

        $words = array_map(static fn (array $o) => [
            'id' => (int)$o['id'],
            'texto_en' => (string)$o['texto_en'],
            'blank_index' => $o['blank_index'] !== null ? (int)$o['blank_index'] : null,
            'orden' => (int)$o['orden'],
        ], $this->repo->getOptionsByExercise($exerciseId));

        return ['success' => true, 'words' => $words, 'blanks_in_prompt' => substr_count((string)$exercise['prompt_en'], '___')];
    }

    /**
     * @param list<array<string, mixed>> $words
     * @return array<string, mixed>
     */
    public function saveWordBank(int $exerciseId, array $words, array $query = []): array
    {
        try {
            $empresaId = $this->scope->requireEmpresaId($query);
        } catch (RuntimeException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        $exercise = $this->repo->findExercise($empresaId, $exerciseId);
        if ($exercise === null) {
            return ['success' => false, 'message' => 'Exercise not found.'];
        }

        $clean = [];
        foreach ($words as $word) {
            if (!is_array($word)) {
                continue;
            }
            $texto = trim((string)($word['texto_en'] ?? ''));
            if ($texto === '') {
                continue;
            }
            $blankIndex = null;
            if (isset($word['blank_index']) && $word['blank_index'] !== '' && $word['blank_index'] !== null) {
                $blankIndex = max(1, (int)$word['blank_index']);
            }
            $clean[] = ['texto_en' => mb_substr($texto, 0, 500), 'blank_index' => $blankIndex];
        }

        if ($clean === []) {
            return ['success' => false, 'message' => 'Add at least one word.'];
        }

        $blanksInPrompt = substr_count((string)$exercise['prompt_en'], '___');
        $covered = [];
        foreach ($clean as $word) {
            if ($word['blank_index'] !== null) {
                $covered[$word['blank_index']] = true;
            }
        }
        $missing = [];
        for ($i = 1; $i <= $blanksInPrompt; $i++) {
            if (empty($covered[$i])) {
                $missing[] = $i;
            }
        }
        if ($missing !== []) {
            return ['success' => false, 'message' => 'Missing a correct word for blank #' . implode(', #', $missing) . '.'];
        }

        $this->repo->deleteOptionsByExercise($exerciseId);
        $orden = 10;
        foreach ($clean as $word) {
            $this->repo->insertOption($exerciseId, $word['texto_en'], $word['blank_index'] !== null, $orden, $word['blank_index']);
            $orden += 10;
        }

        return ['success' => true, 'message' => 'Word bank saved.'];
    }

    /**
     * "Sentence order": misma tabla acad_exercise_option, pero cada fila es
     * una palabra de una oración concreta. blank_index = número de oración
     * (1..N, un ejercicio puede tener varias), orden = posición correcta de
     * la palabra dentro de esa oración. El admin escribe cada oración como
     * texto plano (se tokeniza por espacios) en vez de palabra por palabra,
     * para no obligarlo a teclear posición/oración a mano por cada palabra.
     *
     * @return array<string, mixed>
     */
    public function listSentenceOrder(int $exerciseId, array $query = []): array
    {
        try {
            $empresaId = $this->scope->requireEmpresaId($query);
        } catch (RuntimeException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        $exercise = $this->repo->findExercise($empresaId, $exerciseId);
        if ($exercise === null) {
            return ['success' => false, 'message' => 'Exercise not found.'];
        }

        $sentences = [];
        $distractors = [];
        foreach ($this->repo->getOptionsByExercise($exerciseId) as $o) {
            if ($o['blank_index'] === null) {
                $distractors[] = ['id' => (int)$o['id'], 'texto_en' => (string)$o['texto_en']];
                continue;
            }
            $idx = (int)$o['blank_index'];
            $sentences[$idx][] = (string)$o['texto_en'];
        }
        ksort($sentences);
        // Se reconstruye cada oración como texto (uniendo sus palabras en el
        // orden en que fueron guardadas) para que el admin la reedite como
        // texto plano, igual que la escribió originalmente.
        $sentenceTexts = array_values(array_map(static fn (array $words) => implode(' ', $words), $sentences));

        return ['success' => true, 'sentences' => $sentenceTexts, 'distractors' => $distractors];
    }

    /**
     * @param list<string> $sentences
     * @param list<string> $distractors
     * @return array<string, mixed>
     */
    public function saveSentenceOrder(int $exerciseId, array $sentences, array $distractors, array $query = []): array
    {
        try {
            $empresaId = $this->scope->requireEmpresaId($query);
        } catch (RuntimeException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        $exercise = $this->repo->findExercise($empresaId, $exerciseId);
        if ($exercise === null) {
            return ['success' => false, 'message' => 'Exercise not found.'];
        }

        $cleanSentences = [];
        foreach ($sentences as $i => $sentence) {
            $words = preg_split('/\s+/', trim((string)$sentence), -1, PREG_SPLIT_NO_EMPTY);
            if ($words === false || $words === []) {
                continue;
            }
            if (count($words) < 2) {
                return ['success' => false, 'message' => 'Sentence #' . ($i + 1) . ' needs at least 2 words.'];
            }
            $cleanSentences[] = array_map(static fn ($w) => mb_substr((string)$w, 0, 500), $words);
        }

        if ($cleanSentences === []) {
            return ['success' => false, 'message' => 'Add at least one sentence.'];
        }

        $cleanDistractors = [];
        foreach ($distractors as $word) {
            $word = trim((string)$word);
            if ($word !== '') {
                $cleanDistractors[] = mb_substr($word, 0, 500);
            }
        }

        $this->repo->deleteOptionsByExercise($exerciseId);

        $orden = 10;
        foreach ($cleanSentences as $sentenceIndex => $words) {
            $position = 10;
            foreach ($words as $word) {
                $this->repo->insertOption($exerciseId, $word, true, $position, $sentenceIndex + 1);
                $position += 10;
            }
        }
        foreach ($cleanDistractors as $word) {
            $this->repo->insertOption($exerciseId, $word, false, $orden, null);
            $orden += 10;
        }

        $sentenceCount = count($cleanSentences);
        return [
            'success' => true,
            'message' => 'Saved ' . $sentenceCount . ' sentence' . ($sentenceCount === 1 ? '' : 's') . ', ' . count($cleanDistractors) . ' distractor word(s).',
        ];
    }

    /**
     * "Dialogue": misma tabla acad_exercise_option otra vez, pero cada fila
     * es un TURNO del diálogo — orden = número de turno, blank_index = rol
     * (1 o 2) que dice esa línea. No hay noción de correcto/incorrecto (es
     * un guion, no una respuesta), así que es_correcta siempre queda true.
     *
     * @return array<string, mixed>
     */
    public function listDialogueTurns(int $exerciseId, array $query = []): array
    {
        try {
            $empresaId = $this->scope->requireEmpresaId($query);
        } catch (RuntimeException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        $exercise = $this->repo->findExercise($empresaId, $exerciseId);
        if ($exercise === null) {
            return ['success' => false, 'message' => 'Exercise not found.'];
        }

        $turns = array_map(static fn (array $o) => [
            'id' => (int)$o['id'],
            'texto_en' => (string)$o['texto_en'],
            'role' => (int)$o['blank_index'],
            'orden' => (int)$o['orden'],
        ], $this->repo->getOptionsByExercise($exerciseId));

        return ['success' => true, 'turns' => $turns];
    }

    /**
     * @param list<array<string, mixed>> $turns
     * @return array<string, mixed>
     */
    public function saveDialogueTurns(int $exerciseId, array $turns, array $query = []): array
    {
        try {
            $empresaId = $this->scope->requireEmpresaId($query);
        } catch (RuntimeException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        $exercise = $this->repo->findExercise($empresaId, $exerciseId);
        if ($exercise === null) {
            return ['success' => false, 'message' => 'Exercise not found.'];
        }

        $clean = [];
        foreach ($turns as $turn) {
            if (!is_array($turn)) {
                continue;
            }
            $texto = trim((string)($turn['texto_en'] ?? ''));
            if ($texto === '') {
                continue;
            }
            $role = (int)($turn['role'] ?? 0);
            if ($role !== 1 && $role !== 2) {
                return ['success' => false, 'message' => 'Each turn needs a role (1 or 2). "' . mb_substr($texto, 0, 40) . '" has none.'];
            }
            $clean[] = ['texto_en' => mb_substr($texto, 0, 500), 'role' => $role];
        }

        if (count($clean) < 2) {
            return ['success' => false, 'message' => 'Add at least 2 turns.'];
        }

        if (count(array_unique(array_column($clean, 'role'))) < 2) {
            return ['success' => false, 'message' => 'Use both roles (1 and 2) at least once — a dialogue needs two speakers.'];
        }

        $this->repo->deleteOptionsByExercise($exerciseId);
        $orden = 10;
        foreach ($clean as $turn) {
            $this->repo->insertOption($exerciseId, $turn['texto_en'], true, $orden, $turn['role']);
            $orden += 10;
        }

        return ['success' => true, 'message' => 'Saved ' . count($clean) . ' turns.'];
    }
}
