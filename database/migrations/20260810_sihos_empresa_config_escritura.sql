/* ============================================================
   SIHOS — credenciales de escritura por empresa, separadas de las de
   solo lectura que usan los reportes. Opcionales (NULL = la acción de
   borrado no aparece para esa empresa hasta que se configuren).
   Uso: eliminar DetaPlan huérfano de notas sobre facturas de vigencia
   anterior (ver SihosPresupuestoEliminacionService). Nunca se reutiliza
   la conexión de solo lectura (SihosExternalRepository, READ ONLY a
   nivel de sesión MySQL) para esta escritura.
============================================================ */

ALTER TABLE `sihos_empresa_config`
    ADD COLUMN `usuario_escritura` VARCHAR(120) NULL DEFAULT NULL COMMENT 'show:none' AFTER `charset`,
    ADD COLUMN `password_escritura_cifrado` TEXT NULL DEFAULT NULL COMMENT 'show:none' AFTER `usuario_escritura`;

/* Acción "eliminar" en el ítem del cruce (ruta 'sihos/cruce'): quién puede
   borrar el DetaPlan huérfano, además de "ver" (ya existente). */
INSERT INTO item_accion (item_id, accion_id, estado_id)
SELECT i.id, a.id, 1
FROM item i
INNER JOIN accion a ON a.codigo = 'eliminar'
WHERE i.ruta = 'sihos/cruce'
  AND NOT EXISTS (SELECT 1 FROM item_accion ia WHERE ia.item_id = i.id AND ia.accion_id = a.id);
