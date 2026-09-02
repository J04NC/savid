/* ============================================================
   Corrige a MAYÚSCULAS el nombre de los ítems de menú del bloque
   SIHOS > Reportes > Nómina creados en las migraciones
   20260824/20260829/20260830 (que quedaron en minúscula/mixto por
   descuido) — convención del proyecto: el nombre de todo ítem de
   menú (carpeta o pantalla) va siempre en MAYÚSCULAS.

   Se identifica cada fila por `ruta` (estable) para las 3 hojas. Para
   la carpeta "Nómina" (sin ruta propia) NO basta con
   `item_padre_id = REPORTES AND ruta = ''`: REPORTES tiene otra
   carpeta hermana con ruta vacía ("Presupuesto", desde la migración
   20260812) — un WHERE así de amplio la sobreescribiría también
   (error real cometido y corregido a mano al aplicar esta migración
   por primera vez). Por eso el nombre viejo 'Nómina' SÍ es parte del
   filtro aquí — es seguro porque, a diferencia de las hojas de abajo,
   no hay guarda `<> 'NÓMINA'` que la collation insensible a
   mayúsculas/acentos pudiera neutralizar: `nombre = 'Nómina'` calza
   igual con 'NÓMINA' ya corregido (idempotente) y nunca con
   'Presupuesto'.
============================================================ */

SET @mod_sihos_id := (SELECT id FROM modulo WHERE LOWER(TRIM(nombre)) = 'sihos' ORDER BY id LIMIT 1);
SET @item_reportes_id := (SELECT id FROM item WHERE modulo_id = @mod_sihos_id AND item_padre_id IS NULL AND nombre = 'REPORTES' LIMIT 1);

UPDATE item
SET nombre = 'NÓMINA'
WHERE modulo_id = @mod_sihos_id
  AND item_padre_id = @item_reportes_id
  AND ruta = ''
  AND nombre = 'Nómina';

UPDATE item SET nombre = 'APORTES EN LÍNEA (PILA)'
WHERE ruta = 'sihos/nominaPila';

UPDATE item SET nombre = 'CORRECCIÓN NÓMINA (PILA)'
WHERE ruta = 'sihos/nominaPilaCorreccion';

UPDATE item SET nombre = 'PLANILLA INTEGRADA VS SIHOS'
WHERE ruta = 'sihos/nominaPlanillaIntegrada';
