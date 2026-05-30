-- Renombra estructura especifica de laboratorio a termino general
-- De: sgd_seccion_analitica / seccion_analitica_id
-- A:  sgd_linea_documental / linea_documental_id

ALTER TABLE `sgd_documento`
    DROP FOREIGN KEY `fk_sgd_documento_seccion_analitica`;

ALTER TABLE `sgd_documento`
    DROP INDEX `idx_sgd_documento_seccion_analitica`,
    CHANGE COLUMN `seccion_analitica_id` `linea_documental_id` INT UNSIGNED NULL COMMENT 'Clasificador opcional por documento|rel:sgd_linea_documental|label:codigo',
    ADD KEY `idx_sgd_documento_linea_documental` (`linea_documental_id`);

RENAME TABLE `sgd_seccion_analitica` TO `sgd_linea_documental`;

ALTER TABLE `sgd_linea_documental`
    DROP FOREIGN KEY `fk_sgd_seccion_analitica_empresa`,
    DROP FOREIGN KEY `fk_sgd_seccion_analitica_estado`;

ALTER TABLE `sgd_linea_documental`
    DROP INDEX `uk_sgd_seccion_analitica_empresa_codigo`,
    DROP INDEX `idx_sgd_seccion_analitica_empresa`,
    DROP INDEX `idx_sgd_seccion_analitica_estado`,
    ADD UNIQUE KEY `uk_sgd_linea_documental_empresa_codigo` (`empresa_id`, `codigo`),
    ADD KEY `idx_sgd_linea_documental_empresa` (`empresa_id`),
    ADD KEY `idx_sgd_linea_documental_estado` (`estado_id`);

ALTER TABLE `sgd_linea_documental`
    ADD CONSTRAINT `fk_sgd_linea_documental_empresa`
        FOREIGN KEY (`empresa_id`) REFERENCES `empresa` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_sgd_linea_documental_estado`
        FOREIGN KEY (`estado_id`) REFERENCES `estado` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `sgd_documento`
    ADD CONSTRAINT `fk_sgd_documento_linea_documental`
        FOREIGN KEY (`linea_documental_id`) REFERENCES `sgd_linea_documental` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE;

UPDATE `sgd_linea_documental`
SET `nombre` = REPLACE(`nombre`, 'Seccion TA importada', 'Linea TA importada')
WHERE `nombre` LIKE 'Seccion TA importada%';

ALTER TABLE `sgd_linea_documental`
    MODIFY COLUMN `codigo` VARCHAR(8) NOT NULL COMMENT 'Clasificador por empresa|rel:sgd_linea_documental|label:codigo';
