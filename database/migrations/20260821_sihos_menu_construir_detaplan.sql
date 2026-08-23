/* ============================================================
   SIHOS — nueva acción "Construir DetaPlan" sobre el ítem sihos/cruce:
   construye en SIHOS la línea DetaPlan que le falta a una nota (NCF) sobre
   una factura de la misma vigencia (sección 3 del reporte), a partir del
   rubro configurado en TipoUsua para el tipo de usuario de la factura y el
   ValoTota de la nota.

   No reutiliza 'eliminar' (verbo contrario, aunque mismo dominio DetaPlan)
   ni 'nota_ajuste' (familia de ajustes contables, dominio distinto). Esta
   acción usa su propio código, 'construir_detaplan'.
============================================================ */

INSERT INTO accion (codigo, nombre)
SELECT 'construir_detaplan', 'Construir DetaPlan'
WHERE NOT EXISTS (SELECT 1 FROM accion WHERE codigo = 'construir_detaplan');

INSERT INTO item_accion (item_id, accion_id, estado_id)
SELECT i.id, a.id, 1
FROM item i
INNER JOIN accion a ON a.codigo = 'construir_detaplan'
WHERE i.ruta = 'sihos/cruce'
  AND NOT EXISTS (SELECT 1 FROM item_accion ia WHERE ia.item_id = i.id AND ia.accion_id = a.id);
