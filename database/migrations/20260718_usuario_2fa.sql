/* ============================================================
   Doble factor de autenticación (2FA) por correo — opt-in por usuario
   Usado por TwoFactorService / TwoFactorRepository
============================================================ */

ALTER TABLE `usuario`
    ADD COLUMN `two_factor_enabled` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'type:checkbox|order:95|label:Doble factor (correo)|title:Pide un código enviado al correo en cada inicio de sesión desde un dispositivo nuevo'
    AFTER `sesion_idle_minutos`;

CREATE TABLE IF NOT EXISTS `usuario_2fa_codigo` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `usuario_id` INT UNSIGNED NOT NULL,
    `code_hash` CHAR(64) NOT NULL COMMENT 'sha256 del código de 6 dígitos; el código en claro nunca se persiste',
    `intentos` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `expires_at` DATETIME(3) NOT NULL,
    `used_at` DATETIME(3) NULL DEFAULT NULL,
    `ip_origen` VARCHAR(45) NULL,
    `created_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `created_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    PRIMARY KEY (`id`),
    KEY `idx_usuario_2fa_codigo_usuario` (`usuario_id`),
    KEY `idx_usuario_2fa_codigo_vigencia` (`usuario_id`, `used_at`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `usuario_2fa_dispositivo` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `usuario_id` INT UNSIGNED NOT NULL,
    `token_hash` CHAR(64) NOT NULL COMMENT 'sha256 del token de la cookie "recordar dispositivo"',
    `expires_at` DATETIME(3) NOT NULL,
    `revoked_at` DATETIME(3) NULL DEFAULT NULL,
    `ip_origen` VARCHAR(45) NULL,
    `user_agent` VARCHAR(255) NULL,
    `created_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_usuario_2fa_dispositivo_token_hash` (`token_hash`),
    KEY `idx_usuario_2fa_dispositivo_usuario` (`usuario_id`, `revoked_at`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
