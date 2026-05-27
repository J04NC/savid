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

        if (!$this->dashboardService->userHasAccessToModulo((int)$moduloId)) {
            $_SESSION['flash_notice'] = 'No tiene permiso para acceder a este módulo en el contexto actual.';
            header('Location: ?url=dashboard');
            exit;
        }

        $moduloData = $this->dashboardService->getModuloData($moduloId);
        $items = $moduloData['items'];
        $moduloNombre = $moduloData['moduloNombre'];
        $moduloId = (int)$moduloId;

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

        $itemRow = $this->dashboardService->findItemForAccessCheck((int)$itemId);

        if (!$itemRow) {
            $_SESSION['flash_notice'] = 'Ítem no encontrado o sin acceso.';
            header('Location: ?url=dashboard');
            exit;
        }

        $itemRuta = trim((string)($itemRow['ruta'] ?? ''));
        if ($itemRuta !== ''
            && class_exists('PermisoService')
            && !PermisoService::can($itemRuta, 'ver')) {
            $_SESSION['flash_notice'] = 'No tiene permiso para este ítem en la empresa o sede actual.';
            header('Location: ?url=dashboard');
            exit;
        }

        $itemData = $this->dashboardService->getItemData($itemId);
        $items = $itemData['items'];
        $breadcrumb = $itemData['breadcrumb'];
        $moduloId = (int)($itemRow['modulo_id'] ?? 0);
        if ($moduloId <= 0) {
            $moduloId = null;
        }

        $view = BASE_PATH . '/app/views/modulo.php';

        require BASE_PATH . '/app/views/layouts/main.php';

    }

    /**
     * Sube la versión en sesión para los query strings de CSS/JS y fuerza recarga en el navegador.
     */
    public function refreshAssets()
    {
        if (!isset($_SESSION['user_id'])) {
            header('Location: ?url=login');
            exit;
        }

        $_SESSION['asset_cache_bust'] = time();

        $host = $_SERVER['HTTP_HOST'] ?? '';
        $ref = $_SERVER['HTTP_REFERER'] ?? '';

        if ($ref !== '' && $host !== '') {
            $parsed = parse_url($ref);
            if (!empty($parsed['scheme']) && ($parsed['host'] ?? '') === $host) {
                header('Location: ' . $ref);
                exit;
            }
        }

        header('Location: ?url=dashboard');
        exit;
    }
}

