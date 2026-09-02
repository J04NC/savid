/* ============================================================
   Módulo SIHOS — nuevo reporte "Nómina: Aportes en línea (PILA)"
   (sihos/nominaPila): una fila por empleado (más novedades del
   período) con los valores de cotización de pensión/salud/ARL/
   parafiscales, exportable a .xlsx en el mismo orden de columnas
   que la plantilla de cargue de aportes en línea.

   Resultado:
     SIHOS
     └── REPORTES
         ├── Presupuesto
         │   └── Cruce reconocimientos vs contabilidad  (sihos/cruce)
         └── NÓMINA                                      (carpeta nueva)
             └── APORTES EN LÍNEA (PILA)  (sihos/nominaPila)
============================================================ */

SET @mod_sihos_id := (SELECT id FROM modulo WHERE LOWER(TRIM(nombre)) = 'sihos' ORDER BY id LIMIT 1);
SET @item_reportes_id := (SELECT id FROM item WHERE modulo_id = @mod_sihos_id AND item_padre_id IS NULL AND nombre = 'REPORTES' LIMIT 1);

INSERT INTO item (modulo_id, nombre, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_sihos_id, 'NÓMINA', '', '🧾', 20, @item_reportes_id, 1
WHERE @item_reportes_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM item WHERE modulo_id = @mod_sihos_id AND item_padre_id = @item_reportes_id AND nombre = 'NÓMINA');

SET @item_nomina_id := (SELECT id FROM item WHERE modulo_id = @mod_sihos_id AND item_padre_id = @item_reportes_id AND nombre = 'NÓMINA' LIMIT 1);

INSERT INTO item (modulo_id, nombre, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_sihos_id, 'APORTES EN LÍNEA (PILA)', 'sihos/nominaPila', '📋', 10, @item_nomina_id, 1
WHERE @item_nomina_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM item WHERE ruta = 'sihos/nominaPila' LIMIT 1);

/* item_accion: solo 'ver' — pantalla propia de solo lectura contra SIHOS
   (no CRUD genérico); la exportación .xlsx (sihos/nominaPilaExportar) se
   valida en el controller contra este mismo 'ver', sin ítem de menú propio,
   igual que las acciones AJAX de sihos/cruce. */
INSERT INTO item_accion (item_id, accion_id, estado_id)
SELECT i.id, a.id, 1
FROM item i
INNER JOIN accion a ON a.codigo = 'ver'
WHERE i.ruta = 'sihos/nominaPila'
  AND NOT EXISTS (SELECT 1 FROM item_accion ia WHERE ia.item_id = i.id AND ia.accion_id = a.id);
