<?php

class ModuleController
{
    private ModuleService $moduleService;

    public function __construct()
    {
        $this->moduleService = new ModuleService();
    }

    private function isSuperAdmin(): bool
    {
        return !empty($_SESSION['es_super_admin'])
            || (int)($_SESSION['rol_id'] ?? 0) === 1;
    }

    public function index()
    {

        $errors = [];
        $old = [];

        $url = $_GET['url'] ?? '';
        $url = trim($url,'/');
        $parts = explode('/',$url);
        $ruta = $parts[0] ?? '';

        /*
        =========================
        BUSCAR ITEM
        =========================
        */

        $currentItem = $this->moduleService->findCurrentItem($ruta);

        /*
        =========================
        VER (servidor): sin esto, tras cambiar sede podía cargarse la tabla sin botones
        pero la URL seguía siendo válida (el middleware no revisa index).
        =========================
        */

        if ($currentItem && class_exists('PermisoService') && !PermisoService::can($currentItem['ruta'], 'ver')) {
            $_SESSION['flash_notice'] = 'No tiene permiso para esta pantalla en la empresa o sede actual.';
            header('Location: ?url=dashboard');
            exit;
        }

        /*
        =========================
        DELETE
        =========================
        */

        if(isset($_GET['delete']) && $currentItem){

            $id = $_GET['delete'];
            $permitido = $this->moduleService->canExecuteAction($currentItem['id'], 'eliminar');

            if(!$permitido){
                die("No tienes permiso para eliminar");
            }

            if ($currentItem['ruta'] === 'empresa' && !$this->isSuperAdmin()) {
                $_SESSION['flash_notice'] = 'Solo un superadministrador puede eliminar empresas.';
                header("Location: ?url=".$ruta);
                exit;
            }

            $this->moduleService->deleteRecord($currentItem['ruta'], $id);

            header("Location: ?url=".$ruta);
            exit;
        }

        /*
        =========================
        BREADCRUMB (INTOCADO)
        =========================
        */

        $breadcrumb = $this->moduleService->buildBreadcrumb($currentItem);

        /*
        =========================
        VISTA PERSONALIZADA
        =========================
        */

        $view = BASE_PATH . "/app/views/$ruta/index.php";

        if (file_exists($view)) {
            if (!$currentItem) {
                $_SESSION['flash_notice'] = 'La ruta solicitada no está disponible.';
                header('Location: ?url=dashboard');
                exit;
            }
            require BASE_PATH . '/app/views/layouts/main.php';
            return;
        }

        /*
        =========================
        GUARDAR
        =========================
        */

        if($_SERVER['REQUEST_METHOD'] === 'POST'){

            try{
                $permitido = $this->moduleService->canExecuteAction($currentItem['id'], 'guardar');

                if(!$permitido){
                    throw new Exception("No tienes permiso para guardar");
                }

                if ($currentItem['ruta'] === 'empresa') {
                    $postedId = (int)($_POST['id'] ?? 0);
                    $isCreating = ($postedId <= 0);
                    if ($isCreating && !$this->isSuperAdmin()) {
                        throw new Exception(json_encode([
                            'general' => 'Solo un superadministrador puede crear empresas.',
                        ], JSON_UNESCAPED_UNICODE));
                    }
                    if (!$isCreating && !$this->isSuperAdmin()) {
                        $crudService = new CrudService();
                        if (!$crudService->userCanManageEmpresa($postedId)) {
                            throw new Exception(json_encode([
                                'general' => 'No tiene permiso para editar esta empresa.',
                            ], JSON_UNESCAPED_UNICODE));
                        }
                    }
                }

                $this->moduleService->save($currentItem['ruta'],$_POST);

                header("Location: ?url=".$ruta."&success=1");
                exit;

            }catch(Exception $e){

                $errors = json_decode($e->getMessage(),true);

                if(!is_array($errors)){
                    $errors = ['general'=>$e->getMessage()];
                }

                $old = $_POST;
            }
        }

        /*
        =========================
        CRUD
        =========================
        */

        if($currentItem){
            $crud = $this->moduleService->getCrudData($currentItem);
            $data = $crud['data'];
            $columns = $crud['columns'];
            $acciones = $crud['acciones'];
            $relations = $crud['relations'];
            $relationData = $crud['relationData'];
            $catalogRegistry = $crud['catalogRegistry'] ?? [];
            $crudContextTable = $currentItem['ruta'];
            $tipodocumentoMetaById = $crud['tipodocumentoMetaById'] ?? [];

            $view = BASE_PATH . "/app/views/crud/table.php";
            require BASE_PATH . '/app/views/layouts/main.php';
            return;
        }

        echo "Módulo no encontrado";
    }

    /**
     * Búsqueda JSON para FK con relmode autocomplete/auto (validación por INFORMATION_SCHEMA + permiso ver en context).
     */
    public function catalogSearch(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $context = preg_replace('/[^A-Za-z0-9_]/', '', (string)($_GET['context'] ?? ''));
        $field = preg_replace('/[^A-Za-z0-9_]/', '', (string)($_GET['field'] ?? ''));
        $q = (string)($_GET['q'] ?? '');
        $limit = (int)($_GET['limit'] ?? 25);

        if ($context === '' || $field === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'context y field son obligatorios'], JSON_UNESCAPED_UNICODE);

            return;
        }

        $currentItem = $this->moduleService->findCurrentItem($context);

        if (!$currentItem || (class_exists('PermisoService') && !PermisoService::can($context, 'ver'))) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);

            return;
        }

        $parents = [];

        foreach ($_GET as $k => $v) {
            if (!is_string($k) || !str_starts_with($k, 'parent_')) {
                continue;
            }
            $pname = preg_replace('/[^A-Za-z0-9_]/', '', substr($k, strlen('parent_')));

            if ($pname === '') {
                continue;
            }

            $parents[$pname] = is_scalar($v) ? $v : '';
        }

        $crudService = new CrudService();
        $items = $crudService->searchCatalogOptions($context, $field, $q, $parents, $limit);

        echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
    }

    public function __call($method,$params)
    {
        $this->index();
    }
}