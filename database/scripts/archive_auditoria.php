#!/usr/bin/env php
<?php
/**
 * Archiva registros antiguos de `auditoria` → `auditoria_archivo`.
 *
 * Uso:
 *   php database/scripts/archive_auditoria.php
 *   php database/scripts/archive_auditoria.php 36
 *
 * El segundo argumento son meses de retención en tabla caliente (default 24).
 * Programar en cron, por ejemplo el día 1 de cada mes a las 03:00:
 *   0 3 1 * * cd /var/www/savid && php database/scripts/archive_auditoria.php 24
 */

define('BASE_PATH', dirname(__DIR__, 2));

$dbFile = BASE_PATH . '/config/Database.php';
if (is_readable($dbFile)) {
    require_once $dbFile;
} else {
    require_once BASE_PATH . '/config.example/Database.php';
}

require_once BASE_PATH . '/core/AuditingPDO.php';
require_once BASE_PATH . '/core/AuditingPDOStatement.php';
require_once BASE_PATH . '/app/services/AuditService.php';
require_once BASE_PATH . '/app/models/AuditRepository.php';
require_once BASE_PATH . '/app/services/AuditQueryService.php';

$months = isset($argv[1]) ? (int)$argv[1] : 24;
$months = max(6, min(120, $months));

$service = new AuditQueryService();
$result = $service->archiveHotData($months);

echo sprintf(
    "[%s] Archivados %d registros anteriores a %s\n",
    date('Y-m-d H:i:s'),
    $result['moved'],
    $result['cutoff']
);
