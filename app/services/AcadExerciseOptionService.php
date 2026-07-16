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
}
