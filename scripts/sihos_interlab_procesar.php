<?php

/**
 * Procesa la Interfaz Laboratorio (resultados + solicitudes) de TODAS las
 * empresas que tengan la credencial de escritura configurada
 * (sihos_empresa_config.usuario_interlab), usando exactamente el mismo
 * SihosInterlabProcesarService que usa el botón "Procesar seleccionados" de
 * SIHOS > Procesos > Interfaz Laboratorio — cero lógica duplicada entre el
 * cron y el botón manual.
 *
 * Uso (crontab, cada N minutos):
 *   php /var/www/savid/scripts/sihos_interlab_procesar.php
 *
 * No requiere sesión HTTP ($_SESSION['user_id'] queda null en la auditoría,
 * igual que cualquier otro script CLI de scripts/).
 */

declare(strict_types=1);

require __DIR__ . '/../core/AppBootstrap.php';
AppBootstrap::initCore();

function log_linea(string $mensaje): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $mensaje . "\n";
}

$configRepo = new SihosEmpresaConfigRepository();
$database = new Database();
$pdo = $database->connect();

$stmt = $pdo->query("
    SELECT empresa_id FROM sihos_empresa_config
    WHERE usuario_interlab IS NOT NULL AND usuario_interlab <> ''
      AND password_interlab_cifrado IS NOT NULL AND deleted_at IS NULL
");
$empresaIds = array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'empresa_id'));

if ($empresaIds === []) {
    log_linea('Ninguna empresa tiene credencial de escritura de Interfaz Laboratorio configurada. Nada que hacer.');
    exit(0);
}

$interlabService = new SihosInterlabService();
$procesarService = new SihosInterlabProcesarService();

foreach ($empresaIds as $empresaId) {
    log_linea("=== Empresa {$empresaId} ===");

    $pendientes = $interlabService->buildIdsResultadosPendientes($empresaId);
    if (!$pendientes['ok']) {
        log_linea('  Resultados: ERROR — ' . $pendientes['error']);
    } else {
        $ok = 0;
        $err = 0;
        foreach ($pendientes['ids'] as $id) {
            $resultado = $procesarService->procesarResultadoUno($empresaId, $id);
            if ($resultado['ok']) {
                $ok++;
            } else {
                $err++;
                log_linea("  Resultado id={$id}: ERROR — " . $resultado['message']);
            }
        }
        log_linea("  Resultados: {$ok} ok, {$err} con error (de " . count($pendientes['ids']) . ' pendientes).');
    }

    $candidatas = $interlabService->buildSolicitudesCandidatas($empresaId);
    if (!$candidatas['ok']) {
        log_linea('  Solicitudes: ERROR — ' . $candidatas['error']);
        continue;
    }

    $ok = 0;
    $err = 0;
    foreach ($candidatas['candidatas'] as $candidata) {
        $resultado = $procesarService->procesarSolicitudUna($empresaId, $candidata);
        if ($resultado['ok']) {
            $ok++;
        } else {
            $err++;
            log_linea('  Solicitud ' . $candidata['ConsAdmi'] . '-' . $candidata['ConsOrde'] . '-' . $candidata['Item'] . ': ERROR — ' . $resultado['message']);
        }
    }
    log_linea("  Solicitudes: {$ok} ok, {$err} con error (de " . count($candidatas['candidatas']) . ' candidatas).');
}

log_linea('Proceso terminado.');
