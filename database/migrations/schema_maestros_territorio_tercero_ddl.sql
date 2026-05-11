/*
    Script único: tipodocumento (opcional), tipopersona, ubicación y tercero.

    Jerarquía territorial:
      Departamento → Municipio → Zona (urbana | rural)
        urbana  → Comuna → Barrio
        rural   → Corregimiento → Vereda

    Requisito: tabla `estado` ya debe existir (FK estado_id).

    Seeds DIVIPOLA Colombia (departamentos, municipios, zonas plantilla + ejemplos):
      python3 database/scripts/generate_colombia_territorio_seed.py
          -o database/migrations/seed_colombia_territorio_divipola.sql
      Fuente CSV: database/seeds/source/divipola_municipios.csv

    Script único consolidado (DDL + seeds + cierre FK):
      cat schema_maestros_territorio_tercero_ddl.sql seed_colombia_territorio_divipola.sql
          schema_maestros_territorio_tercero_footer.sql > schema_maestros_territorio_tercero.sql
      (ejecutar dentro de database/migrations/)

    Si `tipodocumento` ya existe en tu BD, elimina o comenta el bloque 01.

    Si migras desde una versión anterior de este esquema (`pais.codigo`, `comuna.municipio_id`,
    `zona.comuna_id`), requiere ALTER/drops manuales — este archivo apunta a instalaciones nuevas.
*/

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

/* =============================================================================
   01 — tipodocumento (omitir si ya la tienes)
   ============================================================================= */

CREATE TABLE IF NOT EXISTS `tipodocumento` (
    `id` TINYINT(3) UNSIGNED NOT NULL AUTO_INCREMENT,
    `codigo` VARCHAR(4) NOT NULL COMMENT 'Código según resolución DIAN / RUT normativa local',
    `nombre` VARCHAR(100) NOT NULL COMMENT 'Nombre del tipo de documento',
    `estado_id` SMALLINT(5) UNSIGNED NOT NULL DEFAULT '1' COMMENT 'activo,inactivo',
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`) USING BTREE,
    UNIQUE INDEX `tipo_documento_codigo_unique` (`codigo`) USING BTREE,
    INDEX `fk_tipodocumento_estado` (`estado_id`) USING BTREE,
    CONSTRAINT `fk_tipodocumento_estado` FOREIGN KEY (`estado_id`) REFERENCES `estado` (`id`)
        ON UPDATE NO ACTION ON DELETE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* =============================================================================
   02 — tipopersona
   ============================================================================= */

CREATE TABLE IF NOT EXISTS `tipopersona` (
    `id` TINYINT(3) UNSIGNED NOT NULL AUTO_INCREMENT,
    `codigo` VARCHAR(10) NOT NULL COMMENT 'Código corto interno',
    `nombre` VARCHAR(100) NOT NULL,
    `estado_id` SMALLINT(5) UNSIGNED NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_tipopersona_codigo` (`codigo`),
    KEY `fk_tipopersona_estado` (`estado_id`),
    CONSTRAINT `fk_tipopersona_estado` FOREIGN KEY (`estado_id`) REFERENCES `estado` (`id`)
        ON UPDATE NO ACTION ON DELETE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tipopersona` (`id`, `codigo`, `nombre`, `estado_id`) VALUES
    (1, 'N', 'Persona natural', 1),
    (2, 'J', 'Persona jurídica', 1)
ON DUPLICATE KEY UPDATE `nombre` = VALUES(`nombre`);

/* =============================================================================
   03 — país (ISO 3166-1 numérico, alpha-2, alpha-3)
   ============================================================================= */

CREATE TABLE IF NOT EXISTS `pais` (
    `id` SMALLINT(5) UNSIGNED NOT NULL AUTO_INCREMENT,
    `codigo_iso_numerico` SMALLINT(5) UNSIGNED NOT NULL COMMENT 'ISO 3166-1 numeric',
    `codigo_alpha2` CHAR(2) NOT NULL COMMENT 'ISO 3166-1 alpha-2',
    `codigo_alpha3` CHAR(3) NOT NULL COMMENT 'ISO 3166-1 alpha-3',
    `nombre` VARCHAR(120) NOT NULL,
    `estado_id` SMALLINT(5) UNSIGNED NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_pais_iso_num` (`codigo_iso_numerico`),
    UNIQUE KEY `uk_pais_alpha2` (`codigo_alpha2`),
    UNIQUE KEY `uk_pais_alpha3` (`codigo_alpha3`),
    KEY `fk_pais_estado` (`estado_id`),
    CONSTRAINT `fk_pais_estado` FOREIGN KEY (`estado_id`) REFERENCES `estado` (`id`)
        ON UPDATE NO ACTION ON DELETE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `pais` (`codigo_iso_numerico`, `codigo_alpha2`, `codigo_alpha3`, `nombre`, `estado_id`) VALUES
    (170, 'CO', 'COL', 'Colombia', 1),
    (840, 'US', 'USA', 'Estados Unidos de América', 1),
    (484, 'MX', 'MEX', 'México', 1),
    (604, 'PE', 'PER', 'Perú', 1),
    (218, 'EC', 'ECU', 'Ecuador', 1),
    (862, 'VE', 'VEN', 'Venezuela', 1),
    (32,  'AR', 'ARG', 'Argentina', 1),
    (76,  'BR', 'BRA', 'Brasil', 1),
    (152, 'CL', 'CHL', 'Chile', 1),
    (591, 'PA', 'PAN', 'Panamá', 1),
    (340, 'HN', 'HND', 'Honduras', 1),
    (188, 'CR', 'CRI', 'Costa Rica', 1),
    (332, 'HT', 'HTI', 'Haití', 1),
    (214, 'DO', 'DOM', 'República Dominicana', 1),
    (192, 'CU', 'CUB', 'Cuba', 1),
    (858, 'UY', 'URY', 'Uruguay', 1),
    (600, 'PY', 'PRY', 'Paraguay', 1),
    (68,  'BO', 'BOL', 'Bolivia', 1),
    (328, 'GY', 'GUY', 'Guyana', 1),
    (740, 'SR', 'SUR', 'Suriname', 1),
    (780, 'TT', 'TTO', 'Trinidad y Tobago', 1),
    (124, 'CA', 'CAN', 'Canadá', 1),
    (826, 'GB', 'GBR', 'Reino Unido', 1),
    (724, 'ES', 'ESP', 'España', 1),
    (276, 'DE', 'DEU', 'Alemania', 1),
    (250, 'FR', 'FRA', 'Francia', 1),
    (380, 'IT', 'ITA', 'Italia', 1),
    (156, 'CN', 'CHN', 'China', 1),
    (392, 'JP', 'JPN', 'Japón', 1)
ON DUPLICATE KEY UPDATE
    `nombre` = VALUES(`nombre`),
    `codigo_iso_numerico` = VALUES(`codigo_iso_numerico`),
    `codigo_alpha3` = VALUES(`codigo_alpha3`);

/* =============================================================================
   04 — departamento
   ============================================================================= */

CREATE TABLE IF NOT EXISTS `departamento` (
    `id` SMALLINT(5) UNSIGNED NOT NULL AUTO_INCREMENT,
    `pais_id` SMALLINT(5) UNSIGNED NOT NULL,
    `codigo` VARCHAR(10) NOT NULL COMMENT 'Código DIVIPOLA/DANE departamento',
    `nombre` VARCHAR(120) NOT NULL,
    `estado_id` SMALLINT(5) UNSIGNED NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_departamento_pais_codigo` (`pais_id`, `codigo`),
    KEY `fk_departamento_pais` (`pais_id`),
    KEY `fk_departamento_estado` (`estado_id`),
    CONSTRAINT `fk_departamento_pais` FOREIGN KEY (`pais_id`) REFERENCES `pais` (`id`)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_departamento_estado` FOREIGN KEY (`estado_id`) REFERENCES `estado` (`id`)
        ON UPDATE NO ACTION ON DELETE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* =============================================================================
   05 — municipio (ciudad / municipio unificado)
   ============================================================================= */

CREATE TABLE IF NOT EXISTS `municipio` (
    `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `departamento_id` SMALLINT(5) UNSIGNED NOT NULL,
    `codigo` VARCHAR(10) NOT NULL COMMENT 'DANE municipio (ej. 5 dígitos)',
    `nombre` VARCHAR(120) NOT NULL,
    `estado_id` SMALLINT(5) UNSIGNED NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_municipio_depto_codigo` (`departamento_id`, `codigo`),
    KEY `fk_municipio_departamento` (`departamento_id`),
    KEY `fk_municipio_estado` (`estado_id`),
    KEY `idx_municipio_nombre` (`nombre`),
    CONSTRAINT `fk_municipio_departamento` FOREIGN KEY (`departamento_id`) REFERENCES `departamento` (`id`)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_municipio_estado` FOREIGN KEY (`estado_id`) REFERENCES `estado` (`id`)
        ON UPDATE NO ACTION ON DELETE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* =============================================================================
   06 — zona (urbana o rural por municipio)
   ============================================================================= */

CREATE TABLE IF NOT EXISTS `zona` (
    `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `municipio_id` INT(10) UNSIGNED NOT NULL,
    `tipo` ENUM('urbana', 'rural') NOT NULL COMMENT 'Clasificación territorio urbano vs rural',
    `codigo` VARCHAR(20) NULL COMMENT 'U = urbana plantilla, R = rural plantilla',
    `nombre` VARCHAR(200) NOT NULL,
    `estado_id` SMALLINT(5) UNSIGNED NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_zona_municipio_tipo` (`municipio_id`, `tipo`),
    KEY `fk_zona_municipio` (`municipio_id`),
    KEY `fk_zona_estado` (`estado_id`),
    CONSTRAINT `fk_zona_municipio` FOREIGN KEY (`municipio_id`) REFERENCES `municipio` (`id`)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_zona_estado` FOREIGN KEY (`estado_id`) REFERENCES `estado` (`id`)
        ON UPDATE NO ACTION ON DELETE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* =============================================================================
   07 — comuna (solo bajo zona urbana)
   ============================================================================= */

CREATE TABLE IF NOT EXISTS `comuna` (
    `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `zona_id` INT(10) UNSIGNED NOT NULL,
    `codigo` VARCHAR(20) NOT NULL,
    `nombre` VARCHAR(120) NOT NULL,
    `estado_id` SMALLINT(5) UNSIGNED NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_comuna_zona_codigo` (`zona_id`, `codigo`),
    KEY `fk_comuna_zona` (`zona_id`),
    KEY `fk_comuna_estado` (`estado_id`),
    CONSTRAINT `fk_comuna_zona` FOREIGN KEY (`zona_id`) REFERENCES `zona` (`id`)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_comuna_estado` FOREIGN KEY (`estado_id`) REFERENCES `estado` (`id`)
        ON UPDATE NO ACTION ON DELETE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* =============================================================================
   08 — barrio (bajo comuna / zona urbana)
   ============================================================================= */

CREATE TABLE IF NOT EXISTS `barrio` (
    `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `comuna_id` INT(10) UNSIGNED NOT NULL,
    `codigo` VARCHAR(20) NOT NULL,
    `nombre` VARCHAR(120) NOT NULL,
    `estado_id` SMALLINT(5) UNSIGNED NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_barrio_comuna_codigo` (`comuna_id`, `codigo`),
    KEY `fk_barrio_comuna` (`comuna_id`),
    KEY `fk_barrio_estado` (`estado_id`),
    KEY `idx_barrio_comuna_nombre` (`comuna_id`, `nombre`),
    CONSTRAINT `fk_barrio_comuna` FOREIGN KEY (`comuna_id`) REFERENCES `comuna` (`id`)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_barrio_estado` FOREIGN KEY (`estado_id`) REFERENCES `estado` (`id`)
        ON UPDATE NO ACTION ON DELETE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* =============================================================================
   09 — corregimiento (bajo zona rural)
   ============================================================================= */

CREATE TABLE IF NOT EXISTS `corregimiento` (
    `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `zona_id` INT(10) UNSIGNED NOT NULL,
    `codigo` VARCHAR(20) NOT NULL,
    `nombre` VARCHAR(120) NOT NULL,
    `estado_id` SMALLINT(5) UNSIGNED NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_corregimiento_zona_codigo` (`zona_id`, `codigo`),
    KEY `fk_corregimiento_zona` (`zona_id`),
    KEY `fk_corregimiento_estado` (`estado_id`),
    CONSTRAINT `fk_corregimiento_zona` FOREIGN KEY (`zona_id`) REFERENCES `zona` (`id`)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_corregimiento_estado` FOREIGN KEY (`estado_id`) REFERENCES `estado` (`id`)
        ON UPDATE NO ACTION ON DELETE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* =============================================================================
   10 — vereda (bajo corregimiento)
   ============================================================================= */

CREATE TABLE IF NOT EXISTS `vereda` (
    `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `corregimiento_id` INT(10) UNSIGNED NOT NULL,
    `codigo` VARCHAR(20) NOT NULL,
    `nombre` VARCHAR(120) NOT NULL,
    `estado_id` SMALLINT(5) UNSIGNED NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_vereda_corregimiento_codigo` (`corregimiento_id`, `codigo`),
    KEY `fk_vereda_corregimiento` (`corregimiento_id`),
    KEY `fk_vereda_estado` (`estado_id`),
    CONSTRAINT `fk_vereda_corregimiento` FOREIGN KEY (`corregimiento_id`) REFERENCES `corregimiento` (`id`)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_vereda_estado` FOREIGN KEY (`estado_id`) REFERENCES `estado` (`id`)
        ON UPDATE NO ACTION ON DELETE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* =============================================================================
   11 — tercero
   ============================================================================= */

CREATE TABLE IF NOT EXISTS `tercero` (
    `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `tipopersona_id` TINYINT(3) UNSIGNED NOT NULL DEFAULT 1,
    `tipodocumento_id` TINYINT(3) UNSIGNED NULL COMMENT 'FK tipodocumento',
    `doc_numero` VARCHAR(32) NULL,
    `dv` TINYINT(3) UNSIGNED NULL COMMENT 'DV NIT / dígito verificación',
    `razon_social` VARCHAR(255) NULL COMMENT 'Jurídica o nombre comercial',
    `nombres` VARCHAR(120) NULL,
    `apellidos` VARCHAR(120) NULL,
    `email` VARCHAR(190) NULL,
    `telefono` VARCHAR(40) NULL,
    `celular` VARCHAR(40) NULL,
    `direccion` VARCHAR(255) NULL,
    `municipio_id` INT(10) UNSIGNED NULL COMMENT 'Ubicación principal',
    `barrio_id` INT(10) UNSIGNED NULL COMMENT 'Urbano: barrio',
    `vereda_id` INT(10) UNSIGNED NULL COMMENT 'Rural: vereda',
    `estado_id` SMALLINT(5) UNSIGNED NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `fk_tercero_tipopersona` (`tipopersona_id`),
    KEY `fk_tercero_tipodocumento` (`tipodocumento_id`),
    KEY `fk_tercero_municipio` (`municipio_id`),
    KEY `fk_tercero_barrio` (`barrio_id`),
    KEY `fk_tercero_vereda` (`vereda_id`),
    KEY `fk_tercero_estado` (`estado_id`),
    KEY `idx_tercero_doc` (`tipodocumento_id`, `doc_numero`),
    KEY `idx_tercero_razon` (`razon_social`(100)),
    CONSTRAINT `fk_tercero_tipopersona` FOREIGN KEY (`tipopersona_id`) REFERENCES `tipopersona` (`id`)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_tercero_tipodocumento` FOREIGN KEY (`tipodocumento_id`) REFERENCES `tipodocumento` (`id`)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT `fk_tercero_municipio` FOREIGN KEY (`municipio_id`) REFERENCES `municipio` (`id`)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT `fk_tercero_barrio` FOREIGN KEY (`barrio_id`) REFERENCES `barrio` (`id`)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT `fk_tercero_vereda` FOREIGN KEY (`vereda_id`) REFERENCES `vereda` (`id`)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT `fk_tercero_estado` FOREIGN KEY (`estado_id`) REFERENCES `estado` (`id`)
        ON UPDATE NO ACTION ON DELETE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
