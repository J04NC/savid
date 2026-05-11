-- Alcance opcional por empresa/sede para cada fila en usuario_rol (coherente con tenantScopeSql de permiso / rol_permiso).
-- Si ya existen las columnas, omitir o ajustar manualmente.

ALTER TABLE usuario_rol
    ADD COLUMN empresa_id INT UNSIGNED NULL DEFAULT NULL COMMENT 'NULL = rol global' AFTER rol_id,
    ADD COLUMN sede_id INT UNSIGNED NULL DEFAULT NULL COMMENT 'NULL = toda la empresa o global' AFTER empresa_id;

-- Opcional: integridad referencial (ajustar nombres de FK si difieren en tu BD)
-- ALTER TABLE usuario_rol ADD CONSTRAINT fk_usuario_rol_empresa FOREIGN KEY (empresa_id) REFERENCES empresa(id);
-- ALTER TABLE usuario_rol ADD CONSTRAINT fk_usuario_rol_sede FOREIGN KEY (sede_id) REFERENCES sede(id);
