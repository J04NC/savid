<?php
/**
 * Router Principal con Middleware de Seguridad
 */
class Router
{
    public function run()
    {
        $url = $_GET['url'] ?? 'login';
        $url = trim($url, '/');
        $parts = explode('/', $url);

        $controllerName = ucfirst($parts[0]) . 'Controller';
        $method = $parts[1] ?? 'index';
        $params = array_slice($parts, 2);

        // 🔥 MIDDLEWARE DE SEGURIDAD
        $this->middleware($controllerName, $method);

        if (SessionManager::userLogged()
            && ContextGateService::mustRedirectToContextSelection($controllerName, $method)) {
            header('Location: ?url=context/cambiarSede');
            exit;
        }

        $controllerFile = BASE_PATH . '/app/controllers/' . $controllerName . '.php';

        if (!file_exists($controllerFile)) {
            $controllerName = 'ModuleController';
            $controllerFile = BASE_PATH . '/app/controllers/ModuleController.php';
        }

        require_once $controllerFile;
        $controller = new $controllerName();

        if (!method_exists($controller, $method)) {
            http_response_code(404);
            die("Método no encontrado: $controllerName::$method()");
        }

        call_user_func_array([$controller, $method], $params);
    }

    private function middleware($controllerName, $method)
    {
        /*
         * Sesión obligatoria en todo el sistema salvo pantallas de Login.
         * (Antes se excluían index/modulo/item y cualquier usuario podía pegar ?url=usuario sin sesión.)
         */
        if ($controllerName !== 'LoginController') {
            SessionManager::requireLogin();
        }

        /*
         * Permiso "ver" por URL para controladores dedicados (usuario/permisos, rol/permisos, etc.).
         * Dashboard y CRUD dinámico se validan dentro de cada controlador con PermisoService::can(ruta).
         */
        $rutaCompleta = $_GET['url'] ?? '';
        $usuarioJsonLookupMethods = [
            'uploadAsset',
            'lookupDocumento',
            'lookupDocumentoNumero',
            'lookupEmail',
            'lookupUsername',
            'lookupEmailTercero',
            'lookupIdentificacion',
        ];

        $empresaJsonApiMethods = ['uploadLogo', 'lookupNit', 'searchRepresentante', 'lookupRepresentante'];

        $sgdElaboracionJsonMethods = ['elaboracionUploadMedia', 'elaboracionImportWord', 'elaboracionImportWordFetch', 'elaboracionPreviewPdf'];

        if (SessionManager::userLogged()
            && $controllerName === 'UsuarioController'
            && in_array($method, $usuarioJsonLookupMethods, true)
            && class_exists('PermisoService')
            && !PermisoService::canUsuarioFormApi()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Sin permiso'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (SessionManager::userLogged()
            && $controllerName === 'ItemController'
            && class_exists('PermisoService')
            && !PermisoService::isSuperAdminSession()) {
            http_response_code(403);
            exit('Acceso denegado. Solo superadministrador.');
        }

        if (SessionManager::userLogged()
            && !in_array($controllerName, ['LoginController', 'DashboardController', 'ModuleController', 'ContextController'], true)
            && class_exists('PermisoService')
            && !($controllerName === 'UsuarioController' && in_array($method, $usuarioJsonLookupMethods, true))
            && !($controllerName === 'EmpresaController' && in_array($method, $empresaJsonApiMethods, true))
            && !($controllerName === 'SgdController'
                && in_array($method, $sgdElaboracionJsonMethods, true)
                && (
                    ($method === 'elaboracionPreviewPdf'
                        && (PermisoService::can('sgd/elaboracion', 'ver') || PermisoService::can('sgd/documentos', 'ver')))
                    || ($method !== 'elaboracionPreviewPdf' && PermisoService::can('sgd/elaboracion', 'ver'))
                ))
            && !PermisoService::can($rutaCompleta, 'ver')) {
            http_response_code(403);
            exit('Acceso denegado.');
        }
    }
}