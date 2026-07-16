/* ============================================================
   Academic Fase 1c — grupos de menú por audiencia (Docente vs
   Estudiante) + nombre_es en `item` para que el superadmin vea
   los nombres de menú en español sin tocar la UI en inglés que
   ven docentes/estudiantes. Complementa 20260709_acad_fase1.sql
   y 20260709_acad_fase1b_enrolment_crud.sql.
============================================================ */

-- 1) nombre_es: columna genérica en `item` (reutilizable a futuro
--    por cualquier módulo, no solo Academic). Fallback opcional:
--    si es NULL, el menú sigue mostrando `nombre` como siempre.
ALTER TABLE `item`
    ADD COLUMN `nombre_es` VARCHAR(191) NULL DEFAULT NULL COMMENT 'show:none' AFTER `nombre`;

-- 2) separar el grupo "GESTION" (mezcla docente+estudiante) en
--    "GESTIÓN DOCENTE" (matrícula, calificar, progreso) y
--    "ESTUDIANTE" (estudiar, practicar, mi progreso).
SET @modulo_acad_id := (SELECT id FROM modulo WHERE nombre = 'ACADEMIC' LIMIT 1);
SET @grp_gestion_id := (SELECT id FROM item WHERE modulo_id = @modulo_acad_id AND item_padre_id IS NULL AND nombre = 'GESTION' LIMIT 1);

UPDATE item SET nombre = 'GESTIÓN DOCENTE'
WHERE id = @grp_gestion_id;

INSERT INTO item (modulo_id, nombre, ruta, icono, orden, item_padre_id, estado_id)
SELECT @modulo_acad_id, 'ESTUDIANTE', '', '🎒', 30, NULL, 1
WHERE @grp_gestion_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM item WHERE modulo_id = @modulo_acad_id AND item_padre_id IS NULL AND nombre = 'ESTUDIANTE');

SET @grp_estudiante_id := (SELECT id FROM item WHERE modulo_id = @modulo_acad_id AND item_padre_id IS NULL AND nombre = 'ESTUDIANTE' LIMIT 1);

UPDATE item SET item_padre_id = @grp_estudiante_id
WHERE modulo_id = @modulo_acad_id AND ruta IN ('acad/study', 'acad/practice', 'acad/myprogress');

UPDATE item SET item_padre_id = @grp_gestion_id
WHERE modulo_id = @modulo_acad_id AND ruta IN ('acad_enrolment', 'acad/grading', 'acad/progress');

-- 3) nombre_es de los headers y de cada ítem de Academic (visible
--    solo si $_SESSION['es_super_admin'] es true, ver MenuService).
UPDATE item SET nombre_es = 'CONFIGURACIÓN' WHERE modulo_id = @modulo_acad_id AND item_padre_id IS NULL AND nombre = 'CONFIGURACION';
UPDATE item SET nombre_es = 'GESTIÓN DOCENTE' WHERE id = @grp_gestion_id;
UPDATE item SET nombre_es = 'ESTUDIANTE' WHERE id = @grp_estudiante_id;

UPDATE item SET nombre_es = 'Niveles CEFR' WHERE modulo_id = @modulo_acad_id AND ruta = 'acad_level';
UPDATE item SET nombre_es = 'Destrezas' WHERE modulo_id = @modulo_acad_id AND ruta = 'acad_skill';
UPDATE item SET nombre_es = 'Descriptores (Can-do)' WHERE modulo_id = @modulo_acad_id AND ruta = 'acad_cefr_descriptor';
UPDATE item SET nombre_es = 'Unidades' WHERE modulo_id = @modulo_acad_id AND ruta = 'acad_unit';
UPDATE item SET nombre_es = 'Lecciones' WHERE modulo_id = @modulo_acad_id AND ruta = 'acad_lesson';
UPDATE item SET nombre_es = 'Tipos de Ejercicio' WHERE modulo_id = @modulo_acad_id AND ruta = 'acad_exercise_type';
UPDATE item SET nombre_es = 'Ejercicios' WHERE modulo_id = @modulo_acad_id AND ruta = 'acad_exercise';
UPDATE item SET nombre_es = 'Matrícula de Estudiantes' WHERE modulo_id = @modulo_acad_id AND ruta = 'acad_enrolment';
UPDATE item SET nombre_es = 'Calificar Pendientes' WHERE modulo_id = @modulo_acad_id AND ruta = 'acad/grading';
UPDATE item SET nombre_es = 'Progreso de Estudiantes' WHERE modulo_id = @modulo_acad_id AND ruta = 'acad/progress';
UPDATE item SET nombre_es = 'Estudiar' WHERE modulo_id = @modulo_acad_id AND ruta = 'acad/study';
UPDATE item SET nombre_es = 'Practicar Ejercicio' WHERE modulo_id = @modulo_acad_id AND ruta = 'acad/practice';
UPDATE item SET nombre_es = 'Mi Progreso' WHERE modulo_id = @modulo_acad_id AND ruta = 'acad/myprogress';
