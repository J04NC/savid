/* ============================================================
   Botón "Roles" en la pantalla de Empresa — abre el modal para habilitar
   qué roles puede usar esa empresa. Sin fila en permiso/rol_permiso a
   propósito: solo superadmin lo ve (bypass general de PermisoService),
   y el controller (EmpresaController::roles) también valida superadmin
   por su cuenta como defensa adicional.
============================================================ */

INSERT INTO `accion` (nombre, codigo, descripcion, orden, icono, accion_codigo, estado_id)
VALUES ('Roles Empresa', 'roles_empresa', 'Roles habilitados para la empresa', 8, '🎭', 'empresa_roles', 1);

INSERT INTO item_accion (item_id, accion_id, estado_id)
SELECT i.id, a.id, 1
FROM item i
JOIN accion a ON a.accion_codigo = 'empresa_roles'
WHERE i.ruta = 'empresa';
