<?php

class AuditoriaController
{
    private AuditQueryService $auditQuery;
    private ModuleService $moduleService;

    public function __construct()
    {
        $this->auditQuery = new AuditQueryService();
        $this->moduleService = new ModuleService();
    }

    public function index(): void
    {
        if (!$this->auditQuery->canView()) {
            $_SESSION['flash_notice'] = 'No tiene permiso para consultar la auditoría.';
            header('Location: ?url=dashboard');
            exit;
        }

        // DataTables pide las filas al servidor página por página (modo servidor, ver datos()):
        // aquí solo se arma el formulario de filtros, sin traer registros de auditoría.
        $tablas = $this->auditQuery->getTableNames();
        $reportScope = $this->auditQuery->getReportScope($_GET);
        $breadcrumb = $this->moduleService->buildBreadcrumbForRuta('auditoria');

        $filters = [
            'desde' => $_GET['desde'] ?? '',
            'hasta' => $_GET['hasta'] ?? '',
            'tabla' => $_GET['tabla'] ?? '',
            'accion' => $_GET['accion'] ?? '',
            'usuario_id' => $_GET['usuario_id'] ?? '',
            'registro_id' => $_GET['registro_id'] ?? '',
            'empresa_id' => $reportScope['filterEmpresaId'] ?? '',
            'sede_id' => $reportScope['filterSedeId'] ?? '',
            'q' => $_GET['q'] ?? '',
            'archivo' => !empty($_GET['archivo']),
        ];

        $esSuperAdmin = PermisoService::isSuperAdminSession();
        $reportUrl = 'auditoria';

        $view = BASE_PATH . '/app/views/auditoria/index.php';
        require BASE_PATH . '/app/views/layouts/main.php';
    }

    /**
     * GET ?url=auditoria/datos — fuente de datos para DataTables en modo servidor.
     */
    public function datos(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!$this->auditQuery->canView()) {
            http_response_code(403);
            echo json_encode(['error' => 'Sin permiso'], JSON_UNESCAPED_UNICODE);

            return;
        }

        $draw = (int)($_GET['draw'] ?? 0);
        $start = max(0, (int)($_GET['start'] ?? 0));
        // El listado ya no trae los snapshots JSON pesados (ver AuditRepository::search), así que
        // 1000 filas acotadas es seguro; el límite real evita un valor arbitrario/negativo del cliente.
        $length = (int)($_GET['length'] ?? 10);
        if ($length <= 0 || $length > 1000) {
            $length = 10;
        }
        $orderColIndex = (int)($_GET['order'][0]['column'] ?? 0);
        $orderDir = (string)($_GET['order'][0]['dir'] ?? 'desc');
        $globalSearch = (string)($_GET['search']['value'] ?? '');

        $result = $this->auditQuery->listForDataTable($_GET, $start, $length, $orderColIndex, $orderDir, $globalSearch);

        echo json_encode([
            'draw' => $draw,
            'recordsTotal' => $result['recordsTotal'],
            'recordsFiltered' => $result['recordsFiltered'],
            'data' => $result['data'],
        ], JSON_UNESCAPED_UNICODE);
    }

    public function detalle(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!$this->auditQuery->canView()) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Sin permiso'], JSON_UNESCAPED_UNICODE);

            return;
        }

        $id = (int)($_GET['id'] ?? 0);
        $fromArchive = !empty($_GET['archivo']);

        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'ID inválido'], JSON_UNESCAPED_UNICODE);

            return;
        }

        $row = $this->auditQuery->getDetail($id, $fromArchive);

        if (!$row) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Registro no encontrado'], JSON_UNESCAPED_UNICODE);

            return;
        }

        echo json_encode(['ok' => true, 'data' => $row], JSON_UNESCAPED_UNICODE);
    }

    public function archivar(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!PermisoService::isSuperAdminSession()) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Solo superadministrador'], JSON_UNESCAPED_UNICODE);

            return;
        }

        $months = (int)($_POST['meses'] ?? $_GET['meses'] ?? 24);
        $months = max(6, min(120, $months));

        try {
            $result = $this->auditQuery->archiveHotData($months);
            echo json_encode([
                'ok' => true,
                'moved' => $result['moved'],
                'cutoff' => $result['cutoff'],
                'message' => "Se archivaron {$result['moved']} registros anteriores a {$result['cutoff']}.",
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode([
                'ok' => false,
                'error' => 'Error al archivar: ' . $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE);
        }
    }
}
