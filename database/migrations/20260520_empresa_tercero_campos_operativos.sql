-- Empresa: datos comunes en tercero; operativos en empresa (logo, sitio web, representante legal).
-- Ejecutar una sola vez. Si una sentencia falla porque ya se aplicó, omitir esa línea.

UPDATE tercero t
INNER JOIN empresa e ON e.tercero_id = t.id
SET
    t.razon_social = COALESCE(NULLIF(TRIM(e.razon_social), ''), t.razon_social),
    t.email = COALESCE(e.email, t.email),
    t.telefono = COALESCE(e.telefono, t.telefono),
    t.direccion = COALESCE(e.direccion, t.direccion);

ALTER TABLE empresa
    ADD COLUMN sitio_web VARCHAR(255) NULL AFTER logo;

ALTER TABLE empresa
    ADD COLUMN logo2 VARCHAR(512) NULL AFTER sitio_web;

ALTER TABLE empresa
    ADD COLUMN representante_terceroidentificacion_id BIGINT UNSIGNED NULL AFTER terceroidentificacion_id;

ALTER TABLE empresa
    MODIFY COLUMN logo VARCHAR(512) NULL;

ALTER TABLE empresa
    ADD CONSTRAINT fk_empresa_rep_terceroidentificacion
        FOREIGN KEY (representante_terceroidentificacion_id)
        REFERENCES terceroidentificacion (id)
        ON DELETE SET NULL
        ON UPDATE CASCADE;

ALTER TABLE empresa
    DROP COLUMN razon_social,
    DROP COLUMN direccion,
    DROP COLUMN telefono,
    DROP COLUMN email,
    DROP COLUMN ciudad,
    DROP COLUMN contacto;
