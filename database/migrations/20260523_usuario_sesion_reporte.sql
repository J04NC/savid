/* ============================================================
   Reporte sesiones activas — ítem id 13 (ruta: sesiones)
   Tabla usuario_sesion + permiso ver en item_accion
============================================================ */

CREATE TABLE IF NOT EXISTS `usuario_sesion` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `usuario_id` INT UNSIGNED NOT NULL,
    `php_session_id` VARCHAR(128) NULL,
    `empresa_id` INT UNSIGNED NULL,
    `sede_id` INT UNSIGNED NULL,
    `ip` VARCHAR(45) NULL,
    `user_agent` VARCHAR(255) NULL,
    `login_at` DATETIME(3) NOT NULL,
    `last_activity_at` DATETIME(3) NOT NULL,
    `logout_at` DATETIME(3) NULL,
    `logout_motivo` VARCHAR(32) NULL COMMENT 'logout, idle, admin',
    `created_at` DATETIME(3) NULL DEFAULT NULL,
    `created_by` INT UNSIGNED NULL DEFAULT NULL,
    `updated_at` DATETIME(3) NULL DEFAULT NULL,
    `updated_by` INT UNSIGNED NULL DEFAULT NULL,
    `deleted_at` DATETIME(3) NULL DEFAULT NULL,
    `deleted_by` INT UNSIGNED NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_usuario_sesion_usuario` (`usuario_id`),
    KEY `idx_usuario_sesion_login` (`login_at`),
    KEY `idx_usuario_sesion_activa` (`logout_at`, `last_activity_at`),
    KEY `idx_usuario_sesion_empresa` (`empresa_id`, `sede_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* Ítem 13: asegurar ruta y menú bajo Reportes */
UPDATE `item`
SET `nombre` = 'Sesiones activas',
    `ruta` = 'sesiones',
    `icono` = '🔐',
    `item_padre_id` = COALESCE(`item_padre_id`, (
        SELECT id FROM (
            SELECT i.id FROM item i
            WHERE LOWER(TRIM(i.nombre)) = 'reportes'
              AND (i.item_padre_id IS NULL OR i.item_padre_id = 0)
            LIMIT 1
        ) t
    )),
    `deleted_at` = NULL,
    `deleted_by` = NULL,
    `estado_id` = 1
WHERE `id` = 13;

INSERT INTO item_accion (item_id, accion_id, estado_id)
SELECT 13, a.id, 1
FROM accion a
WHERE a.codigo = 'ver'
  AND NOT EXISTS (
      SELECT 1 FROM item_accion ia WHERE ia.item_id = 13 AND ia.accion_id = a.id
  );

INSERT INTO rol_permiso (rol_id, empresa_id, sede_id, item_accion_id, estado_id)
SELECT 1, NULL, NULL, ia.id, 5
FROM item_accion ia
INNER JOIN accion a ON a.id = ia.accion_id
WHERE ia.item_id = 13
  AND a.codigo = 'ver'
  AND NOT EXISTS (
      SELECT 1 FROM rol_permiso rp
      WHERE rp.rol_id = 1 AND rp.item_accion_id = ia.id AND rp.estado_id = 5
  );
