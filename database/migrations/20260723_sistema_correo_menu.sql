/* ============================================================
   Configuración del sistema (correo saliente) — pantalla de solo lectura
   + botón de prueba, restringida a superadmin (sin permiso otorgado a
   otros roles). Controller propio (SistemaController), no motor CRUD.
============================================================ */

INSERT INTO `item` (modulo_id, item_padre_id, nombre, ruta, icono, orden, estado_id)
VALUES (1, 14, 'CONFIGURACION CORREO', 'sistema', '📧', 40, 1);

INSERT INTO item_accion (item_id, accion_id, estado_id)
SELECT i.id, a.id, 1
FROM item i
JOIN accion a ON a.codigo = 'ver'
WHERE i.ruta = 'sistema';
