/* ============================================================
   F3c — Catálogo secciones M4, perfil por tipo, elaboración maestro
   Ejecutar: mysql savid < database/migrations/20260606_sgd_seccion_elaboracion.sql
============================================================ */

CREATE TABLE IF NOT EXISTS `sgd_seccion` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `empresa_id` INT NOT NULL,
    `codigo` VARCHAR(32) NOT NULL COMMENT 'Clave estable (portada, objetivo…)|label:Código',
    `nombre` VARCHAR(120) NOT NULL COMMENT 'label:Nombre',
    `clase` ENUM('auto','contenido','sistema') NOT NULL DEFAULT 'contenido'
        COMMENT 'auto=PDF; contenido=redacción; sistema=datos SAVID|type:select',
    `orden` INT NOT NULL DEFAULT 0 COMMENT 'label:Orden',
    `ayuda` TEXT NULL COMMENT 'label:Ayuda',
    `estado_id` SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'label:Estado|reltipo:GENERAL',
    `created_at` DATETIME(3) NULL DEFAULT NULL,
    `created_by` INT UNSIGNED NULL,
    `updated_at` DATETIME(3) NULL DEFAULT NULL,
    `updated_by` INT UNSIGNED NULL,
    `deleted_at` DATETIME(3) NULL DEFAULT NULL,
    `deleted_by` INT UNSIGNED NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_sgd_seccion_empresa_codigo` (`empresa_id`, `codigo`),
    KEY `idx_sgd_seccion_empresa` (`empresa_id`),
    CONSTRAINT `fk_sgd_seccion_empresa`
        FOREIGN KEY (`empresa_id`) REFERENCES `empresa` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sgd_tipo_documental_seccion` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `empresa_id` INT NOT NULL,
    `tipo_documental_id` INT UNSIGNED NOT NULL COMMENT 'rel:sgd_tipo_documental|label:codigo',
    `seccion_id` INT UNSIGNED NOT NULL COMMENT 'rel:sgd_seccion|label:codigo',
    `estado_seccion` ENUM('aplica','no_aplica','opcional') NOT NULL DEFAULT 'no_aplica'
        COMMENT 'Aplica=obligatoria; Opcional=según contenido|type:select',
    `estado_id` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    `created_at` DATETIME(3) NULL DEFAULT NULL,
    `created_by` INT UNSIGNED NULL,
    `updated_at` DATETIME(3) NULL DEFAULT NULL,
    `updated_by` INT UNSIGNED NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_sgd_tipo_doc_seccion` (`empresa_id`, `tipo_documental_id`, `seccion_id`),
    KEY `idx_sgd_tipo_doc_seccion_tipo` (`tipo_documental_id`),
    CONSTRAINT `fk_sgd_tipo_doc_seccion_empresa`
        FOREIGN KEY (`empresa_id`) REFERENCES `empresa` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_sgd_tipo_doc_seccion_tipo`
        FOREIGN KEY (`tipo_documental_id`) REFERENCES `sgd_tipo_documental` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_sgd_tipo_doc_seccion_seccion`
        FOREIGN KEY (`seccion_id`) REFERENCES `sgd_seccion` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sgd_documento_elaboracion` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `empresa_id` INT NOT NULL,
    `documento_id` INT UNSIGNED NOT NULL COMMENT 'rel:sgd_documento|label:nombre',
    `opciones_json` JSON NULL COMMENT 'Toggles secciones opcionales {codigo: bool}',
    `contenido_json` JSON NULL COMMENT 'Contenido por sección {codigo: valor}',
    `estado_id` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    `created_at` DATETIME(3) NULL DEFAULT NULL,
    `created_by` INT UNSIGNED NULL,
    `updated_at` DATETIME(3) NULL DEFAULT NULL,
    `updated_by` INT UNSIGNED NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_sgd_doc_elab_documento` (`documento_id`),
    KEY `idx_sgd_doc_elab_empresa` (`empresa_id`),
    CONSTRAINT `fk_sgd_doc_elab_documento`
        FOREIGN KEY (`documento_id`) REFERENCES `sgd_documento` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_sgd_doc_elab_empresa`
        FOREIGN KEY (`empresa_id`) REFERENCES `empresa` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `sgd_documento_version`
    ADD COLUMN `contenido_json` JSON NULL
        COMMENT 'Snapshot elaboración al publicar versión'
        AFTER `archivo_ruta`;

SET @mod_sgd_id := (
    SELECT id FROM modulo
    WHERE LOWER(TRIM(nombre)) IN ('gestión documental', 'gestion documental')
    ORDER BY id LIMIT 1
);

SET @item_sgd_id := (
    SELECT id FROM item
    WHERE modulo_id = @mod_sgd_id AND (item_padre_id IS NULL OR item_padre_id = 0)
    ORDER BY id LIMIT 1
);

INSERT INTO item (modulo_id, nombre, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_sgd_id, 'Secciones documentales', 'sgd/secciones', '📑', 45, @item_sgd_id, 1
WHERE @mod_sgd_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM item WHERE ruta = 'sgd/secciones' LIMIT 1);

INSERT INTO item (modulo_id, nombre, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_sgd_id, 'Elaboración maestro', 'sgd/elaboracion', '✍️', 91, @item_sgd_id, 1
WHERE @mod_sgd_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM item WHERE ruta = 'sgd/elaboracion' LIMIT 1);

INSERT INTO item_accion (item_id, accion_id, estado_id)
SELECT i.id, a.id, 1
FROM item i
CROSS JOIN accion a
WHERE i.ruta IN ('sgd/secciones', 'sgd/elaboracion')
  AND a.codigo IN ('ver', 'guardar', 'eliminar')
  AND NOT EXISTS (
      SELECT 1 FROM item_accion ia WHERE ia.item_id = i.id AND ia.accion_id = a.id
  );

INSERT INTO rol_permiso (rol_id, empresa_id, sede_id, item_accion_id, estado_id)
SELECT 1, NULL, NULL, ia.id, 5
FROM item_accion ia
INNER JOIN item i ON i.id = ia.item_id
WHERE i.ruta IN ('sgd/secciones', 'sgd/elaboracion')
  AND NOT EXISTS (
      SELECT 1 FROM rol_permiso rp
      WHERE rp.rol_id = 1 AND rp.item_accion_id = ia.id AND rp.estado_id = 5
  );

INSERT INTO `accion` (`nombre`, `codigo`, `descripcion`, `orden`, `icono`, `accion_codigo`, `estado_id`)
SELECT
    'Perfil secciones M4',
    'perfil_secciones',
    'Define qué secciones aplican, no aplican u son opcionales para tipos maestro.',
    16,
    '📑',
    'sgd_tipo_secciones',
    1
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM `accion` WHERE `accion_codigo` = 'sgd_tipo_secciones' LIMIT 1
);

SET @accion_secciones_id := (SELECT `id` FROM `accion` WHERE `accion_codigo` = 'sgd_tipo_secciones' LIMIT 1);

INSERT INTO `item_accion` (`item_id`, `accion_id`, `estado_id`)
SELECT i.`id`, @accion_secciones_id, 1
FROM `item` i
WHERE i.`ruta` = 'sgd_tipo_documental'
  AND @accion_secciones_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM `item_accion` ia
      WHERE ia.`item_id` = i.`id` AND ia.`accion_id` = @accion_secciones_id
  );

INSERT INTO `rol_permiso` (`rol_id`, `empresa_id`, `sede_id`, `item_accion_id`, `estado_id`)
SELECT 1, NULL, NULL, ia.`id`, 5
FROM `item_accion` ia
INNER JOIN `item` i ON i.`id` = ia.`item_id`
INNER JOIN `accion` a ON a.`id` = ia.`accion_id`
WHERE i.`ruta` = 'sgd_tipo_documental'
  AND a.`accion_codigo` = 'sgd_tipo_secciones'
  AND NOT EXISTS (
      SELECT 1 FROM `rol_permiso` rp
      WHERE rp.`rol_id` = 1 AND rp.`item_accion_id` = ia.`id` AND rp.estado_id = 5
  );
