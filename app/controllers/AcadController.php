<?php

class AcadController
{
    private ModuleService $moduleService;
    private AcadStudyService $studyService;
    private AcadPracticeViewService $practiceViewService;
    private AcadExerciseOptionService $optionService;
    private AcadExerciseService $exerciseService;
    private AcadAttemptService $attemptService;
    private AcadProgressService $progressService;

    public function __construct()
    {
        $this->moduleService = new ModuleService();
        $this->studyService = new AcadStudyService();
        $this->practiceViewService = new AcadPracticeViewService();
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
        $moduleId = (int)($_GET['module_id'] ?? 0);
        $unitId = (int)($_GET['unit_id'] ?? 0);
        $lessonId = (int)($_GET['lesson_id'] ?? 0);

        $data = $this->studyService->buildViewData($empresaId, $levelId, $moduleId, $unitId, $lessonId);
        $levels = $data['levels'];
        $modules = $data['modules'];
        $units = $data['units'];
        $lessons = $data['lessons'];
        $exercises = $data['exercises'];
        $currentLesson = $data['currentLesson'];
        $currentLevel = $data['currentLevel'];
        $currentModule = $data['currentModule'];
        $currentUnit = $data['currentUnit'];

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

        $data = $this->practiceViewService->buildViewData($empresaId, $usuarioId, $exerciseId, $_GET);
        $exercise = $data['exercise'];
        $options = $data['options'];
        $lastAttempt = $data['lastAttempt'];
        $displayPrompt = $data['displayPrompt'];
        $scaffoldColumns = $data['scaffoldColumns'];
        $referenceAudios = $data['referenceAudios'];
        $audioSlotAttempts = $data['audioSlotAttempts'];
        $tongueTwisterHistory = $data['tongueTwisterHistory'];
        $dialogueRole = $data['dialogueRole'];
        $dialogueTurns = $data['dialogueTurns'];

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
     * JSON: bolsa de palabras de un ejercicio "Fill in the blanks" (acción
     * "configurar" embebida en el CRUD genérico de acad_exercise, igual que
     * exerciseOptions() para Multiple Choice).
     */
    public function exerciseWordBank(): void
    {
        $exerciseId = (int)($_GET['exercise_id'] ?? $_POST['exercise_id'] ?? 0);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!PermisoService::can('acad_exercise', 'configurar')) {
                $this->jsonResponse(['success' => false, 'message' => 'No permission.']);

                return;
            }
            $words = json_decode((string)($_POST['words'] ?? '[]'), true);
            $result = $this->optionService->saveWordBank($exerciseId, is_array($words) ? $words : [], $_GET);
            $this->jsonResponse($result);

            return;
        }

        if (!PermisoService::can('acad_exercise', 'configurar')) {
            $this->jsonResponse(['success' => false, 'message' => 'No permission.']);

            return;
        }

        $this->jsonResponse($this->optionService->listWordBank($exerciseId, $_GET));
    }

    /**
     * JSON: oraciones (SENTENCE_ORDER) de un ejercicio de ordenar palabras
     * (acción "configurar" embebida en el CRUD genérico de acad_exercise,
     * igual que exerciseOptions()/exerciseWordBank()).
     */
    public function exerciseSentenceOrder(): void
    {
        $exerciseId = (int)($_GET['exercise_id'] ?? $_POST['exercise_id'] ?? 0);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!PermisoService::can('acad_exercise', 'configurar')) {
                $this->jsonResponse(['success' => false, 'message' => 'No permission.']);

                return;
            }
            $sentences = json_decode((string)($_POST['sentences'] ?? '[]'), true);
            $distractors = json_decode((string)($_POST['distractors'] ?? '[]'), true);
            $result = $this->optionService->saveSentenceOrder(
                $exerciseId,
                is_array($sentences) ? $sentences : [],
                is_array($distractors) ? $distractors : [],
                $_GET
            );
            $this->jsonResponse($result);

            return;
        }

        if (!PermisoService::can('acad_exercise', 'configurar')) {
            $this->jsonResponse(['success' => false, 'message' => 'No permission.']);

            return;
        }

        $this->jsonResponse($this->optionService->listSentenceOrder($exerciseId, $_GET));
    }

    /**
     * JSON: turnos (DIALOGUE) de un ejercicio de diálogo/role-play (acción
     * "configurar" embebida en el CRUD genérico de acad_exercise, igual que
     * exerciseOptions()/exerciseWordBank()/exerciseSentenceOrder()).
     */
    public function exerciseDialogueTurns(): void
    {
        $exerciseId = (int)($_GET['exercise_id'] ?? $_POST['exercise_id'] ?? 0);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!PermisoService::can('acad_exercise', 'configurar')) {
                $this->jsonResponse(['success' => false, 'message' => 'No permission.']);

                return;
            }
            $turns = json_decode((string)($_POST['turns'] ?? '[]'), true);
            $result = $this->optionService->saveDialogueTurns($exerciseId, is_array($turns) ? $turns : [], $_GET);
            $this->jsonResponse($result);

            return;
        }

        if (!PermisoService::can('acad_exercise', 'configurar')) {
            $this->jsonResponse(['success' => false, 'message' => 'No permission.']);

            return;
        }

        $this->jsonResponse($this->optionService->listDialogueTurns($exerciseId, $_GET));
    }

    /**
     * JSON: audios de referencia de un ejercicio (N por ejercicio).
     * GET lista, POST con "archivo" agrega uno nuevo (grabado o subido),
     * POST con "generate_text" lo genera con texto a voz, POST con
     * "generate_dialogue" regenera TODOS los turnos de un DIALOGUE con dos
     * voces (una por rol), POST con "delete_id" borra uno existente.
     */
    public function exerciseReferenceAudio(): void
    {
        if (!PermisoService::can('acad_exercise', 'configurar')) {
            $this->jsonResponse(['success' => false, 'message' => 'No permission.']);

            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!empty($_POST['delete_id'] ?? '')) {
                $this->jsonResponse($this->exerciseService->deleteReferenceAudio($_POST, $_GET));

                return;
            }

            if (!empty($_POST['generate_dialogue'] ?? '')) {
                $this->jsonResponse($this->exerciseService->generateDialogueAudio($_POST, $_GET));

                return;
            }

            if (!empty($_POST['generate_text'] ?? '')) {
                $this->jsonResponse($this->exerciseService->generateReferenceAudio($_POST, $_GET));

                return;
            }

            $this->jsonResponse($this->exerciseService->addReferenceAudio($_FILES, $_POST, $_GET));

            return;
        }

        $exerciseId = (int)($_GET['exercise_id'] ?? 0);
        $this->jsonResponse($this->exerciseService->listReferenceAudios($_GET, $exerciseId));
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
        $pending = $this->attemptService->listPending($empresaId);

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
