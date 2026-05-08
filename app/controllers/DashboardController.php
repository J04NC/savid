<?php

class DashboardController
{
    private DashboardService $dashboardService;

    public function __construct()
    {
        if (isset($_SESSION['user_id'])) {
            $this->dashboardService = new DashboardService($_SESSION['user_id']);
        }
    }

    public function index()
    {
        if (!isset($_SESSION['user_id'])) {
            header("Location: ?url=login");
            exit;
        }

        $modulos = $this->dashboardService->getMenuPrincipal();
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

        $moduloData = $this->dashboardService->getModuloData($moduloId);
        $items = $moduloData['items'];
        $moduloNombre = $moduloData['moduloNombre'];

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
        if (!isset($_SESSION['user_id'])) {
            header("Location: ?url=login");
            exit;
        }

        $itemData = $this->dashboardService->getItemData($itemId);
        $items = $itemData['items'];
        $breadcrumb = $itemData['breadcrumb'];

        $view = BASE_PATH . '/app/views/modulo.php';

        require BASE_PATH . '/app/views/layouts/main.php';

    }
}

