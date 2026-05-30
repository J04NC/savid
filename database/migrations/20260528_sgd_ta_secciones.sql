-- SGD TA: lineas documentales + normalizacion de documentos PN-TA*
-- Requiere: tablas sgd_* de F1 ya creadas.

CREATE TABLE IF NOT EXISTS `sgd_linea_documental` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `empresa_id` INT NOT NULL,
    `codigo` VARCHAR(8) NOT NULL COMMENT 'Clasificador por empresa|rel:sgd_linea_documental|label:codigo',
    `nombre` VARCHAR(120) NOT NULL,
    `orden` INT NOT NULL DEFAULT 0,
    `estado_id` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    `created_at` DATETIME(3) NULL DEFAULT NULL,
    `created_by` INT UNSIGNED NULL,
    `updated_at` DATETIME(3) NULL DEFAULT NULL,
    `updated_by` INT UNSIGNED NULL,
    `deleted_at` DATETIME(3) NULL DEFAULT NULL,
    `deleted_by` INT UNSIGNED NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_sgd_linea_documental_empresa_codigo` (`empresa_id`, `codigo`),
    KEY `idx_sgd_linea_documental_empresa` (`empresa_id`),
    KEY `idx_sgd_linea_documental_estado` (`estado_id`),
    CONSTRAINT `fk_sgd_linea_documental_empresa`
        FOREIGN KEY (`empresa_id`) REFERENCES `empresa` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_sgd_linea_documental_estado`
        FOREIGN KEY (`estado_id`) REFERENCES `estado` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `sgd_documento`
    ADD COLUMN `linea_documental_id` INT UNSIGNED NULL COMMENT 'Clasificador opcional por documento|rel:sgd_linea_documental|label:codigo' AFTER `tipo_documental_id`,
    ADD KEY `idx_sgd_documento_linea_documental` (`linea_documental_id`),
    ADD CONSTRAINT `fk_sgd_documento_linea_documental`
        FOREIGN KEY (`linea_documental_id`) REFERENCES `sgd_linea_documental` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE;

-- Tipo documental TA (tecnica analitica)
INSERT INTO `sgd_tipo_documental` (`empresa_id`, `codigo`, `nombre`, `modo`, `orden`, `estado_id`, `created_at`)
SELECT e.id, 'TA', 'Tecnica analitica', 'maestro', 55, 1, NOW(3)
FROM empresa e
WHERE NOT EXISTS (
    SELECT 1
    FROM sgd_tipo_documental t
    WHERE t.empresa_id = e.id
      AND UPPER(TRIM(t.codigo)) = 'TA'
      AND t.deleted_at IS NULL
);

-- Catalogo base de lineas documentales TA (por empresa)
INSERT INTO `sgd_linea_documental` (`empresa_id`, `codigo`, `nombre`, `orden`, `estado_id`, `created_at`)
SELECT e.id, s.codigo, s.nombre, s.orden, 1, NOW(3)
FROM empresa e
CROSS JOIN (
    SELECT 'M' AS codigo, 'Microbiologia' AS nombre, 10 AS orden
    UNION ALL SELECT 'H', 'Hematologia', 20
    UNION ALL SELECT 'I', 'Inmunologia', 30
    UNION ALL SELECT 'IH', 'Inmunohematologia', 40
    UNION ALL SELECT 'E', 'Especializado', 50
    UNION ALL SELECT 'Q', 'Quimica', 60
    UNION ALL SELECT 'O', 'Orinas', 70
    UNION ALL SELECT 'BM', 'Biologia molecular', 80
) s
WHERE NOT EXISTS (
    SELECT 1
    FROM sgd_linea_documental x
    WHERE x.empresa_id = e.id
      AND x.codigo = s.codigo
      AND x.deleted_at IS NULL
);

-- Lineas extras detectadas en base actual (evita perder trazabilidad)
INSERT INTO `sgd_linea_documental` (`empresa_id`, `codigo`, `nombre`, `orden`, `estado_id`, `created_at`)
SELECT DISTINCT
    d.empresa_id,
    CASE
        WHEN UPPER(REPLACE(d.consecutivo, ' ', '')) REGEXP '^-?PN-TAIH' THEN 'IH'
        WHEN UPPER(REPLACE(d.consecutivo, ' ', '')) REGEXP '^-?PN-TABM' THEN 'BM'
        WHEN UPPER(REPLACE(d.consecutivo, ' ', '')) REGEXP '^-?PN-TACIT' THEN 'CIT'
        WHEN UPPER(REPLACE(d.consecutivo, ' ', '')) REGEXP '^-?PN-TACO' THEN 'CO'
        WHEN UPPER(REPLACE(d.consecutivo, ' ', '')) REGEXP '^-?PN-TA([A-Z]{1,3})' THEN
            REGEXP_REPLACE(SUBSTRING_INDEX(REGEXP_REPLACE(UPPER(REPLACE(d.consecutivo, ' ', '')), '^-?PN-TA', ''), '-', 1), '[0-9]', '')
        ELSE NULL
    END AS codigo,
    'Linea TA importada',
    900,
    1,
    NOW(3)
FROM sgd_documento d
WHERE d.deleted_at IS NULL
  AND UPPER(REPLACE(d.consecutivo, ' ', '')) LIKE '%PN-TA%'
HAVING codigo IS NOT NULL
   AND codigo <> ''
   AND NOT EXISTS (
       SELECT 1
       FROM sgd_linea_documental x
       WHERE x.empresa_id = d.empresa_id
         AND x.codigo = codigo
         AND x.deleted_at IS NULL
   );

-- Nota: la normalizacion de registros PN-TA* se ejecuta con script PHP
-- (scripts/sgd_normalize_ta.php) para manejar variantes y trazabilidad.
