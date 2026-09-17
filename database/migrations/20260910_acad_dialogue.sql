/* ============================================================
   Academic — ejercicio "diálogo" (role-play contra la máquina, DIALOGUE).

   Reutiliza acad_exercise_option (cada fila = un turno del diálogo) y
   acad_exercise_reference_audio (el audio TTS de cada turno, generado con
   una voz distinta por rol) — sin tablas nuevas, sin columnas nuevas.

   Modelo:
     - acad_exercise_option: orden = número de turno (1..N); blank_index =
       rol/hablante del turno (1 o 2); texto_en = la línea de ese turno;
       es_correcta = sin uso (siempre true, un guion no es correcto/
       incorrecto).
     - acad_exercise_reference_audio: orden = MISMO número de turno que la
       opción que narra (join lógico turno<->audio, sin FK — ambas tablas
       ya se leen ordenadas por `orden` en AcadRepository). texto_en = la
       línea (mismo texto que la opción, por consistencia con el resto de
       audios de referencia que ya usan texto_en como etiqueta).
     - acad_attempt.reference_audio_id (columna ya existente desde la
       migración de audio múltiple) enlaza la grabación del estudiante para
       SU turno con el audio de referencia (y por tanto con la opción) de
       ese turno.

   Sigue el mismo criterio de trazabilidad ya aplicado en las migraciones de
   SENTENCE_ORDER y TONGUE_TWISTER: documentar en el COMMENT de columna el
   significado acumulado por tipo de ejercicio, no solo en el código PHP.
============================================================ */

ALTER TABLE `acad_exercise_option`
    MODIFY COLUMN `blank_index` SMALLINT UNSIGNED NULL COMMENT
        'Significado segun exercise_type de la fila padre (acad_exercise.exercise_type_codigo): FILL_BLANKS = hueco (1..N) que esta palabra resuelve en el prompt, NULL = senuelo. SENTENCE_ORDER = numero de oracion (1..N) a la que pertenece la palabra, NULL = senuelo no asignado a ninguna oracion. DIALOGUE = rol/hablante del turno (1 o 2), nunca NULL para este tipo. MULTIPLE_CHOICE = no se usa (siempre NULL).',
    MODIFY COLUMN `orden` SMALLINT NOT NULL COMMENT
        'Significado segun exercise_type: MULTIPLE_CHOICE/FILL_BLANKS = orden de aparicion/insercion (sin efecto en la calificacion). SENTENCE_ORDER = posicion correcta (1..N) de la palabra dentro de su oracion (blank_index) — SI es la respuesta correcta. DIALOGUE = numero de turno (1..N) en la conversacion; hace pareja con acad_exercise_reference_audio.orden del mismo ejercicio para saber que audio TTS narra este turno.',
    MODIFY COLUMN `es_correcta` TINYINT(1) NOT NULL COMMENT
        'Significado segun exercise_type: MULTIPLE_CHOICE = esta es la opcion correcta. FILL_BLANKS/SENTENCE_ORDER = la palabra pertenece a un hueco/oracion real (blank_index no nulo) en vez de ser senuelo; redundante con blank_index, se mantiene por consistencia. DIALOGUE = no se usa (siempre true).';

ALTER TABLE `acad_exercise_reference_audio`
    MODIFY COLUMN `orden` SMALLINT NOT NULL COMMENT
        'Significado segun exercise_type del ejercicio dueño: AUDIO_RESPONSE/TONGUE_TWISTER = orden de aparicion visual de los N audios de referencia, sin relacion con otra tabla. DIALOGUE = numero de turno, igual al `orden` de la fila de acad_exercise_option que este audio narra (join logico por valor, ambas tablas se leen ordenadas por `orden`).';

/* Un tipo de ejercicio DIALOGUE por cada empresa que ya tenga el catalogo
   Academic (misma empresa que su skill SPEAKING, mismo patron de alta que
   AUDIO_RESPONSE/TONGUE_TWISTER). */
INSERT INTO acad_exercise_type (empresa_id, codigo, nombre_en, skill_id, orden, estado_id, created_at)
SELECT s.empresa_id, 'DIALOGUE', 'Dialogue (role-play)', s.id, 70, 1, NOW(3)
FROM acad_skill s
WHERE s.codigo = 'SPEAKING'
  AND NOT EXISTS (
      SELECT 1 FROM acad_exercise_type t WHERE t.empresa_id = s.empresa_id AND t.codigo = 'DIALOGUE'
  );
