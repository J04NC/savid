<?php

class DashboardController
{
    public function index()
    {
        if (!isset($_SESSION['user_id'])) {
            header("Location: ?url=login");
            exit;
        }

        $menuService = new MenuService($_SESSION['user_id']);
        $modulos = $menuService->getMenuPrincipal();
        $breadcrumb = '<a href="?url=dashboard">INICIO</a>';

        $view = BASE_PATH . '/app/views/home.php';
        require BASE_PATH . '/app/views/layouts/main.php';
    }

    public function modulo($moduloId)
    {
        if (!isset($_SESSION['user_id'])) {
            header("Location: ?url=login");
            exit;
        }

        $menuService = new MenuService($_SESSION['user_id']);

        $items = $menuService->getItemsByModulo($moduloId);
        $moduloNombre = $menuService->getModuloNombre($moduloId);

        $breadcrumb = '
        <a href="?url=dashboard">INICIO</a>
        <span class="separator"> / </span>
        <span>'.$moduloNombre.'</span>
        ';

        $view = BASE_PATH . '/app/views/modulo.php';
        require BASE_PATH . '/app/views/layouts/main.php';
    }

    public function item($itemId)
    {

        $database = new Database();
        $pdo = $database->connect();

        // OBTENER HIJOS
        $stmt = $pdo->prepare("
            SELECT *
            FROM item
            WHERE item_padre_id = ?
            AND estado = 1
            ORDER BY orden
        ");

        $stmt->execute([$itemId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // CONSTRUIR BREADCRUMB
        $breadcrumb = '<a href="?url=dashboard">INICIO</a>';

        // obtener item actual
        $stmt = $pdo->prepare("
            SELECT id,nombre,item_padre_id,modulo_id
            FROM item
            WHERE id = ?
        ");
        $stmt->execute([$itemId]);
        $currentItem = $stmt->fetch(PDO::FETCH_ASSOC);

        // obtener modulo
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

        // construir jerarquia items
        $currentId = $itemId;
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
            $breadcrumb .= '<a href="?url=dashboard/item/'.$p['id'].'">'.strtoupper($p['nombre']).'</a>';

        }

        $view = BASE_PATH . '/app/views/modulo.php';

        require BASE_PATH . '/app/views/layouts/main.php';

    }
}

