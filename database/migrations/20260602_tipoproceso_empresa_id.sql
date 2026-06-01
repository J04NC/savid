/* tipoproceso.empresa_id — catálogo por empresa (Lab Central = id 1) */
/* Ejecutar: php scripts/apply_tipoproceso_empresa_id.php */

ALTER TABLE `tipoproceso`
    ADD COLUMN `empresa_id` INT NOT NULL DEFAULT 1
        COMMENT 'label:Empresa|rel:tercero|label:razon_social|title:Razón social de la empresa'
        AFTER `id`;

UPDATE `tipoproceso` SET `empresa_id` = 1 WHERE `empresa_id` IS NULL OR `empresa_id` = 0;

ALTER TABLE `tipoproceso`
    MODIFY COLUMN `empresa_id` INT NOT NULL;

ALTER TABLE `tipoproceso`
    DROP INDEX `uk_tipoproceso_codigo`,
    ADD UNIQUE KEY `uk_tipoproceso_empresa_codigo` (`empresa_id`, `codigo`),
    ADD KEY `idx_tipoproceso_empresa` (`empresa_id`);

ALTER TABLE `tipoproceso`
    ADD CONSTRAINT `fk_tipoproceso_empresa`
        FOREIGN KEY (`empresa_id`) REFERENCES `empresa` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE;
