/* Ítem contenedor SGD: sin ruta directa → menú hijo vía dashboard/item/{id} */
UPDATE item
SET ruta = ''
WHERE ruta = 'sgd'
  AND (item_padre_id IS NULL OR item_padre_id = 0)
LIMIT 1;
