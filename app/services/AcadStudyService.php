<?php

/**
 * Orquesta los datos de navegación de la pantalla "study" (niveles → módulos
 * → unidades → lecciones → ejercicios). Antes esta lógica vivía directamente
 * en AcadController usando AcadRepository sin pasar por un Service.
 */
class AcadStudyService
{
    private AcadRepository $repo;

    public function __construct()
    {
        $this->repo = new AcadRepository();
    }

    /**
     * @return array{
     *     levels: list<array<string,mixed>>,
     *     modules: list<array<string,mixed>>,
     *     units: list<array<string,mixed>>,
     *     lessons: list<array<string,mixed>>,
     *     exercises: list<array<string,mixed>>,
     *     currentLesson: ?array<string,mixed>,
     *     currentLevel: ?array<string,mixed>,
     *     currentModule: ?array<string,mixed>,
     *     currentUnit: ?array<string,mixed>
     * }
     */
    public function buildViewData(int $empresaId, int $levelId, int $moduleId, int $unitId, int $lessonId): array
    {
        $levels = $empresaId > 0 ? $this->repo->getLevels($empresaId) : [];
        $modules = $levelId > 0 ? $this->repo->getModulesByLevel($empresaId, $levelId) : [];
        $units = $moduleId > 0 ? $this->repo->getUnitsByModule($empresaId, $moduleId) : [];
        $lessons = $unitId > 0 ? $this->repo->getLessonsByUnit($empresaId, $unitId) : [];

        $exercises = [];
        $currentLesson = null;
        if ($lessonId > 0) {
            $currentLesson = $this->repo->findLesson($empresaId, $lessonId);
            $exercises = $this->repo->getExercisesByLesson($empresaId, $lessonId);
        }

        $currentLevel = $levelId > 0 ? $this->repo->findLevel($empresaId, $levelId) : null;
        $currentModule = $moduleId > 0 ? $this->repo->findModule($empresaId, $moduleId) : null;
        $currentUnit = $unitId > 0 ? $this->repo->findUnit($empresaId, $unitId) : null;

        return compact(
            'levels', 'modules', 'units', 'lessons', 'exercises',
            'currentLesson', 'currentLevel', 'currentModule', 'currentUnit'
        );
    }
}
