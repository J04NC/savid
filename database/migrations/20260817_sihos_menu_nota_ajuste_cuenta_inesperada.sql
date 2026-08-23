/* ============================================================
   SIHOS — nueva acción "Nota de ajuste" sobre el ítem sihos/cruce: crea una
   Nota Contabilidad (NC) en SIHOS que cancela una cuenta contable fuera de
   lo esperado (sección 5a del reporte) contra la(s) cuenta(s) 4312 que la
   factura ya tiene, repartido proporcionalmente si hay más de una.

   No se reutiliza la acción 'reversar' del catálogo (id=6): queda
   reservada para el módulo financiero propio de SAVID, aunque hoy no
   tenga uso. Esta acción usa su propio código, 'nota_ajuste'.
============================================================ */

INSERT INTO accion (codigo, nombre)
SELECT 'nota_ajuste', 'Nota de ajuste'
WHERE NOT EXISTS (SELECT 1 FROM accion WHERE codigo = 'nota_ajuste');

INSERT INTO item_accion (item_id, accion_id, estado_id)
SELECT i.id, a.id, 1
FROM item i
INNER JOIN accion a ON a.codigo = 'nota_ajuste'
WHERE i.ruta = 'sihos/cruce'
  AND NOT EXISTS (SELECT 1 FROM item_accion ia WHERE ia.item_id = i.id AND ia.accion_id = a.id);
