/* ============================================================
   Academic Fase 1b — expone acad_enrolment vía CRUD genérico
   (matricular estudiante en un nivel CEFR). Complementa
   20260709_acad_fase1.sql: esa migración creó la tabla pero sin
   COLUMN_COMMENT listos para el motor CRUD ni ítem de menú.
============================================================ */

ALTER TABLE `acad_enrolment`
    MODIFY COLUMN `sede_id` INT UNSIGNED NULL COMMENT 'rel:sede|label:nombre|order:20',
    MODIFY COLUMN `usuario_id` INT NOT NULL COMMENT 'rel:usuario|label:username|title:Student user account|order:30',
    MODIFY COLUMN `level_id` INT UNSIGNED NOT NULL COMMENT 'rel:acad_level|label:codigo|title:Current CEFR level|order:40',
    MODIFY COLUMN `estado_id` SMALLINT UNSIGNED NOT NULL DEFAULT 11 COMMENT 'label:Enrolment status|reltipo:ACAD_MATRICULA|order:50',
    MODIFY COLUMN `created_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    MODIFY COLUMN `created_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    MODIFY COLUMN `updated_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    MODIFY COLUMN `updated_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    MODIFY COLUMN `deleted_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    MODIFY COLUMN `deleted_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none';

ALTER TABLE `acad_enrolment`
    MODIFY COLUMN `empresa_id` INT NOT NULL COMMENT 'label:Empresa|rel:tercero|label:razon_social|title:Razón social de la empresa (tercero vinculado)|order:10';

SET @item_acad_id := (SELECT id FROM item WHERE ruta = 'acad' LIMIT 1);

INSERT INTO item (modulo_id, nombre, ruta, icono, orden, item_padre_id, estado_id)
SELECT modulo_id, 'Student Enrolment', 'acad_enrolment', '🧑‍🎓', 25, id, 1
FROM item WHERE ruta = 'acad'
  AND NOT EXISTS (SELECT 1 FROM item WHERE ruta = 'acad_enrolment' LIMIT 1);

INSERT INTO item_accion (item_id, accion_id, estado_id)
SELECT i.id, a.id, 1
FROM item i CROSS JOIN accion a
WHERE i.ruta = 'acad_enrolment'
  AND a.codigo IN ('ver', 'guardar', 'eliminar')
  AND NOT EXISTS (SELECT 1 FROM item_accion ia WHERE ia.item_id = i.id AND ia.accion_id = a.id);

INSERT INTO rol_permiso (rol_id, empresa_id, sede_id, item_accion_id, estado_id)
SELECT 1, NULL, NULL, ia.id, 5
FROM item_accion ia INNER JOIN item i ON i.id = ia.item_id
WHERE i.ruta = 'acad_enrolment'
  AND NOT EXISTS (SELECT 1 FROM rol_permiso rp WHERE rp.rol_id = 1 AND rp.item_accion_id = ia.id AND rp.estado_id = 5);

INSERT INTO rol_permiso (rol_id, empresa_id, sede_id, item_accion_id, estado_id)
SELECT rp_src.rol_id, NULL, NULL, ia.id, 5
FROM item_accion ia
INNER JOIN item i ON i.id = ia.item_id
CROSS JOIN (SELECT DISTINCT id AS rol_id FROM rol WHERE nombre IN ('Coordinador Académico', 'Docente Académico')) rp_src
WHERE i.ruta = 'acad_enrolment'
  AND NOT EXISTS (SELECT 1 FROM rol_permiso rp WHERE rp.rol_id = rp_src.rol_id AND rp.item_accion_id = ia.id AND rp.estado_id = 5);
