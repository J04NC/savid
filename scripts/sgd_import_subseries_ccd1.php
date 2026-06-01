<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

spl_autoload_register(function ($class) {
    $paths = [
        BASE_PATH . '/core/',
        BASE_PATH . '/app/controllers/',
        BASE_PATH . '/app/models/',
        BASE_PATH . '/app/services/',
        BASE_PATH . '/app/helpers/',
        BASE_PATH . '/core/middleware/',
    ];
    foreach ($paths as $path) {
        $file = $path . $class . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

require BASE_PATH . '/config/Database.php';

$empresaId = isset($argv[1]) ? (int)$argv[1] : 1;
$file = $argv[2] ?? BASE_PATH . '/docs/sgd/SGD/20260102_CCD_GENERAL_LABCENTRAL.xlsx';

if (!is_readable($file)) {
    fwrite(STDERR, "Archivo no encontrado: {$file}\n");
    exit(1);
}

$service = new SgdImportService();
$result = $service->importSubseriesCcd1($empresaId, $file);

if (!$result['success']) {
    fwrite(STDERR, ($result['message'] ?? 'Error') . "\n");
    exit(2);
}

$stats = $result['stats'] ?? [];
echo "Importación CCD1 completada (empresa {$empresaId})\n";
echo '  Series nuevas: ' . ($stats['series'] ?? 0) . "\n";
echo '  Subseries nuevas: ' . ($stats['subseries'] ?? 0) . "\n";
echo '  Subseries actualizadas: ' . ($stats['actualizadas'] ?? 0) . "\n";

$pdo = (new Database())->connect();
$total = (int)$pdo->query("SELECT COUNT(*) FROM sgd_subserie WHERE empresa_id = {$empresaId}")->fetchColumn();
echo "  Total subseries en BD: {$total}\n";
