<?php

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/config/Database.php';
Database::bootstrapEnv();
require BASE_PATH . '/public/index.php';

// Minimal bootstrap without full router
spl_autoload_register(function ($class) {
    foreach ([
        BASE_PATH . '/app/services/',
        BASE_PATH . '/app/models/',
        BASE_PATH . '/core/',
    ] as $path) {
        $file = $path . $class . '.php';
        if (is_readable($file)) {
            require_once $file;
            return;
        }
    }
});

$docx = $argv[1] ?? '';
if ($docx === '' || !is_readable($docx)) {
    fwrite(STDERR, "Usage: php scripts/test_word_import_cli.php /path/to/file.docx\n");
    exit(1);
}

$_SESSION['user_id'] = 1;
$_GET = ['empresa_id' => 1, 'documento_id' => 477];
$_POST = ['documento_id' => 477];
$_FILES = [
    'archivo' => [
        'name' => basename($docx),
        'type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'tmp_name' => $docx,
        'error' => 0,
        'size' => filesize($docx),
    ],
];

$service = new SgdDocxImportService();
$result = $service->import($_FILES, $_POST, $_GET);

echo 'success: ' . ($result['success'] ? 'yes' : 'no') . PHP_EOL;
echo 'message: ' . ($result['message'] ?? '') . PHP_EOL;
echo 'staged: ' . (!empty($result['staged']) ? 'yes' : 'no') . PHP_EOL;
echo 'token: ' . ($result['import_token'] ?? '') . PHP_EOL;
echo 'html_len: ' . strlen($result['html'] ?? '') . PHP_EOL;
echo 'plain_len: ' . strlen($result['plain'] ?? '') . PHP_EOL;

$payload = [
    'ok' => $result['success'],
    'html' => $result['html'] ?? '',
    'staged' => !empty($result['staged']),
    'import_token' => $result['import_token'] ?? null,
    'stats' => $result['stats'] ?? [],
];
$json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
echo 'json_encode: ' . ($json === false ? 'FAIL ' . json_last_error_msg() : 'ok len=' . strlen($json)) . PHP_EOL;

if (!empty($result['import_token'])) {
    $loaded = SgdWordImportStaging::load($result['import_token'], 1, 477, 1);
    echo 'staging_load: ' . ($loaded ? 'ok len=' . strlen($loaded['html']) : 'fail') . PHP_EOL;
}
