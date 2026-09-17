/* ============================================================
   Academic — audios de referencia múltiples por ejercicio (antes
   1 solo audio en acad_exercise.audio_referencia_ruta) + columna
   en acad_attempt para saber a cuál audio corresponde cada
   grabación del estudiante. Migra los audios ya cargados de
   "Beginner 1 Adultos" (71 ejercicios) antes de borrar la columna
   vieja, para no perder nada.
============================================================ */

CREATE TABLE IF NOT EXISTS `acad_exercise_reference_audio` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `exercise_id` INT UNSIGNED NOT NULL,
    `texto_en` VARCHAR(200) NULL COMMENT 'Etiqueta opcional, ej. "Good morning"',
    `ruta` VARCHAR(500) NOT NULL,
    `orden` SMALLINT NOT NULL DEFAULT 0,
    `created_at` DATETIME(3) NULL DEFAULT NULL,
    `created_by` INT UNSIGNED NULL DEFAULT NULL,
    `updated_at` DATETIME(3) NULL DEFAULT NULL,
    `updated_by` INT UNSIGNED NULL DEFAULT NULL,
    `deleted_at` DATETIME(3) NULL DEFAULT NULL,
    `deleted_by` INT UNSIGNED NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_acad_exercise_reference_audio_exercise` (`exercise_id`),
    CONSTRAINT `fk_acad_exref_audio_exercise` FOREIGN KEY (`exercise_id`) REFERENCES `acad_exercise` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* Migra los audios ya cargados (idempotente vía NOT EXISTS por exercise_id) */
INSERT INTO acad_exercise_reference_audio (exercise_id, ruta, orden, created_at)
SELECT e.id, e.audio_referencia_ruta, 10, NOW(3)
FROM acad_exercise e
WHERE e.audio_referencia_ruta IS NOT NULL AND e.audio_referencia_ruta <> ''
  AND e.deleted_at IS NULL
  AND NOT EXISTS (SELECT 1 FROM acad_exercise_reference_audio ra WHERE ra.exercise_id = e.id);

/* ------------------------------------------------------------------ */
/* acad_attempt.reference_audio_id                                     */
/* ------------------------------------------------------------------ */

SET @has_ref_audio_id := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'acad_attempt' AND COLUMN_NAME = 'reference_audio_id'
);
SET @sql := IF(@has_ref_audio_id = 0,
    'ALTER TABLE `acad_attempt` ADD COLUMN `reference_audio_id` INT UNSIGNED NULL COMMENT ''Cual de los N audios de referencia responde esta grabacion'' AFTER `exercise_id`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_idx_ref_audio := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'acad_attempt' AND INDEX_NAME = 'idx_acad_attempt_reference_audio'
);
SET @sql := IF(@has_idx_ref_audio = 0,
    'ALTER TABLE `acad_attempt` ADD KEY `idx_acad_attempt_reference_audio` (`reference_audio_id`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_fk_ref_audio := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'acad_attempt' AND CONSTRAINT_NAME = 'fk_acad_attempt_reference_audio'
);
SET @sql := IF(@has_fk_ref_audio = 0,
    'ALTER TABLE `acad_attempt` ADD CONSTRAINT `fk_acad_attempt_reference_audio` FOREIGN KEY (`reference_audio_id`) REFERENCES `acad_exercise_reference_audio` (`id`) ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

/* ------------------------------------------------------------------ */
/* Elimina la columna vieja (ya migrada arriba)                        */
/* ------------------------------------------------------------------ */

SET @has_old_audio_col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'acad_exercise' AND COLUMN_NAME = 'audio_referencia_ruta'
);
SET @sql := IF(@has_old_audio_col > 0,
    'ALTER TABLE `acad_exercise` DROP COLUMN `audio_referencia_ruta`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
