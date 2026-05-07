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
        // Proteger todas las rutas excepto login
        if ($controllerName !== 'LoginController' && 
            !in_array($method, ['index', 'modulo', 'item'])) {
            
            SessionManager::requireLogin();
        }

        // Permisos dinámicos (tu lógica existente)
        $rutaCompleta = $_GET['url'] ?? '';
        if (SessionManager::userLogged() && 
            !in_array($controllerName, ['LoginController', 'DashboardController']) &&
            !in_array($method, ['index', 'modulo', 'item'])) {
            
            if (class_exists('PermisoService') && !PermisoService::can($rutaCompleta, 'ver')) {
                http_response_code(403);
                die("Acceso denegado a: $rutaCompleta");
            }
        }
    }
}