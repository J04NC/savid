/* ============================================================
   Columna de control para no reenviar el aviso de "suscripción por vencer"
   en cada corrida del cron — se marca una vez notificada esa fila (una
   renovación crea una fila nueva vía SubscriptionRepository::createRenewal(),
   así que no hace falta resetearla).
============================================================ */

ALTER TABLE suscripcion
ADD COLUMN alerta_vencimiento_enviada_at DATETIME(3) NULL COMMENT 'show:none' AFTER estado_id;
