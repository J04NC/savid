/* ============================================================
   Academic — ejercicio "ordenar palabras" (SENTENCE_ORDER).

   Reutiliza acad_exercise_option, la misma tabla de Multiple Choice y
   Fill in the blanks — sin tablas nuevas, sin columnas nuevas (a
   diferencia de las dos migraciones anteriores, esta no necesita
   ninguna columna adicional).

   Modelo: cada palabra de cada oracion es una fila.
     - blank_index = numero de oracion (1..N) a la que pertenece la
       palabra dentro del ejercicio (un ejercicio puede tener varias
       oraciones); NULL = palabra senuelo, no pertenece a ninguna
       oracion.
     - orden       = posicion correcta (1..N) de la palabra DENTRO de
       su oracion.
     - es_correcta = true si la palabra pertenece a alguna oracion
       (blank_index no nulo); false si es senuelo. Redundante con
       blank_index pero se mantiene por consistencia con Fill in the
       blanks (misma regla: es_correcta = blank_index !== null).

   Como blank_index, orden y es_correcta ya venian cargando significados
   distintos segun el tipo de ejercicio (Multiple Choice / Fill in the
   blanks) y ahora suman uno mas (Sentence order), se deja documentado
   TODO el significado acumulado en el COMMENT de cada columna — pedido
   explicito del usuario antes de seguir sumando tipos, para que quede
   trazable desde el esquema mismo y no solo disperso en el codigo PHP.
============================================================ */

ALTER TABLE `acad_exercise_option`
    MODIFY COLUMN `blank_index` SMALLINT UNSIGNED NULL COMMENT
        'Significado segun exercise_type de la fila padre (acad_exercise.exercise_type_codigo): FILL_BLANKS = hueco (1..N) que esta palabra resuelve en el prompt, NULL = senuelo. SENTENCE_ORDER = numero de oracion (1..N) a la que pertenece la palabra, NULL = senuelo no asignado a ninguna oracion. MULTIPLE_CHOICE = no se usa (siempre NULL).',
    MODIFY COLUMN `orden` SMALLINT NOT NULL COMMENT
        'Significado segun exercise_type: MULTIPLE_CHOICE/FILL_BLANKS = orden de aparicion/insercion (sin efecto en la calificacion). SENTENCE_ORDER = posicion correcta (1..N) de la palabra dentro de su oracion (blank_index) — SI es la respuesta correcta, no solo un orden visual.',
    MODIFY COLUMN `es_correcta` TINYINT(1) NOT NULL COMMENT
        'Significado segun exercise_type: MULTIPLE_CHOICE = esta es la opcion correcta. FILL_BLANKS/SENTENCE_ORDER = la palabra pertenece a un hueco/oracion real (blank_index no nulo) en vez de ser senuelo; redundante con blank_index, se mantiene por consistencia y para no romper lecturas existentes que ya usan este campo.';

/* Un tipo de ejercicio SENTENCE_ORDER por cada empresa que ya tenga el
   catalogo Academic (misma empresa que su skill READING, mismo patron
   de alta que FILL_BLANKS). */
INSERT INTO acad_exercise_type (empresa_id, codigo, nombre_en, skill_id, orden, estado_id, created_at)
SELECT s.empresa_id, 'SENTENCE_ORDER', 'Sentence order', s.id, 50, 1, NOW(3)
FROM acad_skill s
WHERE s.codigo = 'READING'
  AND NOT EXISTS (
      SELECT 1 FROM acad_exercise_type t WHERE t.empresa_id = s.empresa_id AND t.codigo = 'SENTENCE_ORDER'
  );
