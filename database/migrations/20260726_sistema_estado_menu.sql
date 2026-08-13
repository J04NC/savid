/* ============================================================
   Estado del sistema — pantalla de solo lectura (superadmin): chequeos de
   salud (MySQL, almacenamiento, sesiones, Redis) reutilizando la misma
   lógica del endpoint ?url=health, más el estado del último backup de BD.
   Controller propio (SistemaController::estado), no motor CRUD.
============================================================ */

INSERT INTO `item` (modulo_id, item_padre_id, nombre, ruta, icono, orden, estado_id)
VALUES (1, 14, 'ESTADO DEL SISTEMA', 'sistema/estado', '🩺', 60, 1);

INSERT INTO item_accion (item_id, accion_id, estado_id)
SELECT i.id, a.id, 1
FROM item i
JOIN accion a ON a.codigo = 'ver'
WHERE i.ruta = 'sistema/estado';
