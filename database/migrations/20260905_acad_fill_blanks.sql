/* ============================================================
   Academic — ejercicio "completar espacios" (toca-palabra / toca-hueco).
   Reutiliza acad_exercise_option como bolsa de palabras (una columna
   nueva, blank_index) y acad_exercise.prompt_en como el texto con
   huecos marcados "___". Sin tablas nuevas.
============================================================ */

SET @has_blank_index := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'acad_exercise_option' AND COLUMN_NAME = 'blank_index'
);
SET @sql := IF(@has_blank_index = 0,
    'ALTER TABLE `acad_exercise_option` ADD COLUMN `blank_index` SMALLINT UNSIGNED NULL COMMENT ''Hueco (1..N) que esta palabra resuelve; NULL = senuelo, no va en ningun hueco'' AFTER `es_correcta`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

/* Un tipo de ejercicio FILL_BLANKS por cada empresa que ya tenga el
   catalogo Academic (misma empresa que su skill READING). */
INSERT INTO acad_exercise_type (empresa_id, codigo, nombre_en, skill_id, orden, estado_id, created_at)
SELECT s.empresa_id, 'FILL_BLANKS', 'Fill in the blanks', s.id, 40, 1, NOW(3)
FROM acad_skill s
WHERE s.codigo = 'READING'
  AND NOT EXISTS (
      SELECT 1 FROM acad_exercise_type t WHERE t.empresa_id = s.empresa_id AND t.codigo = 'FILL_BLANKS'
  );
