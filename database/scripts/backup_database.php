#!/usr/bin/env php
<?php
/**
 * Backup de la base de datos (mysqldump comprimido) con retención configurable.
 *
 * Uso:
 *   php database/scripts/backup_database.php
 *   php database/scripts/backup_database.php 30
 *
 * El argumento son los días de retención (default 14): los backups más viejos
 * se borran automáticamente. Los archivos quedan en storage/backups/ (fuera
 * del control de versiones). También se puede generar un backup manual desde
 * la UI (Estado del sistema, superadmin) — misma lógica, ver DatabaseBackupService.
 * Programar en cron, por ejemplo todos los días a las 02:00 (solo un nodo):
 *   0 2 * * * cd /var/www/savid && php database/scripts/backup_database.php 14
 */

define('BASE_PATH', dirname(__DIR__, 2));

require_once BASE_PATH . '/core/AppBootstrap.php';
AppBootstrap::initCore(false);

$lockName = 'backup_database';
if (!DistributedLockService::acquire($lockName, 1800)) {
    fwrite(STDERR, "[" . date('Y-m-d H:i:s') . "] Otro nodo ya está ejecutando backup_database.\n");
    exit(0);
}

try {
    $retentionDays = isset($argv[1]) ? (int)$argv[1] : 14;

    $result = (new DatabaseBackupService())->crear($retentionDays);

    if (!$result['ok']) {
        fwrite(STDERR, "[" . date('Y-m-d H:i:s') . "] Error en backup: {$result['error']}\n");
        exit(1);
    }

    echo sprintf(
        "[%s] Backup creado: %s (%s MB)\n",
        date('Y-m-d H:i:s'),
        $result['archivo'],
        $result['tamanoMb']
    );

    if ($result['borrados'] > 0) {
        echo sprintf(
            "[%s] Backups antiguos eliminados (> %d días): %d\n",
            date('Y-m-d H:i:s'),
            $retentionDays,
            $result['borrados']
        );
    }
} finally {
    DistributedLockService::release($lockName);
}
