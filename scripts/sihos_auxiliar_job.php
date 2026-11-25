<?php

/**
 * Worker del reporte SIHOS "Auxiliar" (lo lanza SihosAuxiliarJobService en
 * segundo plano para esquivar el timeout de ~100 s de Cloudflare).
 *
 * Uso: php scripts/sihos_auxiliar_job.php <empresa_id> <token>
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/core/AppBootstrap.php';
AppBootstrap::initCore();

$empresaId = (int)($argv[1] ?? 0);
$token = (string)($argv[2] ?? '');
if ($empresaId <= 0 || $token === '') {
    fwrite(STDERR, "Uso: php scripts/sihos_auxiliar_job.php <empresa_id> <token>\n");
    exit(1);
}

set_time_limit(0);
// El detalle se lee sin buffer y el Excel se escribe en streaming; lo que crece
// son los totales por cuenta y tercero (decenas de MB en el peor caso visto).
ini_set('memory_limit', '512M');

(new SihosAuxiliarJobService())->ejecutar($empresaId, $token);
