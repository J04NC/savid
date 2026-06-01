/* ============================================================
   Tipos de estado + columna estado_tipo_id en estado
   Catálogo único; filtrar combos CRUD con |reltipo:CODIGO|
   Ejecutar: mysql savid < database/migrations/20260529_estado_tipo.sql
============================================================ */

CREATE TABLE IF NOT EXISTS `estado_tipo` (
    `id` TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `codigo` VARCHAR(32) NOT NULL COMMENT 'GENERAL, CONTABLE, PERMISO, DOCUMENTAL',
    `nombre` VARCHAR(120) NOT NULL,
    `orden` SMALLINT NOT NULL DEFAULT 0,
    `created_at` DATETIME(3) NULL DEFAULT NULL,
    `updated_at` DATETIME(3) NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_estado_tipo_codigo` (`codigo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `estado_tipo` (`id`, `codigo`, `nombre`, `orden`, `created_at`)
VALUES
    (1, 'GENERAL', 'General (activo / inactivo)', 10, NOW(3)),
    (2, 'CONTABLE', 'Contable (preliminar / confirmado)', 20, NOW(3)),
    (3, 'PERMISO', 'Permiso (permitir / denegar)', 30, NOW(3)),
    (4, 'DOCUMENTAL', 'Documental (ciclo de vida del documento)', 40, NOW(3))
ON DUPLICATE KEY UPDATE
    `nombre` = VALUES(`nombre`),
    `orden` = VALUES(`orden`);

ALTER TABLE `estado`
    ADD COLUMN `estado_tipo_id` TINYINT UNSIGNED NULL COMMENT 'rel:estado_tipo|label:codigo'
        AFTER `nombre`;

UPDATE `estado` SET `estado_tipo_id` = 1, `nombre` = 'ACTIVO' WHERE `id` = 1;
UPDATE `estado` SET `estado_tipo_id` = 1, `nombre` = 'INACTIVO' WHERE `id` = 2;
UPDATE `estado` SET `estado_tipo_id` = 2, `nombre` = 'PRELIMINAR' WHERE `id` = 3;
UPDATE `estado` SET `estado_tipo_id` = 2, `nombre` = 'CONFIRMADO' WHERE `id` = 4;
UPDATE `estado` SET `estado_tipo_id` = 3, `nombre` = 'PERMITIR' WHERE `id` = 5;
UPDATE `estado` SET `estado_tipo_id` = 3, `nombre` = 'DENEGAR' WHERE `id` = 6;

INSERT INTO `estado` (`id`, `estado_tipo_id`, `nombre`, `created_at`)
VALUES
    (7, 4, 'BORRADOR', NOW(3)),
    (8, 4, 'VIGENTE', NOW(3)),
    (9, 4, 'OBSOLETO', NOW(3)),
    (10, 4, 'FIRMADO', NOW(3))
ON DUPLICATE KEY UPDATE
    `estado_tipo_id` = VALUES(`estado_tipo_id`),
    `nombre` = VALUES(`nombre`);

UPDATE `estado` SET `estado_tipo_id` = 1 WHERE `estado_tipo_id` IS NULL;

ALTER TABLE `estado`
    MODIFY COLUMN `estado_tipo_id` TINYINT UNSIGNED NOT NULL,
    ADD KEY `idx_estado_tipo` (`estado_tipo_id`);

ALTER TABLE `estado`
    ADD CONSTRAINT `fk_estado_estado_tipo`
        FOREIGN KEY (`estado_tipo_id`) REFERENCES `estado_tipo` (`id`)
        ON UPDATE CASCADE
        ON DELETE RESTRICT;

/* Comentarios en columnas estado_id (solo tablas que ya tenían comentario o uso explícito) */

ALTER TABLE `accion`
    MODIFY COLUMN `estado_id` SMALLINT UNSIGNED NOT NULL DEFAULT 1
    COMMENT 'label:Estado|reltipo:GENERAL|title:Acción visible en menú (activa o inactiva).';

ALTER TABLE `empresa`
    MODIFY COLUMN `estado_id` SMALLINT UNSIGNED NULL DEFAULT NULL
    COMMENT 'label:Estado|reltipo:GENERAL|title:Empresa habilitada o inactiva en el sistema.';

ALTER TABLE `sede`
    MODIFY COLUMN `estado_id` SMALLINT UNSIGNED NULL DEFAULT NULL
    COMMENT 'label:Estado|reltipo:GENERAL|title:Sede operativa o inactiva.';

ALTER TABLE `item_accion`
    MODIFY COLUMN `estado_id` SMALLINT UNSIGNED NOT NULL DEFAULT 1
    COMMENT 'label:Estado|reltipo:GENERAL|title:Vínculo ítem-acción activo o inactivo.';

ALTER TABLE `tipodocumento`
    MODIFY COLUMN `estado_id` SMALLINT UNSIGNED NOT NULL DEFAULT 1
    COMMENT 'label:Estado|reltipo:GENERAL|title:Tipo de documento de identificación activo o inactivo.';

ALTER TABLE `usuario`
    MODIFY COLUMN `estado_id` SMALLINT UNSIGNED NULL DEFAULT NULL
    COMMENT 'label:Estado|reltipo:GENERAL|title:Cuenta de usuario activa o inactiva.';

ALTER TABLE `tercero`
    MODIFY COLUMN `estado_id` SMALLINT UNSIGNED NOT NULL DEFAULT 1
    COMMENT 'label:Estado|reltipo:GENERAL|title:Tercero activo o inactivo.';

ALTER TABLE `suscripcion`
    MODIFY COLUMN `estado_id` SMALLINT UNSIGNED NOT NULL DEFAULT 1
    COMMENT 'label:Estado|reltipo:GENERAL|title:Suscripción activa o inactiva.';

ALTER TABLE `sgd_proceso`
    MODIFY COLUMN `estado_id` SMALLINT UNSIGNED NOT NULL DEFAULT 1
    COMMENT 'label:Estado|reltipo:GENERAL|title:Proceso documental activo o inactivo.';

ALTER TABLE `sgd_tipo_documental`
    MODIFY COLUMN `estado_id` SMALLINT UNSIGNED NOT NULL DEFAULT 1
    COMMENT 'label:Estado|reltipo:GENERAL|title:Tipo documental activo o inactivo.';

ALTER TABLE `sgd_dependencia`
    MODIFY COLUMN `estado_id` SMALLINT UNSIGNED NOT NULL DEFAULT 1
    COMMENT 'label:Estado|reltipo:GENERAL|title:Dependencia CCD activa o inactiva.';

ALTER TABLE `sgd_serie`
    MODIFY COLUMN `estado_id` SMALLINT UNSIGNED NOT NULL DEFAULT 1
    COMMENT 'label:Estado|reltipo:GENERAL|title:Serie documental activa o inactiva.';

ALTER TABLE `sgd_subserie`
    MODIFY COLUMN `estado_id` SMALLINT UNSIGNED NOT NULL DEFAULT 1
    COMMENT 'label:Estado|reltipo:GENERAL|title:Subserie documental activa o inactiva.';

ALTER TABLE `sgd_linea_documental`
    MODIFY COLUMN `estado_id` SMALLINT UNSIGNED NOT NULL DEFAULT 1
    COMMENT 'label:Estado|reltipo:GENERAL|title:Línea documental activa o inactiva.';

ALTER TABLE `sgd_empresa_config`
    MODIFY COLUMN `estado_id` SMALLINT UNSIGNED NOT NULL DEFAULT 1
    COMMENT 'label:Estado|reltipo:GENERAL|title:Configuración SGD activa o inactiva.';

ALTER TABLE `sgd_ccd_entrada`
    MODIFY COLUMN `estado_id` SMALLINT UNSIGNED NOT NULL DEFAULT 1
    COMMENT 'label:Estado|reltipo:GENERAL|title:Entrada CCD activa o inactiva.';

ALTER TABLE `sgd_tipo_documental_padre`
    MODIFY COLUMN `estado_id` INT UNSIGNED NOT NULL DEFAULT 1
    COMMENT 'label:Estado|reltipo:GENERAL|title:Regla de tipo padre activa o inactiva.';

ALTER TABLE `rol_permiso`
    MODIFY COLUMN `estado_id` SMALLINT UNSIGNED NOT NULL DEFAULT 5
    COMMENT 'label:Estado del permiso|reltipo:PERMISO|title:Permitir o denegar esta acción para el rol en el contexto empresa/sede.';

ALTER TABLE `sgd_documento`
    MODIFY COLUMN `estado_id` SMALLINT UNSIGNED NOT NULL DEFAULT 1
    COMMENT 'label:Estado registro|reltipo:GENERAL|title:Fila del listado maestro activa o inactiva (no confundir con estado documental).';
