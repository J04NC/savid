/* ============================================================
   Sistema de auditoría SAVID
   - Tabla auditoria (operativa, consultas rápidas)
   - Tabla auditoria_archivo (retención larga)
   - deleted_at / deleted_by en tablas de negocio (soft delete)
   - Ítem menú: Administración → Reportes → Auditoría (?url=auditoria)
   Ejecutar una vez en la BD savid.
============================================================ */

/* ------------------------------------------------------------------ */
/* 1) Tabla auditoria (últimos ~24 meses en caliente)                  */
/* ------------------------------------------------------------------ */

CREATE TABLE IF NOT EXISTS `auditoria` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `occurred_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `accion` ENUM('INSERT','UPDATE','DELETE') NOT NULL,
    `tabla` VARCHAR(64) NOT NULL,
    `registro_id` VARCHAR(64) NULL COMMENT 'PK principal serializada si compuesta',
    `datos_anteriores` JSON NULL,
    `datos_nuevos` JSON NULL,
    `campos_cambiados` JSON NULL,
    `sql_resumen` VARCHAR(500) NULL,
    `usuario_id` INT UNSIGNED NULL,
    `empresa_id` INT UNSIGNED NULL,
    `sede_id` INT UNSIGNED NULL,
    `ip` VARCHAR(45) NULL,
    `user_agent` VARCHAR(255) NULL,
    `request_url` VARCHAR(500) NULL,
    PRIMARY KEY (`id`),
    KEY `idx_auditoria_occurred` (`occurred_at`),
    KEY `idx_auditoria_tabla_registro` (`tabla`, `registro_id`),
    KEY `idx_auditoria_usuario` (`usuario_id`),
    KEY `idx_auditoria_accion` (`accion`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* ------------------------------------------------------------------ */
/* 2) Tabla archivo (misma estructura, datos movidos por antigüedad)   */
/* ------------------------------------------------------------------ */

CREATE TABLE IF NOT EXISTS `auditoria_archivo` (
    `id` BIGINT UNSIGNED NOT NULL,
    `occurred_at` DATETIME(3) NOT NULL,
    `accion` ENUM('INSERT','UPDATE','DELETE') NOT NULL,
    `tabla` VARCHAR(64) NOT NULL,
    `registro_id` VARCHAR(64) NULL,
    `datos_anteriores` JSON NULL,
    `datos_nuevos` JSON NULL,
    `campos_cambiados` JSON NULL,
    `sql_resumen` VARCHAR(500) NULL,
    `usuario_id` INT UNSIGNED NULL,
    `empresa_id` INT UNSIGNED NULL,
    `sede_id` INT UNSIGNED NULL,
    `ip` VARCHAR(45) NULL,
    `user_agent` VARCHAR(255) NULL,
    `request_url` VARCHAR(500) NULL,
    `archived_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`id`),
    KEY `idx_auditoria_archivo_occurred` (`occurred_at`),
    KEY `idx_auditoria_archivo_tabla` (`tabla`, `registro_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* ------------------------------------------------------------------ */
/* 3) Soft delete: deleted_at + deleted_by donde falten               */
/*    (omite tablas de auditoría y tablas sin columna id)             */
/* ------------------------------------------------------------------ */

DROP PROCEDURE IF EXISTS savid_add_soft_delete_columns;

DELIMITER $$

CREATE PROCEDURE savid_add_soft_delete_columns()
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
              AND TABLE_NAME = tname
              AND COLUMN_NAME = 'deleted_at'
        ) THEN
            SET @sql = CONCAT(
                'ALTER TABLE `', tname, '` ',
                'ADD COLUMN `deleted_at` DATETIME(3) NULL DEFAULT NULL, ',
                'ADD COLUMN `deleted_by` INT UNSIGNED NULL DEFAULT NULL, ',
                'ADD KEY `idx_', tname, '_deleted` (`deleted_at`)'
            );
            PREPARE stmt FROM @sql;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        END IF;
    END LOOP;
    CLOSE cur;
END$$

DELIMITER ;

CALL savid_add_soft_delete_columns();
DROP PROCEDURE IF EXISTS savid_add_soft_delete_columns;

/* ------------------------------------------------------------------ */
/* 4) Menú: módulo Administración → Reportes → Auditoría              */
/* ------------------------------------------------------------------ */

INSERT INTO modulo (nombre, icono, orden, estado_id)
SELECT 'Administración', '⚙️', 900, 1
WHERE NOT EXISTS (
    SELECT 1 FROM modulo WHERE LOWER(TRIM(nombre)) = 'administración' LIMIT 1
);

SET @mod_admin_id := (
    SELECT id FROM modulo
    WHERE LOWER(TRIM(nombre)) IN ('administración', 'administracion')
    ORDER BY id LIMIT 1
);

INSERT INTO item (modulo_id, nombre, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_admin_id, 'Reportes', '', '📊', 800, NULL, 1
WHERE @mod_admin_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM item
      WHERE modulo_id = @mod_admin_id
        AND item_padre_id IS NULL
        AND LOWER(TRIM(nombre)) = 'reportes'
      LIMIT 1
  );

SET @item_reportes_id := (
    SELECT id FROM item
    WHERE modulo_id = @mod_admin_id
      AND (item_padre_id IS NULL OR item_padre_id = 0)
      AND LOWER(TRIM(nombre)) = 'reportes'
    ORDER BY id LIMIT 1
);

INSERT INTO item (modulo_id, nombre, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_admin_id, 'Auditoría', 'auditoria', '📋', 10, @item_reportes_id, 1
WHERE @mod_admin_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM item WHERE ruta = 'auditoria' LIMIT 1
  );

SET @item_auditoria_id := (SELECT id FROM item WHERE ruta = 'auditoria' LIMIT 1);

INSERT INTO item_accion (item_id, accion_id, estado_id)
SELECT @item_auditoria_id, a.id, 1
FROM accion a
WHERE @item_auditoria_id IS NOT NULL
  AND a.codigo IN ('ver', 'guardar')
  AND NOT EXISTS (
      SELECT 1 FROM item_accion ia
      WHERE ia.item_id = @item_auditoria_id AND ia.accion_id = a.id
  );

/* Superadmin (rol 1): permiso ver en auditoría si no existe */
INSERT INTO rol_permiso (rol_id, empresa_id, sede_id, item_accion_id, estado_id)
SELECT 1, NULL, NULL, ia.id, 5
FROM item_accion ia
INNER JOIN item i ON i.id = ia.item_id
INNER JOIN accion a ON a.id = ia.accion_id
WHERE i.ruta = 'auditoria'
  AND a.codigo = 'ver'
  AND NOT EXISTS (
      SELECT 1 FROM rol_permiso rp
      WHERE rp.rol_id = 1 AND rp.item_accion_id = ia.id AND rp.estado_id = 5
  );
