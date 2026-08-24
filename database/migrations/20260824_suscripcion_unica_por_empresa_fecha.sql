/* ============================================================
   Evita renovaciones duplicadas de suscripción.

   SuscripcionService::renew() leía el estado (getRenewalPreview) y luego
   insertaba, sin transacción ni condición: dos clics seguidos en
   "Renovar", o dos superadministradores a la vez, creaban dos
   suscripciones para la misma empresa el mismo día. Es el mismo patrón
   de condición de carrera que el "trial ya canjeado".

   La comprobación en PHP no basta: entre el SELECT y el INSERT cabe otra
   petición. Este índice único traslada la garantía al motor, que es el
   único punto donde la exclusión es real. El repositorio traduce el
   error 1062 en un mensaje claro.

   Verificado antes de aplicar: 0 colisiones en los datos existentes.
   ============================================================ */

ALTER TABLE suscripcion
    ADD UNIQUE KEY uk_suscripcion_empresa_inicio (empresa_id, fecha_inicio);
