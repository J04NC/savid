/* ============================================================
   Recuperación de contraseña — tabla de tokens de un solo uso
   Usada por PasswordResetService / PasswordResetRepository
============================================================ */

CREATE TABLE IF NOT EXISTS `usuario_password_reset` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `usuario_id` INT UNSIGNED NOT NULL,
    `token_hash` CHAR(64) NOT NULL COMMENT 'sha256 del token enviado por correo; el token en claro nunca se persiste',
    `expires_at` DATETIME(3) NOT NULL,
    `used_at` DATETIME(3) NULL DEFAULT NULL,
    `ip_origen` VARCHAR(45) NULL,
    `created_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `created_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_usuario_password_reset_token_hash` (`token_hash`),
    KEY `idx_usuario_password_reset_usuario` (`usuario_id`),
    KEY `idx_usuario_password_reset_vigencia` (`usuario_id`, `used_at`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
