<?php

class ModuleController
{
    private ModuleService $moduleService;

    public function __construct()
    {
        $this->moduleService = new ModuleService();
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
        DELETE
        =========================
        */

        if(isset($_GET['delete']) && $currentItem){

            $id = $_GET['delete'];
            $permitido = $this->moduleService->canExecuteAction($currentItem['id'], 'eliminar');

            if(!$permitido){
                die("No tienes permiso para eliminar");
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

        if(file_exists($view)){
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
            $relationData = $crud['relationData'];

            $view = BASE_PATH . "/app/views/crud/table.php";
            require BASE_PATH . '/app/views/layouts/main.php';
            return;
        }

        echo "Módulo no encontrado";
    }

    public function __call($method,$params)
    {
        $this->index();
    }
}