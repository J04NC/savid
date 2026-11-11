/* ============================================================
   SIHOS — nueva acción "Anular liquidaciones" sobre el ítem sihos/liquidacion:
   desde el modal "Liquidaciones con factura anulada" del reporte Liquidación,
   anula en SIHOS (EncaFact.Anulado = 1, CausAnul = 2) las liquidaciones vivas y
   confirmadas cuyas facturas están TODAS anuladas por una nota crédito. Acción
   propia, separada de 'ver' y de 'corregir_profesional'. Se asigna a los roles
   desde la matriz de roles, como las demás acciones de escritura SIHOS.
============================================================ */
INSERT INTO accion (codigo, nombre)
SELECT 'anular_liquidacion', 'Anular liquidaciones'
WHERE NOT EXISTS (SELECT 1 FROM accion WHERE codigo = 'anular_liquidacion');
INSERT INTO item_accion (item_id, accion_id, estado_id)
SELECT i.id, a.id, 1
FROM item i
INNER JOIN accion a ON a.codigo = 'anular_liquidacion'
WHERE i.ruta = 'sihos/liquidacion'
  AND NOT EXISTS (SELECT 1 FROM item_accion ia WHERE ia.item_id = i.id AND ia.accion_id = a.id);
