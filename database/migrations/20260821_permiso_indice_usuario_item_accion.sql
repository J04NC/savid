-- Índice para la asignación de permisos por usuario (modal usuario/permisos).
--
-- El guardado por lote hace:
--   DELETE FROM permiso WHERE usuario_id = ? AND item_accion_id IN (...)
--
-- Sin este índice MySQL solo aprovecha el prefijo usuario_id de uk_permiso
-- (key_len=4) y filtra el resto con "Using where", bloqueando bajo REPEATABLE
-- READ todo el rango de filas de ese usuario en lugar de las filas exactas.
-- Con un lote grande (un módulo completo) eso derivaba en gap locks sostenidos
-- durante toda la transacción y en errores 1205 (lock wait timeout, 50s) para
-- cualquier petición concurrente.

ALTER TABLE permiso
    ADD INDEX idx_permiso_usuario_item_accion (usuario_id, item_accion_id);
