/* ============================================================
   Módulo SIHOS — nuevo reporte "DOCUMENTOS CONTABLES" (sihos/documentosContables):
   todas las líneas contables causadas y no anuladas de un rango, sumadas por
   documento y cuenta, de ColGAAP (DetaCont) o NIIF (DetaNIIF), con filtro de
   cuenta (inicia por / igual a / entre). Se descarga en Excel (.xlsx).
   Reporte de solo lectura: sin escritura hacia SIHOS.

   Resultado:
     SIHOS
     └── REPORTES
         └── CONTABILIDAD                 (grupo nuevo)
             └── DOCUMENTOS CONTABLES     (sihos/documentosContables)

   Los permisos del rol SIHOS sobre el ítem nuevo se asignan desde la matriz
   de roles (empresa/sede), como en los demás reportes SIHOS.
============================================================ */

SET @mod_sihos_id := (SELECT id FROM modulo WHERE LOWER(TRIM(nombre)) = 'sihos' ORDER BY id LIMIT 1);
SET @item_reportes_id := (SELECT id FROM item WHERE modulo_id = @mod_sihos_id AND item_padre_id IS NULL AND nombre = 'REPORTES' LIMIT 1);

INSERT INTO item (modulo_id, nombre, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_sihos_id, 'CONTABILIDAD', NULL, '📒', 60, @item_reportes_id, 1
WHERE @item_reportes_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM item WHERE modulo_id = @mod_sihos_id AND item_padre_id = @item_reportes_id AND nombre = 'CONTABILIDAD' LIMIT 1);

SET @item_contabilidad_id := (SELECT id FROM item WHERE modulo_id = @mod_sihos_id AND item_padre_id = @item_reportes_id AND nombre = 'CONTABILIDAD' LIMIT 1);

INSERT INTO item (modulo_id, nombre, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_sihos_id, 'DOCUMENTOS CONTABLES', 'sihos/documentosContables', '📄', 10, @item_contabilidad_id, 1
WHERE @item_contabilidad_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM item WHERE ruta = 'sihos/documentosContables' LIMIT 1);

/* item_accion: solo 'ver' — pantalla de solo lectura contra SIHOS. */
INSERT INTO item_accion (item_id, accion_id, estado_id)
SELECT i.id, a.id, 1
FROM item i
INNER JOIN accion a ON a.codigo = 'ver'
WHERE i.ruta = 'sihos/documentosContables'
  AND NOT EXISTS (SELECT 1 FROM item_accion ia WHERE ia.item_id = i.id AND ia.accion_id = a.id);
