<?php
// ============================================
// SISTEMA SAVID - PUNTO DE ENTRADA PRINCIPAL
// ============================================

// Mostrar errores solo fuera de producción
$appEnv = strtolower(trim((string)(getenv('APP_ENV') ?: 'development')));
if ($appEnv === 'production') {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
} else {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

// Definir ruta base
define('BASE_PATH', dirname(__DIR__));

$composerAutoload = BASE_PATH . '/vendor/autoload.php';
if (is_readable($composerAutoload)) {
    require_once $composerAutoload;
}

// Base de datos: config local (gitignored) o plantilla versionada
$dbFile = BASE_PATH . '/config/Database.php';
if (is_readable($dbFile)) {
    require_once $dbFile;
} else {
    require_once BASE_PATH . '/config.example/Database.php';
}

// Zona horaria de la aplicación (alineada con MySQL / servidor)
Database::bootstrapEnv();
$appTz = getenv('APP_TIMEZONE') ?: 'America/Bogota';
if (@date_default_timezone_set($appTz) === false) {
    date_default_timezone_set('UTC');
}

// ✅ INICIO DE SESIÓN GLOBAL - ÚNICO LUGAR
require_once BASE_PATH . '/core/SessionManager.php';
require_once BASE_PATH . '/core/AuditingPDO.php';
require_once BASE_PATH . '/core/AuditingPDOStatement.php';
SessionManager::start();

// Autocarga inteligente
spl_autoload_register(function ($class) {
    $paths = [
        BASE_PATH . '/core/',
        BASE_PATH . '/app/storage/',
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