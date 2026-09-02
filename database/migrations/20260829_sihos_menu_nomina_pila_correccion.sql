/* ============================================================
   Módulo SIHOS — nueva pantalla "Corrección Nómina (PILA)"
   (sihos/nominaPilaCorreccion): carga el CSV de "posibles
   correcciones" del portal de aportes en línea y corrige en
   DetaNomi (aporte patronal) las cotizaciones que aún se puedan
   ajustar antes de confirmar la nómina en SIHOS.

   Resultado:
     SIHOS
     └── REPORTES
         └── NÓMINA
             ├── APORTES EN LÍNEA (PILA)        (sihos/nominaPila)
             └── CORRECCIÓN NÓMINA (PILA)        (nueva)
============================================================ */

SET @mod_sihos_id := (SELECT id FROM modulo WHERE LOWER(TRIM(nombre)) = 'sihos' ORDER BY id LIMIT 1);
SET @item_reportes_id := (SELECT id FROM item WHERE modulo_id = @mod_sihos_id AND item_padre_id IS NULL AND nombre = 'REPORTES' LIMIT 1);
SET @item_nomina_id := (SELECT id FROM item WHERE modulo_id = @mod_sihos_id AND item_padre_id = @item_reportes_id AND nombre = 'NÓMINA' LIMIT 1);

INSERT INTO item (modulo_id, nombre, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_sihos_id, 'CORRECCIÓN NÓMINA (PILA)', 'sihos/nominaPilaCorreccion', '🛠️', 20, @item_nomina_id, 1
WHERE @item_nomina_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM item WHERE ruta = 'sihos/nominaPilaCorreccion' LIMIT 1);

/* item_accion: 'ver' (abrir la pantalla y cargar el CSV / ver la vista
   previa) y 'guardar' (aplicar las correcciones seleccionadas contra
   DetaNomi) — sin 'limpiar'/'eliminar', no es un CRUD genérico. */
INSERT INTO item_accion (item_id, accion_id, estado_id)
SELECT i.id, a.id, 1
FROM item i
INNER JOIN accion a ON a.codigo IN ('ver', 'guardar')
WHERE i.ruta = 'sihos/nominaPilaCorreccion'
  AND NOT EXISTS (SELECT 1 FROM item_accion ia WHERE ia.item_id = i.id AND ia.accion_id = a.id);
