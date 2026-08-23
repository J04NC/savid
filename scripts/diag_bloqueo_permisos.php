<?php

/**
 * Muestreo de estado mientras se reproduce el bloqueo del modal de permisos.
 *
 * Uso:  php scripts/diag_bloqueo_permisos.php [segundos]
 *
 * Cada segundo registra en storage/diag_bloqueo.log:
 *   - transacciones InnoDB abiertas (con antigüedad y filas bloqueadas)
 *   - consultas en curso y esperas de lock
 *   - workers de php-fpm y carga del sistema
 *
 * Solo lee metadatos de ejecución (no datos de negocio).
 */

require __DIR__ . '/../core/AppBootstrap.php';
AppBootstrap::initCore();

$segundos = isset($argv[1]) ? max(5, (int)$argv[1]) : 300;
$destino = __DIR__ . '/../storage/diag_bloqueo.log';

$db = new Database();
$pdo = $db->connect();

$fin = time() + $segundos;

function anotar(string $destino, string $texto): void
{
    file_put_contents($destino, $texto, FILE_APPEND);
}

anotar($destino, sprintf("\n===== inicio muestreo %s (%ds) =====\n", date('Y-m-d H:i:s'), $segundos));

while (time() < $fin) {
    $marca = date('H:i:s');
    $lineas = [];

    // Consultas activas (excluye las de este script y las inactivas sin transacción).
    $stmt = $pdo->query("
        SELECT id, command, time, state, LEFT(COALESCE(info, ''), 120) AS info
        FROM information_schema.processlist
        WHERE db = DATABASE() AND id <> CONNECTION_ID()
        ORDER BY time DESC
    ");
    foreach ($stmt as $r) {
        if ($r['command'] === 'Sleep' && (int)$r['time'] < 5) {
            continue;
        }
        $lineas[] = sprintf(
            '  hilo %s | %s | %ss | %s | %s',
            $r['id'],
            $r['command'],
            $r['time'],
            $r['state'],
            $r['info']
        );
    }

    $stmt = $pdo->query("
        SELECT trx_mysql_thread_id AS hilo, trx_state AS estado,
               TIMESTAMPDIFF(SECOND, trx_started, NOW()) AS seg,
               trx_rows_locked AS filas_bloq,
               LEFT(COALESCE(trx_query, ''), 120) AS q
        FROM information_schema.INNODB_TRX
        ORDER BY trx_started
    ");
    foreach ($stmt as $r) {
        $lineas[] = sprintf(
            '  TRX hilo %s | %s | %ss abierta | %s filas bloqueadas | %s',
            $r['hilo'],
            $r['estado'],
            $r['seg'],
            $r['filas_bloq'],
            $r['q']
        );
    }

    // Esperas de lock: quién espera a quién.
    try {
        $stmt = $pdo->query("
            SELECT r.trx_mysql_thread_id AS esperando,
                   b.trx_mysql_thread_id AS bloqueado_por,
                   LEFT(COALESCE(r.trx_query, ''), 80) AS q
            FROM performance_schema.data_lock_waits w
            JOIN information_schema.INNODB_TRX r ON r.trx_id = w.requesting_engine_transaction_id
            JOIN information_schema.INNODB_TRX b ON b.trx_id = w.blocking_engine_transaction_id
        ");
        foreach ($stmt as $r) {
            $lineas[] = sprintf(
                '  ESPERA hilo %s bloqueado por hilo %s | %s',
                $r['esperando'],
                $r['bloqueado_por'],
                $r['q']
            );
        }
    } catch (Throwable $e) {
        // performance_schema puede no estar disponible; no es crítico.
    }

    $workers = (int)shell_exec('pgrep -c php-fpm 2>/dev/null');
    $carga = trim((string)@file_get_contents('/proc/loadavg'));

    if ($lineas !== []) {
        anotar($destino, sprintf("[%s] workers=%d carga=%s\n%s\n", $marca, $workers, $carga, implode("\n", $lineas)));
    }

    sleep(1);
}

anotar($destino, sprintf("===== fin muestreo %s =====\n", date('Y-m-d H:i:s')));
echo "Muestreo terminado. Revisa storage/diag_bloqueo.log\n";
