/* ============================================================
   F2b — Historial de versiones del documento maestro + PDF oficial
   Ejecutar: mysql savid < database/migrations/20260604_sgd_documento_version.sql
============================================================ */

CREATE TABLE IF NOT EXISTS `sgd_documento_version` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `empresa_id` INT NOT NULL,
    `documento_id` INT UNSIGNED NOT NULL COMMENT 'rel:sgd_documento|label:nombre',
    `numero` VARCHAR(32) NOT NULL COMMENT 'Número de versión (1, 2, 8.0…)',
    `notas` TEXT NULL,
    `archivo_ruta` VARCHAR(500) NULL COMMENT 'Ruta pública del PDF oficial (/uploads/sgd/...)',
    `estado_id` SMALLINT UNSIGNED NOT NULL DEFAULT 7 COMMENT 'label:Estado documental|reltipo:DOCUMENTAL|title:Borrador, vigente u obsoleto de esta versión.',
    `fecha_aprobacion` DATE NULL,
    `es_vigente` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=versión publicada actual del documento',
    `created_at` DATETIME(3) NULL DEFAULT NULL,
    `created_by` INT UNSIGNED NULL,
    `updated_at` DATETIME(3) NULL DEFAULT NULL,
    `updated_by` INT UNSIGNED NULL,
    `deleted_at` DATETIME(3) NULL DEFAULT NULL,
    `deleted_by` INT UNSIGNED NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_sgd_doc_ver_doc_numero` (`documento_id`, `numero`),
    KEY `idx_sgd_doc_ver_empresa` (`empresa_id`),
    KEY `idx_sgd_doc_ver_documento` (`documento_id`),
    KEY `idx_sgd_doc_ver_estado` (`estado_id`),
    CONSTRAINT `fk_sgd_doc_ver_documento`
        FOREIGN KEY (`documento_id`) REFERENCES `sgd_documento` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_sgd_doc_ver_empresa`
        FOREIGN KEY (`empresa_id`) REFERENCES `empresa` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_sgd_doc_ver_estado`
        FOREIGN KEY (`estado_id`) REFERENCES `estado` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
