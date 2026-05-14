-- =============================================================================
-- Acción especial CRUD: modal "Identificaciones" en el ítem `tercero`
-- =============================================================================
-- Requisitos:
--   • Debe existir un ítem con `item.ruta = 'tercero'` (CRUD maestro tercero).
--   • Tabla `terceroidentificacion` (opcional para el listado del modal; si no existe, el modal avisa).
--   • Tabla `tipodocumento` recomendada (LEFT JOIN en el controlador).
--
-- Clave JS: `accion.accion_codigo` = `tercero_identificaciones` (ver `public/js/crud.js` → modales).
--
-- Permiso al rol administrador: por defecto `rol_id = 1`. Si su superadmin usa otro rol,
--   cambie el valor en el INSERT de `rol_permiso` o añada más INSERTs.
--
-- Nota: usuarios con `es_super_admin` en sesión ya pasan `PermisoService::can()` sin revisar matriz;
--   este `rol_permiso` sirve para el rol con ID 1 (matriz / usuarios sin flag superadmin).
-- =============================================================================

-- 1) Catálogo de acción (una fila por código reutilizable entre ítems)
INSERT INTO `accion` (`nombre`, `icono`, `codigo`, `accion_codigo`, `orden`, `estado_id`)
SELECT 'Identificaciones', '📇', 'tercero_identificaciones', 'tercero_identificaciones', 90, 1
FROM (SELECT 1 AS `_x`) AS `_d`
WHERE NOT EXISTS (
    SELECT 1 FROM `accion` `a` WHERE `a`.`accion_codigo` = 'tercero_identificaciones' LIMIT 1
);

-- 2) Vincular la acción al ítem cuya ruta es `tercero`
INSERT INTO `item_accion` (`item_id`, `accion_id`, `estado_id`)
SELECT `i`.`id`, `a`.`id`, 1
FROM `item` `i`
JOIN `accion` `a` ON `a`.`accion_codigo` = 'tercero_identificaciones'
LEFT JOIN `item_accion` `ia` ON `ia`.`item_id` = `i`.`id` AND `ia`.`accion_id` = `a`.`id`
WHERE `i`.`ruta` = 'tercero'
  AND `ia`.`id` IS NULL;

-- 3) Permitir la acción al rol 1 (ajuste el rol_id si aplica)
INSERT INTO `rol_permiso` (`rol_id`, `empresa_id`, `sede_id`, `item_accion_id`, `estado_id`)
SELECT 1, NULL, NULL, `ia`.`id`, 5
FROM `item_accion` `ia`
JOIN `item` `i` ON `i`.`id` = `ia`.`item_id`
JOIN `accion` `a` ON `a`.`id` = `ia`.`accion_id`
LEFT JOIN `rol_permiso` `rp`
    ON `rp`.`rol_id` = 1
   AND `rp`.`item_accion_id` = `ia`.`id`
   AND `rp`.`empresa_id` IS NULL
   AND `rp`.`sede_id` IS NULL
WHERE `i`.`ruta` = 'tercero'
  AND `a`.`accion_codigo` = 'tercero_identificaciones'
  AND `rp`.`item_accion_id` IS NULL;
