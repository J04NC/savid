#!/usr/bin/env php
<?php
/**
 * Prueba local de generación PDF SGD (F3c).
 * Uso: php scripts/test_sgd_pdf_generate.php EMPRESA_ID DOCUMENTO_ID VERSION_ID
 */
define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/core/AppBootstrap.php';
AppBootstrap::initCore();

$empresaId = isset($argv[1]) ? (int)$argv[1] : 0;
$documentoId = isset($argv[2]) ? (int)$argv[2] : 0;
$versionId = isset($argv[3]) ? (int)$argv[3] : 0;

if ($empresaId <= 0 || $documentoId <= 0 || $versionId <= 0) {
    fwrite(STDERR, "Uso: php scripts/test_sgd_pdf_generate.php EMPRESA_ID DOCUMENTO_ID VERSION_ID\n");
    exit(1);
}

$repo = new SgdRepository();
$version = $repo->findDocumentoVersionById($empresaId, $versionId);
if ($version === null) {
    fwrite(STDERR, "Versión no encontrada.\n");
    exit(1);
}

$service = new SgdPdfGenerationService();
$result = $service->generateForVersion($empresaId, $documentoId, $versionId, $version);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
exit($result['success'] ? 0 : 1);
