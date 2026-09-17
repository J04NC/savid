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
        $moduleId = (int)($_GET['module_id'] ?? 0);
        $unitId = (int)($_GET['unit_id'] ?? 0);

        $levels = $empresaId > 0 ? $this->repo->getLevels($empresaId) : [];
        $modules = $levelId > 0 ? $this->repo->getModulesByLevel($empresaId, $levelId) : [];
        $units = $moduleId > 0 ? $this->repo->getUnitsByModule($empresaId, $moduleId) : [];
        $lessons = $unitId > 0 ? $this->repo->getLessonsByUnit($empresaId, $unitId) : [];
        $exercises = [];
        $currentLesson = null;
        $lessonId = (int)($_GET['lesson_id'] ?? 0);
        if ($lessonId > 0) {
            $currentLesson = $this->repo->findLesson($empresaId, $lessonId);
            $exercises = $this->repo->getExercisesByLesson($empresaId, $lessonId);
        }

        $currentLevel = $levelId > 0 ? $this->repo->findLevel($empresaId, $levelId) : null;
        $currentModule = $moduleId > 0 ? $this->repo->findModule($empresaId, $moduleId) : null;
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
            $dialogueRole = (int)($_GET['role'] ?? 1) === 2 ? 2 : 1;
            $dialogueTurns = $this->buildDialogueTurns($empresaId, $exerciseId, $usuarioId, $options, $referenceAudios, $dialogueRole);
        }

        $breadcrumb = $this->moduleService->buildBreadcrumbForRuta('acad/practice');

        $view = BASE_PATH . '/app/views/acad/practice.php';
        require BASE_PATH . '/app/views/layouts/main.php';
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
