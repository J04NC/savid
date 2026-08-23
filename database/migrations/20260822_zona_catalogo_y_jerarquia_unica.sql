/* ============================================================
   Rediseño del territorio: `zona` pasa a ser catálogo y la jerarquía
   urbana/rural se unifica en una sola (modelo tomado de SIHOS).

   ANTES
     municipio (1122)
        └── zona (2244 = una fila "urbana" y otra "rural" POR municipio)
              ├── comuna ──────── barrio      (rama urbana)
              └── corregimiento ── vereda     (rama rural)

   DESPUÉS  (equivalente a CodiZona / CodiComu / CodiBarr de SIHOS)
     zona (2 filas: U=Urbana, R=Rural)   ← catálogo puro, con código SISPRO
     municipio (1122)
        └── comuna (municipio_id + zona_id) ── barrio

   Por qué: "urbana/rural" es una clasificación de la dirección, no una
   entidad territorial. Materializarla como 2244 filas obligaba a que un
   `zona_id` mezclara dos cosas (qué municipio + urbano/rural), duplicaba
   cuatro tablas estructuralmente idénticas (comuna/corregimiento y
   barrio/vereda) y arrastraba el toggle de JS en el formulario de tercero.
   Un corregimiento es una comuna con zona rural; una vereda, un barrio
   con zona rural.

   Momento: hoy la rama rural no tiene ni un solo uso
   (tercero.corregimiento_id y tercero.vereda_id = 0 filas) y solo 3 de
   1122 municipios tienen comunas cargadas. Más adelante sería costoso.

   Respaldo previo: storage/backups/territorio_pre_rediseno_*.sql
   ============================================================ */

SET FOREIGN_KEY_CHECKS = 0;

/* ------------------------------------------------------------
   1. Tablas de trabajo con el mapeo viejo -> nuevo
   ------------------------------------------------------------ */

DROP TABLE IF EXISTS _mig_zona_map;
CREATE TABLE _mig_zona_map (
    zona_id_viejo INT UNSIGNED NOT NULL PRIMARY KEY,
    municipio_id  INT UNSIGNED NOT NULL,
    tipo          ENUM('urbana','rural') NOT NULL,
    zona_id_nuevo INT UNSIGNED NULL
);

INSERT INTO _mig_zona_map (zona_id_viejo, municipio_id, tipo)
SELECT id, municipio_id, tipo FROM zona;

/* ------------------------------------------------------------
   2. `zona` se reconstruye como catálogo de 2 filas
   ------------------------------------------------------------ */

ALTER TABLE zona DROP FOREIGN KEY fk_zona_municipio;

DROP TABLE IF EXISTS zona_nueva;
CREATE TABLE zona_nueva (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    codigo        VARCHAR(20) NOT NULL COMMENT 'uppercase|order:10|title:U = urbana, R = rural',
    nombre        VARCHAR(200) NOT NULL COMMENT 'order:20',
    tipo          ENUM('urbana','rural') NOT NULL COMMENT 'order:30|title:Clasificación urbano/rural de la dirección',
    codigo_sispro CHAR(2) NULL COMMENT 'order:40|title:Código oficial de reporte nacional (01 urbana, 02 rural)',
    estado_id     SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'label:Estado|reltipo:GENERAL',
    created_at    TIMESTAMP NULL DEFAULT NULL COMMENT 'show:none',
    updated_at    TIMESTAMP NULL DEFAULT NULL COMMENT 'show:none',
    deleted_at    DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    deleted_by    INT UNSIGNED NULL COMMENT 'show:none',
    created_by    INT UNSIGNED NULL COMMENT 'show:none',
    updated_by    INT UNSIGNED NULL COMMENT 'show:none',
    UNIQUE KEY uk_zona_codigo (codigo),
    UNIQUE KEY uk_zona_tipo (tipo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO zona_nueva (codigo, nombre, tipo, codigo_sispro, estado_id) VALUES
    ('U', 'Urbana', 'urbana', '01', 1),
    ('R', 'Rural',  'rural',  '02', 1);

UPDATE _mig_zona_map m
JOIN zona_nueva zn ON zn.tipo = m.tipo
SET m.zona_id_nuevo = zn.id;

/* ------------------------------------------------------------
   3. `comuna` recibe municipio_id y repunta zona_id al catálogo
   ------------------------------------------------------------ */

ALTER TABLE comuna DROP FOREIGN KEY fk_comuna_zona;

/* El único viejo era (zona_id, codigo). Como zona_id pasa a valer 1 de 2, el
   mismo código en municipios distintos chocaría; se sustituye más abajo por
   (municipio_id, zona_id, codigo). */
ALTER TABLE comuna DROP INDEX uk_comuna_zona_codigo;

ALTER TABLE comuna
    ADD COLUMN municipio_id INT UNSIGNED NULL COMMENT 'rel:municipio|label:nombre|relmode:autocomplete|order:10' AFTER id,
    /* Columna puente para reenlazar veredas sin depender de coincidencias
       de nombre/código; se elimina al final. */
    ADD COLUMN _mig_corregimiento_id INT UNSIGNED NULL;

UPDATE comuna c
JOIN _mig_zona_map m ON m.zona_id_viejo = c.zona_id
SET c.municipio_id = m.municipio_id,
    c.zona_id      = m.zona_id_nuevo;

/* ------------------------------------------------------------
   4. corregimiento -> comuna  y  vereda -> barrio  (zona rural)
   ------------------------------------------------------------ */

INSERT INTO comuna (municipio_id, zona_id, codigo, nombre, estado_id,
                    created_at, updated_at, created_by, updated_by,
                    _mig_corregimiento_id)
SELECT m.municipio_id, m.zona_id_nuevo, cg.codigo, cg.nombre, cg.estado_id,
       cg.created_at, cg.updated_at, cg.created_by, cg.updated_by,
       cg.id
FROM corregimiento cg
JOIN _mig_zona_map m ON m.zona_id_viejo = cg.zona_id;

/* Las veredas cuelgan de la comuna creada a partir de su corregimiento. */
INSERT INTO barrio (comuna_id, codigo, nombre, estado_id,
                    created_at, updated_at, created_by, updated_by)
SELECT c.id, v.codigo, v.nombre, v.estado_id,
       v.created_at, v.updated_at, v.created_by, v.updated_by
FROM vereda v
JOIN comuna c ON c._mig_corregimiento_id = v.corregimiento_id;

/* ------------------------------------------------------------
   5. `tercero`: repunta zona_id y absorbe las columnas rurales
   ------------------------------------------------------------ */

ALTER TABLE tercero DROP FOREIGN KEY fk_tercero_zona;
ALTER TABLE tercero DROP FOREIGN KEY fk_tercero_corregiminento;
ALTER TABLE tercero DROP FOREIGN KEY fk_tercero_vereda;

UPDATE tercero t
JOIN _mig_zona_map m ON m.zona_id_viejo = t.zona_id
SET t.zona_id = m.zona_id_nuevo;

/* Direcciones rurales existentes (hoy 0 filas) pasan a comuna/barrio. */
UPDATE tercero t
JOIN comuna c ON c._mig_corregimiento_id = t.corregimiento_id
SET t.comuna_id = c.id
WHERE t.comuna_id IS NULL;

UPDATE tercero t
JOIN vereda v ON v.id = t.vereda_id
JOIN comuna c ON c._mig_corregimiento_id = v.corregimiento_id
JOIN barrio b ON b.comuna_id = c.id AND b.nombre <=> v.nombre AND b.codigo <=> v.codigo
SET t.barrio_id = b.id
WHERE t.barrio_id IS NULL;

ALTER TABLE tercero
    DROP COLUMN corregimiento_id,
    DROP COLUMN vereda_id;

/* ------------------------------------------------------------
   6. Sustituye zona y elimina las tablas de la rama rural
   ------------------------------------------------------------ */

DROP TABLE zona;
RENAME TABLE zona_nueva TO zona;

DROP TABLE vereda;
DROP TABLE corregimiento;

/* ------------------------------------------------------------
   7. Claves foráneas e índices definitivos
   ------------------------------------------------------------ */

ALTER TABLE comuna
    DROP COLUMN _mig_corregimiento_id,
    MODIFY COLUMN municipio_id INT UNSIGNED NOT NULL
        COMMENT 'rel:municipio|label:nombre|relmode:autocomplete|order:10',
    ADD CONSTRAINT fk_comuna_municipio FOREIGN KEY (municipio_id) REFERENCES municipio (id),
    ADD CONSTRAINT fk_comuna_zona FOREIGN KEY (zona_id) REFERENCES zona (id),
    ADD UNIQUE KEY uk_comuna_muni_zona_codigo (municipio_id, zona_id, codigo);

ALTER TABLE tercero
    ADD CONSTRAINT fk_tercero_zona FOREIGN KEY (zona_id) REFERENCES zona (id);

DROP TABLE _mig_zona_map;

SET FOREIGN_KEY_CHECKS = 1;
