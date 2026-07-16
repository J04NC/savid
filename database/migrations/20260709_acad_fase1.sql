/* ============================================================
   Academic Fase 1 — Currículo CEFR (A1/A2) + ejercicios de práctica
   Reading / Writing / Speaking, sin grupos/asistencia/lesson plans/placement
   (esas capas quedan para fases posteriores, ver docs/academic/FICHA_MODULO_ACADEMIC.md)
   Ejecutar una vez en la BD savid.
============================================================ */

/* ------------------------------------------------------------------ */
/* Capa A — Currículo                                                  */
/* ------------------------------------------------------------------ */

CREATE TABLE IF NOT EXISTS `acad_level` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `empresa_id` INT NOT NULL COMMENT 'label:Empresa|rel:tercero|label:razon_social|title:Razón social de la empresa (tercero vinculado)',
    `codigo` VARCHAR(8) NOT NULL COMMENT 'CEFR level code (A1, A2...)|uppercase|order:10',
    `nombre_en` VARCHAR(120) NOT NULL COMMENT 'label:Name (English)|order:20',
    `orden` SMALLINT NOT NULL DEFAULT 0 COMMENT 'order:30',
    `estado_id` SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'label:Status|reltipo:GENERAL|title:Level active or inactive.|order:40',
    `created_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `created_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    `updated_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `updated_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    `deleted_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `deleted_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_acad_level_empresa_codigo` (`empresa_id`, `codigo`),
    KEY `idx_acad_level_empresa` (`empresa_id`),
    CONSTRAINT `fk_acad_level_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresa` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_acad_level_estado` FOREIGN KEY (`estado_id`) REFERENCES `estado` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `acad_skill` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `empresa_id` INT NOT NULL COMMENT 'label:Empresa|rel:tercero|label:razon_social|title:Razón social de la empresa (tercero vinculado)',
    `codigo` VARCHAR(16) NOT NULL COMMENT 'READING, WRITING, SPEAKING|uppercase|order:10',
    `nombre_en` VARCHAR(60) NOT NULL COMMENT 'label:Name (English)|order:20',
    `orden` SMALLINT NOT NULL DEFAULT 0 COMMENT 'order:30',
    `estado_id` SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'label:Status|reltipo:GENERAL|title:Skill active or inactive.|order:40',
    `created_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `created_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    `updated_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `updated_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    `deleted_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `deleted_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_acad_skill_empresa_codigo` (`empresa_id`, `codigo`),
    KEY `idx_acad_skill_empresa` (`empresa_id`),
    CONSTRAINT `fk_acad_skill_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresa` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_acad_skill_estado` FOREIGN KEY (`estado_id`) REFERENCES `estado` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `acad_cefr_descriptor` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `empresa_id` INT NOT NULL COMMENT 'label:Empresa|rel:tercero|label:razon_social|title:Razón social de la empresa (tercero vinculado)',
    `level_id` INT UNSIGNED NOT NULL COMMENT 'rel:acad_level|label:codigo|title:CEFR level|order:10',
    `skill_id` INT UNSIGNED NOT NULL COMMENT 'rel:acad_skill|label:codigo|title:Skill|order:20',
    `codigo` VARCHAR(24) NOT NULL COMMENT 'e.g. A1-R-01|uppercase|order:30',
    `descriptor_en` VARCHAR(500) NOT NULL COMMENT 'type:textarea|label:Can-do statement (English)|order:40|span:full',
    `orden` SMALLINT NOT NULL DEFAULT 0 COMMENT 'order:50',
    `estado_id` SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'label:Status|reltipo:GENERAL|title:Descriptor active or inactive.|order:60',
    `created_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `created_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    `updated_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `updated_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    `deleted_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `deleted_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_acad_descriptor_empresa_codigo` (`empresa_id`, `codigo`),
    KEY `idx_acad_descriptor_level` (`level_id`),
    KEY `idx_acad_descriptor_skill` (`skill_id`),
    CONSTRAINT `fk_acad_descriptor_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresa` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_acad_descriptor_level` FOREIGN KEY (`level_id`) REFERENCES `acad_level` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_acad_descriptor_skill` FOREIGN KEY (`skill_id`) REFERENCES `acad_skill` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_acad_descriptor_estado` FOREIGN KEY (`estado_id`) REFERENCES `estado` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `acad_unit` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `empresa_id` INT NOT NULL COMMENT 'label:Empresa|rel:tercero|label:razon_social|title:Razón social de la empresa (tercero vinculado)',
    `level_id` INT UNSIGNED NOT NULL COMMENT 'rel:acad_level|label:codigo|title:CEFR level|order:10',
    `titulo_en` VARCHAR(200) NOT NULL COMMENT 'label:Title (English)|order:20',
    `orden` SMALLINT NOT NULL DEFAULT 0 COMMENT 'order:30',
    `estado_id` SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'label:Status|reltipo:GENERAL|title:Unit active or inactive.|order:40',
    `created_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `created_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    `updated_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `updated_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    `deleted_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `deleted_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    PRIMARY KEY (`id`),
    KEY `idx_acad_unit_empresa` (`empresa_id`),
    KEY `idx_acad_unit_level` (`level_id`),
    CONSTRAINT `fk_acad_unit_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresa` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_acad_unit_level` FOREIGN KEY (`level_id`) REFERENCES `acad_level` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_acad_unit_estado` FOREIGN KEY (`estado_id`) REFERENCES `estado` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `acad_lesson` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `empresa_id` INT NOT NULL COMMENT 'label:Empresa|rel:tercero|label:razon_social|title:Razón social de la empresa (tercero vinculado)',
    `unit_id` INT UNSIGNED NOT NULL COMMENT 'rel:acad_unit|label:titulo_en|title:Syllabus unit|order:10',
    `titulo_en` VARCHAR(200) NOT NULL COMMENT 'label:Title (English)|order:20',
    `notas` VARCHAR(500) NULL COMMENT 'type:textarea|label:Notes|order:30|span:full',
    `orden` SMALLINT NOT NULL DEFAULT 0 COMMENT 'order:40',
    `estado_id` SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'label:Status|reltipo:GENERAL|title:Lesson active or inactive.|order:50',
    `created_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `created_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    `updated_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `updated_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    `deleted_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `deleted_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    PRIMARY KEY (`id`),
    KEY `idx_acad_lesson_empresa` (`empresa_id`),
    KEY `idx_acad_lesson_unit` (`unit_id`),
    CONSTRAINT `fk_acad_lesson_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresa` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_acad_lesson_unit` FOREIGN KEY (`unit_id`) REFERENCES `acad_unit` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_acad_lesson_estado` FOREIGN KEY (`estado_id`) REFERENCES `estado` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* ------------------------------------------------------------------ */
/* Capa D reducida — Ejercicios de práctica e intentos                 */
/* ------------------------------------------------------------------ */

CREATE TABLE IF NOT EXISTS `acad_exercise_type` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `empresa_id` INT NOT NULL COMMENT 'label:Empresa|rel:tercero|label:razon_social|title:Razón social de la empresa (tercero vinculado)',
    `codigo` VARCHAR(24) NOT NULL COMMENT 'MULTIPLE_CHOICE, OPEN_TEXT, AUDIO_RESPONSE|uppercase|order:10',
    `nombre_en` VARCHAR(60) NOT NULL COMMENT 'label:Name (English)|order:20',
    `skill_id` INT UNSIGNED NULL COMMENT 'rel:acad_skill|label:codigo|title:Default skill for this exercise type|order:30',
    `orden` SMALLINT NOT NULL DEFAULT 0 COMMENT 'order:40',
    `estado_id` SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'label:Status|reltipo:GENERAL|title:Exercise type active or inactive.|order:50',
    `created_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `created_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    `updated_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `updated_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    `deleted_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `deleted_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_acad_exercise_type_empresa_codigo` (`empresa_id`, `codigo`),
    KEY `idx_acad_exercise_type_empresa` (`empresa_id`),
    KEY `idx_acad_exercise_type_skill` (`skill_id`),
    CONSTRAINT `fk_acad_exercise_type_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresa` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_acad_exercise_type_skill` FOREIGN KEY (`skill_id`) REFERENCES `acad_skill` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_acad_exercise_type_estado` FOREIGN KEY (`estado_id`) REFERENCES `estado` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `acad_exercise` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `empresa_id` INT NOT NULL COMMENT 'label:Empresa|rel:tercero|label:razon_social|title:Razón social de la empresa (tercero vinculado)',
    `lesson_id` INT UNSIGNED NOT NULL COMMENT 'rel:acad_lesson|label:titulo_en|title:Lesson|order:10',
    `skill_id` INT UNSIGNED NOT NULL COMMENT 'rel:acad_skill|label:codigo|title:Skill practiced|order:20',
    `exercise_type_id` INT UNSIGNED NOT NULL COMMENT 'rel:acad_exercise_type|label:codigo|title:Exercise type|order:30',
    `descriptor_id` INT UNSIGNED NULL COMMENT 'rel:acad_cefr_descriptor|label:codigo|title:Optional can-do descriptor this exercise practices|order:40',
    `titulo_en` VARCHAR(200) NOT NULL COMMENT 'label:Title (English)|order:50',
    `prompt_en` TEXT NOT NULL COMMENT 'type:textarea|label:Prompt / instructions (English)|order:60|span:full',
    `audio_referencia_ruta` VARCHAR(500) NULL COMMENT 'show:none',
    `max_score` DECIMAL(6,2) NOT NULL DEFAULT 100.00 COMMENT 'label:Max score|order:70',
    `orden` SMALLINT NOT NULL DEFAULT 0 COMMENT 'order:80',
    `estado_id` SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'label:Status|reltipo:GENERAL|title:Exercise active or inactive.|order:90',
    `created_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `created_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    `updated_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `updated_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    `deleted_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `deleted_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    PRIMARY KEY (`id`),
    KEY `idx_acad_exercise_empresa` (`empresa_id`),
    KEY `idx_acad_exercise_lesson` (`lesson_id`),
    KEY `idx_acad_exercise_skill` (`skill_id`),
    KEY `idx_acad_exercise_type` (`exercise_type_id`),
    KEY `idx_acad_exercise_descriptor` (`descriptor_id`),
    CONSTRAINT `fk_acad_exercise_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresa` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_acad_exercise_lesson` FOREIGN KEY (`lesson_id`) REFERENCES `acad_lesson` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_acad_exercise_skill` FOREIGN KEY (`skill_id`) REFERENCES `acad_skill` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_acad_exercise_type` FOREIGN KEY (`exercise_type_id`) REFERENCES `acad_exercise_type` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_acad_exercise_descriptor` FOREIGN KEY (`descriptor_id`) REFERENCES `acad_cefr_descriptor` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_acad_exercise_estado` FOREIGN KEY (`estado_id`) REFERENCES `estado` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `acad_exercise_option` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `exercise_id` INT UNSIGNED NOT NULL,
    `texto_en` VARCHAR(500) NOT NULL,
    `es_correcta` TINYINT(1) NOT NULL DEFAULT 0,
    `orden` SMALLINT NOT NULL DEFAULT 0,
    `created_at` DATETIME(3) NULL DEFAULT NULL,
    `created_by` INT UNSIGNED NULL DEFAULT NULL,
    `updated_at` DATETIME(3) NULL DEFAULT NULL,
    `updated_by` INT UNSIGNED NULL DEFAULT NULL,
    `deleted_at` DATETIME(3) NULL DEFAULT NULL,
    `deleted_by` INT UNSIGNED NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_acad_exercise_option_exercise` (`exercise_id`),
    CONSTRAINT `fk_acad_exercise_option_exercise` FOREIGN KEY (`exercise_id`) REFERENCES `acad_exercise` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `acad_enrolment` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `empresa_id` INT NOT NULL,
    `sede_id` INT UNSIGNED NULL,
    `usuario_id` INT NOT NULL COMMENT 'Student user account',
    `level_id` INT UNSIGNED NOT NULL COMMENT 'Current CEFR level',
    `estado_id` SMALLINT UNSIGNED NOT NULL DEFAULT 11 COMMENT 'Enrolment status: 11=MATRICULADO 12=RETIRADO 13=GRADUADO (reltipo ACAD_MATRICULA)',
    `created_at` DATETIME(3) NULL DEFAULT NULL,
    `created_by` INT UNSIGNED NULL DEFAULT NULL,
    `updated_at` DATETIME(3) NULL DEFAULT NULL,
    `updated_by` INT UNSIGNED NULL DEFAULT NULL,
    `deleted_at` DATETIME(3) NULL DEFAULT NULL,
    `deleted_by` INT UNSIGNED NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_acad_enrolment_empresa_usuario` (`empresa_id`, `usuario_id`),
    KEY `idx_acad_enrolment_level` (`level_id`),
    CONSTRAINT `fk_acad_enrolment_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresa` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_acad_enrolment_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuario` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_acad_enrolment_level` FOREIGN KEY (`level_id`) REFERENCES `acad_level` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_acad_enrolment_estado` FOREIGN KEY (`estado_id`) REFERENCES `estado` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `acad_attempt` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `empresa_id` INT NOT NULL,
    `exercise_id` INT UNSIGNED NOT NULL,
    `usuario_id` INT NOT NULL COMMENT 'Student who attempted',
    `iniciado_at` DATETIME(3) NULL,
    `enviado_at` DATETIME(3) NULL,
    `respuesta_texto` TEXT NULL COMMENT 'Written answer (writing skill)',
    `selected_option_id` INT UNSIGNED NULL COMMENT 'Selected option (reading multiple choice)',
    `audio_ruta` VARCHAR(500) NULL COMMENT 'Recorded answer path (speaking skill)',
    `score` DECIMAL(6,2) NULL,
    `feedback_en` TEXT NULL,
    `calificado_by` INT NULL COMMENT 'Grader user account',
    `calificado_at` DATETIME(3) NULL,
    `estado_id` SMALLINT UNSIGNED NOT NULL DEFAULT 14 COMMENT 'Attempt status: 14=PENDIENTE 15=CALIFICADO 16=AUTO_CALIFICADO (reltipo ACAD_INTENTO)',
    `created_at` DATETIME(3) NULL DEFAULT NULL,
    `created_by` INT UNSIGNED NULL DEFAULT NULL,
    `updated_at` DATETIME(3) NULL DEFAULT NULL,
    `updated_by` INT UNSIGNED NULL DEFAULT NULL,
    `deleted_at` DATETIME(3) NULL DEFAULT NULL,
    `deleted_by` INT UNSIGNED NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_acad_attempt_empresa_estado` (`empresa_id`, `estado_id`),
    KEY `idx_acad_attempt_exercise` (`exercise_id`),
    KEY `idx_acad_attempt_usuario` (`usuario_id`),
    CONSTRAINT `fk_acad_attempt_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresa` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_acad_attempt_exercise` FOREIGN KEY (`exercise_id`) REFERENCES `acad_exercise` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_acad_attempt_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuario` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_acad_attempt_option` FOREIGN KEY (`selected_option_id`) REFERENCES `acad_exercise_option` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_acad_attempt_calificador` FOREIGN KEY (`calificado_by`) REFERENCES `usuario` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_acad_attempt_estado` FOREIGN KEY (`estado_id`) REFERENCES `estado` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* ------------------------------------------------------------------ */
/* Extensión del catálogo genérico de estados (no se crea tabla propia)*/
/* Ids reservados de forma explícita, siguiendo el patrón usado en     */
/* 20260529_estado_tipo.sql. Verificado antes de escribir esta         */
/* migración: tipoestado/estado_tipo llegan hasta id=4, estado hasta   */
/* id=10 — si se corrieron migraciones adicionales entre medio, ajustar*/
/* estos literales antes de ejecutar.                                  */
/* ------------------------------------------------------------------ */

INSERT INTO `tipoestado` (`id`, `codigo`, `nombre`, `orden`, `created_at`)
VALUES
    (5, 'ACAD_MATRICULA', 'Académico — matrícula del estudiante', 50, NOW(3)),
    (6, 'ACAD_INTENTO', 'Académico — intento de ejercicio', 60, NOW(3))
ON DUPLICATE KEY UPDATE
    `nombre` = VALUES(`nombre`),
    `orden` = VALUES(`orden`);

INSERT INTO `estado_tipo` (`id`, `codigo`, `nombre`, `orden`, `created_at`)
VALUES
    (5, 'ACAD_MATRICULA', 'Académico — matrícula del estudiante', 50, NOW(3)),
    (6, 'ACAD_INTENTO', 'Académico — intento de ejercicio', 60, NOW(3))
ON DUPLICATE KEY UPDATE
    `nombre` = VALUES(`nombre`),
    `orden` = VALUES(`orden`);

INSERT INTO `estado` (`id`, `tipoestado_id`, `estado_tipo_id`, `nombre`, `created_at`)
VALUES
    (11, 5, 5, 'MATRICULADO', NOW(3)),
    (12, 5, 5, 'RETIRADO', NOW(3)),
    (13, 5, 5, 'GRADUADO', NOW(3)),
    (14, 6, 6, 'PENDIENTE', NOW(3)),
    (15, 6, 6, 'CALIFICADO', NOW(3)),
    (16, 6, 6, 'AUTO_CALIFICADO', NOW(3))
ON DUPLICATE KEY UPDATE
    `tipoestado_id` = VALUES(`tipoestado_id`),
    `estado_tipo_id` = VALUES(`estado_tipo_id`),
    `nombre` = VALUES(`nombre`);

/* ------------------------------------------------------------------ */
/* Menú Academic                                                       */
/* ------------------------------------------------------------------ */

INSERT INTO modulo (nombre, icono, orden, estado_id)
SELECT 'Academic', '🎓', 400, 1
WHERE NOT EXISTS (SELECT 1 FROM modulo WHERE LOWER(TRIM(nombre)) = 'academic' LIMIT 1);

SET @mod_acad_id := (SELECT id FROM modulo WHERE LOWER(TRIM(nombre)) = 'academic' ORDER BY id LIMIT 1);

INSERT INTO item (modulo_id, nombre, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_acad_id, 'Academic', 'acad', '🎓', 10, NULL, 1
WHERE @mod_acad_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM item WHERE ruta = 'acad' LIMIT 1);

SET @item_acad_id := (SELECT id FROM item WHERE ruta = 'acad' LIMIT 1);

/* A. Configuración — catálogos CRUD genérico */
INSERT INTO item (modulo_id, nombre, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_acad_id, 'CEFR Levels', 'acad_level', '🏷️', 20, @item_acad_id, 1
WHERE @mod_acad_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM item WHERE ruta = 'acad_level' LIMIT 1);

INSERT INTO item (modulo_id, nombre, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_acad_id, 'Skills', 'acad_skill', '🗣️', 30, @item_acad_id, 1
WHERE @mod_acad_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM item WHERE ruta = 'acad_skill' LIMIT 1);

INSERT INTO item (modulo_id, nombre, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_acad_id, 'Can-do Descriptors', 'acad_cefr_descriptor', '📜', 40, @item_acad_id, 1
WHERE @mod_acad_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM item WHERE ruta = 'acad_cefr_descriptor' LIMIT 1);

INSERT INTO item (modulo_id, nombre, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_acad_id, 'Units', 'acad_unit', '📦', 50, @item_acad_id, 1
WHERE @mod_acad_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM item WHERE ruta = 'acad_unit' LIMIT 1);

INSERT INTO item (modulo_id, nombre, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_acad_id, 'Lessons', 'acad_lesson', '📖', 60, @item_acad_id, 1
WHERE @mod_acad_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM item WHERE ruta = 'acad_lesson' LIMIT 1);

INSERT INTO item (modulo_id, nombre, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_acad_id, 'Exercise Types', 'acad_exercise_type', '🧩', 70, @item_acad_id, 1
WHERE @mod_acad_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM item WHERE ruta = 'acad_exercise_type' LIMIT 1);

INSERT INTO item (modulo_id, nombre, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_acad_id, 'Exercises', 'acad_exercise', '✏️', 80, @item_acad_id, 1
WHERE @mod_acad_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM item WHERE ruta = 'acad_exercise' LIMIT 1);

/* B. Maestro/Profesor — vistas a medida */
INSERT INTO item (modulo_id, nombre, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_acad_id, 'Grade Pending Attempts', 'acad/grading', '✅', 90, @item_acad_id, 1
WHERE @mod_acad_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM item WHERE ruta = 'acad/grading' LIMIT 1);

INSERT INTO item (modulo_id, nombre, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_acad_id, 'Student Progress', 'acad/progress', '📊', 100, @item_acad_id, 1
WHERE @mod_acad_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM item WHERE ruta = 'acad/progress' LIMIT 1);

/* C. Estudiante — vistas a medida */
INSERT INTO item (modulo_id, nombre, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_acad_id, 'Study', 'acad/study', '📚', 110, @item_acad_id, 1
WHERE @mod_acad_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM item WHERE ruta = 'acad/study' LIMIT 1);

INSERT INTO item (modulo_id, nombre, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_acad_id, 'Take Exercise', 'acad/practice', '🎯', 120, @item_acad_id, 1
WHERE @mod_acad_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM item WHERE ruta = 'acad/practice' LIMIT 1);

INSERT INTO item (modulo_id, nombre, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_acad_id, 'My Progress', 'acad/myprogress', '📈', 130, @item_acad_id, 1
WHERE @mod_acad_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM item WHERE ruta = 'acad/myprogress' LIMIT 1);

/* ------------------------------------------------------------------ */
/* item_accion — ver/guardar/eliminar en catálogos + hub;               */
/* configurar en acad_exercise (opciones MCQ); ver(+guardar) en vistas  */
/* ------------------------------------------------------------------ */

INSERT INTO item_accion (item_id, accion_id, estado_id)
SELECT i.id, a.id, 1
FROM item i CROSS JOIN accion a
WHERE i.ruta IN (
    'acad_level', 'acad_skill', 'acad_cefr_descriptor',
    'acad_unit', 'acad_lesson', 'acad_exercise_type', 'acad_exercise'
)
  AND a.codigo IN ('ver', 'guardar', 'eliminar')
  AND NOT EXISTS (SELECT 1 FROM item_accion ia WHERE ia.item_id = i.id AND ia.accion_id = a.id);

INSERT INTO item_accion (item_id, accion_id, estado_id)
SELECT i.id, a.id, 1
FROM item i
INNER JOIN accion a ON a.codigo = 'configurar'
WHERE i.ruta = 'acad_exercise'
  AND NOT EXISTS (SELECT 1 FROM item_accion ia WHERE ia.item_id = i.id AND ia.accion_id = a.id);

INSERT INTO item_accion (item_id, accion_id, estado_id)
SELECT i.id, a.id, 1
FROM item i
INNER JOIN accion a ON a.codigo = 'ver'
WHERE i.ruta IN ('acad', 'acad/progress', 'acad/study', 'acad/myprogress')
  AND NOT EXISTS (SELECT 1 FROM item_accion ia WHERE ia.item_id = i.id AND ia.accion_id = a.id);

INSERT INTO item_accion (item_id, accion_id, estado_id)
SELECT i.id, a.id, 1
FROM item i CROSS JOIN accion a
WHERE i.ruta IN ('acad/grading', 'acad/practice')
  AND a.codigo IN ('ver', 'guardar')
  AND NOT EXISTS (SELECT 1 FROM item_accion ia WHERE ia.item_id = i.id AND ia.accion_id = a.id);

/* Verificación de humo (no falla la migración, solo referencia):
   SELECT i.ruta, COUNT(*) FROM item_accion ia INNER JOIN item i ON i.id = ia.item_id
   WHERE i.ruta LIKE 'acad%' GROUP BY i.ruta; */

/* ------------------------------------------------------------------ */
/* Roles nuevos                                                        */
/* ------------------------------------------------------------------ */

INSERT INTO rol (nombre, descripcion, estado_id)
SELECT 'Coordinador Académico', 'Administra currículo CEFR, ejercicios y ve progreso de estudiantes', 1
WHERE NOT EXISTS (SELECT 1 FROM rol WHERE LOWER(TRIM(nombre)) = 'coordinador académico' LIMIT 1);

INSERT INTO rol (nombre, descripcion, estado_id)
SELECT 'Docente Académico', 'Autoría de lecciones/ejercicios, calificación de intentos, progreso de estudiantes', 1
WHERE NOT EXISTS (SELECT 1 FROM rol WHERE LOWER(TRIM(nombre)) = 'docente académico' LIMIT 1);

INSERT INTO rol (nombre, descripcion, estado_id)
SELECT 'Estudiante', 'Estudia el currículo y toma ejercicios de práctica', 1
WHERE NOT EXISTS (SELECT 1 FROM rol WHERE LOWER(TRIM(nombre)) = 'estudiante' LIMIT 1);

SET @rol_coordinador_id := (SELECT id FROM rol WHERE LOWER(TRIM(nombre)) = 'coordinador académico' LIMIT 1);
SET @rol_docente_id := (SELECT id FROM rol WHERE LOWER(TRIM(nombre)) = 'docente académico' LIMIT 1);
SET @rol_estudiante_id := (SELECT id FROM rol WHERE LOWER(TRIM(nombre)) = 'estudiante' LIMIT 1);

/* ------------------------------------------------------------------ */
/* rol_permiso                                                         */
/* ------------------------------------------------------------------ */

/* Super Admin: acceso total a todo lo de Academic (patrón SGD) */
INSERT INTO rol_permiso (rol_id, empresa_id, sede_id, item_accion_id, estado_id)
SELECT 1, NULL, NULL, ia.id, 5
FROM item_accion ia INNER JOIN item i ON i.id = ia.item_id
WHERE (i.ruta = 'acad' OR i.ruta LIKE 'acad_%' OR i.ruta LIKE 'acad/%')
  AND NOT EXISTS (SELECT 1 FROM rol_permiso rp WHERE rp.rol_id = 1 AND rp.item_accion_id = ia.id AND rp.estado_id = 5);

/* Coordinador Académico: hub + todos los catálogos (incl. eliminar/configurar) + grading + progress */
INSERT INTO rol_permiso (rol_id, empresa_id, sede_id, item_accion_id, estado_id)
SELECT @rol_coordinador_id, NULL, NULL, ia.id, 5
FROM item_accion ia INNER JOIN item i ON i.id = ia.item_id
WHERE @rol_coordinador_id IS NOT NULL
  AND (
      i.ruta = 'acad'
      OR i.ruta IN ('acad_level', 'acad_skill', 'acad_cefr_descriptor', 'acad_unit', 'acad_lesson', 'acad_exercise_type', 'acad_exercise')
      OR i.ruta IN ('acad/grading', 'acad/progress')
  )
  AND NOT EXISTS (SELECT 1 FROM rol_permiso rp WHERE rp.rol_id = @rol_coordinador_id AND rp.item_accion_id = ia.id AND rp.estado_id = 5);

/* Docente Académico: hub + catálogos sin eliminar + configurar (opciones) + grading + progress */
INSERT INTO rol_permiso (rol_id, empresa_id, sede_id, item_accion_id, estado_id)
SELECT @rol_docente_id, NULL, NULL, ia.id, 5
FROM item_accion ia
INNER JOIN item i ON i.id = ia.item_id
INNER JOIN accion a ON a.id = ia.accion_id
WHERE @rol_docente_id IS NOT NULL
  AND (
      (i.ruta = 'acad' AND a.codigo = 'ver')
      OR (i.ruta IN ('acad_level', 'acad_skill', 'acad_cefr_descriptor', 'acad_unit', 'acad_lesson', 'acad_exercise_type', 'acad_exercise')
          AND a.codigo IN ('ver', 'guardar', 'configurar'))
      OR (i.ruta IN ('acad/grading', 'acad/progress'))
  )
  AND NOT EXISTS (SELECT 1 FROM rol_permiso rp WHERE rp.rol_id = @rol_docente_id AND rp.item_accion_id = ia.id AND rp.estado_id = 5);

/* Estudiante: hub + study + practice + my-progress */
INSERT INTO rol_permiso (rol_id, empresa_id, sede_id, item_accion_id, estado_id)
SELECT @rol_estudiante_id, NULL, NULL, ia.id, 5
FROM item_accion ia INNER JOIN item i ON i.id = ia.item_id
WHERE @rol_estudiante_id IS NOT NULL
  AND i.ruta IN ('acad', 'acad/study', 'acad/practice', 'acad/myprogress')
  AND NOT EXISTS (SELECT 1 FROM rol_permiso rp WHERE rp.rol_id = @rol_estudiante_id AND rp.item_accion_id = ia.id AND rp.estado_id = 5);
