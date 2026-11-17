#!/usr/bin/env php
<?php
/**
 * Imprime, una por línea, las tablas que tienen columna empresa_id en el
 * esquema REAL de la base de datos conectada (no en los archivos de
 * migración, que pueden quedar desactualizados si algún ALTER se corrió a
 * mano sin dejar migración — ver TenantIsolationGuard).
 *
 * Usado por .githooks/pre-commit (chequeo de aislamiento por tenant) y por
 * TenantIsolationGuard::tablasMultiTenant() como referencia de la misma
 * fuente de verdad. Si no hay BD disponible, el hook cae de vuelta a leer
 * database/migrations/*.sql (menos preciso, ver limitación documentada en
 * README.md).
 *
 * Uso: php scripts/dump_tenant_tables.php
 */
define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/core/AppBootstrap.php';
AppBootstrap::initCore();

$pdo = (new Database())->connect();

$stmt = $pdo->prepare(
    "SELECT DISTINCT TABLE_NAME FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'empresa_id'"
);
$stmt->execute();

foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $tabla) {
    echo $tabla . "\n";
}
