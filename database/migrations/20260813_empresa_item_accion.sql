/* ============================================================
   Botón "Ítems" en la pantalla de Empresa — abre el modal para habilitar
   qué ítems del menú puede usar esa empresa. Sin fila en permiso/rol_permiso
   a propósito: solo superadmin lo ve (bypass general de PermisoService),
   y el controller (EmpresaController::items) también valida superadmin
   por su cuenta como defensa adicional.
============================================================ */

INSERT INTO `accion` (nombre, codigo, descripcion, orden, icono, accion_codigo, estado_id)
VALUES ('Ítems Empresa', 'items_empresa', 'Ítems del menú habilitados para la empresa', 7, '🧩', 'empresa_items', 1);

INSERT INTO item_accion (item_id, accion_id, estado_id)
SELECT i.id, a.id, 1
FROM item i
JOIN accion a ON a.accion_codigo = 'empresa_items'
WHERE i.ruta = 'empresa';
