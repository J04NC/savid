-- Vincula la acción especial "item_accion" (id 14) al ítem CRUD `item` (ruta item).
-- Habilita el botón que abre el modal ?url=item/acciones/{itemId}.

INSERT INTO item_accion (item_id, accion_id, estado_id)
SELECT i.id, 14, 1
FROM item i
WHERE i.ruta = 'item'
  AND NOT EXISTS (
      SELECT 1 FROM item_accion ia
      WHERE ia.item_id = i.id AND ia.accion_id = 14
  );
