<?php

class SgdController
{
    private ModuleService $moduleService;
    private SgdScopeService $scope;
    private SgdConfigService $configService;
    private SgdImportService $importService;
    private SgdDocumentoService $documentoService;
    private SgdDocumentoVersionService $versionService;
    private SgdCcdService $ccdService;
    private SgdTipoDocumentalPadreService $tipoPadreService;
    private SgdFormularioService $formularioService;
    private SgdSeccionService $seccionService;
    private SgdTipoDocumentalSeccionService $tipoSeccionService;
    private SgdElaboracionService $elaboracionService;
    private SgdPdfGenerationService $pdfService;
    private SgdFormularioPreviewService $formularioPreviewService;
    private SgdRegistroService $registroService;

    public function __construct()
    {
        $this->moduleService = new ModuleService();
        $this->scope = new SgdScopeService();
        $this->configService = new SgdConfigService();
        $this->importService = new SgdImportService();
        $this->documentoService = new SgdDocumentoService();
        $this->versionService = new SgdDocumentoVersionService();
        $this->ccdService = new SgdCcdService();
        $this->tipoPadreService = new SgdTipoDocumentalPadreService();
        $this->formularioService = new SgdFormularioService();
        $this->seccionService = new SgdSeccionService();
        $this->tipoSeccionService = new SgdTipoDocumentalSeccionService();
        $this->elaboracionService = new SgdElaboracionService();
        $this->pdfService = new SgdPdfGenerationService();
        $this->formularioPreviewService = new SgdFormularioPreviewService();
        $this->registroService = new SgdRegistroService();
    }

    public function index(): void
    {
        $hubId = $this->resolveHubItemId();
        if ($hubId > 0) {
            $modQ = isset($_GET['modulo']) ? '&modulo=' . (int)$_GET['modulo'] : '';
            header('Location: ?url=dashboard/item/' . $hubId . $modQ);
            exit;
        }

        $_SESSION['flash_notice'] = 'No se encontró el ítem de menú SGD.';
        header('Location: ?url=dashboard');
        exit;
    }

    private function resolveHubItemId(): int
    {
        $database = new Database();
        $pdo = $database->connect();
        $nd = SoftDeleteService::sqlAndNotDeleted($pdo, 'item', 'i');
        $modNd = SoftDeleteService::sqlAndNotDeleted($pdo, 'modulo', 'm');

        $stmt = $pdo->query("
            SELECT i.id
            FROM item i
            INNER JOIN modulo m ON m.id = i.modulo_id
            WHERE (i.item_padre_id IS NULL OR i.item_padre_id = 0)
              AND (i.ruta IS NULL OR TRIM(i.ruta) = '')
              AND LOWER(TRIM(m.nombre)) IN ('gestión documental', 'gestion documental')
              {$nd}
              {$modNd}
            ORDER BY i.id
            LIMIT 1
        ");
        $id = $stmt ? $stmt->fetchColumn() : false;

        return $id !== false ? (int)$id : 0;
    }

    public function config(): void
    {
        if (!PermisoService::can('sgd/config', 'ver')) {
            $this->deny();
        }

        $page = $this->configService->getConfigPageData($_GET);
        $scope = $page['scope'];
        $config = $page['config'];
        $counts = $page['counts'];
        $tipos = $page['tipos'];
        $configExtra = SgdConfigService::parseConfigJson($config);
        $formatoPdf = $configExtra['formato_pdf'] ?? SgdSeccionService::defaultFormatoPdf();
        $archivoOficial = SgdConfigService::archivoOficialSettings($configExtra);
        $breadcrumb = $this->moduleService->buildBreadcrumbForRuta('sgd/config');
        $canConfigurar = PermisoService::can('sgd/config', 'configurar')
            || PermisoService::can('sgd/config', 'guardar');

        $view = BASE_PATH . '/app/views/sgd/config.php';
        require BASE_PATH . '/app/views/layouts/main.php';
    }

    public function guardarConfig(): void
    {
        if (!PermisoService::can('sgd/config', 'configurar')
            && !PermisoService::can('sgd/config', 'guardar')) {
            $this->deny();
        }

        $result = $this->configService->saveConfig($_POST, $_GET);
        $_SESSION['flash_notice'] = $result['message'];
        $q = $this->empresaQuery();
        header('Location: ?url=sgd/config' . $q);

        exit;
    }

    public function cargarTiposPlantilla(): void
    {
        if (!PermisoService::can('sgd/config', 'configurar')
            && !PermisoService::can('sgd/config', 'guardar')) {
            $this->deny();
        }

        $result = $this->configService->loadTiposPlantilla($_GET);
        $_SESSION['flash_notice'] = $result['message'];
        $q = $this->empresaQuery();
        header('Location: ?url=sgd/config' . $q);

        exit;
    }

    public function cargarSeccionesPlantilla(): void
    {
        if (!PermisoService::can('sgd/config', 'configurar')
            && !PermisoService::can('sgd/config', 'guardar')) {
            $this->deny();
        }

        $result = $this->configService->importSeccionesPlantilla($_GET);
        $_SESSION['flash_notice'] = $result['message'];
        $q = $this->empresaQuery();
        header('Location: ?url=sgd/config' . $q);

        exit;
    }

    public function cargarBloquesOperativos(): void
    {
        if (!PermisoService::can('sgd/config', 'configurar')
            && !PermisoService::can('sgd/config', 'guardar')) {
            $this->deny();
        }

        $result = $this->configService->importBloquesOperativos($_GET);
        $_SESSION['flash_notice'] = $result['message'];
        $q = $this->empresaQuery();
        header('Location: ?url=sgd/config' . $q);

        exit;
    }

    public function importar(): void
    {
        if (!PermisoService::can('sgd/importar', 'ver')) {
            $this->deny();
        }

        $scope = $this->scope->buildScope($_GET);
        $samples = $this->configService->listSampleFiles();
        $breadcrumb = $this->moduleService->buildBreadcrumbForRuta('sgd/importar');
        $canImportar = PermisoService::can('sgd/importar', 'importar')
            || PermisoService::can('sgd/importar', 'guardar');
        $empresaId = $scope['empresaId'] ?? null;

        $view = BASE_PATH . '/app/views/sgd/importar.php';
        require BASE_PATH . '/app/views/layouts/main.php';
    }

    public function ejecutarImportar(): void
    {
        if (!PermisoService::can('sgd/importar', 'importar')
            && !PermisoService::can('sgd/importar', 'guardar')) {
            $this->deny();
        }

        try {
            $empresaId = $this->scope->requireEmpresaId($_GET);
        } catch (RuntimeException $e) {
            $_SESSION['flash_notice'] = $e->getMessage();
            header('Location: ?url=sgd/importar');

            exit;
        }

        $tipo = trim((string)($_POST['tipo_import'] ?? ''));
        $vigencia = trim((string)($_POST['ccd_vigencia'] ?? '')) ?: null;
        $anioRaw = trim((string)($_POST['ccd_anio'] ?? ''));
        $anio = $anioRaw !== '' && ctype_digit($anioRaw) ? (int)$anioRaw : null;

        $filePath = $this->resolveUploadPath($empresaId);
        if ($filePath === null) {
            $sample = trim((string)($_POST['archivo_muestra'] ?? ''));
            if ($sample !== '') {
                $safe = basename($sample);
                $candidate = BASE_PATH . '/docs/sgd/SGD/' . $safe;
                if (is_readable($candidate)) {
                    $filePath = $candidate;
                }
            }
        }

        if ($filePath === null) {
            $_SESSION['flash_notice'] = 'Seleccione un archivo Excel (.xls o .xlsx).';
            header('Location: ?url=sgd/importar' . $this->empresaQuery());

            exit;
        }

        if ($tipo === 'ccd') {
            $result = $this->importService->importCcd($empresaId, $filePath, $vigencia, $anio);
        } elseif ($tipo === 'maestro') {
            $result = $this->importService->importMaestro($empresaId, $filePath);
        } else {
            $_SESSION['flash_notice'] = 'Tipo de importación no válido.';
            header('Location: ?url=sgd/importar' . $this->empresaQuery());

            exit;
        }

        $msg = $result['message'];
        if (!empty($result['stats'])) {
            $msg .= ' ' . json_encode($result['stats'], JSON_UNESCAPED_UNICODE);
        }
        $_SESSION['flash_notice'] = $msg;
        header('Location: ?url=sgd/importar' . $this->empresaQuery());

        exit;
    }

    public function documentos(): void
    {
        if (!PermisoService::can('sgd/documentos', 'ver')) {
            $this->deny();
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->handleDocumentosPost();

            return;
        }

        $page = $this->documentoService->getPageData($_GET);
        $scope = $page['scope'];
        $empresaId = $page['empresaId'];
        $documentos = $page['documentos'];
        $total = $page['total'];
        $search = $page['search'];
        $edit = $page['edit'];
        $catalogos = $page['catalogos'];
        $catalogosJson = $page['catalogosJson'];
        $selectedGridId = (int)($page['selectedGridId'] ?? 0);
        $versiones = $page['versiones'] ?? [];
        $suggestedVersionNumero = (string)($page['suggestedVersionNumero'] ?? '1');
        $canAutoGeneratePdf = !empty($page['canAutoGeneratePdf']);
        $canPreviewPlantilla = !empty($page['canPreviewPlantilla']);
        $previewPlantillaUrl = (string)($page['previewPlantillaUrl'] ?? '');
        $esOperativo = !empty($page['esOperativo']);
        $formatoArchivoEsperado = (string)($page['formatoArchivoEsperado'] ?? 'pdf_auto');
        $canAutoGenerateEsqueleto = !empty($page['canAutoGenerateEsqueleto']);
        $uploadExtensions = $page['uploadExtensions'] ?? ['pdf'];
        $arquetipoOperativo = (string)($page['arquetipoOperativo'] ?? 'libre');
        $breadcrumb = $this->moduleService->buildBreadcrumbForRuta('sgd/documentos');
        $canGuardar = PermisoService::can('sgd/documentos', 'guardar');
        $canEliminar = PermisoService::can('sgd/documentos', 'eliminar');
        $canEliminarVersion = PermisoService::can('sgd/documentos', 'version_eliminar');
        $canRevertVigente = PermisoService::can('sgd/documentos', 'version_revertir_vigente');

        $view = BASE_PATH . '/app/views/sgd/documentos.php';
        require BASE_PATH . '/app/views/layouts/main.php';
    }

    public function formularios(): void
    {
        if (!PermisoService::can('sgd/formularios', 'ver')) {
            $this->deny();
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->handleFormulariosPost();

            return;
        }

        $page = $this->formularioService->getPageData($_GET);
        if (($page['proposito'] ?? '') === 'elaboracion' && !empty($page['documentoId'])) {
            $q = $this->empresaQuery();
            $docId = (int)$page['documentoId'];
            header('Location: ?url=sgd/elaboracion' . $q . ($q === '' ? '&' : '&') . 'documento_id=' . $docId);
            exit;
        }

        $scope = $page['scope'];
        $empresaId = $page['empresaId'];
        $documentoId = (int)($page['documentoId'] ?? 0);
        $documento = $page['documento'];
        $codigoDisplay = (string)($page['codigoDisplay'] ?? '');
        $proposito = (string)($page['proposito'] ?? 'operativo');
        $propositoLabel = (string)($page['propositoLabel'] ?? '');
        $version = $page['version'];
        $versiones = $page['versiones'] ?? [];
        $esquema = $page['esquema'];
        $esquemaJson = (string)($page['esquemaJson'] ?? '{}');
        $tiposCampo = $page['tiposCampo'] ?? [];
        $arquetipos = $page['arquetipos'] ?? [];
        $arquetipoPiloto = (string)($page['arquetipoPiloto'] ?? 'acta');
        $borradorDesdeVigente = !empty($page['borradorDesdeVigente']);
        $versionVigenteNumero = $page['versionVigenteNumero'] ?? null;
        $needsNewVersionConfirm = !empty($page['needsNewVersionConfirm']);
        $documentoVersion = $page['documentoVersion'] ?? null;
        $canPreviewPlantilla = !empty($page['canPreviewPlantilla']);
        $previewPdfUrl = (string)($page['previewPdfUrl'] ?? '');
        $breadcrumb = $this->moduleService->buildBreadcrumbForRuta('sgd/formularios');
        $canGuardar = PermisoService::can('sgd/formularios', 'guardar')
            || PermisoService::can('sgd/documentos', 'guardar');

        $view = BASE_PATH . '/app/views/sgd/formularios.php';
        require BASE_PATH . '/app/views/layouts/main.php';
    }

    public function registros(): void
    {
        if (!PermisoService::can('sgd/registros', 'ver')) {
            $this->deny();
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->handleRegistrosPost();

            return;
        }

        $page = $this->registroService->getListPageData($_GET);
        $scope = $page['scope'];
        $empresaId = $page['empresaId'];
        $documentoId = (int)($page['documentoId'] ?? 0);
        $documento = $page['documento'];
        $codigoDisplay = (string)($page['codigoDisplay'] ?? '');
        $registros = $page['registros'] ?? [];
        $breadcrumb = $this->moduleService->buildBreadcrumbForRuta('sgd/registros');
        $canGuardar = PermisoService::can('sgd/registros', 'guardar');
        $canEliminar = PermisoService::can('sgd/registros', 'eliminar');

        $view = BASE_PATH . '/app/views/sgd/registros.php';
        require BASE_PATH . '/app/views/layouts/main.php';
    }

    public function registrosDiligenciar(): void
    {
        if (!PermisoService::can('sgd/registros', 'ver')) {
            $this->deny();
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->handleRegistrosDiligenciarPost();

            return;
        }

        $page = $this->registroService->getDiligenciarPageData($_GET);
        $scope = $page['scope'];
        $empresaId = $page['empresaId'];
        $registroId = (int)($page['registroId'] ?? 0);
        $registro = $page['registro'];
        $documento = $page['documento'];
        $codigoDisplay = (string)($page['codigoDisplay'] ?? '');
        $esquema = $page['esquema'];
        $esquemaJson = (string)($page['esquemaJson'] ?? '{}');
        $datosJson = (string)($page['datosJson'] ?? '{}');
        $editable = !empty($page['editable']);
        $breadcrumb = $this->moduleService->buildBreadcrumbForRuta('sgd/registros');
        $canGuardar = PermisoService::can('sgd/registros', 'guardar');

        $view = BASE_PATH . '/app/views/sgd/registros_diligenciar.php';
        require BASE_PATH . '/app/views/layouts/main.php';
    }

    private function handleRegistrosPost(): void
    {
        $action = trim((string)($_POST['_action'] ?? 'crear'));

        if ($action === 'crear' && !PermisoService::can('sgd/registros', 'guardar')) {
            $this->deny();
        }
        if ($action === 'anular' && !PermisoService::can('sgd/registros', 'eliminar')) {
            $this->deny();
        }

        $result = match ($action) {
            'crear' => $this->registroService->crearRegistro($_GET, $_POST),
            'anular' => $this->registroService->anularRegistro($_POST, $_GET),
            default => ['success' => false, 'message' => 'Acción no válida.'],
        };

        if ($this->wantsJsonResponse()) {
            $this->jsonResponse($result);

            return;
        }

        $_SESSION['flash_notice'] = $result['message'];
        $q = $this->empresaQuery();
        if ($action === 'crear' && !empty($result['success']) && !empty($result['id'])) {
            header('Location: ?url=sgd/registrosDiligenciar' . $q . '&id=' . (int)$result['id']);
            exit;
        }

        $docId = (int)($_POST['documento_id'] ?? $_GET['documento_id'] ?? 0);
        if ($docId > 0) {
            $q .= ($q === '' ? '&' : '&') . 'documento_id=' . $docId;
        }
        header('Location: ?url=sgd/registros' . $q);
        exit;
    }

    private function handleRegistrosDiligenciarPost(): void
    {
        if (!PermisoService::can('sgd/registros', 'guardar')) {
            $this->deny();
        }

        $action = trim((string)($_POST['_action'] ?? 'guardar'));
        $result = match ($action) {
            'guardar' => $this->registroService->guardarDatos($_POST, $_GET),
            'cerrar' => $this->registroService->cerrarRegistro($_POST, $_GET),
            default => ['success' => false, 'message' => 'Acción no válida.'],
        };

        if ($this->wantsJsonResponse()) {
            $this->jsonResponse($result);

            return;
        }

        $_SESSION['flash_notice'] = $result['message'];
        $q = $this->empresaQuery();
        $registroId = (int)($_POST['registro_id'] ?? $_GET['id'] ?? 0);
        header('Location: ?url=sgd/registrosDiligenciar' . $q . '&id=' . $registroId);
        exit;
    }

    private function handleFormulariosPost(): void
    {
        $action = trim((string)($_POST['_action'] ?? 'save'));

        if (!PermisoService::can('sgd/formularios', 'guardar')
            && !PermisoService::can('sgd/documentos', 'guardar')) {
            $this->deny();
        }

        $result = match ($action) {
            'save' => $this->formularioService->saveEsquema($_POST, $_GET),
            'create_borrador' => $this->formularioService->createBorradorFromVigente($_GET, $_POST),
            default => ['success' => false, 'message' => 'Acción no válida.'],
        };

        $_SESSION['flash_notice'] = $result['message'];
        $q = $this->empresaQuery();
        $docId = (int)($_POST['documento_id'] ?? $_GET['documento_id'] ?? 0);
        if ($docId > 0) {
            $q .= ($q === '' ? '&' : '&') . 'documento_id=' . $docId;
        }
        header('Location: ?url=sgd/formularios' . $q);

        exit;
    }

    public function ccd(): void
    {
        if (!PermisoService::can('sgd/ccd', 'ver')) {
            $this->deny();
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->handleCcdPost();

            return;
        }

        $page = $this->ccdService->getPageData($_GET);
        $scope = $page['scope'];
        $empresaId = $page['empresaId'];
        $entradas = $page['entradas'];
        $total = $page['total'];
        $filterDependenciaId = $page['filterDependenciaId'];
        $edit = $page['edit'];
        $config = $page['config'];
        $catalogos = $page['catalogos'];
        $catalogosJson = $page['catalogosJson'];
        $selectedGridId = (int)($page['selectedGridId'] ?? 0);
        $breadcrumb = $this->moduleService->buildBreadcrumbForRuta('sgd/ccd');
        $canGuardar = PermisoService::can('sgd/ccd', 'guardar');
        $canEliminar = PermisoService::can('sgd/ccd', 'eliminar');
        $canImportar = PermisoService::can('sgd/importar', 'importar')
            || PermisoService::can('sgd/importar', 'guardar');

        $view = BASE_PATH . '/app/views/sgd/ccd.php';
        require BASE_PATH . '/app/views/layouts/main.php';
    }

    private function handleCcdPost(): void
    {
        $action = trim((string)($_POST['_action'] ?? 'save'));

        if ($action === 'delete') {
            if (!PermisoService::can('sgd/ccd', 'eliminar')) {
                $this->deny();
            }
            $result = $this->ccdService->delete($_GET, $_POST);
            $_SESSION['flash_notice'] = $result['message'];
            header('Location: ?url=sgd/ccd' . $this->empresaQuery());

            exit;
        }

        if (!PermisoService::can('sgd/ccd', 'guardar')) {
            $this->deny();
        }

        $result = $this->ccdService->save($_POST, $_GET);
        $_SESSION['flash_notice'] = $result['message'];
        $q = $this->empresaQuery();
        if (!empty($result['id'])) {
            $q .= ($q === '' ? '&' : '&') . 'id=' . (int)$result['id'];
        }
        $dep = (int)($_POST['dependencia_id'] ?? $_GET['dependencia_id'] ?? 0);
        if ($dep > 0) {
            $q .= ($q === '' ? '&' : '&') . 'dependencia_id=' . $dep;
        }
        header('Location: ?url=sgd/ccd' . $q);

        exit;
    }

    private function handleDocumentosPost(): void
    {
        $action = trim((string)($_POST['_action'] ?? 'save'));

        if ($action === 'delete') {
            if (!PermisoService::can('sgd/documentos', 'eliminar')) {
                $this->deny();
            }
            $result = $this->documentoService->delete($_GET, $_POST);
            $_SESSION['flash_notice'] = $result['message'];
            header('Location: ?url=sgd/documentos' . $this->empresaQuery());

            exit;
        }

        if (in_array($action, ['version_create', 'version_publish', 'version_obsolete', 'version_save_fecha', 'version_delete', 'version_revert_vigente'], true)) {
            if ($action === 'version_delete') {
                if (!PermisoService::can('sgd/documentos', 'version_eliminar')) {
                    $this->deny();
                }
            } elseif ($action === 'version_revert_vigente') {
                if (!PermisoService::can('sgd/documentos', 'version_revertir_vigente')) {
                    $this->deny();
                }
            } elseif (!PermisoService::can('sgd/documentos', 'guardar')) {
                $this->deny();
            }

            $result = match ($action) {
                'version_create' => $this->versionService->create($_POST, $_GET),
                'version_publish' => $this->versionService->publish($_POST, $_GET),
                'version_obsolete' => $this->versionService->obsoleteDocument($_POST, $_GET),
                'version_save_fecha' => $this->versionService->saveFechaAprobacion($_POST, $_GET),
                'version_delete' => $this->versionService->deleteVersion($_POST, $_GET),
                'version_revert_vigente' => $this->versionService->revertVigenteVersion($_POST, $_GET),
                default => ['success' => false, 'message' => 'Acción no válida.'],
            };

            $_SESSION['flash_notice'] = $result['message'];
            $q = $this->empresaQuery();
            $docId = (int)($_POST['documento_id'] ?? $_GET['id'] ?? 0);
            if ($docId > 0) {
                $q .= ($q === '' ? '&' : '&') . 'id=' . $docId;
            }
            header('Location: ?url=sgd/documentos' . $q);

            exit;
        }

        if (!PermisoService::can('sgd/documentos', 'guardar')) {
            $this->deny();
        }

        $result = $this->documentoService->save($_POST, $_GET);
        $_SESSION['flash_notice'] = $result['message'];
        $q = $this->empresaQuery();
        if (!empty($result['id'])) {
            $q .= ($q === '' ? '&' : '&') . 'id=' . (int)$result['id'];
        }
        header('Location: ?url=sgd/documentos' . $q);

        exit;
    }

    /**
     * Subida AJAX de PDF para una versión en borrador.
     * Ruta: ?url=sgd/documentoVersionUpload&empresa_id=…
     */
    public function documentoVersionUpload(): void
    {
        if (!PermisoService::can('sgd/documentos', 'guardar')) {
            $this->jsonResponse(['ok' => false, 'error' => 'Sin permiso']);
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonResponse(['ok' => false, 'error' => 'Método no permitido']);
            return;
        }

        $result = $this->versionService->uploadPdf($_FILES, $_POST, $_GET);

        if (!empty($_POST['_redirect'])) {
            $flash = $result['message'];
            if (!empty($result['warning'])) {
                $flash .= ' ' . $result['warning'];
            }
            $_SESSION['flash_notice'] = $flash;
            $q = $this->empresaQuery();
            $docId = (int)($_POST['documento_id'] ?? $_GET['id'] ?? 0);
            if ($docId > 0) {
                $q .= ($q === '' ? '&' : '&') . 'id=' . $docId;
            }
            header('Location: ?url=sgd/documentos' . $q);
            exit;
        }

        $this->jsonResponse([
            'ok' => $result['success'],
            'message' => $result['message'],
            'path' => $result['path'] ?? null,
            'warning' => $result['warning'] ?? null,
            'error' => $result['success'] ? null : $result['message'],
        ]);
    }

    /**
     * Modal: padres permitidos por tipo documental.
     * Ruta: ?url=sgd/tipoDocumentalPadres/{tipoId}
     */
    public function tipoDocumentalPadres($tipoId = null): void
    {
        if (!PermisoService::can('sgd_tipo_documental', 'ver')) {
            $this->renderTipoPadresModalError('No tiene permiso para ver tipos documentales.');
            return;
        }

        $tid = $tipoId !== null && $tipoId !== '' ? (int)$tipoId : 0;
        if ($tid <= 0) {
            $this->renderTipoPadresModalError('Seleccione un tipo documental en la tabla y vuelva a abrir la acción.');
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!PermisoService::can('sgd_tipo_documental', 'guardar')) {
                $this->jsonResponse(['success' => false, 'message' => 'No tiene permiso para guardar.']);
                return;
            }

            $padreIds = $_POST['padre_tipo_ids'] ?? [];
            if (!is_array($padreIds)) {
                $padreIds = $padreIds !== '' && $padreIds !== null ? [(string)$padreIds] : [];
            }

            $result = $this->tipoPadreService->save($tid, $padreIds, $_GET);
            $this->jsonResponse($result);
            return;
        }

        $data = $this->tipoPadreService->getModalData($tid, $_GET);
        if ($data === null) {
            $this->renderTipoPadresModalError('Tipo documental no encontrado o empresa no válida.');
            return;
        }

        $tipo = $data['tipo'];
        $tipos = $data['tipos'];
        $padresPermitidosIds = $data['padresPermitidosIds'];
        $canGuardar = PermisoService::can('sgd_tipo_documental', 'guardar');
        $endpoint = '?url=sgd/tipoDocumentalPadres/' . $tid . $this->empresaQuery();

        require BASE_PATH . '/app/views/sgd/tipo_documental_padres_modal.php';
    }

    /**
     * Modal: perfil secciones M4 por tipo documental maestro.
     * Ruta: ?url=sgd/tipoDocumentalSecciones/{tipoId}
     */
    public function tipoDocumentalSecciones($tipoId = null): void
    {
        if (!PermisoService::can('sgd_tipo_documental', 'ver')) {
            $this->renderTipoSeccionesModalError('No tiene permiso para ver tipos documentales.');
            return;
        }

        $tid = $tipoId !== null && $tipoId !== '' ? (int)$tipoId : 0;
        if ($tid <= 0) {
            $this->renderTipoSeccionesModalError('Seleccione un tipo documental en la tabla y vuelva a abrir la acción.');
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!PermisoService::can('sgd_tipo_documental', 'guardar')) {
                $this->jsonResponse(['success' => false, 'message' => 'No tiene permiso para guardar.']);
                return;
            }

            $estados = $_POST['estado_seccion'] ?? [];
            if (!is_array($estados)) {
                $estados = [];
            }

            $result = $this->tipoSeccionService->save($tid, $estados, $_GET);
            $this->jsonResponse($result);
            return;
        }

        $data = $this->tipoSeccionService->getModalData($tid, $_GET);
        if ($data === null) {
            $this->renderTipoSeccionesModalError('Tipo no encontrado, empresa no válida o el tipo no es maestro.');
            return;
        }

        $tipo = $data['tipo'];
        $secciones = $data['secciones'];
        $estados = $data['estados'];
        $canGuardar = PermisoService::can('sgd_tipo_documental', 'guardar');
        $endpoint = '?url=sgd/tipoDocumentalSecciones/' . $tid . $this->empresaQuery();

        require BASE_PATH . '/app/views/sgd/tipo_documental_secciones_modal.php';
    }

    public function secciones(): void
    {
        if (!PermisoService::can('sgd/secciones', 'ver')) {
            $this->deny();
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->handleSeccionesPost();
            return;
        }

        $page = $this->seccionService->getPageData($_GET);
        extract($page, EXTR_SKIP);
        $breadcrumb = $this->moduleService->buildBreadcrumbForRuta('sgd/secciones');
        $canGuardar = PermisoService::can('sgd/secciones', 'guardar');
        $canEliminar = PermisoService::can('sgd/secciones', 'eliminar');

        $view = BASE_PATH . '/app/views/sgd/secciones.php';
        require BASE_PATH . '/app/views/layouts/main.php';
    }

    private function handleSeccionesPost(): void
    {
        $action = trim((string)($_POST['_action'] ?? 'save'));

        if ($action === 'delete') {
            if (!PermisoService::can('sgd/secciones', 'eliminar')) {
                $this->deny();
            }
            $result = $this->seccionService->delete($_POST, $_GET);
            $_SESSION['flash_notice'] = $result['message'];
            header('Location: ?url=sgd/secciones' . $this->empresaQuery());
            exit;
        }

        if (!PermisoService::can('sgd/secciones', 'guardar')) {
            $this->deny();
        }

        $result = $this->seccionService->save($_POST, $_GET);
        $_SESSION['flash_notice'] = $result['message'];
        $q = $this->empresaQuery();
        if (!empty($result['id'])) {
            $q .= ($q === '' ? '&' : '&') . 'id=' . (int)$result['id'];
        }
        header('Location: ?url=sgd/secciones' . $q);
        exit;
    }

    /**
     * Ruta: ?url=sgd/elaboracionImportWord&empresa_id=…&documento_id=…
     */
    public function elaboracionImportWord(): void
    {
        ob_start();

        if (!PermisoService::can('sgd/elaboracion', 'guardar')) {
            ob_end_clean();
            $this->jsonResponse(['ok' => false, 'error' => 'Sin permiso']);
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            ob_end_clean();
            $this->jsonResponse(['ok' => false, 'error' => 'Método no permitido']);
            return;
        }

        try {
            $service = new SgdDocxImportService();
            $result = $service->import($_FILES, $_POST, $_GET);
        } catch (Throwable $e) {
            error_log('elaboracionImportWord: ' . $e->getMessage());
            ob_end_clean();
            $this->jsonResponse([
                'ok' => false,
                'error' => 'Error al convertir el Word: ' . $e->getMessage(),
            ]);
            return;
        }

        ob_end_clean();
        $this->jsonResponse([
            'ok' => $result['success'],
            'html' => $result['html'] ?? '',
            'plain' => $result['plain'] ?? '',
            'stats' => $result['stats'] ?? [],
            'messages' => $result['messages'] ?? [],
            'message' => $result['message'],
            'error' => $result['success'] ? null : $result['message'],
            'source' => $result['success'] ? 'server' : null,
            'converter' => $result['converter'] ?? null,
            'staged' => !empty($result['staged']),
            'import_token' => $result['import_token'] ?? null,
            'content_chars' => $result['content_chars'] ?? 0,
        ]);
        return;
    }

    /**
     * Ruta: ?url=sgd/elaboracionImportWordFetch&empresa_id=…&documento_id=…&token=…
     */
    public function elaboracionImportWordFetch(): void
    {
        if (!PermisoService::can('sgd/elaboracion', 'guardar')) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Sin permiso';
            exit;
        }

        try {
            $empresaId = $this->scope->requireEmpresaId($_GET);
        } catch (RuntimeException $e) {
            http_response_code(400);
            header('Content-Type: text/plain; charset=utf-8');
            echo $e->getMessage();
            exit;
        }

        if (!$this->scope->canAccessEmpresa($empresaId, $_GET)) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Sin permiso para esta empresa.';
            exit;
        }

        $documentoId = (int)($_GET['documento_id'] ?? 0);
        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
        $token = trim((string)($_GET['token'] ?? ''));

        if ($documentoId <= 0 || $userId <= 0 || $token === '') {
            http_response_code(400);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Parámetros inválidos.';
            exit;
        }

        $loaded = SgdWordImportStaging::load($token, $empresaId, $documentoId, $userId);
        if ($loaded === null) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Importación no encontrada o expirada.';
            exit;
        }

        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        echo $loaded['html'];
        exit;
    }

    /**
     * Ruta: ?url=sgd/elaboracionUploadMedia&empresa_id=…&documento_id=…
     */
    public function elaboracionUploadMedia(): void
    {
        if (!PermisoService::can('sgd/elaboracion', 'guardar')) {
            $this->jsonResponse(['ok' => false, 'error' => 'Sin permiso']);
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonResponse(['ok' => false, 'error' => 'Método no permitido']);
            return;
        }

        $result = $this->elaboracionService->uploadMedia($_FILES, $_POST, $_GET);
        $this->jsonResponse([
            'ok' => $result['success'],
            'path' => $result['path'] ?? null,
            'message' => $result['message'],
            'error' => $result['success'] ? null : $result['message'],
        ]);
        return;
    }

    /**
     * Vista previa PDF en línea (no publica ni guarda archivo oficial).
     * Ruta: ?url=sgd/elaboracionPreviewPdf&empresa_id=…&documento_id=…[&version_id=…]
     */
    public function elaboracionPreviewPdf(): void
    {
        if (!PermisoService::can('sgd/elaboracion', 'ver') && !PermisoService::can('sgd/documentos', 'ver')) {
            $this->deny();
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            http_response_code(405);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Método no permitido.';
            exit;
        }

        try {
            $empresaId = $this->scope->requireEmpresaId($_GET);
        } catch (RuntimeException $e) {
            http_response_code(400);
            header('Content-Type: text/plain; charset=utf-8');
            echo $e->getMessage();
            exit;
        }

        if (!$this->scope->canAccessEmpresa($empresaId, $_GET)) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Sin permiso para esta empresa.';
            exit;
        }

        $documentoId = isset($_GET['documento_id']) && ctype_digit((string)$_GET['documento_id'])
            ? (int)$_GET['documento_id']
            : 0;
        if ($documentoId <= 0) {
            http_response_code(400);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Documento no válido.';
            exit;
        }

        $versionId = isset($_GET['version_id']) && ctype_digit((string)$_GET['version_id'])
            ? (int)$_GET['version_id']
            : null;

        $result = $this->pdfService->generatePreview($empresaId, $documentoId, $versionId);
        if (!$result['success']) {
            http_response_code(400);
            header('Content-Type: text/plain; charset=utf-8');
            echo (string)($result['message'] ?? 'No se pudo generar la vista previa.');
            exit;
        }

        $binary = $result['binary'] ?? '';
        $filename = 'preview-doc-' . $documentoId . '.pdf';

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($binary));
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        echo $binary;
        exit;
    }

    public function lookupTerceros(): void
    {
        if (!PermisoService::can('sgd/registros', 'ver')
            && !PermisoService::can('sgd/formularios', 'ver')
            && !PermisoService::can('sgd/documentos', 'ver')) {
            $this->deny();
        }

        try {
            $empresaId = $this->scope->requireEmpresaId($_GET);
        } catch (RuntimeException $e) {
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage(), 'items' => []]);

            return;
        }

        if (!$this->scope->canAccessEmpresa($empresaId, $_GET)) {
            $this->jsonResponse(['success' => false, 'message' => 'Sin permiso.', 'items' => []]);
        }

        $q = trim((string)($_GET['q'] ?? ''));
        $svc = new SgdTerceroLookupService();
        $this->jsonResponse([
            'success' => true,
            'items' => $svc->search($q),
        ]);
    }

    public function formularioPreviewPdf(): void
    {
        if (!PermisoService::can('sgd/formularios', 'ver')
            && !PermisoService::can('sgd/documentos', 'ver')) {
            $this->deny();
        }

        try {
            $empresaId = $this->scope->requireEmpresaId($_GET);
        } catch (RuntimeException $e) {
            http_response_code(400);
            header('Content-Type: text/plain; charset=utf-8');
            echo $e->getMessage();
            exit;
        }

        if (!$this->scope->canAccessEmpresa($empresaId, $_GET)) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Sin permiso para esta empresa.';
            exit;
        }

        $documentoId = isset($_GET['documento_id']) && ctype_digit((string)$_GET['documento_id'])
            ? (int)$_GET['documento_id']
            : 0;
        if ($documentoId <= 0) {
            http_response_code(400);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Documento no válido.';
            exit;
        }

        $formularioVersionId = isset($_GET['formulario_version_id']) && ctype_digit((string)$_GET['formulario_version_id'])
            ? (int)$_GET['formulario_version_id']
            : null;

        $result = $this->formularioPreviewService->generatePreview($empresaId, $documentoId, $formularioVersionId);
        if (!$result['success']) {
            http_response_code(400);
            header('Content-Type: text/plain; charset=utf-8');
            echo (string)($result['message'] ?? 'No se pudo generar la vista previa.');
            exit;
        }

        $binary = $result['binary'] ?? '';
        $filename = 'preview-formato-' . $documentoId . '.pdf';

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($binary));
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        echo $binary;
        exit;
    }

    public function elaboracion(): void
    {
        if (!PermisoService::can('sgd/elaboracion', 'ver')) {
            $this->deny();
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!PermisoService::can('sgd/elaboracion', 'guardar')) {
                $this->deny();
            }
            $result = $this->elaboracionService->save($_POST, $_GET);
            if ($this->wantsJsonResponse()) {
                $this->jsonResponse([
                    'ok' => $result['success'],
                    'message' => $result['message'],
                    'content_chars' => $result['content_chars'] ?? 0,
                ]);
            }

            $_SESSION['flash_notice'] = $result['message'];
            $q = $this->empresaQuery();
            $docId = (int)($_POST['documento_id'] ?? $_GET['documento_id'] ?? 0);
            if ($docId > 0) {
                $q .= ($q === '' ? '&' : '&') . 'documento_id=' . $docId;
            }
            header('Location: ?url=sgd/elaboracion' . $q);
            exit;
        }

        $page = $this->elaboracionService->getPageData($_GET);
        extract($page, EXTR_SKIP);
        $breadcrumb = $this->moduleService->buildBreadcrumbForRuta('sgd/elaboracion');
        $canGuardar = PermisoService::can('sgd/elaboracion', 'guardar');
        $contenidoJson = json_encode($contenido ?? [], JSON_UNESCAPED_UNICODE);
        $opcionesJson = json_encode($opciones ?? [], JSON_UNESCAPED_UNICODE);

        $view = BASE_PATH . '/app/views/sgd/elaboracion.php';
        require BASE_PATH . '/app/views/layouts/main.php';
    }

    private function resolveUploadPath(int $empresaId): ?string
    {
        if (empty($_FILES['archivo']['tmp_name']) || !is_uploaded_file($_FILES['archivo']['tmp_name'])) {
            return null;
        }

        $name = $_FILES['archivo']['name'] ?? '';
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['xls', 'xlsx'], true)) {
            return null;
        }

        $filename = date('Ymd_His') . '_' . preg_replace('/[^a-zA-Z0-9._-]+/', '_', basename($name));

        return StorageService::instance()->putUploadedFile(
            StorageService::ZONE_SGD_IMPORTS,
            $filename,
            $_FILES['archivo'],
            $empresaId
        );
    }

    private function empresaQuery(): string
    {
        $eid = (int)($_GET['empresa_id'] ?? $_POST['empresa_id'] ?? 0);
        if ($eid > 0) {
            return '&empresa_id=' . $eid;
        }

        return '';
    }

    private function deny(): void
    {
        $_SESSION['flash_notice'] = 'No tiene permiso para esta acción SGD.';
        header('Location: ?url=dashboard');
        exit;
    }

    private function renderTipoPadresModalError(string $message): void
    {
        echo '<div class="sgd-tipo-padres-modal modal-inner">'
            . '<header class="modal-form-head"><h3 class="modal-form-title">Padres permitidos</h3>'
            . '<p class="modal-form-alert">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
            . '</header>'
            . '<footer class="sgd-tipo-padres-footer">'
            . '<button type="button" class="btn-cancel" onclick="closeModalGod()">Cerrar</button>'
            . '</footer></div>';
    }

    private function renderTipoSeccionesModalError(string $message): void
    {
        echo '<div class="sgd-tipo-secciones-modal modal-inner">'
            . '<header class="modal-form-head"><h3 class="modal-form-title">Perfil de secciones</h3>'
            . '<p class="modal-form-alert">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
            . '</header>'
            . '<footer class="sgd-tipo-padres-footer">'
            . '<button type="button" class="btn-cancel" onclick="closeModalGod()">Cerrar</button>'
            . '</footer></div>';
    }

    private function wantsJsonResponse(): bool
    {
        if (!empty($_POST['_ajax'])) {
            return true;
        }

        $xhr = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';

        return is_string($xhr) && strcasecmp($xhr, 'XMLHttpRequest') === 0;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonResponse(array $payload): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $flags = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
        if (defined('JSON_PARTIAL_OUTPUT_ON_ERROR')) {
            $flags |= JSON_PARTIAL_OUTPUT_ON_ERROR;
        }
        $json = json_encode($payload, $flags);
        if ($json === false) {
            http_response_code(500);
            echo json_encode([
                'ok' => false,
                'error' => 'No se pudo generar la respuesta JSON: ' . json_last_error_msg(),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        echo $json;
        exit;
    }
}
