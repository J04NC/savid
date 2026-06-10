/* ============================================================
   F2b — Esquema normalizado sgd_documento para entornos F1 (columna codigo)
   Idempotente: no altera BD que ya tiene consecutivo sin codigo.
   Tras aplicar, reimportar listado maestro para parsear codigo → consecutivo/padre.
   Ejecutar: mysql savid < database/migrations/20260604_sgd_documento_normalizado.sql
============================================================ */

SET @has_codigo := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'sgd_documento'
      AND COLUMN_NAME = 'codigo'
);

SET @has_consecutivo := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'sgd_documento'
      AND COLUMN_NAME = 'consecutivo'
);

SET @has_documento_id := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'sgd_documento'
      AND COLUMN_NAME = 'documento_id'
);

SET @sql := IF(
    @has_documento_id = 0,
    'ALTER TABLE `sgd_documento` ADD COLUMN `documento_id` INT UNSIGNED NULL COMMENT ''rel:sgd_documento|label:nombre|title:Documento padre (NULL = raíz).'' AFTER `proceso_id`, ADD KEY `idx_sgd_documento_padre` (`documento_id`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @has_consecutivo = 0,
    'ALTER TABLE `sgd_documento` ADD COLUMN `consecutivo` VARCHAR(64) NULL COMMENT ''Segmento del código (no el código completo).'' AFTER `linea_documental_id`',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @has_codigo > 0 AND @has_consecutivo > 0,
    'UPDATE `sgd_documento` SET `consecutivo` = `codigo` WHERE (`consecutivo` IS NULL OR TRIM(`consecutivo`) = '''') AND `codigo` IS NOT NULL AND TRIM(`codigo`) <> ''''',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_uk_codigo := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'sgd_documento'
      AND INDEX_NAME = 'uk_sgd_documento_empresa_codigo'
);

SET @sql := IF(
    @has_uk_codigo > 0,
    'ALTER TABLE `sgd_documento` DROP INDEX `uk_sgd_documento_empresa_codigo`',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @has_codigo > 0,
    'ALTER TABLE `sgd_documento` DROP COLUMN `codigo`',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
