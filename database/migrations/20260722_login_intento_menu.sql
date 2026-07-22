/* ============================================================
   Pantalla de solo lectura para revisar intentos de login (superadmin),
   vía el motor CRUD genérico. Sin item_accion de guardar/eliminar:
   ningún botón de edición aparece, y show:table oculta todos los
   campos del formulario (no tiene sentido editar un log de intentos).
============================================================ */

ALTER TABLE `login_intento`
    MODIFY COLUMN `created_at` DATETIME(3) NULL DEFAULT NULL
    COMMENT 'label:Fecha|order:10|show:table',
    MODIFY COLUMN `username` VARCHAR(150) NOT NULL
    COMMENT 'label:Usuario|order:20|show:table',
    MODIFY COLUMN `ip` VARCHAR(45) NOT NULL
    COMMENT 'label:IP|order:30|show:table',
    MODIFY COLUMN `exitoso` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'label:Resultado|order:40|show:table';

INSERT INTO `item` (modulo_id, item_padre_id, nombre, ruta, icono, orden, estado_id)
VALUES (1, 14, 'INTENTOS DE LOGIN', 'login_intento', '🛡️', 30, 1);

INSERT INTO item_accion (item_id, accion_id, estado_id)
SELECT i.id, a.id, 1
FROM item i
JOIN accion a ON a.codigo = 'ver'
WHERE i.ruta = 'login_intento';
