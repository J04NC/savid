<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/config/Database.php';

$pdo = (new Database())->connect();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$sqlFile = BASE_PATH . '/database/migrations/20260601_sgd_tipoproceso.sql';
$pdo->exec(file_get_contents($sqlFile));
echo "OK: tabla tipoproceso\n";

$hasCol = (int)$pdo->query("
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'sgd_proceso'
      AND COLUMN_NAME = 'tipoproceso_id'
")->fetchColumn();

if ($hasCol === 0) {
    $pdo->exec("
        ALTER TABLE `sgd_proceso`
        ADD COLUMN `tipoproceso_id` TINYINT UNSIGNED NULL
            COMMENT 'rel:tipoproceso|label:nombre|title:Tipo de proceso (estratégico, misional, apoyo)'
            AFTER `nombre`
    ");
    echo "OK: ADD tipoproceso_id\n";
}

$pdo->exec("
    UPDATE `sgd_proceso` p
    INNER JOIN `tipoproceso` tp ON UPPER(TRIM(tp.nombre)) = UPPER(TRIM(p.tipo_proceso))
    SET p.tipoproceso_id = tp.id
    WHERE p.tipo_proceso IS NOT NULL AND TRIM(p.tipo_proceso) <> ''
");
echo "OK: migrar datos tipo_proceso → tipoproceso_id\n";

$orphan = (int)$pdo->query("
    SELECT COUNT(*) FROM sgd_proceso
    WHERE tipo_proceso IS NOT NULL AND TRIM(tipo_proceso) <> '' AND tipoproceso_id IS NULL
")->fetchColumn();
if ($orphan > 0) {
    echo "AVISO: {$orphan} filas con tipo_proceso sin match en tipoproceso\n";
}

$hasOld = (int)$pdo->query("
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'sgd_proceso'
      AND COLUMN_NAME = 'tipo_proceso'
")->fetchColumn();

if ($hasOld > 0) {
    $pdo->exec('ALTER TABLE `sgd_proceso` DROP COLUMN `tipo_proceso`');
    echo "OK: DROP tipo_proceso\n";
}

$fkExists = (int)$pdo->query("
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'sgd_proceso'
      AND CONSTRAINT_NAME = 'fk_sgd_proceso_tipoproceso'
")->fetchColumn();

if ($fkExists === 0) {
    $pdo->exec("
        ALTER TABLE `sgd_proceso`
        ADD KEY `idx_sgd_proceso_tipoproceso` (`tipoproceso_id`),
        ADD CONSTRAINT `fk_sgd_proceso_tipoproceso`
            FOREIGN KEY (`tipoproceso_id`) REFERENCES `tipoproceso` (`id`)
            ON UPDATE CASCADE ON DELETE SET NULL
    ");
    echo "OK: FK tipoproceso\n";
}

/* Menú CRUD tipoproceso */
$modId = $pdo->query("
    SELECT id FROM modulo
    WHERE LOWER(TRIM(nombre)) IN ('gestión documental', 'gestion documental')
    ORDER BY id LIMIT 1
")->fetchColumn();

if ($modId) {
    $parentId = $pdo->query("
        SELECT id FROM item
        WHERE modulo_id = " . (int)$modId . " AND (item_padre_id IS NULL OR item_padre_id = 0)
        ORDER BY id LIMIT 1
    ")->fetchColumn();

    $pdo->exec("
        INSERT INTO item (modulo_id, nombre, ruta, icono, orden, item_padre_id, estado_id)
        SELECT " . (int)$modId . ", 'Tipos de proceso', 'tipoproceso', '🏷️', 35, " . (int)$parentId . ", 1
        WHERE NOT EXISTS (SELECT 1 FROM item WHERE ruta = 'tipoproceso' LIMIT 1)
    ");
    echo "OK: menú tipoproceso\n";
}

echo "Migración tipoproceso completada.\n";
