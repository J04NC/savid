/* FKs SGD — el CRUD también usa rel: en COLUMN_COMMENT si faltan estas FK */

ALTER TABLE `sgd_serie`
    ADD CONSTRAINT `fk_sgd_serie_dependencia`
        FOREIGN KEY (`dependencia_id`) REFERENCES `sgd_dependencia` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `sgd_subserie`
    ADD CONSTRAINT `fk_sgd_subserie_serie`
        FOREIGN KEY (`serie_id`) REFERENCES `sgd_serie` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `sgd_documento`
    ADD CONSTRAINT `fk_sgd_documento_proceso`
        FOREIGN KEY (`proceso_id`) REFERENCES `sgd_proceso` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_sgd_documento_tipo`
        FOREIGN KEY (`tipo_documental_id`) REFERENCES `sgd_tipo_documental` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `sgd_ccd_entrada`
    ADD CONSTRAINT `fk_sgd_ccd_dependencia`
        FOREIGN KEY (`dependencia_id`) REFERENCES `sgd_dependencia` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_sgd_ccd_serie`
        FOREIGN KEY (`serie_id`) REFERENCES `sgd_serie` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_sgd_ccd_subserie`
        FOREIGN KEY (`subserie_id`) REFERENCES `sgd_subserie` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_sgd_ccd_documento`
        FOREIGN KEY (`documento_id`) REFERENCES `sgd_documento` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE;
