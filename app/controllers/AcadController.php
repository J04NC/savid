<?php

class AcadController
{
    private ModuleService $moduleService;
    private AcadRepository $repo;
    private AcadExerciseOptionService $optionService;
    private AcadExerciseService $exerciseService;
    private AcadAttemptService $attemptService;
    private AcadProgressService $progressService;

    public function __construct()
    {
        $this->moduleService = new ModuleService();
        $this->repo = new AcadRepository();
        $this->optionService = new AcadExerciseOptionService();
        $this->exerciseService = new AcadExerciseService();
        $this->attemptService = new AcadAttemptService();
        $this->progressService = new AcadProgressService();
    }

    /**
     * Sin vista propia: redirige al listado genérico de ítems hijos del hub
     * Academic, igual que SgdController::index().
     */
    public function index(): void
    {
        $item = $this->moduleService->findCurrentItem('acad');
        if ($item) {
            header('Location: ?url=dashboard/item/' . (int)$item['id']);
            exit;
        }

        $_SESSION['flash_notice'] = 'No se encontró el ítem de menú Academic.';
        header('Location: ?url=dashboard');
        exit;
    }

    public function study(): void
    {
        if (!PermisoService::can('acad/study', 'ver')) {
            $this->deny();
        }

        $empresaId = (int)($_SESSION['empresa_id'] ?? 0);
        $levelId = (int)($_GET['level_id'] ?? 0);
        $unitId = (int)($_GET['unit_id'] ?? 0);

        $levels = $empresaId > 0 ? $this->repo->getLevels($empresaId) : [];
        $units = $levelId > 0 ? $this->repo->getUnitsByLevel($empresaId, $levelId) : [];
        $lessons = $unitId > 0 ? $this->repo->getLessonsByUnit($empresaId, $unitId) : [];
        $exercises = [];
        $currentLesson = null;
        $lessonId = (int)($_GET['lesson_id'] ?? 0);
        if ($lessonId > 0) {
            $currentLesson = $this->repo->findLesson($empresaId, $lessonId);
            $exercises = $this->repo->getExercisesByLesson($empresaId, $lessonId);
        }

        $currentLevel = $levelId > 0 ? $this->repo->findLevel($empresaId, $levelId) : null;
        $currentUnit = $unitId > 0 ? $this->repo->findUnit($empresaId, $unitId) : null;

        $breadcrumb = $this->moduleService->buildBreadcrumbForRuta('acad/study');

        $view = BASE_PATH . '/app/views/acad/study.php';
        require BASE_PATH . '/app/views/layouts/main.php';
    }

    public function practice(): void
    {
        if (!PermisoService::can('acad/practice', 'ver')) {
            $this->deny();
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->handlePracticePost();

            return;
        }

        $empresaId = (int)($_SESSION['empresa_id'] ?? 0);
        $usuarioId = (int)($_SESSION['user_id'] ?? 0);
        $exerciseId = (int)($_GET['exercise_id'] ?? 0);

        $exercise = $exerciseId > 0 ? $this->repo->findExercise($empresaId, $exerciseId) : null;
        $options = [];
        if ($exercise !== null && $exercise['exercise_type_codigo'] === 'MULTIPLE_CHOICE') {
            $options = $this->repo->getOptionsByExercise($exerciseId);
        }
        $lastAttempt = $exercise !== null
            ? $this->repo->findLatestAttempt($empresaId, $exerciseId, $usuarioId)
            : null;

        $breadcrumb = $this->moduleService->buildBreadcrumbForRuta('acad/practice');

        $view = BASE_PATH . '/app/views/acad/practice.php';
        require BASE_PATH . '/app/views/layouts/main.php';
    }

    private function handlePracticePost(): void
    {
        if (!PermisoService::can('acad/practice', 'guardar')) {
            $this->jsonResponse(['success' => false, 'message' => 'No permission.']);

            return;
        }

        $usuarioId = (int)($_SESSION['user_id'] ?? 0);

        if (!empty($_FILES['archivo']['name'] ?? '')) {
            $result = $this->attemptService->submitSpeakingAudio($_FILES, $_POST, $_GET, $usuarioId);
        } else {
            $result = $this->attemptService->submit($_POST, $_GET, $usuarioId);
        }

        $this->jsonResponse($result);
    }

    /**
     * JSON: opciones de un ejercicio de opción múltiple (acción "configurar"
     * embebida en el CRUD genérico de acad_exercise).
     */
    public function exerciseOptions(): void
    {
        $exerciseId = (int)($_GET['exercise_id'] ?? $_POST['exercise_id'] ?? 0);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!PermisoService::can('acad_exercise', 'configurar')) {
                $this->jsonResponse(['success' => false, 'message' => 'No permission.']);

                return;
            }
            $options = json_decode((string)($_POST['options'] ?? '[]'), true);
            $result = $this->optionService->saveOptions($exerciseId, is_array($options) ? $options : [], $_GET);
            $this->jsonResponse($result);

            return;
        }

        if (!PermisoService::can('acad_exercise', 'configurar')) {
            $this->jsonResponse(['success' => false, 'message' => 'No permission.']);

            return;
        }

        $this->jsonResponse($this->optionService->listOptions($exerciseId, $_GET));
    }

    /**
     * JSON: sube el audio de referencia de un ejercicio de speaking.
     */
    public function exerciseUploadAudio(): void
    {
        if (!PermisoService::can('acad_exercise', 'guardar')) {
            $this->jsonResponse(['success' => false, 'message' => 'No permission.']);

            return;
        }

        $this->jsonResponse($this->exerciseService->uploadReferenceAudio($_FILES, $_POST, $_GET));
    }

    public function grading(): void
    {
        if (!PermisoService::can('acad/grading', 'ver')) {
            $this->deny();
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->handleGradingPost();

            return;
        }

        $empresaId = (int)($_SESSION['empresa_id'] ?? 0);
        $pending = $empresaId > 0 ? $this->repo->findPendingAttempts($empresaId) : [];

        $breadcrumb = $this->moduleService->buildBreadcrumbForRuta('acad/grading');

        $view = BASE_PATH . '/app/views/acad/grading.php';
        require BASE_PATH . '/app/views/layouts/main.php';
    }

    private function handleGradingPost(): void
    {
        if (!PermisoService::can('acad/grading', 'guardar')) {
            $this->jsonResponse(['success' => false, 'message' => 'No permission.']);

            return;
        }

        $attemptId = (int)($_POST['attempt_id'] ?? 0);
        $calificadorId = (int)($_SESSION['user_id'] ?? 0);
        $result = $this->attemptService->grade($attemptId, $_POST, $_GET, $calificadorId);
        $this->jsonResponse($result);
    }

    public function progress(): void
    {
        if (!PermisoService::can('acad/progress', 'ver')) {
            $this->deny();
        }

        $data = $this->progressService->teacherProgress($_GET);
        $students = $data['students'] ?? [];

        $breadcrumb = $this->moduleService->buildBreadcrumbForRuta('acad/progress');

        $view = BASE_PATH . '/app/views/acad/progress.php';
        require BASE_PATH . '/app/views/layouts/main.php';
    }

    public function myprogress(): void
    {
        if (!PermisoService::can('acad/myprogress', 'ver')) {
            $this->deny();
        }

        $usuarioId = (int)($_SESSION['user_id'] ?? 0);
        $data = $this->progressService->studentProgress($_GET, $usuarioId);
        $enrolment = $data['enrolment'] ?? null;
        $bySkill = $data['bySkill'] ?? [];

        $breadcrumb = $this->moduleService->buildBreadcrumbForRuta('acad/myprogress');

        $view = BASE_PATH . '/app/views/acad/my_progress.php';
        require BASE_PATH . '/app/views/layouts/main.php';
    }

    private function deny(): void
    {
        $_SESSION['flash_notice'] = 'No tiene permiso para esta acción de Academic.';
        header('Location: ?url=dashboard');
        exit;
    }

    private function jsonResponse(array $payload): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    }
}
