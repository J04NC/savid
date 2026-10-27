/* ============================================================
   Academic — ejercicio "verdadero/falso con corrección" (TRUE_FALSE_FIX).

   Reutiliza acad_exercise_option (cada fila = un enunciado) y
   acad_attempt.respuesta_texto (las correcciones escritas, ya en texto
   legible, no JSON) — sin tablas nuevas, sin columnas nuevas.

   Modelo:
     - acad_exercise.prompt_en: el texto de lectura/contexto sobre el que
       tratan los enunciados (se muestra normal, como en OPEN_TEXT — este
       tipo no usa ninguna convención especial dentro de prompt_en).
     - acad_exercise_option: orden = orden de aparicion del enunciado (1..N);
       texto_en = el enunciado; es_correcta = si el enunciado, TAL COMO ESTA
       ESCRITO, es verdadero (1) o falso (0); blank_index = sin uso.
     - Calificacion hibrida: marcar V/F autocalifica al instante
       (proporcion de aciertos). Si el estudiante marca algun enunciado como
       Falso, la correccion que escriba para ese enunciado pasa a la cola de
       revision del profesor (ESTADO_PENDIENTE) — el puntaje ya calculado
       queda guardado igual, el profesor solo lo ajusta si hace falta tras
       leer las correcciones. Si el estudiante marca todo como Verdadero (no
       hay nada que corregir), el intento queda autocalificado sin pasar por
       la cola.
============================================================ */

ALTER TABLE `acad_exercise_option`
    MODIFY COLUMN `blank_index` SMALLINT UNSIGNED NULL COMMENT
        'Significado segun exercise_type de la fila padre (acad_exercise.exercise_type_codigo): FILL_BLANKS = hueco (1..N) que esta palabra resuelve en el prompt, NULL = senuelo. SENTENCE_ORDER = numero de oracion (1..N) a la que pertenece la palabra, NULL = senuelo no asignado a ninguna oracion. DIALOGUE = rol/hablante del turno (1 o 2), nunca NULL para este tipo. MULTIPLE_CHOICE/TRUE_FALSE_FIX = no se usa (siempre NULL).',
    MODIFY COLUMN `orden` SMALLINT NOT NULL COMMENT
        'Significado segun exercise_type: MULTIPLE_CHOICE/FILL_BLANKS/TRUE_FALSE_FIX = orden de aparicion/insercion (sin efecto en la calificacion). SENTENCE_ORDER = posicion correcta (1..N) de la palabra dentro de su oracion (blank_index) — SI es la respuesta correcta. DIALOGUE = numero de turno (1..N) en la conversacion; hace pareja con acad_exercise_reference_audio.orden del mismo ejercicio para saber que audio TTS narra este turno.',
    MODIFY COLUMN `es_correcta` TINYINT(1) NOT NULL COMMENT
        'Significado segun exercise_type: MULTIPLE_CHOICE = esta es la opcion correcta. FILL_BLANKS/SENTENCE_ORDER = la palabra pertenece a un hueco/oracion real (blank_index no nulo) en vez de ser senuelo; redundante con blank_index, se mantiene por consistencia. DIALOGUE = no se usa (siempre true). TRUE_FALSE_FIX = el enunciado, tal como esta escrito, es verdadero (1) o falso (0).';

/* Un tipo de ejercicio TRUE_FALSE_FIX por cada empresa que ya tenga el
   catalogo Academic (misma empresa que su skill READING, mismo patron de
   alta que FILL_BLANKS/SENTENCE_ORDER). */
INSERT INTO acad_exercise_type (empresa_id, codigo, nombre_en, skill_id, orden, estado_id, created_at)
SELECT s.empresa_id, 'TRUE_FALSE_FIX', 'True/False (with correction)', s.id, 80, 1, NOW(3)
FROM acad_skill s
WHERE s.codigo = 'READING'
  AND NOT EXISTS (
      SELECT 1 FROM acad_exercise_type t WHERE t.empresa_id = s.empresa_id AND t.codigo = 'TRUE_FALSE_FIX'
  );
