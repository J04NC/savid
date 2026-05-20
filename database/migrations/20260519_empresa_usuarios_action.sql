/* ============================================================
   Migración: registrar la acción "Usuarios Empresa" para el ítem empresa
   Habilita el botón en el CRUD que abre el modal de gestión de usuarios
   vinculados a una empresa (?url=empresa/usuarios/{empresaId}).
============================================================ */

/* 1) Crear la acción global (similar a 'Sede Empresa', 'Roles Usuario', etc.) */
INSERT INTO accion (
  nombre, codigo, descripcion, orden, icono, accion_codigo, estado_id
) VALUES (
  'Usuarios Empresa',
  'usuarios_empresa',
  'Modal para gestionar los usuarios vinculados a la empresa.',
  6,
  '👥',
  'empresa_usuarios',
  1
);

SET @nueva_accion_id := LAST_INSERT_ID();

/* 2) Vincular la acción al ítem empresa */
INSERT INTO item_accion (item_id, accion_id, estado_id)
SELECT i.id, @nueva_accion_id, 1
FROM item i
WHERE i.ruta = 'empresa';

/* 3) Verificación opcional:
   SELECT * FROM accion WHERE accion_codigo = 'empresa_usuarios';
   SELECT * FROM item_accion WHERE accion_id = (SELECT id FROM accion WHERE accion_codigo='empresa_usuarios');
*/
