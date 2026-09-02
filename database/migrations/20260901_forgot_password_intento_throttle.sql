/* ============================================================
   Protección de abuso en "olvidé mi contraseña": bitácora de solicitudes
   por IP, usada por ForgotPasswordThrottleService para bloquear
   temporalmente tras varias solicitudes seguidas (5 solicitudes / 15 min
   -> bloqueo 15 min). login/forgotSend siempre responde el mismo mensaje
   genérico sin importar si el usuario/correo existe (anti-enumeración);
   esta tabla evita que se pueda lanzar sin límite ese endpoint.
============================================================ */

CREATE TABLE IF NOT EXISTS `forgot_password_intento` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ip` VARCHAR(45) NOT NULL,
    `identificador` VARCHAR(150) NOT NULL COMMENT 'Tal como se escribió; puede no corresponder a una cuenta real',
    `created_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    PRIMARY KEY (`id`),
    KEY `idx_forgot_password_intento_lookup` (`ip`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
