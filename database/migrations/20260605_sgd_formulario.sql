/* ============================================================
   F3a — Plantillas de formulario (elaboración / operativo)
   Ejecutar: mysql savid < database/migrations/20260605_sgd_formulario.sql
============================================================ */

CREATE TABLE IF NOT EXISTS `sgd_formulario` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `empresa_id` INT NOT NULL,
    `documento_id` INT UNSIGNED NOT NULL COMMENT 'rel:sgd_documento|label:nombre',
    `proposito` ENUM('elaboracion','operativo') NOT NULL DEFAULT 'operativo'
        COMMENT 'elaboración=maestro; operativo=registro diligenciable|type:select',
    `estado_id` SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'label:Estado|reltipo:GENERAL',
    `created_at` DATETIME(3) NULL DEFAULT NULL,
    `created_by` INT UNSIGNED NULL,
    `updated_at` DATETIME(3) NULL DEFAULT NULL,
    `updated_by` INT UNSIGNED NULL,
    `deleted_at` DATETIME(3) NULL DEFAULT NULL,
    `deleted_by` INT UNSIGNED NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_sgd_formulario_doc_proposito` (`documento_id`, `proposito`),
    KEY `idx_sgd_formulario_empresa` (`empresa_id`),
    CONSTRAINT `fk_sgd_formulario_documento`
        FOREIGN KEY (`documento_id`) REFERENCES `sgd_documento` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_sgd_formulario_empresa`
        FOREIGN KEY (`empresa_id`) REFERENCES `empresa` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sgd_formulario_version` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `empresa_id` INT NOT NULL,
    `formulario_id` INT UNSIGNED NOT NULL COMMENT 'rel:sgd_formulario|label:documento_id',
    `sede_id` INT UNSIGNED NULL COMMENT 'rel:sede|label:nombre|title:NULL=plantilla global empresa',
    `numero` VARCHAR(32) NOT NULL DEFAULT '1',
    `esquema_json` JSON NOT NULL COMMENT 'Definición de campos (diseñador)',
    `estado_id` SMALLINT UNSIGNED NOT NULL DEFAULT 7 COMMENT 'label:Estado documental|reltipo:DOCUMENTAL',
    `es_vigente` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME(3) NULL DEFAULT NULL,
    `created_by` INT UNSIGNED NULL,
    `updated_at` DATETIME(3) NULL DEFAULT NULL,
    `updated_by` INT UNSIGNED NULL,
    `deleted_at` DATETIME(3) NULL DEFAULT NULL,
    `deleted_by` INT UNSIGNED NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_sgd_form_ver_form_sede_num` (`formulario_id`, `sede_id`, `numero`),
    KEY `idx_sgd_form_ver_empresa` (`empresa_id`),
    KEY `idx_sgd_form_ver_formulario` (`formulario_id`),
    CONSTRAINT `fk_sgd_form_ver_formulario`
        FOREIGN KEY (`formulario_id`) REFERENCES `sgd_formulario` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_sgd_form_ver_empresa`
        FOREIGN KEY (`empresa_id`) REFERENCES `empresa` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_sgd_form_ver_estado`
        FOREIGN KEY (`estado_id`) REFERENCES `estado` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
SELECT @mod_sgd_id, 'Diseñador formularios', 'sgd/formularios', '📝', 92, @item_sgd_id, 1
WHERE @mod_sgd_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM item WHERE ruta = 'sgd/formularios' LIMIT 1);

INSERT INTO item_accion (item_id, accion_id, estado_id)
SELECT i.id, a.id, 1
FROM item i
CROSS JOIN accion a
WHERE i.ruta = 'sgd/formularios'
  AND a.codigo IN ('ver', 'guardar', 'eliminar')
  AND NOT EXISTS (
      SELECT 1 FROM item_accion ia WHERE ia.item_id = i.id AND ia.accion_id = a.id
  );

INSERT INTO rol_permiso (rol_id, empresa_id, sede_id, item_accion_id, estado_id)
SELECT 1, NULL, NULL, ia.id, 5
FROM item_accion ia
INNER JOIN item i ON i.id = ia.item_id
WHERE i.ruta = 'sgd/formularios'
  AND NOT EXISTS (
      SELECT 1 FROM rol_permiso rp
      WHERE rp.rol_id = 1 AND rp.item_accion_id = ia.id AND rp.estado_id = 5
  );
