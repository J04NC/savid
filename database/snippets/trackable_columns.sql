/* ============================================================
   Columnas estándar de trazabilidad y soft delete (SAVID)
   Incluir al final de cada CREATE TABLE con PK id.
   Comentario show:none → ocultas en formulario y grilla CRUD.
============================================================ */

    `created_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `created_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    `updated_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `updated_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    `deleted_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `deleted_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none'

/* Tablas de solo auditoría (sin soft delete): omitir deleted_* */
