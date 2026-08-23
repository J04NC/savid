<?php

class SihosController
{
    private SihosConnectionService $connectionService;
    private SihosCruceReconocimientoService $cruceService;
    private SihosPresupuestoEliminacionService $eliminacionService;
    private SihosCancelacionCuentaService $cancelacionCuentaService;

    public function __construct()
    {
        $this->connectionService = new SihosConnectionService();
        $this->cruceService = new SihosCruceReconocimientoService();
        $this->eliminacionService = new SihosPresupuestoEliminacionService();
        $this->cancelacionCuentaService = new SihosCancelacionCuentaService();
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
        $puedeReversarCuenta = PermisoService::can('sihos/cruce', 'nota_ajuste');
        $puedeConstruirDetaPlan = PermisoService::can('sihos/cruce', 'construir_detaplan');

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

    /**
     * POST ?url=sihos/cruceConstruirDetaPlan — construye en SIHOS la línea
     * DetaPlan que le falta a una nota (NCF) sobre una factura de la misma
     * vigencia (sección 3 del reporte). Requiere el permiso
     * 'construir_detaplan' sobre sihos/cruce, además del 'ver' que ya aplica
     * Router::middleware().
     */
    public function cruceConstruirDetaPlan(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!PermisoService::can('sihos/cruce', 'construir_detaplan')) {
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

        $resultado = $this->eliminacionService->construirDetaPlan($empresaId, $codiDocu, $numeDocu);

        if (!$resultado['ok']) {
            http_response_code(400);
        }

        echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
    }

    /**
     * POST ?url=sihos/cruceReversarCuentaInesperada — crea en SIHOS la Nota
     * Contabilidad (NC) que cancela una cuenta contable fuera de lo
     * esperado (sección 5a) contra la(s) cuenta(s) 4312 de la factura.
     * Requiere el permiso 'nota_ajuste' sobre sihos/cruce, además del 'ver'
     * que ya aplica Router::middleware().
     */
    public function cruceReversarCuentaInesperada(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!PermisoService::can('sihos/cruce', 'nota_ajuste')) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => 'Sin permiso.'], JSON_UNESCAPED_UNICODE);

            return;
        }

        $scope = $this->connectionService->buildScope($_POST);
        $empresaId = $scope['empresaId'] !== null ? (int)$scope['empresaId'] : 0;
        $codiDocu = trim((string)($_POST['codi_docu'] ?? ''));
        $numeDocu = trim((string)($_POST['nume_docu'] ?? ''));
        $consDeta = (int)($_POST['cons_deta'] ?? 0);

        if ($empresaId <= 0 || $codiDocu === '' || $numeDocu === '' || $consDeta <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Faltan datos del documento.'], JSON_UNESCAPED_UNICODE);

            return;
        }

        $resultado = $this->cancelacionCuentaService->reversarCuentaInesperada($empresaId, $codiDocu, $numeDocu, $consDeta);

        if (!$resultado['ok']) {
            http_response_code(400);
        }

        echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
    }

    /**
     * POST ?url=sihos/cruceReclasificarCuentaVigenciaAnterior — reclasifica
     * la(s) cuenta(s) 4312 de una nota de vigencia anterior (sección 5b)
     * hacia la cuenta configurada que se elija. Decide sola entre editar en
     * sitio (mes abierto) o crear una nota de ajuste (mes cerrado). Mismo
     * permiso 'nota_ajuste' que la acción de la sección 5a — es la misma
     * familia de acción (ajustes contables desde SAVID).
     */
    public function cruceReclasificarCuentaVigenciaAnterior(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!PermisoService::can('sihos/cruce', 'nota_ajuste')) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => 'Sin permiso.'], JSON_UNESCAPED_UNICODE);

            return;
        }

        $scope = $this->connectionService->buildScope($_POST);
        $empresaId = $scope['empresaId'] !== null ? (int)$scope['empresaId'] : 0;
        $codiDocu = trim((string)($_POST['codi_docu'] ?? ''));
        $numeDocu = trim((string)($_POST['nume_docu'] ?? ''));
        $cuentaDestino = trim((string)($_POST['cuenta_destino'] ?? ''));

        if ($empresaId <= 0 || $codiDocu === '' || $numeDocu === '' || $cuentaDestino === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Faltan datos del documento o de la cuenta destino.'], JSON_UNESCAPED_UNICODE);

            return;
        }

        $resultado = $this->cancelacionCuentaService->reclasificarCuentaVigenciaAnterior($empresaId, $codiDocu, $numeDocu, $cuentaDestino);

        if (!$resultado['ok']) {
            http_response_code(400);
        }

        echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
    }
}
