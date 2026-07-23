<?php

class SeguridadController
{
    private SeguridadService $seguridadService;

    public function __construct()
    {
        $this->seguridadService = new SeguridadService();
    }

    public function index(): void
    {
        if (!$this->seguridadService->canView()) {
            $_SESSION['flash_notice'] = 'Solo el superadministrador puede ver el centro de seguridad.';
            header('Location: ?url=dashboard');
            exit;
        }

        $data = $this->seguridadService->buildDashboard();

        $moduleService = new ModuleService();
        $breadcrumb = $moduleService->buildBreadcrumbForRuta('seguridad');

        $view = BASE_PATH . '/app/views/seguridad/index.php';
        require BASE_PATH . '/app/views/layouts/main.php';
    }
}
