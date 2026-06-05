-- Pantalla propia Cuadro de clasificación (CCD), similar a sgd/documentos
UPDATE item
SET
    nombre = 'Cuadro de clasificación (CCD)',
    ruta = 'sgd/ccd',
    icono = '🗂️'
WHERE ruta IN ('sgd_ccd_entrada', 'sgd/ccd')
LIMIT 1;

INSERT INTO item (modulo_id, nombre, ruta, icono, orden, item_padre_id, estado_id)
SELECT m.id, 'Cuadro de clasificación (CCD)', 'sgd/ccd', '🗂️', 95, hub.id, 1
FROM modulo m
INNER JOIN item hub ON hub.modulo_id = m.id AND (hub.item_padre_id IS NULL OR hub.item_padre_id = 0)
WHERE LOWER(TRIM(m.nombre)) IN ('gestión documental', 'gestion documental')
  AND NOT EXISTS (SELECT 1 FROM item WHERE ruta = 'sgd/ccd' LIMIT 1)
ORDER BY hub.id
LIMIT 1;
