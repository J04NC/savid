/* ============================================================
   Crear/renombrar/eliminar roles ahora es exclusivo de superadmin a nivel
   de código (ModuleController). Se revocan las concesiones de guardar/
   eliminar sobre el ítem `rol` que tenían roles no-superadmin (ej.
   "Administrador"), para que la matriz de permisos no siga mostrando
   marcada una capacidad que ya no aplica.
============================================================ */

DELETE rp FROM rol_permiso rp
JOIN item_accion ia ON ia.id = rp.item_accion_id
JOIN item i ON i.id = ia.item_id
JOIN accion a ON a.id = ia.accion_id
WHERE i.ruta = 'rol'
AND a.codigo IN ('guardar', 'eliminar')
AND rp.rol_id <> 1;
