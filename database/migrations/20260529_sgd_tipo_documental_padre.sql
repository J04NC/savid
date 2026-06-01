-- Tipos documentales: qué tipos pueden ser padre de otro tipo (reglas por empresa)
-- Semilla inicial desde relaciones reales en sgd_documento (hijo → padre por tipo)

CREATE TABLE IF NOT EXISTS `sgd_tipo_documental_padre` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `empresa_id` INT NOT NULL,
    `tipo_documental_id` INT UNSIGNED NOT NULL COMMENT 'Tipo del documento hijo|rel:sgd_tipo_documental|label:codigo',
    `tipo_padre_id` INT UNSIGNED NOT NULL COMMENT 'Tipo permitido como padre|rel:sgd_tipo_documental|label:codigo',
    `estado_id` INT UNSIGNED NOT NULL DEFAULT 1,
    `created_at` DATETIME(3) NULL DEFAULT NULL,
    `created_by` INT UNSIGNED NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_sgd_tipo_doc_padre` (`empresa_id`, `tipo_documental_id`, `tipo_padre_id`),
    KEY `idx_sgd_tipo_doc_padre_hijo` (`tipo_documental_id`),
    KEY `idx_sgd_tipo_doc_padre_padre` (`tipo_padre_id`),
    CONSTRAINT `fk_sgd_tipo_doc_padre_empresa`
        FOREIGN KEY (`empresa_id`) REFERENCES `empresa` (`id`),
    CONSTRAINT `fk_sgd_tipo_doc_padre_hijo`
        FOREIGN KEY (`tipo_documental_id`) REFERENCES `sgd_tipo_documental` (`id`),
    CONSTRAINT `fk_sgd_tipo_doc_padre_tipo_padre`
        FOREIGN KEY (`tipo_padre_id`) REFERENCES `sgd_tipo_documental` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pares hijo/padre observados en documentos (documento_id → tipo del padre)
INSERT INTO `sgd_tipo_documental_padre` (`empresa_id`, `tipo_documental_id`, `tipo_padre_id`, `estado_id`, `created_at`)
SELECT DISTINCT
    d.`empresa_id`,
    d.`tipo_documental_id`,
    pad.`tipo_documental_id`,
    1,
    NOW(3)
FROM `sgd_documento` d
INNER JOIN `sgd_documento` pad ON pad.`id` = d.`documento_id`
WHERE d.`documento_id` IS NOT NULL
  AND d.`tipo_documental_id` IS NOT NULL
  AND pad.`tipo_documental_id` IS NOT NULL
  AND d.`tipo_documental_id` <> pad.`tipo_documental_id`
  AND d.`deleted_at` IS NULL
  AND pad.`deleted_at` IS NULL
ON DUPLICATE KEY UPDATE `estado_id` = VALUES(`estado_id`);

-- Acción modal en CRUD Tipos documentales
INSERT INTO `accion` (`nombre`, `codigo`, `descripcion`, `orden`, `icono`, `accion_codigo`, `estado_id`)
SELECT
    'Padres permitidos',
    'padres_permitidos',
    'Configura qué tipos documentales pueden ser padre de este tipo.',
    15,
    '🔗',
    'sgd_tipo_padres',
    1
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM `accion` WHERE `accion_codigo` = 'sgd_tipo_padres' LIMIT 1
);

SET @accion_padres_id := (SELECT `id` FROM `accion` WHERE `accion_codigo` = 'sgd_tipo_padres' LIMIT 1);

INSERT INTO `item_accion` (`item_id`, `accion_id`, `estado_id`)
SELECT i.`id`, @accion_padres_id, 1
FROM `item` i
WHERE i.`ruta` = 'sgd_tipo_documental'
  AND @accion_padres_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM `item_accion` ia
      WHERE ia.`item_id` = i.`id` AND ia.`accion_id` = @accion_padres_id
  );

INSERT INTO `rol_permiso` (`rol_id`, `empresa_id`, `sede_id`, `item_accion_id`, `estado_id`)
SELECT 1, NULL, NULL, ia.`id`, 5
FROM `item_accion` ia
INNER JOIN `item` i ON i.`id` = ia.`item_id`
INNER JOIN `accion` a ON a.`id` = ia.`accion_id`
WHERE i.`ruta` = 'sgd_tipo_documental'
  AND a.`accion_codigo` = 'sgd_tipo_padres'
  AND NOT EXISTS (
      SELECT 1 FROM `rol_permiso` rp
      WHERE rp.`rol_id` = 1 AND rp.`item_accion_id` = ia.`id` AND rp.`estado_id` = 5
  );
