<?php

class SgdController
{
    private ModuleService $moduleService;
    private SgdScopeService $scope;
    private SgdConfigService $configService;
    private SgdImportService $importService;

    public function __construct()
    {
        $this->moduleService = new ModuleService();
        $this->scope = new SgdScopeService();
        $this->configService = new SgdConfigService();
        $this->importService = new SgdImportService();
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

        $dir = BASE_PATH . '/storage/sgd_imports/' . $empresaId;
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return null;
        }

        $dest = $dir . '/' . date('Ymd_His') . '_' . preg_replace('/[^a-zA-Z0-9._-]+/', '_', basename($name));
        if (!move_uploaded_file($_FILES['archivo']['tmp_name'], $dest)) {
            return null;
        }

        return $dest;
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
}
