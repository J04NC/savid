/* ============================================================
   Permiso revertir publicación vigente — sgd/documentos
   Ejecutar: mysql savid < database/migrations/20260611_sgd_version_revertir_vigente_permiso.sql
============================================================ */

INSERT INTO accion (codigo, nombre, estado_id)
SELECT 'version_revertir_vigente', 'Revertir publicación vigente', 1
WHERE NOT EXISTS (SELECT 1 FROM accion WHERE codigo = 'version_revertir_vigente' LIMIT 1);

INSERT INTO item_accion (item_id, accion_id, estado_id)
SELECT i.id, a.id, 1
FROM item i
INNER JOIN accion a ON a.codigo = 'version_revertir_vigente'
WHERE i.ruta = 'sgd/documentos'
  AND NOT EXISTS (
      SELECT 1 FROM item_accion ia
      WHERE ia.item_id = i.id AND ia.accion_id = a.id
  );

INSERT INTO rol_permiso (rol_id, empresa_id, sede_id, item_accion_id, estado_id)
SELECT 1, NULL, NULL, ia.id, 5
FROM item_accion ia
INNER JOIN item i ON i.id = ia.item_id
INNER JOIN accion a ON a.id = ia.accion_id
WHERE i.ruta = 'sgd/documentos'
  AND a.codigo = 'version_revertir_vigente'
  AND NOT EXISTS (
      SELECT 1 FROM rol_permiso rp
      WHERE rp.rol_id = 1 AND rp.item_accion_id = ia.id AND rp.estado_id = 5
  );
