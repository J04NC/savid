<?php

class ModuleController
{
    private ModuleService $moduleService;

    public function __construct()
    {
        $this->moduleService = new ModuleService();
    }

    private function isSuperAdmin(): bool
    {
        return !empty($_SESSION['es_super_admin'])
            || (int)($_SESSION['rol_id'] ?? 0) === 1;
    }

    public function index()
    {

        $errors = [];
        $old = [];

        $url = $_GET['url'] ?? '';
        $url = trim($url,'/');
        $parts = explode('/',$url);
        $ruta = $parts[0] ?? '';

        /*
        =========================
        BUSCAR ITEM
        =========================
        */

        $currentItem = $this->moduleService->findCurrentItem($ruta);

        if ($currentItem && ($currentItem['ruta'] ?? '') === 'item' && !$this->isSuperAdmin()) {
            $_SESSION['flash_notice'] = 'Solo un superadministrador puede acceder al catálogo de ítems.';
            header('Location: ?url=dashboard');
            exit;
        }

        /*
        =========================
        VER (servidor): sin esto, tras cambiar sede podía cargarse la tabla sin botones
        pero la URL seguía siendo válida (el middleware no revisa index).
        =========================
        */

        if ($currentItem && class_exists('PermisoService') && !PermisoService::can($currentItem['ruta'], 'ver')) {
            $_SESSION['flash_notice'] = 'No tiene permiso para esta pantalla en la empresa o sede actual.';
            header('Location: ?url=dashboard');
            exit;
        }

        /*
        =========================
        DELETE
        =========================
        */

        if(isset($_GET['delete']) && $currentItem){

            $id = $_GET['delete'];
            $permitido = $this->moduleService->canExecuteAction($currentItem['id'], 'eliminar');

            if(!$permitido){
                die("No tienes permiso para eliminar");
            }

            if ($currentItem['ruta'] === 'empresa' && !$this->isSuperAdmin()) {
                $_SESSION['flash_notice'] = 'Solo un superadministrador puede eliminar empresas.';
                header("Location: ?url=".$ruta);
                exit;
            }

            $this->moduleService->deleteRecord($currentItem['ruta'], $id);

            header("Location: ?url=".$ruta);
            exit;
        }

        /*
        =========================
        BREADCRUMB (INTOCADO)
        =========================
        */

        $breadcrumb = $this->moduleService->buildBreadcrumb($currentItem);
        $moduloId = $currentItem ? (int)($currentItem['modulo_id'] ?? 0) : null;
        if ($moduloId !== null && $moduloId <= 0) {
            $moduloId = null;
        }

        /*
        =========================
        VISTA PERSONALIZADA
        =========================
        */

        $view = BASE_PATH . "/app/views/$ruta/index.php";

        if (file_exists($view)) {
            if (!$currentItem) {
                $_SESSION['flash_notice'] = 'La ruta solicitada no está disponible.';
                header('Location: ?url=dashboard');
                exit;
            }
            require BASE_PATH . '/app/views/layouts/main.php';
            return;
        }

        /*
        =========================
        GUARDAR
        =========================
        */

        if($_SERVER['REQUEST_METHOD'] === 'POST'){

            try{
                $permitido = $this->moduleService->canExecuteAction($currentItem['id'], 'guardar');

                if(!$permitido){
                    throw new Exception("No tienes permiso para guardar");
                }

                if ($currentItem['ruta'] === 'empresa') {
                    $postedId = (int)($_POST['id'] ?? 0);
                    $isCreating = ($postedId <= 0);
                    if ($isCreating && !$this->isSuperAdmin()) {
                        throw new Exception(json_encode([
                            'general' => 'Solo un superadministrador puede crear empresas.',
                        ], JSON_UNESCAPED_UNICODE));
                    }
                    if (!$isCreating && !$this->isSuperAdmin()) {
                        $crudService = new CrudService();
                        if (!$crudService->userCanManageEmpresa($postedId)) {
                            throw new Exception(json_encode([
                                'general' => 'No tiene permiso para editar esta empresa.',
                            ], JSON_UNESCAPED_UNICODE));
                        }
                    }
                }

                $saved = $this->moduleService->save($currentItem['ruta'], $_POST);

                if ($saved === false) {
                    throw new Exception(json_encode([
                        'general' => 'No se pudo guardar el registro. Revise los datos obligatorios e intente de nuevo.',
                    ], JSON_UNESCAPED_UNICODE));
                }

                if (!empty($_SESSION['flash_notice'])) {
                    // Mensaje ya definido (p. ej. vinculación sin alta nueva en usuario).
                } elseif ($currentItem['ruta'] === 'usuario' && empty($_POST['id'])) {
                    $_SESSION['flash_notice'] = 'Usuario guardado correctamente.';
                }

                if ($currentItem['ruta'] === 'usuario') {
                    unset($_SESSION['usuario_crud_form_draft']);
                }

                $modQ = !empty($currentItem['modulo_id']) ? '&modulo=' . (int)$currentItem['modulo_id'] : '';
                header('Location: ?url=' . $ruta . $modQ . '&success=1');
                exit;

            }catch(Throwable $e){

                $errors = json_decode($e->getMessage(), true);

                if (!is_array($errors)) {
                    $errors = ['general' => $this->formatCrudSaveErrorMessage($e, $currentItem['ruta'] ?? '')];
                }

                $old = $_POST;

                if ($currentItem['ruta'] === 'usuario') {
                    $old = (new CrudService())->sanitizeUsuarioFormPostForDisplay($_POST);
                    $_SESSION['usuario_crud_form_draft'] = $old;
                }
            }
        }

        /*
        =========================
        CRUD
        =========================
        */

        if($currentItem){
            $crud = $this->moduleService->getCrudData($currentItem);
            $data = $crud['data'];
            $columns = $crud['columns'];
            $acciones = $crud['acciones'];
            $relations = $crud['relations'];
            $relationData = $crud['relationData'];
            $catalogRegistry = $crud['catalogRegistry'] ?? [];
            $crudContextTable = $currentItem['ruta'];
            $tipodocumentoMetaById = $crud['tipodocumentoMetaById'] ?? [];
            $usuarioTableColumnFields = $crud['usuarioTableColumnFields'] ?? [];

            $view = BASE_PATH . "/app/views/crud/table.php";
            require BASE_PATH . '/app/views/layouts/main.php';
            return;
        }

        echo "Módulo no encontrado";
    }

    /**
     * Búsqueda JSON para FK con relmode autocomplete/auto (validación por INFORMATION_SCHEMA + permiso ver en context).
     */
    public function catalogSearch(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $context = preg_replace('/[^A-Za-z0-9_]/', '', (string)($_GET['context'] ?? ''));
        $field = preg_replace('/[^A-Za-z0-9_]/', '', (string)($_GET['field'] ?? ''));
        $q = (string)($_GET['q'] ?? '');
        $limit = (int)($_GET['limit'] ?? 25);

        if ($context === '' || $field === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'context y field son obligatorios'], JSON_UNESCAPED_UNICODE);

            return;
        }

        $currentItem = $this->moduleService->findCurrentItem($context);

        if (!$currentItem || (class_exists('PermisoService') && !PermisoService::can($context, 'ver'))) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);

            return;
        }

        $parents = [];

        foreach ($_GET as $k => $v) {
            if (!is_string($k) || !str_starts_with($k, 'parent_')) {
                continue;
            }
            $pname = preg_replace('/[^A-Za-z0-9_]/', '', substr($k, strlen('parent_')));

            if ($pname === '') {
                continue;
            }

            $parents[$pname] = is_scalar($v) ? $v : '';
        }

        $crudService = new CrudService();
        $items = $crudService->searchCatalogOptions($context, $field, $q, $parents, $limit);

        echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
    }

    public function __call($method,$params)
    {
        $this->index();
    }

    private function formatCrudSaveErrorMessage(Throwable $e, string $ruta): string
    {
        $msg = $e->getMessage();

        if (str_contains($msg, 'fk_tercero_identificacion_tipodocumento')) {
            return 'El tipo de documento no es válido. Vuelva a elegir «Cédula de Ciudadanía» (u otro) en el formulario y guarde de nuevo.';
        }

        if (str_contains($msg, 'fk_tercero_identificacion_tercero')) {
            return UsuarioSaveMessages::PERSONA_NOT_FOUND
                . ' Detalle: referencia interna de persona incorrecta; pulse Limpiar y guarde de nuevo.';
        }

        if ($ruta === 'usuario' && str_contains($msg, 'uq_tercero_identificacion_doc')) {
            return 'Ya existe otra persona con ese mismo tipo y número de documento. '
                . 'Busque el documento en el formulario o use otro número.';
        }

        if ($ruta === 'usuario' && str_contains($msg, 'terceroidentificacion')) {
            return 'No se pudo registrar la identificación de la persona. Use Limpiar, complete tipo y número de documento, y guarde de nuevo.';
        }

        return $msg;
    }
}