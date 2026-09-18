<?php

class SihosController
{
    private SihosConnectionService $connectionService;
    private SihosCruceReconocimientoService $cruceService;
    private SihosAuditoriaGlosaService $auditoriaGlosaService;
    private SihosGlosaConclusionService $glosaConclusionService;
    private SihosPresupuestoEliminacionService $eliminacionService;
    private SihosCancelacionCuentaService $cancelacionCuentaService;
    private SihosAuditoriaReferenciasService $auditoriaReferenciasService;
    private SihosNominaPilaService $nominaPilaService;
    private SihosNominaPilaCorreccionService $correccionService;
    private SihosPlanillaIntegradaService $planillaIntegradaService;
    private SihosInterlabService $interlabService;
    private SihosInterlabProcesarService $interlabProcesarService;
    private SihosTarifaProcedimientoService $tarifaProcedimientoService;

    public function __construct()
    {
        $this->connectionService = new SihosConnectionService();
        $this->cruceService = new SihosCruceReconocimientoService();
        $this->auditoriaGlosaService = new SihosAuditoriaGlosaService();
        $this->glosaConclusionService = new SihosGlosaConclusionService();
        $this->eliminacionService = new SihosPresupuestoEliminacionService();
        $this->cancelacionCuentaService = new SihosCancelacionCuentaService();
        $this->auditoriaReferenciasService = new SihosAuditoriaReferenciasService();
        $this->nominaPilaService = new SihosNominaPilaService();
        $this->correccionService = new SihosNominaPilaCorreccionService();
        $this->planillaIntegradaService = new SihosPlanillaIntegradaService();
        $this->interlabService = new SihosInterlabService();
        $this->interlabProcesarService = new SihosInterlabProcesarService();
        $this->tarifaProcedimientoService = new SihosTarifaProcedimientoService();
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
     * GET ?url=sihos/auditoriaGlosa&fecha_inicio=YYYY-MM-DD&fecha_fin=YYYY-MM-DD
     * [&fecha_corte=YYYY-MM-DD&tercero=...&tipo_usuario=...]
     *
     * Reporte de solo lectura: glosas cruzadas contra la factura que
     * referencian, su cartera real y la cuenta de orden de glosa en
     * trámite. `fecha_corte` es independiente del rango — si no viene, toma
     * `fecha_fin`.
     */
    public function auditoriaGlosa(): void
    {
        $scope = $this->connectionService->buildScope($_GET);
        $empresaId = $scope['empresaId'] !== null ? (int)$scope['empresaId'] : null;
        $configurado = $empresaId !== null && $this->connectionService->buildConfigView($empresaId)['configurado'];

        $fechaInicio = trim((string)($_GET['fecha_inicio'] ?? ''));
        $fechaFin = trim((string)($_GET['fecha_fin'] ?? ''));
        $fechaCorte = trim((string)($_GET['fecha_corte'] ?? ''));
        $codigoAdministradora = trim((string)($_GET['administradora'] ?? ''));
        $nombreAdministradora = trim((string)($_GET['administradora_nombre'] ?? ''));
        $tipoUsuario = trim((string)($_GET['tipo_usuario'] ?? ''));
        // Ausente del todo (primera visita, sin filtros enviados) = marcado
        // por defecto; una vez el formulario se envía, la vista siempre
        // manda '0' o '1' explícito (input hidden + checkbox), sin ambigüedad.
        $soloEnCurso = !isset($_GET['en_curso']) || $_GET['en_curso'] === '1';

        $tiposUsuario = [];
        $reporte = null;
        $tokenDatos = null;
        $conclusionSaldoCero = ['exportables' => [], 'excluidas' => []];
        if ($empresaId !== null && $configurado) {
            $tiposUsuario = $this->auditoriaGlosaService->listarTiposUsuario($empresaId);

            if ($fechaFin !== '') {
                $reporte = $this->auditoriaGlosaService->buildReporte(
                    $empresaId,
                    $fechaInicio,
                    $fechaFin,
                    $fechaCorte,
                    $codigoAdministradora,
                    $tipoUsuario,
                    $soloEnCurso
                );

                // Recarga de página con ?administradora=... en la URL (sin pasar
                // por el autocompletado en esta carga): recupera el nombre desde
                // el propio resultado para no dejar el campo de búsqueda vacío.
                if ($nombreAdministradora === '' && $codigoAdministradora !== '' && $reporte['ok'] && $reporte['filas'] !== []) {
                    $nombreAdministradora = (string)($reporte['filas'][0]['NombAdmi'] ?? '');
                }

                // El resultado calculado se cachea (TTL corto, ver
                // SihosAuditoriaGlosaCache) para que la tabla se pagine del
                // lado del servidor (data-dt-server en la vista) sin
                // renderizar decenas de miles de <tr> de una sola vez ni
                // volver a consultar SIHOS por cada página/orden/búsqueda.
                if ($reporte['ok'] && $reporte['filas'] !== []) {
                    $tokenDatos = SihosAuditoriaGlosaCache::store(
                        $empresaId,
                        (int)($_SESSION['user_id'] ?? 0),
                        $reporte['filas'],
                        ['fechaFin' => $fechaFin, 'fechaCorte' => $fechaCorte !== '' ? $fechaCorte : $fechaFin]
                    );

                    $conclusionSaldoCero = $this->auditoriaGlosaService->prepararConclusionSaldoCero($reporte['filas']);
                }
            }
        }

        $puedeConcluirDirecto = PermisoService::can('sihos/auditoriaGlosa', 'concluir');

        $moduleService = new ModuleService();
        $breadcrumb = $moduleService->buildBreadcrumbForRuta('sihos/auditoriaGlosa');

        $view = BASE_PATH . '/app/views/sihos/auditoria-glosa.php';
        require BASE_PATH . '/app/views/layouts/main.php';
    }

    /**
     * GET ?url=sihos/auditoriaGlosaBuscarAdministradora&empresa_id=N&q=texto
     * — autocompletado del filtro de administradora (CodiAdmi) por código,
     * NIT o nombre. Sin ítem de menú propio (ver Router::middleware(),
     * $sihosAuditoriaGlosaAccionMethods): exige el mismo 'ver' de
     * sihos/auditoriaGlosa.
     */
    public function auditoriaGlosaBuscarAdministradora(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $scope = $this->connectionService->buildScope($_GET);
        $empresaId = $scope['empresaId'] !== null ? (int)$scope['empresaId'] : null;
        $termino = trim((string)($_GET['q'] ?? ''));

        if ($empresaId === null || $termino === '') {
            echo json_encode(['ok' => true, 'resultados' => []], JSON_UNESCAPED_UNICODE);

            return;
        }

        $resultados = $this->auditoriaGlosaService->buscarAdministradoras($empresaId, $termino);
        echo json_encode(['ok' => true, 'resultados' => $resultados], JSON_UNESCAPED_UNICODE);
    }

    /**
     * GET ?url=sihos/auditoriaGlosaDatos&empresa_id=N&token=... — fuente de
     * datos server-side de DataTables para la tabla de sihos/auditoriaGlosa
     * (ver public/js/datatables-savid.js, data-dt-server). Pagina/ordena/
     * busca sobre el resultado ya calculado y cacheado (ver
     * SihosAuditoriaGlosaCache) — no vuelve a consultar SIHOS. Sin ítem de
     * menú propio (ver Router::middleware(), $sihosAuditoriaGlosaAccionMethods):
     * exige el mismo 'ver' de sihos/auditoriaGlosa.
     */
    public function auditoriaGlosaDatos(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $scope = $this->connectionService->buildScope($_GET);
        $empresaId = $scope['empresaId'] !== null ? (int)$scope['empresaId'] : null;
        $token = trim((string)($_GET['token'] ?? ''));
        $draw = (int)($_GET['draw'] ?? 0);

        if ($empresaId === null || $token === '') {
            echo json_encode(['draw' => $draw, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []], JSON_UNESCAPED_UNICODE);

            return;
        }

        // El cache puede tener ~87.000 filas (~40 MB de JSON) cuando el
        // reporte se corrió sin "Desde" — sin este colchón, decodificarlo
        // agota el memory_limit real del pool de PHP-FPM (128M) y, con
        // display_errors=Off, deja el body vacío ("Invalid JSON response"
        // en DataTables, caso real 2026-09-11). Envuelve TODO el trabajo
        // (load + paginar + json_encode), no solo el load — ver docblock de
        // SihosMemoryGuard.
        SihosMemoryGuard::ejecutar(1024, function () use ($empresaId, $token, $draw): void {
            $cache = SihosAuditoriaGlosaCache::load($token, $empresaId, (int)($_SESSION['user_id'] ?? 0));
            if ($cache === null) {
                echo json_encode([
                    'draw' => $draw,
                    'recordsTotal' => 0,
                    'recordsFiltered' => 0,
                    'data' => [],
                    'error' => 'El resultado de este reporte expiró o no existe. Vuelva a ejecutar la auditoría.',
                ], JSON_UNESCAPED_UNICODE);

                return;
            }

            $start = max(0, (int)($_GET['start'] ?? 0));
            $length = (int)($_GET['length'] ?? 10);
            if ($length <= 0 || $length > 1000) {
                $length = 10;
            }
            $orderColIndex = (int)($_GET['order'][0]['column'] ?? 0);
            $orderDir = (string)($_GET['order'][0]['dir'] ?? 'asc');
            $globalSearch = (string)($_GET['search']['value'] ?? '');

            $columnSearches = [];
            foreach ((array)($_GET['columns'] ?? []) as $idx => $col) {
                $valor = trim((string)($col['search']['value'] ?? ''));
                if ($valor !== '') {
                    $columnSearches[(int)$idx] = $valor;
                }
            }

            $resultado = $this->auditoriaGlosaService->paginarParaDataTable(
                $cache['filas'],
                $start,
                $length,
                $orderColIndex,
                $orderDir,
                $globalSearch,
                $columnSearches
            );

            echo json_encode([
                'draw' => $draw,
                'recordsTotal' => (int)($cache['meta']['total'] ?? count($cache['filas'])),
                'recordsFiltered' => $resultado['recordsFiltered'],
                'data' => $resultado['data'],
            ], JSON_UNESCAPED_UNICODE);
        });
    }

    /**
     * GET ?url=sihos/auditoriaGlosaExportar&empresa_id=N&token=... —
     * descarga en CSV de TODO el resultado cacheado (no solo la página
     * visible), sin pasar por el DOM del navegador. Sin ítem de menú propio
     * (ver Router::middleware(), $sihosAuditoriaGlosaAccionMethods): exige
     * el mismo 'ver' de sihos/auditoriaGlosa.
     */
    public function auditoriaGlosaExportar(): void
    {
        $scope = $this->connectionService->buildScope($_GET);
        $empresaId = $scope['empresaId'] !== null ? (int)$scope['empresaId'] : null;
        $token = trim((string)($_GET['token'] ?? ''));

        if ($empresaId === null || $token === '') {
            http_response_code(400);
            exit('Falta empresa o token.');
        }

        // Mismo colchón que auditoriaGlosaDatos() — ver SihosMemoryGuard.
        SihosMemoryGuard::ejecutar(1024, function () use ($empresaId, $token): void {
            $cache = SihosAuditoriaGlosaCache::load($token, $empresaId, (int)($_SESSION['user_id'] ?? 0));
            if ($cache === null) {
                http_response_code(410);
                exit('El resultado de este reporte expiró. Vuelva a ejecutar la auditoría.');
            }

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="auditoria_glosa_' . date('Ymd_His') . '.csv"');

            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'Tipo usuario', 'Tercero', 'Administradora', 'Fecha glosa', 'Glosa', 'Factura',
                'Saldo cartera', 'Valor glosa', 'En curso (calc.)', 'Dias', 'Cuenta 8333',
                'Cuenta 8333 NIIF', 'En curso (SIHOS)', 'Acep. IPS', 'Acep. EPS',
            ], ';');

            foreach ($this->auditoriaGlosaService->filasParaExportar($cache['filas']) as $fila) {
                fputcsv($out, $fila, ';');
            }

            fclose($out);
        });
    }

    /**
     * POST ?url=sihos/auditoriaGlosaExportarConcluirCsv — genera el CSV en
     * el formato exacto de "Importar Glosas → Detalle" de SIHOS (ver
     * SihosAuditoriaGlosaService::filasParaConcluirCsv()) para las filas
     * seleccionadas de la sección "Glosas en curso con factura saldada".
     * `claves[]` = subconjunto elegido por el usuario (uno o varios valores
     * "CodiDocu-NumeDocu"); si viene vacío/ausente, exporta TODAS las
     * exportables. Sigue siendo de solo lectura contra SIHOS — genera un
     * archivo para que el usuario lo cargue manualmente ahí, no escribe
     * nada. Sin ítem de menú propio (ver Router::middleware(),
     * $sihosAuditoriaGlosaAccionMethods): exige el mismo 'ver' de
     * sihos/auditoriaGlosa.
     */
    public function auditoriaGlosaExportarConcluirCsv(): void
    {
        $scope = $this->connectionService->buildScope($_POST);
        $empresaId = $scope['empresaId'] !== null ? (int)$scope['empresaId'] : null;
        $token = trim((string)($_POST['token'] ?? ''));
        $fechaCorte = trim((string)($_POST['fecha_corte'] ?? ''));
        $claves = array_map('strval', (array)($_POST['claves'] ?? []));

        if ($empresaId === null || $token === '' || $fechaCorte === '') {
            http_response_code(400);
            exit('Falta empresa, token o fecha de corte.');
        }

        SihosMemoryGuard::ejecutar(1024, function () use ($empresaId, $token, $fechaCorte, $claves): void {
            $cache = SihosAuditoriaGlosaCache::load($token, $empresaId, (int)($_SESSION['user_id'] ?? 0));
            if ($cache === null) {
                http_response_code(410);
                exit('El resultado de este reporte expiró. Vuelva a ejecutar la auditoría.');
            }

            $conclusion = $this->auditoriaGlosaService->prepararConclusionSaldoCero($cache['filas']);
            $exportables = $conclusion['exportables'];

            if ($claves !== []) {
                $clavesSet = array_flip($claves);
                $exportables = array_values(array_filter(
                    $exportables,
                    static fn (array $f): bool => isset($clavesSet[$f['clave']])
                ));
            }

            if ($exportables === []) {
                http_response_code(400);
                exit('No hay glosas seleccionadas para concluir.');
            }

            $filasCsv = $this->auditoriaGlosaService->filasParaConcluirCsv($exportables, $fechaCorte);

            // Sin BOM UTF-8 a propósito: el archivo va destinado a SIHOS
            // (AnotGlos.ObseGlos es latin1_swedish_ci), no a Excel — ver
            // docblock de filasParaConcluirCsv().
            header('Content-Type: text/csv; charset=iso-8859-1');
            header('Content-Disposition: attachment; filename="concluir_glosas_' . date('Ymd_His') . '.csv"');

            $out = fopen('php://output', 'w');
            foreach ($filasCsv as $fila) {
                fputcsv($out, $fila, ';');
            }
            fclose($out);
        });
    }

    /**
     * POST ?url=sihos/auditoriaGlosaConcluirDirecto — escribe directamente
     * en SIHOS (por aceptación EPS/EAPB) UNA glosa "en curso" con factura
     * ya en saldo $0, sin pasar por el CSV de "Importar Glosas". Ver
     * SihosGlosaConclusionService::concluirAceptacionEps(). Requiere el
     * permiso 'concluir' sobre sihos/auditoriaGlosa, además del 'ver' que
     * ya aplica Router::middleware(). Una glosa por clic a propósito — el
     * usuario confirmó explícitamente que quiere confirmación por fila, no
     * un proceso masivo silencioso, dado el riesgo de escribir en un
     * sistema contable externo en producción.
     */
    public function auditoriaGlosaConcluirDirecto(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!PermisoService::can('sihos/auditoriaGlosa', 'concluir')) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => 'Sin permiso.'], JSON_UNESCAPED_UNICODE);

            return;
        }

        $scope = $this->connectionService->buildScope($_POST);
        $empresaId = $scope['empresaId'] !== null ? (int)$scope['empresaId'] : 0;
        $codiDocu = trim((string)($_POST['codi_docu'] ?? ''));
        $numeDocu = trim((string)($_POST['nume_docu'] ?? ''));
        $fechaCorte = trim((string)($_POST['fecha_corte'] ?? ''));

        if ($empresaId <= 0 || $codiDocu === '' || $numeDocu === '' || $fechaCorte === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Faltan datos de la glosa.'], JSON_UNESCAPED_UNICODE);

            return;
        }

        $resultado = SihosMemoryGuard::ejecutar(
            512,
            fn () => $this->glosaConclusionService->concluirAceptacionEps($empresaId, $codiDocu, $numeDocu, $fechaCorte)
        );

        if (!$resultado['ok']) {
            http_response_code(400);
        }

        echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
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
     * GET ?url=sihos/cruceAuditarReferencias&empresa_id=N&codi_docu=X&nume_docu=Y
     * — modal "Auditar referencias" del reporte de cruce: trazabilidad de
     * solo lectura de un documento puntual (contabilidad + presupuesto,
     * documentos que lo referencian). Sin permiso adicional — el middleware
     * ya exige 'ver' sobre sihos/cruce, y es de solo lectura.
     */
    public function cruceAuditarReferencias(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $scope = $this->connectionService->buildScope($_GET);
        $empresaId = $scope['empresaId'] !== null ? (int)$scope['empresaId'] : 0;
        $codiDocu = trim((string)($_GET['codi_docu'] ?? ''));
        $numeDocu = trim((string)($_GET['nume_docu'] ?? ''));

        if ($empresaId <= 0 || $codiDocu === '' || $numeDocu === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Faltan datos del documento.'], JSON_UNESCAPED_UNICODE);

            return;
        }

        $resultado = $this->auditoriaReferenciasService->auditarDocumento($empresaId, $codiDocu, $numeDocu);

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

    /**
     * GET ?url=sihos/interfazLaboratorio&empresa_id=N
     * 3 pestañas: Homologación (catálogo, se carga siempre — es pequeño),
     * Solicitudes y Resultados (transaccionales, solo cargan tras "Consultar"
     * explícito con rango de fechas, igual que cruce()/nominaPila()).
     */
    public function interfazLaboratorio(): void
    {
        $scope = $this->connectionService->buildScope($_GET);
        $empresaId = $scope['empresaId'] !== null ? (int)$scope['empresaId'] : null;
        $configurado = $empresaId !== null && $this->connectionService->buildConfigView($empresaId)['configurado'];

        $fechaInicioResultados = trim((string)($_GET['fecha_inicio_r'] ?? ''));
        $fechaFinResultados = trim((string)($_GET['fecha_fin_r'] ?? ''));
        $fechaInicioSolicitudes = trim((string)($_GET['fecha_inicio_s'] ?? ''));
        $fechaFinSolicitudes = trim((string)($_GET['fecha_fin_s'] ?? ''));
        $codiCupsHomologacion = trim((string)($_GET['codi_cups_h'] ?? ''));

        $resultados = null;
        if ($empresaId !== null && $configurado && isset($_GET['buscar_r']) && $fechaInicioResultados !== '' && $fechaFinResultados !== '') {
            $resultados = $this->interlabService->buildResultados($empresaId, $fechaInicioResultados, $fechaFinResultados);
        }

        $solicitudes = null;
        if ($empresaId !== null && $configurado && isset($_GET['buscar_s']) && $fechaInicioSolicitudes !== '' && $fechaFinSolicitudes !== '') {
            $solicitudes = $this->interlabService->buildSolicitudes($empresaId, $fechaInicioSolicitudes, $fechaFinSolicitudes);
        }

        $homologacion = null;
        if ($empresaId !== null && $configurado) {
            $homologacion = $this->interlabService->buildHomologacion($empresaId, $codiCupsHomologacion);
        }

        $puedeProcesar = PermisoService::can('sihos/interfazLaboratorio', 'procesar');
        $puedeGuardarHomologacion = PermisoService::can('sihos/interfazLaboratorio', 'guardar');
        $puedeEliminarHomologacion = PermisoService::can('sihos/interfazLaboratorio', 'eliminar');

        $moduleService = new ModuleService();
        $breadcrumb = $moduleService->buildBreadcrumbForRuta('sihos/interfazLaboratorio');

        $view = BASE_PATH . '/app/views/sihos/interfaz-laboratorio.php';
        require BASE_PATH . '/app/views/layouts/main.php';
    }

    /**
     * POST ?url=sihos/interfazLaboratorioProcesarResultado — procesa UNA
     * fila de Interfaz_resultados_Roche (crea/actualiza HojaProc/DetaPrue en
     * SIHOS). Escritura clínica real — requiere permiso 'procesar' propio,
     * ver Router::middleware().
     */
    public function interfazLaboratorioProcesarResultado(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!PermisoService::can('sihos/interfazLaboratorio', 'procesar')) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => 'Sin permiso.'], JSON_UNESCAPED_UNICODE);

            return;
        }

        $scope = $this->connectionService->buildScope($_POST);
        $empresaId = $scope['empresaId'] !== null ? (int)$scope['empresaId'] : 0;
        $id = (int)($_POST['id'] ?? 0);

        if ($empresaId <= 0 || $id <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Faltan datos.'], JSON_UNESCAPED_UNICODE);

            return;
        }

        $resultado = $this->interlabProcesarService->procesarResultadoUno($empresaId, $id);
        if (!$resultado['ok']) {
            http_response_code(400);
        }

        echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
    }

    /**
     * POST ?url=sihos/interfazLaboratorioProcesarSolicitud — procesa UNA
     * orden/liquidación candidata (crea la fila en Interfaz_solicitudes_SIHOS
     * si aplica). Requiere permiso 'procesar'.
     */
    public function interfazLaboratorioProcesarSolicitud(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!PermisoService::can('sihos/interfazLaboratorio', 'procesar')) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => 'Sin permiso.'], JSON_UNESCAPED_UNICODE);

            return;
        }

        $scope = $this->connectionService->buildScope($_POST);
        $empresaId = $scope['empresaId'] !== null ? (int)$scope['empresaId'] : 0;

        $candidata = [
            'CodiInst' => trim((string)($_POST['codi_inst'] ?? '')),
            'ConsAdmi' => trim((string)($_POST['cons_admi'] ?? '')),
            'ConsOrde' => trim((string)($_POST['cons_orde'] ?? '')),
            'Item' => trim((string)($_POST['item'] ?? '')),
            'CodiModu' => trim((string)($_POST['codi_modu'] ?? '')),
            'CodiProc' => trim((string)($_POST['codi_proc'] ?? '')),
            'ObseProc' => trim((string)($_POST['obse_proc'] ?? '')),
            'ConsDeFa' => trim((string)($_POST['cons_de_fa'] ?? '')),
            'FechDigi' => trim((string)($_POST['fech_digi'] ?? '')),
            'HoraDigi' => trim((string)($_POST['hora_digi'] ?? '')),
            'UsuaDigi' => trim((string)($_POST['usua_digi'] ?? '')),
            'TipoDocu' => trim((string)($_POST['tipo_docu'] ?? '')),
            'NumeUsua' => trim((string)($_POST['nume_usua'] ?? '')),
            'NumeLiqu' => trim((string)($_POST['nume_liqu'] ?? '')),
            'TipoOrde' => trim((string)($_POST['tipo_orde'] ?? '')),
            'TipoInterfaz' => trim((string)($_POST['tipo_interfaz'] ?? '')),
        ];

        if ($empresaId <= 0 || $candidata['CodiInst'] === '' || $candidata['ConsAdmi'] === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Faltan datos de la candidata.'], JSON_UNESCAPED_UNICODE);

            return;
        }

        $resultado = $this->interlabProcesarService->procesarSolicitudUna($empresaId, $candidata);
        if (!$resultado['ok']) {
            http_response_code(400);
        }

        echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
    }

    /**
     * POST ?url=sihos/interfazLaboratorioHomologacionGuardar — crea o edita
     * una fila del catálogo de homologación. Si vienen `codi_cups_anterior`/
     * `codi_prue_anterior`/`analito_anterior` es edición; si no, creación.
     * Requiere permiso 'guardar'.
     */
    public function interfazLaboratorioHomologacionGuardar(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!PermisoService::can('sihos/interfazLaboratorio', 'guardar')) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => 'Sin permiso.'], JSON_UNESCAPED_UNICODE);

            return;
        }

        $scope = $this->connectionService->buildScope($_POST);
        $empresaId = $scope['empresaId'] !== null ? (int)$scope['empresaId'] : 0;

        $codiCups = trim((string)($_POST['codi_cups'] ?? ''));
        $codiPrue = trim((string)($_POST['codi_prue'] ?? ''));
        $analito = trim((string)($_POST['analito'] ?? ''));
        $codiCupsAnterior = trim((string)($_POST['codi_cups_anterior'] ?? ''));
        $codiPrueAnterior = trim((string)($_POST['codi_prue_anterior'] ?? ''));
        $analitoAnterior = trim((string)($_POST['analito_anterior'] ?? ''));

        if ($empresaId <= 0 || $codiCups === '' || $codiPrue === '' || $analito === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'CUPS, CodiPrue y Analito son obligatorios.'], JSON_UNESCAPED_UNICODE);

            return;
        }

        $esEdicion = $codiCupsAnterior !== '' && $codiPrueAnterior !== '' && $analitoAnterior !== '';
        $resultado = $esEdicion
            ? $this->interlabProcesarService->actualizarHomologacion($empresaId, $codiCupsAnterior, $codiPrueAnterior, $analitoAnterior, $codiCups, $codiPrue, $analito)
            : $this->interlabProcesarService->crearHomologacion($empresaId, $codiCups, $codiPrue, $analito);

        if (!$resultado['ok']) {
            http_response_code(400);
        }

        echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
    }

    /**
     * POST ?url=sihos/interfazLaboratorioHomologacionEliminar — borra una
     * fila del catálogo de homologación. Requiere permiso 'eliminar'.
     */
    public function interfazLaboratorioHomologacionEliminar(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!PermisoService::can('sihos/interfazLaboratorio', 'eliminar')) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => 'Sin permiso.'], JSON_UNESCAPED_UNICODE);

            return;
        }

        $scope = $this->connectionService->buildScope($_POST);
        $empresaId = $scope['empresaId'] !== null ? (int)$scope['empresaId'] : 0;

        $codiCups = trim((string)($_POST['codi_cups'] ?? ''));
        $codiPrue = trim((string)($_POST['codi_prue'] ?? ''));
        $analito = trim((string)($_POST['analito'] ?? ''));

        if ($empresaId <= 0 || $codiCups === '' || $codiPrue === '' || $analito === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Faltan datos de la homologación a eliminar.'], JSON_UNESCAPED_UNICODE);

            return;
        }

        $resultado = $this->interlabProcesarService->eliminarHomologacion($empresaId, $codiCups, $codiPrue, $analito);
        if (!$resultado['ok']) {
            http_response_code(400);
        }

        echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
    }

    /**
     * GET/POST ?url=sihos/tarifaProcedimiento&empresa_id=N&codi_manu=X[&codi_plan=01]
     * Selector de manual + rejilla de tarifas actuales (GET con codi_manu) y
     * carga de archivo (POST con multipart, igual patrón que
     * sihos/nominaPilaCorreccion): arma la vista previa en el mismo request,
     * nunca escribe nada aquí — la escritura real va por
     * tarifaProcedimientoConfirmar(), detrás de su propio permiso 'confirmar'.
     */
    public function tarifaProcedimiento(): void
    {
        $scope = $this->connectionService->buildScope($_REQUEST);
        $empresaId = $scope['empresaId'] !== null ? (int)$scope['empresaId'] : null;
        $configurado = $empresaId !== null && $this->connectionService->buildConfigView($empresaId)['configurado'];

        $codiManu = trim((string)($_REQUEST['codi_manu'] ?? ''));
        $codiPlan = trim((string)($_REQUEST['codi_plan'] ?? '')) ?: '01';

        $manuales = [];
        $planes = [];
        $grid = null;
        $vistaPrevia = null;

        if ($empresaId !== null && $configurado) {
            $opciones = $this->tarifaProcedimientoService->buildManualOptions($empresaId);
            $manuales = $opciones['ok'] ? $opciones['manuales'] : [];

            $opcionesPlan = $this->tarifaProcedimientoService->buildPlanOptions($empresaId);
            $planes = $opcionesPlan['ok'] ? $opcionesPlan['planes'] : [];

            if ($codiManu !== '') {
                $grid = $this->tarifaProcedimientoService->buildGrid($empresaId, $codiManu, $codiPlan);
            }

            if ($_SERVER['REQUEST_METHOD'] === 'POST' && $codiManu !== '') {
                $archivo = $_FILES['archivo_tarifas'] ?? null;
                $modo = trim((string)($_POST['modo'] ?? ''));
                $valorUvbCrudo = trim((string)($_POST['valor_uvb_lote'] ?? ''));
                $valorUvbLote = $valorUvbCrudo !== '' && is_numeric($valorUvbCrudo) ? (float)$valorUvbCrudo : null;

                if ($archivo === null || (int)($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    $vistaPrevia = ['ok' => false, 'error' => 'No se pudo recibir el archivo. Intente de nuevo.'];
                } else {
                    $vistaPrevia = $this->tarifaProcedimientoService->validarArchivo(
                        $empresaId,
                        (int)($_SESSION['user_id'] ?? 0),
                        $archivo['tmp_name'],
                        $codiManu,
                        $codiPlan,
                        $modo,
                        $valorUvbLote
                    );
                }
            }
        }

        $puedeConfirmar = PermisoService::can('sihos/tarifaProcedimiento', 'confirmar');

        $moduleService = new ModuleService();
        $breadcrumb = $moduleService->buildBreadcrumbForRuta('sihos/tarifaProcedimiento');

        $view = BASE_PATH . '/app/views/sihos/tarifa-procedimiento.php';
        require BASE_PATH . '/app/views/layouts/main.php';
    }

    /**
     * GET ?url=sihos/tarifaProcedimientoPlantilla — descarga el .xlsx de
     * plantilla para la carga. Mismo permiso 'ver' de sihos/tarifaProcedimiento
     * (ver Router::middleware()): es una descarga de solo lectura, sin datos
     * de ninguna empresa.
     */
    public function tarifaProcedimientoPlantilla(): void
    {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="plantilla_tarifa_procedimientos.xlsx"');
        header('Cache-Control: max-age=0');

        $this->tarifaProcedimientoService->generarPlantillaXlsx('php://output');
    }

    /**
     * POST ?url=sihos/tarifaProcedimientoConfirmar — ejecuta en SIHOS la
     * carga ya validada (identificada por `token`, ver
     * SihosTarifaProcedimientoCache). Requiere el permiso 'confirmar' sobre
     * sihos/tarifaProcedimiento, además del 'ver' que ya aplica
     * Router::middleware().
     */
    public function tarifaProcedimientoConfirmar(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!PermisoService::can('sihos/tarifaProcedimiento', 'confirmar')) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Sin permiso.'], JSON_UNESCAPED_UNICODE);

            return;
        }

        $scope = $this->connectionService->buildScope($_POST);
        $empresaId = $scope['empresaId'] !== null ? (int)$scope['empresaId'] : 0;
        $token = trim((string)($_POST['token'] ?? ''));

        if ($empresaId <= 0 || $token === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Faltan datos de la vista previa a confirmar.'], JSON_UNESCAPED_UNICODE);

            return;
        }

        $resultado = $this->tarifaProcedimientoService->confirmarCarga($empresaId, (int)($_SESSION['user_id'] ?? 0), $token);

        if (!$resultado['ok']) {
            http_response_code(400);
        }

        echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
    }
}
