<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/config/Database.php';

$pdo = (new Database())->connect();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$newId = (int)$pdo->query("SELECT id FROM item WHERE ruta = 'sgd/ccd' LIMIT 1")->fetchColumn();
$oldId = (int)$pdo->query("SELECT id FROM item WHERE ruta = 'sgd_ccd_entrada' LIMIT 1")->fetchColumn();

if ($newId < 1) {
    $sqlFile = BASE_PATH . '/database/migrations/20260603_sgd_ccd_ruta.sql';
    foreach (array_filter(array_map('trim', explode(';', file_get_contents($sqlFile)))) as $stmt) {
        if ($stmt !== '' && !str_starts_with($stmt, '--')) {
            $pdo->exec($stmt);
        }
    }
    $newId = (int)$pdo->query("SELECT id FROM item WHERE ruta = 'sgd/ccd' LIMIT 1")->fetchColumn();
}

if ($newId < 1) {
    echo "ERROR: ítem sgd/ccd no encontrado\n";
    exit(1);
}

$pdo->exec("
    UPDATE item
    SET nombre = 'Cuadro de clasificación (CCD)', ruta = 'sgd/ccd', icono = '🗂️'
    WHERE id = {$newId}
");

if ($oldId > 0 && $oldId !== $newId) {
    $map = $pdo->query("
        SELECT old_ia.id AS old_ia_id, new_ia.id AS new_ia_id
        FROM item_accion old_ia
        INNER JOIN item_accion new_ia
            ON new_ia.item_id = {$newId} AND new_ia.accion_id = old_ia.accion_id
        WHERE old_ia.item_id = {$oldId}
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($map as $row) {
        $pdo->exec("
            UPDATE rol_permiso
            SET item_accion_id = " . (int)$row['new_ia_id'] . "
            WHERE item_accion_id = " . (int)$row['old_ia_id'] . "
        ");
    }

    $pdo->exec("DELETE FROM item_accion WHERE item_id = {$oldId}");
    $pdo->exec("DELETE FROM item WHERE id = {$oldId}");
    echo "OK: ítem sgd_ccd_entrada ({$oldId}) retirado; permisos migrados a {$newId}\n";
} else {
    echo "OK: menú sgd/ccd (id {$newId})\n";
}
