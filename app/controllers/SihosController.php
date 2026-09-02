<?php

class SihosController
{
    private SihosConnectionService $connectionService;
    private SihosCruceReconocimientoService $cruceService;
    private SihosPresupuestoEliminacionService $eliminacionService;
    private SihosCancelacionCuentaService $cancelacionCuentaService;
    private SihosNominaPilaService $nominaPilaService;
    private SihosNominaPilaCorreccionService $correccionService;
    private SihosPlanillaIntegradaService $planillaIntegradaService;

    public function __construct()
    {
        $this->connectionService = new SihosConnectionService();
        $this->cruceService = new SihosCruceReconocimientoService();
        $this->eliminacionService = new SihosPresupuestoEliminacionService();
        $this->cancelacionCuentaService = new SihosCancelacionCuentaService();
        $this->nominaPilaService = new SihosNominaPilaService();
        $this->correccionService = new SihosNominaPilaCorreccionService();
        $this->planillaIntegradaService = new SihosPlanillaIntegradaService();
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

    /**
     * GET ?url=sihos/nominaPila&empresa_id=N&codi_ano=YYYY&codi_mes=M
     */
    public function nominaPila(): void
    {
        $scope = $this->connectionService->buildScope($_GET);
        $empresaId = $scope['empresaId'] !== null ? (int)$scope['empresaId'] : null;
        $configurado = $empresaId !== null && $this->connectionService->buildConfigView($empresaId)['configurado'];

        // Por defecto, año/mes actuales — los <select> del formulario quedan
        // preseleccionados en el período en curso, pero la consulta pesada
        // contra SIHOS solo corre cuando el usuario le da clic a "Consultar"
        // (botón con name="buscar", nunca al solo cargar la página).
        $codiAno = trim((string)($_GET['codi_ano'] ?? date('Y')));
        $codiMes = trim((string)($_GET['codi_mes'] ?? date('n')));

        $resultado = null;
        if ($empresaId !== null && $configurado && isset($_GET['buscar']) && $codiAno !== '' && $codiMes !== '') {
            $resultado = $this->nominaPilaService->buildFilas($empresaId, $codiAno, $codiMes);
        }

        $moduleService = new ModuleService();
        $breadcrumb = $moduleService->buildBreadcrumbForRuta('sihos/nominaPila');

        $view = BASE_PATH . '/app/views/sihos/nomina-pila.php';
        require BASE_PATH . '/app/views/layouts/main.php';
    }

    /**
     * GET ?url=sihos/nominaPilaExportar&empresa_id=N&codi_ano=YYYY&codi_mes=M
     * Descarga el .xlsx de nómina para "Aportes en línea" (PILA). Mismo
     * permiso 'ver' de sihos/nominaPila: es una descarga de solo lectura del
     * mismo reporte, sin ítem de menú propio (ver Router::middleware(), que
     * ya exige 'ver' sobre esta URL o su raíz 'sihos' antes de llegar aquí);
     * se revalida explícitamente contra 'sihos/nominaPila' para no heredar
     * el permiso más laxo de la raíz 'sihos' (Conexión SIHOS).
     */
    public function nominaPilaExportar(): void
    {
        if (!PermisoService::can('sihos/nominaPila', 'ver')) {
            http_response_code(403);
            exit('Acceso denegado.');
        }

        $scope = $this->connectionService->buildScope($_GET);
        $empresaId = $scope['empresaId'] !== null ? (int)$scope['empresaId'] : 0;
        $codiAno = trim((string)($_GET['codi_ano'] ?? ''));
        $codiMes = trim((string)($_GET['codi_mes'] ?? ''));
        $sucursalCodigo = trim((string)($_GET['sucursal_codigo'] ?? ''));

        if ($empresaId <= 0 || $codiAno === '' || $codiMes === '') {
            http_response_code(400);
            exit('Faltan datos del período.');
        }

        $resultado = $this->nominaPilaService->buildFilas($empresaId, $codiAno, $codiMes);
        if (!$resultado['ok']) {
            http_response_code(400);
            exit(htmlspecialchars($resultado['error'], ENT_QUOTES, 'UTF-8'));
        }

        $nombreArchivo = sprintf('sihos_nomina_pila_%s-%s.xlsx', $codiAno, str_pad($codiMes, 2, '0', STR_PAD_LEFT));

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
        header('Cache-Control: max-age=0');

        $this->nominaPilaService->exportarXlsx($resultado['filas'], $codiAno, $codiMes, 'php://output', $sucursalCodigo);
    }

    /**
     * GET/POST ?url=sihos/nominaPilaCorreccion&empresa_id=N&codi_ano=YYYY&codi_mes=M
     * Carga el CSV de "posibles correcciones" que entrega el portal de
     * aportes en línea y muestra, cruzado contra SIHOS, cuáles se pueden
     * corregir en `DetaNomi` (aporte patronal) antes de confirmar la nómina.
     * Pantalla propia — nunca escribe en el POST de esta acción, solo arma
     * la vista previa (la escritura real va por nominaPilaCorreccionAplicar,
     * detrás de su propio permiso 'guardar').
     */
    public function nominaPilaCorreccion(): void
    {
        $scope = $this->connectionService->buildScope($_REQUEST);
        $empresaId = $scope['empresaId'] !== null ? (int)$scope['empresaId'] : null;
        $configurado = $empresaId !== null && $this->connectionService->buildConfigView($empresaId)['configurado'];

        $codiAno = trim((string)($_REQUEST['codi_ano'] ?? date('Y')));
        $codiMes = trim((string)($_REQUEST['codi_mes'] ?? date('n')));

        $vistaPrevia = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $empresaId !== null && $configurado) {
            $archivo = $_FILES['csv_correcciones'] ?? null;

            if ($archivo === null || (int)($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $vistaPrevia = ['ok' => false, 'error' => 'No se pudo recibir el archivo. Intente de nuevo.'];
            } else {
                try {
                    $filasCsv = $this->correccionService->parseCsv($archivo['tmp_name']);
                    $vistaPrevia = $this->correccionService->construirVistaPrevia($empresaId, $codiAno, $codiMes, $filasCsv);
                } catch (\RuntimeException $e) {
                    $vistaPrevia = ['ok' => false, 'error' => $e->getMessage()];
                }
            }
        }

        $puedeGuardar = PermisoService::can('sihos/nominaPilaCorreccion', 'guardar');

        $moduleService = new ModuleService();
        $breadcrumb = $moduleService->buildBreadcrumbForRuta('sihos/nominaPilaCorreccion');

        $view = BASE_PATH . '/app/views/sihos/nomina-pila-correccion.php';
        require BASE_PATH . '/app/views/layouts/main.php';
    }

    /**
     * POST ?url=sihos/nominaPilaCorreccionAplicar — aplica las correcciones
     * de aporte patronal que el usuario marcó en la vista previa. Responde
     * JSON con el resultado fila por fila (puede haber éxitos y rechazos
     * mezclados: por ejemplo, si la nómina se confirmó en SIHOS justo entre
     * la vista previa y este clic). Requiere el permiso 'guardar' sobre
     * sihos/nominaPilaCorreccion, además del 'ver' que ya aplica
     * Router::middleware().
     */
    public function nominaPilaCorreccionAplicar(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!PermisoService::can('sihos/nominaPilaCorreccion', 'guardar')) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Sin permiso.'], JSON_UNESCAPED_UNICODE);

            return;
        }

        $scope = $this->connectionService->buildScope($_POST);
        $empresaId = $scope['empresaId'] !== null ? (int)$scope['empresaId'] : 0;
        $codiAno = trim((string)($_POST['codi_ano'] ?? ''));
        $codiMes = trim((string)($_POST['codi_mes'] ?? ''));
        $seleccion = $_POST['seleccion'] ?? [];

        if ($empresaId <= 0 || $codiAno === '' || $codiMes === '' || !is_array($seleccion)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Faltan datos del período o de la selección.'], JSON_UNESCAPED_UNICODE);

            return;
        }

        $resultado = $this->correccionService->aplicarCorrecciones($empresaId, $codiAno, $codiMes, $seleccion);

        if (!$resultado['ok']) {
            http_response_code(400);
        }

        echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
    }

    /**
     * GET/POST ?url=sihos/nominaPlanillaIntegrada&empresa_id=N&codi_ano=YYYY&codi_mes=M
     * Carga la "Planilla Integrada de Liquidación de Aportes" que genera el
     * operador al finalizar el cargue completo (ya liquidada/pagada, a
     * diferencia del CSV de "posibles correcciones" de sihos/nominaPilaCorreccion)
     * y la compara de solo lectura contra SIHOS: por empleado+concepto y
     * agregado por administradora. Nunca escribe nada — no tiene acción de
     * aplicar, solo la de 'ver' (igual que sihos/nominaPila).
     */
    public function nominaPlanillaIntegrada(): void
    {
        $scope = $this->connectionService->buildScope($_REQUEST);
        $empresaId = $scope['empresaId'] !== null ? (int)$scope['empresaId'] : null;
        $configurado = $empresaId !== null && $this->connectionService->buildConfigView($empresaId)['configurado'];

        $codiAno = trim((string)($_REQUEST['codi_ano'] ?? date('Y')));
        $codiMes = trim((string)($_REQUEST['codi_mes'] ?? date('n')));

        $comparacion = null;
        $archivoInfo = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $empresaId !== null && $configurado) {
            $archivo = $_FILES['csv_planilla'] ?? null;

            if ($archivo === null || (int)($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $comparacion = ['ok' => false, 'error' => 'No se pudo recibir el archivo. Intente de nuevo.'];
            } else {
                $parseo = $this->planillaIntegradaService->parseCsv($archivo['tmp_name']);
                if (!$parseo['ok']) {
                    $comparacion = ['ok' => false, 'error' => $parseo['error']];
                } else {
                    $archivoInfo = ['empresa' => $parseo['empresa'], 'periodo_archivo' => $parseo['periodo_archivo']];
                    $comparacion = $this->planillaIntegradaService->construirComparacion(
                        $empresaId, $codiAno, $codiMes, $parseo['empleados'], $parseo['administradoras']
                    );
                }
            }
        }

        $moduleService = new ModuleService();
        $breadcrumb = $moduleService->buildBreadcrumbForRuta('sihos/nominaPlanillaIntegrada');

        $view = BASE_PATH . '/app/views/sihos/nomina-planilla-integrada.php';
        require BASE_PATH . '/app/views/layouts/main.php';
    }
}
