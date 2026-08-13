<?php

class SihosController
{
    private SihosConnectionService $connectionService;
    private SihosCruceReconocimientoService $cruceService;
    private SihosPresupuestoEliminacionService $eliminacionService;

    public function __construct()
    {
        $this->connectionService = new SihosConnectionService();
        $this->cruceService = new SihosCruceReconocimientoService();
        $this->eliminacionService = new SihosPresupuestoEliminacionService();
    }

    /**
     * GET ?url=sihos — configuración/conexión con SIHOS (por empresa).
     */
    public function index(): void
    {
        $scope = $this->connectionService->buildScope($_GET);
        $config = $scope['empresaId'] !== null
            ? $this->connectionService->buildConfigView((int)$scope['empresaId'])
            : null;
        $puedeGuardar = PermisoService::can('sihos', 'guardar');

        $moduleService = new ModuleService();
        $breadcrumb = $moduleService->buildBreadcrumbForRuta('sihos');

        $view = BASE_PATH . '/app/views/sihos/config.php';
        require BASE_PATH . '/app/views/layouts/main.php';
    }

    /**
     * POST ?url=sihos/guardarConfig&empresa_id=N — guarda la conexión de esa empresa.
     */
    public function guardarConfig(): void
    {
        $scope = $this->connectionService->buildScope($_GET);
        $empresaId = $scope['empresaId'] !== null ? (int)$scope['empresaId'] : 0;

        if ($empresaId <= 0 || !PermisoService::can('sihos', 'guardar')) {
            http_response_code(403);
            exit('Acceso denegado.');
        }

        $result = $this->connectionService->saveConfig($empresaId, $_POST);
        $_SESSION['flash_notice'] = $result['message'];
        header('Location: ?url=sihos&empresa_id=' . $empresaId);
        exit;
    }

    /**
     * POST ?url=sihos/configProbar&empresa_id=N — prueba la conexión de esa empresa.
     */
    public function configProbar(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $scope = $this->connectionService->buildScope($_GET + $_POST);
        $empresaId = $scope['empresaId'] !== null ? (int)$scope['empresaId'] : 0;

        if ($empresaId <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Seleccione una empresa.'], JSON_UNESCAPED_UNICODE);

            return;
        }

        echo json_encode($this->connectionService->testConnection($empresaId), JSON_UNESCAPED_UNICODE);
    }

    /**
     * GET ?url=sihos/cruce&fecha_inicio=YYYY-MM-DD&fecha_fin=YYYY-MM-DD
     */
    public function cruce(): void
    {
        $scope = $this->connectionService->buildScope($_GET);
        $empresaId = $scope['empresaId'] !== null ? (int)$scope['empresaId'] : null;
        $configurado = $empresaId !== null && $this->connectionService->buildConfigView($empresaId)['configurado'];

        $fechaInicio = trim((string)($_GET['fecha_inicio'] ?? ''));
        $fechaFin = trim((string)($_GET['fecha_fin'] ?? ''));

        $reporte = null;
        if ($empresaId !== null && $configurado && $fechaInicio !== '' && $fechaFin !== '') {
            $reporte = $this->cruceService->buildReporte($empresaId, $fechaInicio, $fechaFin);
        }

        $puedeEliminarDetaPlan = PermisoService::can('sihos/cruce', 'eliminar');

        $moduleService = new ModuleService();
        $breadcrumb = $moduleService->buildBreadcrumbForRuta('sihos/cruce');

        $view = BASE_PATH . '/app/views/sihos/cruce.php';
        require BASE_PATH . '/app/views/layouts/main.php';
    }

    /**
     * POST ?url=sihos/cruceEliminarDetaPlan — borra el DetaPlan huérfano de
     * una nota sobre factura de vigencia anterior (única escritura contra
     * SIHOS de todo el módulo). Requiere el permiso 'eliminar' sobre
     * sihos/cruce, además del 'ver' que ya aplica Router::middleware().
     */
    public function cruceEliminarDetaPlan(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!PermisoService::can('sihos/cruce', 'eliminar')) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => 'Sin permiso.'], JSON_UNESCAPED_UNICODE);

            return;
        }

        $scope = $this->connectionService->buildScope($_POST);
        $empresaId = $scope['empresaId'] !== null ? (int)$scope['empresaId'] : 0;
        $codiDocu = trim((string)($_POST['codi_docu'] ?? ''));
        $numeDocu = trim((string)($_POST['nume_docu'] ?? ''));

        if ($empresaId <= 0 || $codiDocu === '' || $numeDocu === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Faltan datos del documento.'], JSON_UNESCAPED_UNICODE);

            return;
        }

        $resultado = $this->eliminacionService->eliminarDetaPlan($empresaId, $codiDocu, $numeDocu);

        if (!$resultado['ok']) {
            http_response_code(400);
        }

        echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
    }
}
