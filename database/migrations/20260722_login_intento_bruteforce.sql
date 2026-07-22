/* ============================================================
   Protección de fuerza bruta en login: bitácora de intentos por
   usuario+IP, usada por LoginThrottleService para bloquear temporalmente
   tras varios fallos seguidos (5 intentos / 15 min -> bloqueo 15 min).
============================================================ */

CREATE TABLE IF NOT EXISTS `login_intento` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username` VARCHAR(150) NOT NULL COMMENT 'Tal como se escribió; puede no corresponder a un usuario real',
    `ip` VARCHAR(45) NOT NULL,
    `exitoso` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    PRIMARY KEY (`id`),
    KEY `idx_login_intento_lookup` (`username`, `ip`, `exitoso`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
