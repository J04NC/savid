/* ============================================================
   Columnas de trazabilidad: created_at/by, updated_at/by
   En todas las tablas con PK `id` (excepto auditoría).
   Ejecutar una vez en la BD savid.
============================================================ */

DROP PROCEDURE IF EXISTS savid_add_trackable_columns;

DELIMITER $$

CREATE PROCEDURE savid_add_trackable_columns()
BEGIN
    DECLARE done INT DEFAULT 0;
    DECLARE tname VARCHAR(64);
    DECLARE cur CURSOR FOR
        SELECT TABLE_NAME
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_TYPE = 'BASE TABLE'
          AND TABLE_NAME NOT IN ('auditoria', 'auditoria_archivo')
          AND EXISTS (
              SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS c
              WHERE c.TABLE_SCHEMA = DATABASE()
                AND c.TABLE_NAME = TABLES.TABLE_NAME
                AND c.COLUMN_NAME = 'id'
          );
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

    OPEN cur;
    read_loop: LOOP
        FETCH cur INTO tname;
        IF done THEN
            LEAVE read_loop;
        END IF;

        IF NOT EXISTS (
            SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = tname AND COLUMN_NAME = 'created_at'
        ) THEN
            SET @sql = CONCAT(
                'ALTER TABLE `', tname, '` ',
                'ADD COLUMN `created_at` DATETIME(3) NULL DEFAULT NULL'
            );
            PREPARE stmt FROM @sql;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        END IF;

        IF NOT EXISTS (
            SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = tname AND COLUMN_NAME = 'created_by'
        ) THEN
            SET @sql = CONCAT(
                'ALTER TABLE `', tname, '` ',
                'ADD COLUMN `created_by` INT UNSIGNED NULL DEFAULT NULL'
            );
            PREPARE stmt FROM @sql;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        END IF;

        IF NOT EXISTS (
            SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = tname AND COLUMN_NAME = 'updated_at'
        ) THEN
            SET @sql = CONCAT(
                'ALTER TABLE `', tname, '` ',
                'ADD COLUMN `updated_at` DATETIME(3) NULL DEFAULT NULL'
            );
            PREPARE stmt FROM @sql;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        END IF;

        IF NOT EXISTS (
            SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = tname AND COLUMN_NAME = 'updated_by'
        ) THEN
            SET @sql = CONCAT(
                'ALTER TABLE `', tname, '` ',
                'ADD COLUMN `updated_by` INT UNSIGNED NULL DEFAULT NULL'
            );
            PREPARE stmt FROM @sql;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        END IF;

    END LOOP;
    CLOSE cur;
END$$

DELIMITER ;

CALL savid_add_trackable_columns();
DROP PROCEDURE IF EXISTS savid_add_trackable_columns;
