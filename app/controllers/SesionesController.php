<?php

class SesionesController
{
    private SesionReportService $reportService;
    private SesionTrackingService $trackingService;
    private ModuleService $moduleService;

    public function __construct()
    {
        $this->reportService = new SesionReportService();
        $this->trackingService = new SesionTrackingService();
        $this->moduleService = new ModuleService();
    }

    /**
     * Reporte sesiones activas — ítem menú id 13, ruta sesiones.
     */
    public function index(): void
    {
        if (!$this->reportService->canView()) {
            $_SESSION['flash_notice'] = 'No tiene permiso para ver el reporte de sesiones.';
            header('Location: ?url=dashboard');
            exit;
        }

        // DataTables pagina en el navegador (10 por página, como usuario/tercero); traemos todo el set filtrado.
        $list = $this->reportService->listPage($_GET, 100000);
        $reportScope = $list['reportScope'] ?? [];

        $breadcrumb = $this->moduleService->buildBreadcrumbForRuta('sesiones');

        $filters = [
            'desde' => $_GET['desde'] ?? '',
            'hasta' => $_GET['hasta'] ?? '',
            'usuario_id' => $_GET['usuario_id'] ?? '',
            'empresa_id' => $reportScope['filterEmpresaId'] ?? '',
            'sede_id' => $reportScope['filterSedeId'] ?? '',
            'solo_activas' => !empty($_GET['solo_activas']),
            'inactividad_min' => $_GET['inactividad_min'] ?? '30',
            'q' => $_GET['q'] ?? '',
        ];

        $esSuperAdmin = PermisoService::isSuperAdminSession();
        $reportUrl = 'sesiones';

        $view = BASE_PATH . '/app/views/sesiones/index.php';
        require BASE_PATH . '/app/views/layouts/main.php';
    }

    /**
     * Latido de actividad (JSON). Actualiza last_activity_at de la sesión actual
     * y avisa si un administrador cerró esta sesión desde el reporte.
     */
    public function ping(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!isset($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode(['ok' => false], JSON_UNESCAPED_UNICODE);

            return;
        }

        if ($this->trackingService->wasCurrentSessionClosedByAdmin()) {
            echo json_encode(['ok' => true, 'forced_logout' => true], JSON_UNESCAPED_UNICODE);

            return;
        }

        if (!isset($_SESSION['usuario_sesion_id'])) {
            $this->trackingService->openSessionForCurrentUser();
        } else {
            $this->trackingService->touchCurrentSession();
        }

        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    }

    /**
     * POST ?url=sesiones/cerrar/{id} — cierra una sesión ajena (admin / alcance).
     */
    public function cerrar($sesionId = null): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'Método no permitido'], JSON_UNESCAPED_UNICODE);

            return;
        }

        $result = $this->reportService->close((int)$sesionId);

        if (!$result['success']) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => $result['error']], JSON_UNESCAPED_UNICODE);

            return;
        }

        echo json_encode(['ok' => true, 'message' => 'Sesión cerrada.'], JSON_UNESCAPED_UNICODE);
    }
}
