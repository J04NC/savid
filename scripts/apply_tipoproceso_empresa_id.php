<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/config/Database.php';

$pdo = (new Database())->connect();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/** Lab Central — LABORATORIO CLINICO CENTRAL LTDA */
$empresaId = (int)$pdo->query("
    SELECT e.id FROM empresa e
    INNER JOIN tercero t ON t.id = e.tercero_id
    WHERE UPPER(t.razon_social) LIKE '%LABORATORIO CLINICO CENTRAL%'
    ORDER BY e.id ASC
    LIMIT 1
")->fetchColumn();

if ($empresaId < 1) {
    $empresaId = 1;
    echo "AVISO: Lab Central no encontrado por nombre; usando empresa_id = 1\n";
}

$hasCol = (int)$pdo->query("
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tipoproceso'
      AND COLUMN_NAME = 'empresa_id'
")->fetchColumn();

if ($hasCol === 0) {
    $pdo->exec("
        ALTER TABLE `tipoproceso`
        ADD COLUMN `empresa_id` INT NOT NULL DEFAULT {$empresaId}
            COMMENT 'label:Empresa|rel:tercero|label:razon_social|title:Razón social de la empresa'
            AFTER `id`
    ");
    echo "OK: ADD tipoproceso.empresa_id\n";
}

$pdo->exec("UPDATE `tipoproceso` SET `empresa_id` = {$empresaId}");
echo "OK: tipoproceso asociado a empresa_id = {$empresaId} (Lab Central)\n";

$pdo->exec('ALTER TABLE `tipoproceso` MODIFY COLUMN `empresa_id` INT NOT NULL');

$ukOld = (int)$pdo->query("
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tipoproceso'
      AND INDEX_NAME = 'uk_tipoproceso_codigo'
")->fetchColumn();

$ukNew = (int)$pdo->query("
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tipoproceso'
      AND INDEX_NAME = 'uk_tipoproceso_empresa_codigo'
")->fetchColumn();

if ($ukOld > 0 && $ukNew === 0) {
    $pdo->exec('ALTER TABLE `tipoproceso` DROP INDEX `uk_tipoproceso_codigo`');
    echo "OK: DROP uk_tipoproceso_codigo\n";
}

if ($ukNew === 0) {
    $idxEmp = (int)$pdo->query("
        SELECT COUNT(*) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'tipoproceso'
          AND INDEX_NAME = 'idx_tipoproceso_empresa'
    ")->fetchColumn();
    $sql = 'ALTER TABLE `tipoproceso` ADD UNIQUE KEY `uk_tipoproceso_empresa_codigo` (`empresa_id`, `codigo`)';
    if ($idxEmp === 0) {
        $sql .= ', ADD KEY `idx_tipoproceso_empresa` (`empresa_id`)';
    }
    $pdo->exec($sql);
    echo "OK: uk_tipoproceso_empresa_codigo\n";
}

$fkExists = (int)$pdo->query("
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tipoproceso'
      AND CONSTRAINT_NAME = 'fk_tipoproceso_empresa'
")->fetchColumn();

if ($fkExists === 0) {
    $pdo->exec("
        ALTER TABLE `tipoproceso`
        ADD CONSTRAINT `fk_tipoproceso_empresa`
            FOREIGN KEY (`empresa_id`) REFERENCES `empresa` (`id`)
            ON DELETE RESTRICT ON UPDATE CASCADE
    ");
    echo "OK: FK tipoproceso → empresa\n";
}

echo "Migración tipoproceso.empresa_id completada.\n";
