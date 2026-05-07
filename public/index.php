<?php
// ============================================
// SISTEMA SAVID - PUNTO DE ENTRADA PRINCIPAL
// ============================================

// Mostrar errores en desarrollo
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Definir ruta base
define('BASE_PATH', dirname(__DIR__));

// ✅ INICIO DE SESIÓN GLOBAL - ÚNICO LUGAR
require_once BASE_PATH . '/core/SessionManager.php';
SessionManager::start();

// Autocarga inteligente
spl_autoload_register(function ($class) {
    $paths = [
        BASE_PATH . '/core/',
        BASE_PATH . '/app/controllers/',
        BASE_PATH . '/app/models/',
        BASE_PATH . '/app/services/',
        BASE_PATH . '/app/helpers/',
        BASE_PATH . '/core/middleware/'
    ];

    foreach ($paths as $path) {
        $file = $path . $class . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

// ✅ EJECUTAR ROUTER
$router = new Router();
$router->run();