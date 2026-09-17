/* ============================================================
   Academic — ejercicio "trabalenguas cronometrado" (TONGUE_TWISTER).

   Reutiliza el widget de grabacion de audio ya construido para
   AUDIO_RESPONSE (acad_exercise_reference_audio + acad_attempt con
   reference_audio_id) — sin tablas nuevas. A diferencia de
   AUDIO_RESPONSE, aqui NO se bloquea tras la primera confirmacion: el
   estudiante puede grabar varias tomas del mismo trabalenguas para ver
   su progreso, y cada toma queda como una fila propia en acad_attempt
   (todas con reference_audio_id apuntando al mismo audio de referencia).

   La innovacion pedida es medir, en el navegador (sin ffmpeg ni ASR,
   ninguno instalado en este servidor), la duracion de cada grabacion y
   compararla contra la duracion del audio de referencia — puramente
   motivacional (mide velocidad, no exactitud de pronunciacion; el
   profesor sigue calificando de oido via la cola normal de
   ESTADO_PENDIENTE). Como acad_attempt no tenia donde guardar ese dato
   sin inventar una columna especifica de un solo tipo de ejercicio, se
   agrega UNA columna generica JSON reutilizable por cualquier tipo
   futuro con necesidades similares (y por la Parte 2 del plan de
   integridad de intentos: registro de eventos de perdida de foco /
   pegado bloqueado), en vez de sumar una columna dedicada por cada
   necesidad puntual.
============================================================ */

SET @has_meta_json := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'acad_attempt' AND COLUMN_NAME = 'meta_json'
);
SET @sql := IF(@has_meta_json = 0,
    'ALTER TABLE `acad_attempt` ADD COLUMN `meta_json` TEXT NULL COMMENT ''Bolsa JSON generica para datos por intento que no ameritan columna propia. Uso actual: TONGUE_TWISTER guarda {"duration_ms": N} (duracion de la grabacion medida en el navegador, motivacional, no afecta score). Reservada para futuros eventos de integridad (perdida de foco / pegado bloqueado) por intento. NULL = sin datos extra.'' AFTER `audio_ruta`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

/* Un tipo de ejercicio TONGUE_TWISTER por cada empresa que ya tenga el
   catalogo Academic (misma empresa que su skill SPEAKING, mismo patron
   de alta que FILL_BLANKS/SENTENCE_ORDER). */
INSERT INTO acad_exercise_type (empresa_id, codigo, nombre_en, skill_id, orden, estado_id, created_at)
SELECT s.empresa_id, 'TONGUE_TWISTER', 'Tongue twister', s.id, 60, 1, NOW(3)
FROM acad_skill s
WHERE s.codigo = 'SPEAKING'
  AND NOT EXISTS (
      SELECT 1 FROM acad_exercise_type t WHERE t.empresa_id = s.empresa_id AND t.codigo = 'TONGUE_TWISTER'
  );
