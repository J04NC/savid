#!/usr/bin/env php
<?php
/**
 * Avisa por correo a los superadministradores sobre suscripciones próximas a vencer.
 *
 * Uso:
 *   php database/scripts/notificar_vencimientos.php
 *   php database/scripts/notificar_vencimientos.php 15
 *
 * El argumento son los días de anticipación (default 7). Programar en cron,
 * por ejemplo todos los días a las 07:00 (solo un nodo):
 *   0 7 * * * cd /var/www/savid && php database/scripts/notificar_vencimientos.php 7
 */

define('BASE_PATH', dirname(__DIR__, 2));

require_once BASE_PATH . '/core/AppBootstrap.php';
AppBootstrap::initCore(false);

require_once BASE_PATH . '/core/AuditingPDO.php';
require_once BASE_PATH . '/core/AuditingPDOStatement.php';

$lockName = 'notificar_vencimientos';
if (!DistributedLockService::acquire($lockName, 600)) {
    fwrite(STDERR, "[" . date('Y-m-d H:i:s') . "] Otro nodo ya está ejecutando notificar_vencimientos.\n");
    exit(0);
}

try {
    $diasUmbral = isset($argv[1]) ? (int)$argv[1] : 7;
    $diasUmbral = max(1, min(60, $diasUmbral));

    $database = new Database();
    $pdo = $database->connect();
    $repo = new SubscriptionRepository($pdo);
    $alertService = new SecurityAlertService();

    $pendientes = $repo->findProximasAVencerSinAlertar($diasUmbral);
    $enviadas = 0;

    foreach ($pendientes as $s) {
        $diasRestantes = (int)floor((strtotime($s['fecha_fin']) - strtotime(date('Y-m-d'))) / 86400);
        $alertService->notificarSuscripcionPorVencer($s['razon_social'], $s['fecha_fin'], $diasRestantes);
        $repo->marcarAlertaVencimientoEnviada((int)$s['id']);
        $enviadas++;
    }

    echo sprintf(
        "[%s] Avisos de vencimiento enviados: %d (umbral %d días)\n",
        date('Y-m-d H:i:s'),
        $enviadas,
        $diasUmbral
    );
} finally {
    DistributedLockService::release($lockName);
}
