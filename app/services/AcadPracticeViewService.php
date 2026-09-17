<?php

/**
 * Orquesta los datos de la pantalla "practice" (un ejercicio + sus opciones +
 * intento previo + audios de referencia, según el tipo de ejercicio). Antes
 * esta lógica (incluidos buildDialogueTurns/extractScaffoldColumns) vivía
 * directamente en AcadController usando AcadRepository sin pasar por un
 * Service. Distinto de AcadAttemptService (que maneja el ENVÍO de un
 * intento, no armar la vista para practicar).
 */
class AcadPracticeViewService
{
    private AcadRepository $repo;

    public function __construct()
    {
        $this->repo = new AcadRepository();
    }

    /**
     * @param array<string,mixed> $getParams $_GET de la request (solo se usa 'role' para DIALOGUE)
     * @return array{
     *     exercise: ?array<string,mixed>,
     *     options: list<array<string,mixed>>,
     *     lastAttempt: ?array<string,mixed>,
     *     displayPrompt: string,
     *     scaffoldColumns: list<string>,
     *     referenceAudios: list<array<string,mixed>>,
     *     audioSlotAttempts: array<int,?array<string,mixed>>,
     *     tongueTwisterHistory: array<int,list<array<string,mixed>>>,
     *     dialogueRole: int,
     *     dialogueTurns: list<array<string,mixed>>
     * }
     */
    public function buildViewData(int $empresaId, int $usuarioId, int $exerciseId, array $getParams): array
    {
        $exercise = $exerciseId > 0 ? $this->repo->findExercise($empresaId, $exerciseId) : null;
        $options = [];
        if ($exercise !== null && in_array($exercise['exercise_type_codigo'], ['MULTIPLE_CHOICE', 'FILL_BLANKS', 'SENTENCE_ORDER', 'DIALOGUE'], true)) {
            $options = $this->repo->getOptionsByExercise($exerciseId);
            if (in_array($exercise['exercise_type_codigo'], ['FILL_BLANKS', 'SENTENCE_ORDER'], true)) {
                shuffle($options);
            }
        }
        $lastAttempt = $exercise !== null
            ? $this->repo->findLatestAttempt($empresaId, $exerciseId, $usuarioId)
            : null;

        // "Columns:" es una convención de texto libre dentro de prompt_en
        // (igual que "___" para FILL_BLANKS): si el admin la incluye, se usa
        // como andamiaje visual de encabezados de columna (ej. Subject |
        // Adverb | Verb | Object | Place, tal como trae el libro) sobre el
        // ejercicio SENTENCE_ORDER, y se retira del texto de instrucciones
        // que ve el estudiante para no mostrarla como si fuera prosa.
        $scaffoldColumns = [];
        $displayPrompt = (string)($exercise['prompt_en'] ?? '');
        if ($exercise !== null && $exercise['exercise_type_codigo'] === 'SENTENCE_ORDER') {
            [$displayPrompt, $scaffoldColumns] = $this->extractScaffoldColumns($displayPrompt);
        }

        $referenceAudios = $exercise !== null ? $this->repo->getReferenceAudiosByExercise($exerciseId) : [];
        $audioSlotAttempts = [];
        $tongueTwisterHistory = [];
        if ($exercise !== null && $exercise['exercise_type_codigo'] === 'AUDIO_RESPONSE') {
            foreach ($referenceAudios as $ra) {
                $audioSlotAttempts[(int)$ra['id']] = $this->repo->findAttemptForReferenceAudio(
                    $empresaId,
                    $exerciseId,
                    (int)$ra['id'],
                    $usuarioId
                );
            }
        } elseif ($exercise !== null && $exercise['exercise_type_codigo'] === 'TONGUE_TWISTER') {
            // A diferencia de AUDIO_RESPONSE (una toma, bloqueada), aquí se
            // trae el HISTORIAL completo por audio de referencia para poder
            // mostrar la progresión de duración entre tomas.
            foreach ($referenceAudios as $ra) {
                $tongueTwisterHistory[(int)$ra['id']] = $this->repo->findAttemptsHistoryForReferenceAudio(
                    $empresaId,
                    $exerciseId,
                    (int)$ra['id'],
                    $usuarioId
                );
            }
        }

        $dialogueRole = 1;
        $dialogueTurns = [];
        if ($exercise !== null && $exercise['exercise_type_codigo'] === 'DIALOGUE') {
            $dialogueRole = (int)($getParams['role'] ?? 1) === 2 ? 2 : 1;
            $dialogueTurns = $this->buildDialogueTurns($empresaId, $exerciseId, $usuarioId, $options, $referenceAudios, $dialogueRole);
        }

        return compact(
            'exercise', 'options', 'lastAttempt', 'displayPrompt', 'scaffoldColumns',
            'referenceAudios', 'audioSlotAttempts', 'tongueTwisterHistory', 'dialogueRole', 'dialogueTurns'
        );
    }

    /**
     * Arma la lista de turnos para la vista de un ejercicio DIALOGUE: cada
     * turno (opción) se empareja con su audio TTS (mismo `orden` en ambas
     * tablas) y, si el turno es del rol que el estudiante eligió practicar,
     * con su propio intento grabado (si ya lo confirmó).
     *
     * @param list<array<string, mixed>> $turns opciones del ejercicio, ya ordenadas por `orden`
     * @param list<array<string, mixed>> $referenceAudios audios del ejercicio, ya ordenados por `orden`
     * @return list<array<string, mixed>>
     */
    private function buildDialogueTurns(int $empresaId, int $exerciseId, int $usuarioId, array $turns, array $referenceAudios, int $myRole): array
    {
        $audioByOrden = [];
        foreach ($referenceAudios as $ra) {
            $audioByOrden[(int)$ra['orden']] = $ra;
        }

        $result = [];
        foreach ($turns as $turn) {
            $role = (int)$turn['blank_index'];
            $audio = $audioByOrden[(int)$turn['orden']] ?? null;
            $isMine = $role === $myRole;

            $result[] = [
                'turn' => (int)$turn['orden'],
                'role' => $role,
                'texto_en' => (string)$turn['texto_en'],
                'audio' => $audio,
                'isMine' => $isMine,
                'attempt' => ($isMine && $audio !== null)
                    ? $this->repo->findAttemptForReferenceAudio($empresaId, $exerciseId, (int)$audio['id'], $usuarioId)
                    : null,
            ];
        }

        return $result;
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function extractScaffoldColumns(string $promptEn): array
    {
        $lines = explode("\n", $promptEn);
        $columns = [];
        $kept = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*columns\s*:\s*(.+)$/i', $line, $m)) {
                $columns = array_values(array_filter(array_map('trim', explode('|', $m[1]))));
                continue;
            }
            $kept[] = $line;
        }

        return [trim(implode("\n", $kept)), $columns];
    }
}
