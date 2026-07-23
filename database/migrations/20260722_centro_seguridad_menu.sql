/* ============================================================
   Centro de seguridad — pantalla agregada de solo lectura (superadmin):
   % 2FA activo, sesiones activas por empresa, últimos intentos de login
   fallidos y cuentas inactivas. Controller propio (SeguridadController),
   no motor CRUD.
============================================================ */

INSERT INTO `item` (modulo_id, item_padre_id, nombre, ruta, icono, orden, estado_id)
VALUES (1, 14, 'CENTRO DE SEGURIDAD', 'seguridad', '🔒', 50, 1);

INSERT INTO item_accion (item_id, accion_id, estado_id)
SELECT i.id, a.id, 1
FROM item i
JOIN accion a ON a.codigo = 'ver'
WHERE i.ruta = 'seguridad';
