/* ============================================================
   Catálogo tipoproceso + sgd_proceso.tipoproceso_id (reemplaza tipo_proceso)
   Ejecutar: php scripts/apply_sgd_tipoproceso_migration.php
============================================================ */

CREATE TABLE IF NOT EXISTS `tipoproceso` (
    `id` TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `empresa_id` INT NOT NULL COMMENT 'label:Empresa|rel:tercero|label:razon_social|title:Razón social de la empresa',
    `codigo` VARCHAR(16) NOT NULL COMMENT 'Código corto|uppercase',
    `nombre` VARCHAR(120) NOT NULL COMMENT 'Nombre del tipo de proceso',
    `orden` SMALLINT NOT NULL DEFAULT 0,
    `estado_id` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    `created_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `created_by` INT UNSIGNED NULL COMMENT 'show:none',
    `updated_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `updated_by` INT UNSIGNED NULL COMMENT 'show:none',
    `deleted_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `deleted_by` INT UNSIGNED NULL COMMENT 'show:none',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_tipoproceso_empresa_codigo` (`empresa_id`, `codigo`),
    KEY `idx_tipoproceso_empresa` (`empresa_id`),
    KEY `idx_tipoproceso_estado` (`estado_id`),
    CONSTRAINT `fk_tipoproceso_empresa`
        FOREIGN KEY (`empresa_id`) REFERENCES `empresa` (`id`)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_tipoproceso_estado`
        FOREIGN KEY (`estado_id`) REFERENCES `estado` (`id`)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tipoproceso` (`id`, `empresa_id`, `codigo`, `nombre`, `orden`, `estado_id`, `created_at`)
VALUES
    (1, 1, 'ESTRATEGICO', 'PROCESOS ESTRATEGICOS', 10, 1, NOW(3)),
    (2, 1, 'MISIONAL', 'PROCESOS MISIONALES', 20, 1, NOW(3)),
    (3, 1, 'APOYO', 'PROCESOS DE APOYO', 30, 1, NOW(3))
ON DUPLICATE KEY UPDATE
    `empresa_id` = VALUES(`empresa_id`),
    `nombre` = VALUES(`nombre`),
    `orden` = VALUES(`orden`);
