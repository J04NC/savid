/* ============================================================
   SIHOS — nueva acción "Concluir en SIHOS" sobre el ítem sihos/auditoriaGlosa:
   escribe directamente en SIHOS (AnotGlos + reversa de la cuenta de orden
   "en trámite" 8333/8915 vía un nuevo documento GLC + AnotCeCo + DetaFaCr)
   para las glosas "en curso" cuya factura referenciada ya tiene saldo de
   cartera $0 (hallazgo central del reporte). Ver
   SihosGlosaConclusionService::concluirAceptacionEps().

   No se reutiliza 'nota_ajuste' (id ya usado por sihos/cruce): aunque el
   patrón de escritura es el mismo, es una acción distinta sobre un reporte
   distinto — código propio 'concluir'.
============================================================ */

INSERT INTO accion (codigo, nombre)
SELECT 'concluir', 'Concluir en SIHOS'
WHERE NOT EXISTS (SELECT 1 FROM accion WHERE codigo = 'concluir');

INSERT INTO item_accion (item_id, accion_id, estado_id)
SELECT i.id, a.id, 1
FROM item i
INNER JOIN accion a ON a.codigo = 'concluir'
WHERE i.ruta = 'sihos/auditoriaGlosa'
  AND NOT EXISTS (SELECT 1 FROM item_accion ia WHERE ia.item_id = i.id AND ia.accion_id = a.id);
