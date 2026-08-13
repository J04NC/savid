/* ============================================================
   SIHOS — conexión por empresa. Cada empresa (tenant de SAVID) puede
   tener su propia BD de SIHOS (host/usuario/clave distintos). La clave
   se guarda cifrada (AES-256-GCM, ver app/helpers/SihosCredentialCipher.php)
   con SIHOS_CONFIG_KEY (config/.env) — nunca en texto plano.
   Complementa 20260806_sihos_menu.sql.
============================================================ */

CREATE TABLE IF NOT EXISTS `sihos_empresa_config` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `empresa_id` INT NOT NULL COMMENT 'show:none',
    `host` VARCHAR(191) NOT NULL COMMENT 'show:none',
    `puerto` SMALLINT UNSIGNED NOT NULL DEFAULT 3306 COMMENT 'show:none',
    `base_datos` VARCHAR(120) NOT NULL COMMENT 'show:none',
    `usuario` VARCHAR(120) NOT NULL COMMENT 'show:none',
    `password_cifrado` TEXT NULL COMMENT 'show:none',
    `charset` VARCHAR(20) NOT NULL DEFAULT 'utf8mb4' COMMENT 'show:none',
    `estado_id` SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'show:none',
    `created_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `created_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    `updated_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `updated_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    `deleted_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `deleted_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_sihos_empresa_config_empresa` (`empresa_id`),
    CONSTRAINT `fk_sihos_empresa_config_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresa` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_sihos_empresa_config_estado` FOREIGN KEY (`estado_id`) REFERENCES `estado` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* Acción "guardar" en el ítem de conexión (ruta 'sihos'): quién puede
   editar/probar credenciales, además de "ver" (ya existente). */
INSERT INTO item_accion (item_id, accion_id, estado_id)
SELECT i.id, a.id, 1
FROM item i
INNER JOIN accion a ON a.codigo = 'guardar'
WHERE i.ruta = 'sihos'
  AND NOT EXISTS (SELECT 1 FROM item_accion ia WHERE ia.item_id = i.id AND ia.accion_id = a.id);
