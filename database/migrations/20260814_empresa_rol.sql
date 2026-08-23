/* ============================================================
   empresa_rol: qué roles puede usar cada empresa (verlos en la lista de
   Roles, asignarlos a sus usuarios, editar sus permisos vía rol/permisos).
   Mismo patrón que empresa_item, pero para el catálogo de roles, que es
   global (la tabla `rol` no tiene empresa_id) y por eso hoy cualquier
   admin de empresa ve/edita roles de otras empresas.

   Sembrada con lo que cada empresa YA usa hoy (para no romper nada al
   activar el filtro): unión de
     a) roles asignados a usuarios activos de esa empresa (usuario_rol +
        usuario_empresa)
     b) roles con rol_permiso configurado explícitamente para esa empresa
============================================================ */

CREATE TABLE IF NOT EXISTS `empresa_rol` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `empresa_id` INT NOT NULL,
    `rol_id` INT NOT NULL,
    `estado_id` SMALLINT UNSIGNED NOT NULL DEFAULT 1,

    `created_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `created_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    `updated_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `updated_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    `deleted_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `deleted_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_empresa_rol` (`empresa_id`, `rol_id`),
    KEY `idx_empresa_rol_rol` (`rol_id`),
    CONSTRAINT `fk_empresa_rol_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresa` (`id`),
    CONSTRAINT `fk_empresa_rol_rol` FOREIGN KEY (`rol_id`) REFERENCES `rol` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO empresa_rol (empresa_id, rol_id, estado_id)
SELECT DISTINCT ue.empresa_id, ur.rol_id, 1
FROM usuario_rol ur
JOIN usuario_empresa ue ON ue.usuario_id = ur.usuario_id AND ue.estado_id = 1
WHERE ur.estado_id = 1;

INSERT IGNORE INTO empresa_rol (empresa_id, rol_id, estado_id)
SELECT DISTINCT rp.empresa_id, rp.rol_id, 1
FROM rol_permiso rp
WHERE rp.empresa_id IS NOT NULL AND rp.estado_id = 5;
