/* ============================================================
   F3a — Archivo oficial polimórfico + vínculo plantilla operativa
   Ejecutar: mysql savid < database/migrations/20260608_sgd_documento_version_archivo.sql
============================================================ */

ALTER TABLE `sgd_documento_version`
    ADD COLUMN `archivo_tipo` VARCHAR(16) NULL DEFAULT NULL
        COMMENT 'pdf, xlsx, docx… Tipo del archivo oficial del esqueleto'
        AFTER `archivo_ruta`,
    ADD COLUMN `formulario_version_id` INT UNSIGNED NULL DEFAULT NULL
        COMMENT 'rel:sgd_formulario_version|label:numero — plantilla operativa publicada junto a esta versión'
        AFTER `archivo_tipo`;

ALTER TABLE `sgd_documento_version`
    ADD KEY `idx_sgd_doc_ver_formulario_version` (`formulario_version_id`);

ALTER TABLE `sgd_documento_version`
    ADD CONSTRAINT `fk_sgd_doc_ver_formulario_version`
        FOREIGN KEY (`formulario_version_id`) REFERENCES `sgd_formulario_version` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE;
