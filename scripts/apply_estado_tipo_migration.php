<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/config/Database.php';

$pdo = (new Database())->connect();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$steps = [
    "CREATE TABLE IF NOT EXISTS `estado_tipo` (
        `id` TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `codigo` VARCHAR(32) NOT NULL,
        `nombre` VARCHAR(120) NOT NULL,
        `orden` SMALLINT NOT NULL DEFAULT 0,
        `created_at` DATETIME(3) NULL DEFAULT NULL,
        `updated_at` DATETIME(3) NULL DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_estado_tipo_codigo` (`codigo`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "INSERT INTO `estado_tipo` (`id`, `codigo`, `nombre`, `orden`, `created_at`) VALUES
        (1, 'GENERAL', 'General (activo / inactivo)', 10, NOW(3)),
        (2, 'CONTABLE', 'Contable (preliminar / confirmado)', 20, NOW(3)),
        (3, 'PERMISO', 'Permiso (permitir / denegar)', 30, NOW(3)),
        (4, 'DOCUMENTAL', 'Documental (ciclo de vida del documento)', 40, NOW(3))
    ON DUPLICATE KEY UPDATE `nombre` = VALUES(`nombre`), `orden` = VALUES(`orden`)",
];

foreach ($steps as $sql) {
    $pdo->exec($sql);
    echo "OK: estado_tipo\n";
}

$hasCol = (int)$pdo->query("
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'estado' AND COLUMN_NAME = 'estado_tipo_id'
")->fetchColumn();

if ($hasCol === 0) {
    $pdo->exec("ALTER TABLE `estado` ADD COLUMN `estado_tipo_id` TINYINT UNSIGNED NULL AFTER `nombre`");
    echo "OK: ADD estado_tipo_id\n";
}

$pdo->exec("UPDATE `estado` SET `estado_tipo_id` = 1, `nombre` = 'ACTIVO' WHERE `id` = 1");
$pdo->exec("UPDATE `estado` SET `estado_tipo_id` = 1, `nombre` = 'INACTIVO' WHERE `id` = 2");
$pdo->exec("UPDATE `estado` SET `estado_tipo_id` = 2, `nombre` = 'PRELIMINAR' WHERE `id` = 3");
$pdo->exec("UPDATE `estado` SET `estado_tipo_id` = 2, `nombre` = 'CONFIRMADO' WHERE `id` = 4");
$pdo->exec("UPDATE `estado` SET `estado_tipo_id` = 3, `nombre` = 'PERMITIR' WHERE `id` = 5");
$pdo->exec("UPDATE `estado` SET `estado_tipo_id` = 3, `nombre` = 'DENEGAR' WHERE `id` = 6");

$hasTipoestadoCol = (int)$pdo->query("
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'estado' AND COLUMN_NAME = 'tipoestado_id'
")->fetchColumn();

if ($hasTipoestadoCol > 0) {
    $pdo->exec("UPDATE `estado` SET `estado_tipo_id` = `tipoestado_id` WHERE `estado_tipo_id` IS NULL");
    $pdo->exec("INSERT INTO `estado` (`id`, `estado_tipo_id`, `tipoestado_id`, `nombre`, `created_at`) VALUES
        (7, 4, 4, 'BORRADOR', NOW(3)), (8, 4, 4, 'VIGENTE', NOW(3)), (9, 4, 4, 'OBSOLETO', NOW(3)), (10, 4, 4, 'FIRMADO', NOW(3))
        ON DUPLICATE KEY UPDATE `estado_tipo_id` = VALUES(`estado_tipo_id`), `tipoestado_id` = VALUES(`tipoestado_id`), `nombre` = VALUES(`nombre`)");
} else {
    $pdo->exec("INSERT INTO `estado` (`id`, `estado_tipo_id`, `nombre`, `created_at`) VALUES
        (7, 4, 'BORRADOR', NOW(3)), (8, 4, 'VIGENTE', NOW(3)), (9, 4, 'OBSOLETO', NOW(3)), (10, 4, 'FIRMADO', NOW(3))
        ON DUPLICATE KEY UPDATE `estado_tipo_id` = VALUES(`estado_tipo_id`), `nombre` = VALUES(`nombre`)");
}

$pdo->exec("UPDATE `estado` SET `estado_tipo_id` = 1 WHERE `estado_tipo_id` IS NULL");

try {
    $pdo->exec("ALTER TABLE `estado` MODIFY COLUMN `estado_tipo_id` TINYINT UNSIGNED NOT NULL");
} catch (Throwable $e) {
    echo "Nota: estado_tipo_id sigue nullable: " . $e->getMessage() . "\n";
}

$fkExists = (int)$pdo->query("
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'estado' AND CONSTRAINT_NAME = 'fk_estado_estado_tipo'
")->fetchColumn();

if ($fkExists === 0) {
  try {
    $pdo->exec("ALTER TABLE `estado` ADD KEY `idx_estado_tipo` (`estado_tipo_id`)");
  } catch (Throwable $e) {
    // índice puede existir
  }
  $pdo->exec("ALTER TABLE `estado` ADD CONSTRAINT `fk_estado_estado_tipo`
      FOREIGN KEY (`estado_tipo_id`) REFERENCES `estado_tipo` (`id`)
      ON UPDATE CASCADE ON DELETE RESTRICT");
  echo "OK: FK\n";
}

echo "Migración estado_tipo completada.\n";
