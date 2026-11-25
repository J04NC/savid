/* ============================================================
   Módulo SIHOS — nuevo reporte "AUXILIAR" (sihos/auxiliar): réplica del
   informe auxiliar de contabilidad de SIHOS (ColGAAP/NIIF) con sus filtros,
   resumen por cuenta en pantalla y Excel (vertical u horizontal).
   Reporte de solo lectura: sin escritura hacia SIHOS.

   Resultado:
     SIHOS
     └── REPORTES
         └── CONTABILIDAD
             ├── DOCUMENTOS CONTABLES   (sihos/documentosContables)
             └── AUXILIAR               (sihos/auxiliar)

   Requiere 20261125_sihos_menu_documentos_contables.sql (crea CONTABILIDAD).
   Los permisos del rol se asignan desde la matriz de roles (empresa/sede).
============================================================ */

SET @mod_sihos_id := (SELECT id FROM modulo WHERE LOWER(TRIM(nombre)) = 'sihos' ORDER BY id LIMIT 1);
SET @item_reportes_id := (SELECT id FROM item WHERE modulo_id = @mod_sihos_id AND item_padre_id IS NULL AND nombre = 'REPORTES' LIMIT 1);
SET @item_contabilidad_id := (SELECT id FROM item WHERE modulo_id = @mod_sihos_id AND item_padre_id = @item_reportes_id AND nombre = 'CONTABILIDAD' LIMIT 1);

INSERT INTO item (modulo_id, nombre, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_sihos_id, 'AUXILIAR', 'sihos/auxiliar', '📘', 20, @item_contabilidad_id, 1
WHERE @item_contabilidad_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM item WHERE ruta = 'sihos/auxiliar' LIMIT 1);

/* item_accion: solo 'ver' — pantalla de solo lectura contra SIHOS. */
INSERT INTO item_accion (item_id, accion_id, estado_id)
SELECT i.id, a.id, 1
FROM item i
INNER JOIN accion a ON a.codigo = 'ver'
WHERE i.ruta = 'sihos/auxiliar'
  AND NOT EXISTS (SELECT 1 FROM item_accion ia WHERE ia.item_id = i.id AND ia.accion_id = a.id);
