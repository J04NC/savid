/* ============================================================
   sgd_documento: un solo estado vía estado_id (tipo DOCUMENTAL)
   Migra estado_documental → estado_id y elimina la columna varchar.
============================================================ */

UPDATE `sgd_documento`
SET `estado_id` = CASE LOWER(TRIM(`estado_documental`))
    WHEN 'borrador' THEN 7
    WHEN 'vigente' THEN 8
    WHEN 'obsoleto' THEN 9
    WHEN 'firmado' THEN 10
    ELSE 8
END
WHERE `estado_documental` IS NOT NULL AND TRIM(`estado_documental`) <> '';

UPDATE `sgd_documento`
SET `estado_id` = 8
WHERE `estado_id` IN (1, 2);

ALTER TABLE `sgd_documento`
    DROP COLUMN `estado_documental`;

ALTER TABLE `sgd_documento`
    MODIFY COLUMN `estado_id` SMALLINT UNSIGNED NOT NULL DEFAULT 8
    COMMENT 'label:Estado documental|reltipo:DOCUMENTAL|title:Ciclo de vida del documento (borrador, vigente, obsoleto, firmado).';
