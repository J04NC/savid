/* ============================================================
   Módulo SIHOS — "PLANILLA INTEGRADA VS SIHOS" (sihos/nominaPlanillaIntegrada)
   gana acción 'guardar': corrige en DetaNomi el VALOR de ARL y el
   TERCERO (AFP/EPS/CCF/ARL) cuando no coinciden con lo liquidado en
   el archivo, siempre sobre nóminas aún preliminares (sin causar).
   Hasta ahora la pantalla solo tenía 'ver' (reporte de solo lectura).
============================================================ */

INSERT INTO item_accion (item_id, accion_id, estado_id)
SELECT i.id, a.id, 1
FROM item i
INNER JOIN accion a ON a.codigo = 'guardar'
WHERE i.ruta = 'sihos/nominaPlanillaIntegrada'
  AND NOT EXISTS (SELECT 1 FROM item_accion ia WHERE ia.item_id = i.id AND ia.accion_id = a.id);
