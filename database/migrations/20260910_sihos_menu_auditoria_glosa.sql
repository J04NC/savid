/* ============================================================
   Módulo SIHOS — nuevo reporte "Auditoría Glosa" (sihos/auditoriaGlosa):
   cruza cada glosa (AnotGlos) contra la factura que referencia — cartera
   real (cuenta 14%), cuenta de orden de glosa en trámite (8333%, NIIF y no
   NIIF) y el valor nativo de SIHOS (DetaFaCr.GlosCurs) — para detectar
   glosas marcadas "en curso" sobre facturas cuya cartera ya está saldada.
   Reporte de solo lectura: sin escritura hacia SIHOS.

   Resultado:
     SIHOS
     └── REPORTES
         ├── Presupuesto
         │   └── Cruce reconocimientos vs contabilidad  (sihos/cruce)
         ├── NÓMINA
         │   └── ...
         └── GLOSA                                       (carpeta nueva)
             └── AUDITORÍA GLOSA  (sihos/auditoriaGlosa)
============================================================ */

SET @mod_sihos_id := (SELECT id FROM modulo WHERE LOWER(TRIM(nombre)) = 'sihos' ORDER BY id LIMIT 1);
SET @item_reportes_id := (SELECT id FROM item WHERE modulo_id = @mod_sihos_id AND item_padre_id IS NULL AND nombre = 'REPORTES' LIMIT 1);

INSERT INTO item (modulo_id, nombre, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_sihos_id, 'GLOSA', '', '📄', 30, @item_reportes_id, 1
WHERE @item_reportes_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM item WHERE modulo_id = @mod_sihos_id AND item_padre_id = @item_reportes_id AND nombre = 'GLOSA');

SET @item_glosa_id := (SELECT id FROM item WHERE modulo_id = @mod_sihos_id AND item_padre_id = @item_reportes_id AND nombre = 'GLOSA' LIMIT 1);

INSERT INTO item (modulo_id, nombre, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_sihos_id, 'AUDITORÍA GLOSA', 'sihos/auditoriaGlosa', '🔎', 10, @item_glosa_id, 1
WHERE @item_glosa_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM item WHERE ruta = 'sihos/auditoriaGlosa' LIMIT 1);

/* item_accion: solo 'ver' — pantalla propia de solo lectura contra SIHOS,
   sin ninguna acción de escritura (a diferencia de sihos/cruce). */
INSERT INTO item_accion (item_id, accion_id, estado_id)
SELECT i.id, a.id, 1
FROM item i
INNER JOIN accion a ON a.codigo = 'ver'
WHERE i.ruta = 'sihos/auditoriaGlosa'
  AND NOT EXISTS (SELECT 1 FROM item_accion ia WHERE ia.item_id = i.id AND ia.accion_id = a.id);
