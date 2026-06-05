<?php

declare(strict_types=1);

/**
 * Importa CCD por dependencia (Gerencia, TAC, Mercadeo, Talento humano…).
 * Solo filas con código de documento de calidad (columna E del Excel).
 *
 * Uso: php scripts/sgd_import_ccd_otros.php [empresa_id] [directorio]
 */

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
$dir = $argv[2] ?? BASE_PATH . '/docs/sgd/SGD';

if ($empresaId < 1) {
    fwrite(STDERR, "empresa_id inválido\n");
    exit(1);
}

$repo = new SgdRepository();
$service = new SgdImportService();
$files = $repo->listCcdDependenciaFiles($dir);

if ($files === []) {
    fwrite(STDERR, "No hay archivos CCD por dependencia en: {$dir}\n");
    exit(1);
}

echo "Empresa {$empresaId} — importando " . count($files) . " CCD (solo filas con documento de calidad)\n\n";

$totals = [
    'entradas' => 0,
    'omitidas' => 0,
    'omitidas_sin_calidad' => 0,
    'sin_documento_maestro' => 0,
    'dependencias' => 0,
    'series' => 0,
    'subseries' => 0,
];

foreach ($files as $file) {
    $path = $dir . '/' . $file;
    echo "=== {$file} ===\n";
    $result = $service->importCcd($empresaId, $path);
    if (!$result['success']) {
        echo "ERROR: " . ($result['message'] ?? 'falló') . "\n\n";
        continue;
    }
    $stats = $result['stats'] ?? [];
    echo json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
    foreach ($totals as $k => $_) {
        $totals[$k] += (int)($stats[$k] ?? 0);
    }
}

$after = (int)(new Database())->connect()
    ->query("SELECT COUNT(*) FROM sgd_ccd_entrada WHERE empresa_id = {$empresaId}")
    ->fetchColumn();

echo "Totales agregados:\n";
echo json_encode($totals, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
echo "Entradas CCD en BD (empresa {$empresaId}): {$after}\n";
