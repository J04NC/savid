/* ============================================================
   Otorgar version_eliminar a roles que ya tienen guardar en sgd/documentos
   Ejecutar: mysql savid < database/migrations/20260610_sgd_version_eliminar_grant_guardar_roles.sql
============================================================ */

INSERT INTO rol_permiso (rol_id, empresa_id, sede_id, item_accion_id, estado_id)
SELECT DISTINCT rp_guardar.rol_id, rp_guardar.empresa_id, rp_guardar.sede_id, ia_del.id, 5
FROM rol_permiso rp_guardar
INNER JOIN item_accion ia_guardar ON ia_guardar.id = rp_guardar.item_accion_id
INNER JOIN item i ON i.id = ia_guardar.item_id
INNER JOIN accion a_guardar ON a_guardar.id = ia_guardar.accion_id
INNER JOIN item_accion ia_del ON ia_del.item_id = i.id
INNER JOIN accion a_del ON a_del.id = ia_del.accion_id
WHERE i.ruta = 'sgd/documentos'
  AND a_guardar.codigo = 'guardar'
  AND a_del.codigo = 'version_eliminar'
  AND rp_guardar.estado_id = 5
  AND NOT EXISTS (
      SELECT 1 FROM rol_permiso rp2
      WHERE rp2.rol_id = rp_guardar.rol_id
        AND rp2.item_accion_id = ia_del.id
        AND rp2.empresa_id <=> rp_guardar.empresa_id
        AND rp2.sede_id <=> rp_guardar.sede_id
        AND rp2.estado_id = 5
  );
