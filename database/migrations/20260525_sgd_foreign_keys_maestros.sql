-- FKs SGD → maestros (empresa, estado, usuario)
-- Ajusta tipos de columna para compatibilidad con tablas referenciadas.
-- Ejecutar después de 20260525_sgd_foreign_keys.sql

-- Tipos compatibles con empresa(id), estado(id), usuario(id)

ALTER TABLE `sgd_empresa_config`
    MODIFY COLUMN `empresa_id` INT NOT NULL,
    MODIFY COLUMN `estado_id` SMALLINT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `sgd_proceso`
    MODIFY COLUMN `empresa_id` INT NOT NULL,
    MODIFY COLUMN `estado_id` SMALLINT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `tipoproceso`
    MODIFY COLUMN `empresa_id` INT NOT NULL,
    MODIFY COLUMN `estado_id` SMALLINT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `sgd_tipo_documental`
    MODIFY COLUMN `empresa_id` INT NOT NULL,
    MODIFY COLUMN `estado_id` SMALLINT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `sgd_dependencia`
    MODIFY COLUMN `empresa_id` INT NOT NULL,
    MODIFY COLUMN `estado_id` SMALLINT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `sgd_serie`
    MODIFY COLUMN `empresa_id` INT NOT NULL,
    MODIFY COLUMN `estado_id` SMALLINT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `sgd_subserie`
    MODIFY COLUMN `empresa_id` INT NOT NULL,
    MODIFY COLUMN `estado_id` SMALLINT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `sgd_documento`
    MODIFY COLUMN `empresa_id` INT NOT NULL,
    MODIFY COLUMN `estado_id` SMALLINT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `sgd_ccd_entrada`
    MODIFY COLUMN `empresa_id` INT NOT NULL,
    MODIFY COLUMN `estado_id` SMALLINT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `sgd_import_log`
    MODIFY COLUMN `empresa_id` INT NOT NULL,
    MODIFY COLUMN `usuario_id` INT NULL;

-- Claves foráneas

ALTER TABLE `sgd_empresa_config`
    ADD CONSTRAINT `fk_sgd_empresa_config_empresa`
        FOREIGN KEY (`empresa_id`) REFERENCES `empresa` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_sgd_empresa_config_estado`
        FOREIGN KEY (`estado_id`) REFERENCES `estado` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `tipoproceso`
    ADD CONSTRAINT `fk_tipoproceso_empresa`
        FOREIGN KEY (`empresa_id`) REFERENCES `empresa` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_tipoproceso_estado`
        FOREIGN KEY (`estado_id`) REFERENCES `estado` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `sgd_proceso`
    ADD CONSTRAINT `fk_sgd_proceso_empresa`
        FOREIGN KEY (`empresa_id`) REFERENCES `empresa` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_sgd_proceso_estado`
        FOREIGN KEY (`estado_id`) REFERENCES `estado` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_sgd_proceso_tipoproceso`
        FOREIGN KEY (`tipoproceso_id`) REFERENCES `tipoproceso` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `sgd_tipo_documental`
    ADD CONSTRAINT `fk_sgd_tipo_documental_empresa`
        FOREIGN KEY (`empresa_id`) REFERENCES `empresa` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_sgd_tipo_documental_estado`
        FOREIGN KEY (`estado_id`) REFERENCES `estado` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `sgd_dependencia`
    ADD CONSTRAINT `fk_sgd_dependencia_empresa`
        FOREIGN KEY (`empresa_id`) REFERENCES `empresa` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_sgd_dependencia_estado`
        FOREIGN KEY (`estado_id`) REFERENCES `estado` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `sgd_serie`
    ADD CONSTRAINT `fk_sgd_serie_empresa`
        FOREIGN KEY (`empresa_id`) REFERENCES `empresa` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_sgd_serie_estado`
        FOREIGN KEY (`estado_id`) REFERENCES `estado` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `sgd_subserie`
    ADD CONSTRAINT `fk_sgd_subserie_empresa`
        FOREIGN KEY (`empresa_id`) REFERENCES `empresa` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_sgd_subserie_estado`
        FOREIGN KEY (`estado_id`) REFERENCES `estado` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `sgd_documento`
    ADD CONSTRAINT `fk_sgd_documento_empresa`
        FOREIGN KEY (`empresa_id`) REFERENCES `empresa` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_sgd_documento_estado`
        FOREIGN KEY (`estado_id`) REFERENCES `estado` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `sgd_ccd_entrada`
    ADD CONSTRAINT `fk_sgd_ccd_entrada_empresa`
        FOREIGN KEY (`empresa_id`) REFERENCES `empresa` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_sgd_ccd_entrada_estado`
        FOREIGN KEY (`estado_id`) REFERENCES `estado` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `sgd_import_log`
    ADD CONSTRAINT `fk_sgd_import_log_empresa`
        FOREIGN KEY (`empresa_id`) REFERENCES `empresa` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_sgd_import_log_usuario`
        FOREIGN KEY (`usuario_id`) REFERENCES `usuario` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE;
