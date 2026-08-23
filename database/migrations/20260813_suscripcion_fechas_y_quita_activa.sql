/* ============================================================
   Suscripción: fecha_inicio/fecha_fin ahora usan selector de fecha en el CRUD
   (antes sin COLUMN_COMMENT, se veían como texto plano), y se elimina la
   columna `activa` — siempre se usaba junto con `estado_id` (nunca uno sin
   el otro) y jamás se actualizaba a 0 en ningún flujo, incluida la renovación;
   `estado_id` ya cubre por sí solo "suscripción activa o inactiva".
============================================================ */

ALTER TABLE suscripcion
    MODIFY fecha_inicio DATE NOT NULL COMMENT 'type:date|required|label:Fecha inicio',
    MODIFY fecha_fin DATE NOT NULL COMMENT 'type:date|required|label:Fecha fin';

ALTER TABLE suscripcion
    DROP COLUMN activa;
