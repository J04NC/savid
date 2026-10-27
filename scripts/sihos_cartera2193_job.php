<?php

/**
 * Worker del reporte SIHOS 2193 CARTERA (lo lanza SihosCartera2193JobService
 * en segundo plano para esquivar el timeout de ~100 s de Cloudflare).
 *
 * Uso: php scripts/sihos_cartera2193_job.php <empresa_id> <token>
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/core/AppBootstrap.php';
AppBootstrap::initCore();

$empresaId = (int)($argv[1] ?? 0);
$token = (string)($argv[2] ?? '');
if ($empresaId <= 0 || $token === '') {
    fwrite(STDERR, "Uso: php scripts/sihos_cartera2193_job.php <empresa_id> <token>\n");
    exit(1);
}

set_time_limit(0);
ini_set('memory_limit', '1024M');

(new SihosCartera2193JobService())->ejecutar($empresaId, $token);
