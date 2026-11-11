/* ============================================================
   SIHOS — nueva acción "Corregir profesional" sobre el ítem sihos/liquidacion:
   desde el modal de detalle del reporte Liquidación, corrige en SIHOS
   (DetaFact.CodiMedi) el profesional de las líneas marcadas con el profesional
   inferido del reporte (confianza Alta o Media), solo en liquidaciones
   confirmadas y no anuladas. Acción propia, separada de 'ver': el reporte
   sigue siendo de lectura para quien no la tenga. Se asigna a los roles desde
   la matriz de roles, como las demás acciones de escritura SIHOS.
============================================================ */
INSERT INTO accion (codigo, nombre)
SELECT 'corregir_profesional', 'Corregir profesional'
WHERE NOT EXISTS (SELECT 1 FROM accion WHERE codigo = 'corregir_profesional');
INSERT INTO item_accion (item_id, accion_id, estado_id)
SELECT i.id, a.id, 1
FROM item i
INNER JOIN accion a ON a.codigo = 'corregir_profesional'
WHERE i.ruta = 'sihos/liquidacion'
  AND NOT EXISTS (SELECT 1 FROM item_accion ia WHERE ia.item_id = i.id AND ia.accion_id = a.id);
