/* ============================================================
   Botón "Subdominio" en la pantalla de Empresa — abre el modal para
   asignar el subdominio exclusivo de la empresa (crea el DNS en
   Cloudflare y luego guarda empresa.subdominio, ver
   EmpresaSubdominioAdminService). Mismo patrón que 'empresa_roles'
   (20260814_empresa_rol_accion.sql): sin fila en permiso/rol_permiso a
   propósito, solo superadmin lo ve (bypass general de PermisoService),
   y el controller (EmpresaController::subdominio) también valida
   superadmin por su cuenta como defensa adicional.
============================================================ */

INSERT INTO `accion` (nombre, codigo, descripcion, orden, icono, accion_codigo, estado_id)
VALUES ('Subdominio Empresa', 'subdominio_empresa', 'Subdominio exclusivo de la empresa', 9, '🌐', 'empresa_subdominio', 1);

INSERT INTO item_accion (item_id, accion_id, estado_id)
SELECT i.id, a.id, 1
FROM item i
JOIN accion a ON a.accion_codigo = 'empresa_subdominio'
WHERE i.ruta = 'empresa';
