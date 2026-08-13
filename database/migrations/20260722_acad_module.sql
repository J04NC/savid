/* ============================================================
   Academic — inserta la capa "Module" entre Level y Unit.
   Jerarquía nueva: acad_level > acad_module > acad_unit > acad_lesson > acad_exercise.
   Complementa 20260709_acad_fase1.sql (no lo edita: esa migración
   ya está aplicada en producción).
============================================================ */

CREATE TABLE IF NOT EXISTS `acad_module` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `empresa_id` INT NOT NULL COMMENT 'label:Empresa|rel:tercero|label:razon_social|title:Razón social de la empresa (tercero vinculado)',
    `level_id` INT UNSIGNED NOT NULL COMMENT 'rel:acad_level|label:codigo|title:CEFR level|order:10',
    `titulo_en` VARCHAR(200) NOT NULL COMMENT 'label:Title (English)|order:20',
    `orden` SMALLINT NOT NULL DEFAULT 0 COMMENT 'order:30',
    `estado_id` SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'label:Status|reltipo:GENERAL|title:Module active or inactive.|order:40',
    `created_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `created_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    `updated_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `updated_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    `deleted_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `deleted_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    PRIMARY KEY (`id`),
    KEY `idx_acad_module_empresa` (`empresa_id`),
    KEY `idx_acad_module_level` (`level_id`),
    CONSTRAINT `fk_acad_module_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresa` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_acad_module_level` FOREIGN KEY (`level_id`) REFERENCES `acad_level` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_acad_module_estado` FOREIGN KEY (`estado_id`) REFERENCES `estado` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* ------------------------------------------------------------------ */
/* acad_unit: level_id -> module_id (backfill data-driven, sin perder  */
/* las filas de prueba que ya existan)                                 */
/* ------------------------------------------------------------------ */

SET @has_module_id := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'acad_unit' AND COLUMN_NAME = 'module_id'
);

SET @sql := IF(@has_module_id = 0,
    'ALTER TABLE `acad_unit` ADD COLUMN `module_id` INT UNSIGNED NULL COMMENT ''rel:acad_module|label:titulo_en|title:Curriculum module|order:10'' AFTER `empresa_id`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

/* un módulo por defecto por cada (empresa_id, level_id) que ya tenga unidades */
INSERT INTO acad_module (empresa_id, level_id, titulo_en, orden, estado_id, created_at)
SELECT DISTINCT u.empresa_id, u.level_id, 'Module 1', 10, 1, NOW(3)
FROM acad_unit u
WHERE u.module_id IS NULL
  AND NOT EXISTS (
      SELECT 1 FROM acad_module m
      WHERE m.empresa_id = u.empresa_id AND m.level_id = u.level_id AND m.titulo_en = 'Module 1'
  );

UPDATE acad_unit u
INNER JOIN acad_module m ON m.empresa_id = u.empresa_id AND m.level_id = u.level_id AND m.titulo_en = 'Module 1'
SET u.module_id = m.id
WHERE u.module_id IS NULL;

SET @sql := IF(@has_module_id = 0,
    'ALTER TABLE `acad_unit` MODIFY COLUMN `module_id` INT UNSIGNED NOT NULL COMMENT ''rel:acad_module|label:titulo_en|title:Curriculum module|order:10''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_fk_level := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'acad_unit' AND CONSTRAINT_NAME = 'fk_acad_unit_level'
);
SET @sql := IF(@has_fk_level > 0, 'ALTER TABLE `acad_unit` DROP FOREIGN KEY `fk_acad_unit_level`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_idx_level := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'acad_unit' AND INDEX_NAME = 'idx_acad_unit_level'
);
SET @sql := IF(@has_idx_level > 0, 'ALTER TABLE `acad_unit` DROP INDEX `idx_acad_unit_level`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_level_id := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'acad_unit' AND COLUMN_NAME = 'level_id'
);
SET @sql := IF(@has_level_id > 0, 'ALTER TABLE `acad_unit` DROP COLUMN `level_id`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_fk_module := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'acad_unit' AND CONSTRAINT_NAME = 'fk_acad_unit_module'
);
SET @sql := IF(@has_fk_module = 0,
    'ALTER TABLE `acad_unit` ADD KEY `idx_acad_unit_module` (`module_id`), ADD CONSTRAINT `fk_acad_unit_module` FOREIGN KEY (`module_id`) REFERENCES `acad_module` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

/* ------------------------------------------------------------------ */
/* Menú: nuevo ítem CRUD genérico "Modules" (nombre_es "Módulos")      */
/* ------------------------------------------------------------------ */

SET @mod_acad_id := (SELECT id FROM modulo WHERE nombre = 'ACADEMIC' LIMIT 1);
SET @item_acad_id := (SELECT id FROM item WHERE ruta = 'acad' LIMIT 1);
SET @item_config_id := (SELECT id FROM item WHERE modulo_id = @mod_acad_id AND item_padre_id IS NULL AND ruta = '' AND nombre IN ('CONFIGURACION', 'CONFIGURATION') LIMIT 1);
SET @item_config_id := COALESCE(@item_config_id, @item_acad_id);

INSERT INTO item (modulo_id, nombre, nombre_es, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_acad_id, 'Modules', 'Módulos', 'acad_module', '🧱', 45, @item_config_id, 1
WHERE @mod_acad_id IS NOT NULL AND @item_config_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM item WHERE ruta = 'acad_module' LIMIT 1);

INSERT INTO item_accion (item_id, accion_id, estado_id)
SELECT i.id, a.id, 1
FROM item i CROSS JOIN accion a
WHERE i.ruta = 'acad_module'
  AND a.codigo IN ('ver', 'guardar', 'eliminar')
  AND NOT EXISTS (SELECT 1 FROM item_accion ia WHERE ia.item_id = i.id AND ia.accion_id = a.id);

/* Super Admin ya queda cubierto por el wildcard acad_% existente en
   20260709_acad_fase1.sql; solo faltan Coordinador y Docente. */
SET @rol_coordinador_id := (SELECT id FROM rol WHERE LOWER(TRIM(nombre)) = 'coordinador académico' LIMIT 1);
SET @rol_docente_id := (SELECT id FROM rol WHERE LOWER(TRIM(nombre)) = 'docente académico' LIMIT 1);

INSERT INTO rol_permiso (rol_id, empresa_id, sede_id, item_accion_id, estado_id)
SELECT @rol_coordinador_id, NULL, NULL, ia.id, 5
FROM item_accion ia INNER JOIN item i ON i.id = ia.item_id
WHERE @rol_coordinador_id IS NOT NULL AND i.ruta = 'acad_module'
  AND NOT EXISTS (SELECT 1 FROM rol_permiso rp WHERE rp.rol_id = @rol_coordinador_id AND rp.item_accion_id = ia.id AND rp.estado_id = 5);

INSERT INTO rol_permiso (rol_id, empresa_id, sede_id, item_accion_id, estado_id)
SELECT @rol_docente_id, NULL, NULL, ia.id, 5
FROM item_accion ia
INNER JOIN item i ON i.id = ia.item_id
INNER JOIN accion a ON a.id = ia.accion_id
WHERE @rol_docente_id IS NOT NULL AND i.ruta = 'acad_module' AND a.codigo IN ('ver', 'guardar')
  AND NOT EXISTS (SELECT 1 FROM rol_permiso rp WHERE rp.rol_id = @rol_docente_id AND rp.item_accion_id = ia.id AND rp.estado_id = 5);
