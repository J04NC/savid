<?php

class ModuleController
{

    public function index()
    {

        $errors = [];
        $old = [];

        $url = $_GET['url'] ?? '';
        $url = trim($url,'/');
        $parts = explode('/',$url);
        $ruta = $parts[0] ?? '';

        $database = new Database();
        $pdo = $database->connect();

        /*
        =========================
        BUSCAR ITEM
        =========================
        */

        $stmt = $pdo->prepare("
            SELECT *
            FROM item
            WHERE ruta = ?
            LIMIT 1
        ");

        $stmt->execute([$ruta]);
        $currentItem = $stmt->fetch(PDO::FETCH_ASSOC);

        /*
        =========================
        DELETE
        =========================
        */

        if(isset($_GET['delete']) && $currentItem){

            $id = $_GET['delete'];

            $crudService = new CrudService();
            $acciones = $crudService->getAcciones($currentItem['id']);

            $permitido = false;

            foreach($acciones as $acc){
                if($acc['codigo'] == 'eliminar'){
                    if(PermisoService::canByItemAccion($acc['item_accion_id'])){
                        $permitido = true;
                    }
                }
            }

            if(!$permitido){
                die("No tienes permiso para eliminar");
            }

            $stmt = $pdo->prepare("DELETE FROM {$currentItem['ruta']} WHERE id=?");
            $stmt->execute([$id]);

            header("Location: ?url=".$ruta);
            exit;
        }

        /*
        =========================
        BREADCRUMB (INTOCADO)
        =========================
        */

        $breadcrumb = '<a href="?url=dashboard">INICIO</a>';

        if($currentItem){

            // módulo
            $stmt = $pdo->prepare("
                SELECT nombre
                FROM modulo
                WHERE id = ?
            ");

            $stmt->execute([$currentItem['modulo_id']]);
            $modulo = $stmt->fetch(PDO::FETCH_ASSOC);

            if($modulo){
                $breadcrumb .= ' <span class="separator"> / </span> ';
                $breadcrumb .= '<a href="?url=dashboard/modulo/'.$currentItem['modulo_id'].'">'.strtoupper($modulo['nombre']).'</a>';
            }

            // jerarquía items (FIX IMPORTANTE)
            $currentId = $currentItem['id'];
            $path = [];

            while ($currentId) {

                $stmt = $pdo->prepare("
                    SELECT id,nombre,item_padre_id
                    FROM item
                    WHERE id = ?
                ");

                $stmt->execute([$currentId]);
                $item = $stmt->fetch(PDO::FETCH_ASSOC);

                if(!$item) break;

                $path[] = $item;
                $currentId = $item['item_padre_id'];
            }

            $path = array_reverse($path);

            foreach ($path as $p) {
                $breadcrumb .= ' <span class="separator"> / </span> ';
                $breadcrumb .= '<span>'.strtoupper($p['nombre']).'</span>';
            }
        }

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

                $crudService = new CrudService();
                $acciones = $crudService->getAcciones($currentItem['id']);

                $permitido = false;

                foreach($acciones as $acc){
                    if($acc['codigo'] == 'guardar'){
                        if(PermisoService::canByItemAccion($acc['item_accion_id'])){
                            $permitido = true;
                        }
                    }
                }

                if(!$permitido){
                    throw new Exception("No tienes permiso para guardar");
                }

                $crudService->save($currentItem['ruta'],$_POST);

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

            $crudService = new CrudService();

            $data = $crudService->getTableData($currentItem['ruta']);
            $columns = $crudService->getColumns($currentItem['ruta']);
            $acciones = $crudService->getAcciones($currentItem['id']);

            $relations = $crudService->getRelations($currentItem['ruta']);

            $relationData = [];

            foreach ($relations as $campo => $tablaRelacion) {

                // buscar comentario del campo
                $comment = null;

                foreach($columns as $col){
                    if($col['Field'] == $campo){
                        $comment = $col['COLUMN_COMMENT'] ?? null;
                    }
                }

                $relationData[$campo] = $crudService->getRelationData(
                    $tablaRelacion,
                    $campo,
                    $comment
                );
            }

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