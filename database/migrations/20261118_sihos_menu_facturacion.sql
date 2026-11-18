/* ============================================================
   Módulo SIHOS — nuevo reporte "FACTURACIÓN" (sihos/facturacion): facturación
   y notas (notas contables de facturación y glosas aceptadas) que afectan las
   facturas de un rango de fechas, por tipo de usuario y tercero, para cruzar
   contra la DIAN. Reporte de solo lectura: sin escritura hacia SIHOS.

   Resultado:
     SIHOS
     └── REPORTES
         └── FACTURACION Y CARTERA
             ├── DETERIORO          (sihos/deterioro)
             ├── 2193 CARTERA       (sihos/cartera2193)
             └── FACTURACIÓN        (sihos/facturacion)

   Depende de 20261022_sihos_menu_deterioro.sql (crea la carpeta
   FACTURACION Y CARTERA). Los permisos del rol SIHOS sobre el ítem nuevo se
   asignan desde la matriz de roles (empresa/sede), como en los demás
   reportes SIHOS.
============================================================ */

SET @mod_sihos_id := (SELECT id FROM modulo WHERE LOWER(TRIM(nombre)) = 'sihos' ORDER BY id LIMIT 1);
SET @item_reportes_id := (SELECT id FROM item WHERE modulo_id = @mod_sihos_id AND item_padre_id IS NULL AND nombre = 'REPORTES' LIMIT 1);
SET @item_fact_cartera_id := (SELECT id FROM item WHERE modulo_id = @mod_sihos_id AND item_padre_id = @item_reportes_id AND nombre = 'FACTURACION Y CARTERA' LIMIT 1);

INSERT INTO item (modulo_id, nombre, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_sihos_id, 'FACTURACIÓN', 'sihos/facturacion', '🧾', 30, @item_fact_cartera_id, 1
WHERE @item_fact_cartera_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM item WHERE ruta = 'sihos/facturacion' LIMIT 1);

/* item_accion: solo 'ver' — pantalla de solo lectura contra SIHOS. */
INSERT INTO item_accion (item_id, accion_id, estado_id)
SELECT i.id, a.id, 1
FROM item i
INNER JOIN accion a ON a.codigo = 'ver'
WHERE i.ruta = 'sihos/facturacion'
  AND NOT EXISTS (SELECT 1 FROM item_accion ia WHERE ia.item_id = i.id AND ia.accion_id = a.id);
