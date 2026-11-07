/* ============================================================
   Módulo SIHOS — nuevo reporte "LIQUIDACIÓN" (sihos/liquidacion): producción
   por actividad y profesional a partir de las liquidaciones confirmadas
   (EncaFact/DetaFact) de un rango de fechas elegido por el usuario. Línea a
   línea (sin agrupar, a diferencia del reporte de liquidación de SIHOS) y con
   el tipo de servicio del CUPS, para no perder las cirugías que SIHOS guarda
   por componente (honorarios, anestesia, ayudantía, sala, materiales).
   Reporte de solo lectura: sin escritura hacia SIHOS.

   Resultado:
     SIHOS
     └── REPORTES
         └── LIQUIDACIÓN
             └── LIQUIDACIÓN       (sihos/liquidacion)

   Los permisos del rol SIHOS sobre el ítem nuevo se asignan desde la matriz
   de roles (empresa/sede), como en los demás reportes SIHOS.
============================================================ */

SET @mod_sihos_id := (SELECT id FROM modulo WHERE LOWER(TRIM(nombre)) = 'sihos' ORDER BY id LIMIT 1);
SET @item_reportes_id := (SELECT id FROM item WHERE modulo_id = @mod_sihos_id AND item_padre_id IS NULL AND nombre = 'REPORTES' LIMIT 1);

INSERT INTO item (modulo_id, nombre, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_sihos_id, 'LIQUIDACIÓN', '', '🧾', 50, @item_reportes_id, 1
WHERE @item_reportes_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM item WHERE modulo_id = @mod_sihos_id AND item_padre_id = @item_reportes_id AND nombre = 'LIQUIDACIÓN');

SET @item_liquidacion_id := (SELECT id FROM item WHERE modulo_id = @mod_sihos_id AND item_padre_id = @item_reportes_id AND nombre = 'LIQUIDACIÓN' LIMIT 1);

INSERT INTO item (modulo_id, nombre, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_sihos_id, 'LIQUIDACIÓN', 'sihos/liquidacion', '🧾', 10, @item_liquidacion_id, 1
WHERE @item_liquidacion_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM item WHERE ruta = 'sihos/liquidacion' LIMIT 1);

/* item_accion: solo 'ver' — pantalla de solo lectura contra SIHOS. */
INSERT INTO item_accion (item_id, accion_id, estado_id)
SELECT i.id, a.id, 1
FROM item i
INNER JOIN accion a ON a.codigo = 'ver'
WHERE i.ruta = 'sihos/liquidacion'
  AND NOT EXISTS (SELECT 1 FROM item_accion ia WHERE ia.item_id = i.id AND ia.accion_id = a.id);
