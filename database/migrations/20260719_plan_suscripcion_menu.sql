/* ============================================================
   Gestión de planes/suscripciones — expone plan y suscripcion vía el
   motor CRUD genérico (sin controller propio para el CRUD base) y agrega
   la acción especial "Renovar" sobre suscripcion.
   Sin filas en permiso/rol_permiso: PermisoService da acceso automático
   a superadmin y niega por defecto a cualquier otro rol.
============================================================ */

/* 1) plan.estado_id no tenía directivo de relación -> se veía como número */
ALTER TABLE `plan`
    MODIFY COLUMN `estado_id` SMALLINT UNSIGNED NULL
    COMMENT 'label:Estado|reltipo:GENERAL|title:Plan disponible o descontinuado.';

/* 2) Ítems de menú de primer nivel bajo el módulo ADMINISTRACION (modulo_id=1),
      junto con EMPRESA/USUARIO/TERCERO/ITEM/ROL (mismo modulo_id, sin padre). */
INSERT INTO `item` (modulo_id, item_padre_id, nombre, ruta, icono, orden, estado_id)
VALUES (1, NULL, 'PLANES', 'plan', '💳', 10, 1);

INSERT INTO `item` (modulo_id, item_padre_id, nombre, ruta, icono, orden, estado_id)
VALUES (1, NULL, 'SUSCRIPCIONES', 'suscripcion', '📅', 20, 1);

/* 3) Acción especial "Renovar" (accion_codigo usado por data-accion en crud.js) */
INSERT INTO `accion` (nombre, codigo, descripcion, orden, icono, accion_codigo, estado_id)
VALUES (
    'Renovar suscripción',
    'renovar_suscripcion',
    'Crea una nueva suscripción para la empresa, extendiendo la vigencia un año con el mismo plan actual.',
    91,
    '🔄',
    'renovar_suscripcion',
    1
);

/* 4) item_accion: CRUD estándar para plan (ver, limpiar, guardar, eliminar) */
INSERT INTO item_accion (item_id, accion_id, estado_id)
SELECT i.id, a.id, 1
FROM item i
JOIN accion a ON a.codigo IN ('ver', 'limpiar', 'guardar', 'eliminar')
WHERE i.ruta = 'plan';

/* 5) item_accion: CRUD estándar + Renovar para suscripcion */
INSERT INTO item_accion (item_id, accion_id, estado_id)
SELECT i.id, a.id, 1
FROM item i
JOIN accion a ON a.codigo IN ('ver', 'limpiar', 'guardar', 'eliminar', 'renovar_suscripcion')
WHERE i.ruta = 'suscripcion';
