/* ============================================================
   empresa_item: qué ítems del menú puede usar cada empresa. El superadmin
   lo administra desde el botón "Ítems" en la pantalla de Empresa. Se usa
   para filtrar tanto el menú lateral como las matrices de asignación de
   permisos (rol/permisos, usuario/permisos) y como bloqueo duro en
   PermisoService::can().

   Arranca vacía a propósito (sin seed): el superadmin habilita ítem por
   ítem, empresa por empresa. Mientras una empresa no tenga filas aquí,
   sus usuarios no verán ningún ítem en el menú ni podrán acceder a
   ninguna ruta (salvo superadmin, que siempre pasa por alto este filtro).
============================================================ */

CREATE TABLE IF NOT EXISTS `empresa_item` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `empresa_id` INT NOT NULL,
    `item_id` INT NOT NULL,
    `estado_id` SMALLINT UNSIGNED NOT NULL DEFAULT 1,

    `created_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `created_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    `updated_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `updated_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    `deleted_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `deleted_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_empresa_item` (`empresa_id`, `item_id`),
    KEY `idx_empresa_item_item` (`item_id`),
    CONSTRAINT `fk_empresa_item_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresa` (`id`),
    CONSTRAINT `fk_empresa_item_item` FOREIGN KEY (`item_id`) REFERENCES `item` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
