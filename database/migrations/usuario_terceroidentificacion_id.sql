-- Vincula la cuenta de usuario a una fila concreta de `terceroidentificacion`
-- (identificación única: tipo + número + DV), no solo al tercero maestro.
--
-- Ejecutar una vez. Si aún existe `tercero_id`, migrar datos antes de eliminarla.

ALTER TABLE `usuario`
    ADD COLUMN `terceroidentificacion_id` INT UNSIGNED NULL DEFAULT NULL
        COMMENT 'FK terceroidentificacion — identificación de la persona'
        AFTER `estado_id`;

ALTER TABLE `usuario`
    ADD CONSTRAINT `fk_usuario_terceroidentificacion`
        FOREIGN KEY (`terceroidentificacion_id`) REFERENCES `terceroidentificacion` (`id`)
        ON UPDATE CASCADE ON DELETE SET NULL;

-- Poblar desde identificación principal del tercero (si venía de tercero_id):
-- UPDATE usuario u
-- INNER JOIN terceroidentificacion ti ON ti.tercero_id = u.tercero_id AND ti.principal = 1
-- SET u.terceroidentificacion_id = ti.id
-- WHERE u.terceroidentificacion_id IS NULL AND u.tercero_id IS NOT NULL;

-- ALTER TABLE `usuario` DROP FOREIGN KEY `fk_usuario_tercero`;
-- ALTER TABLE `usuario` DROP COLUMN `tercero_id`;
