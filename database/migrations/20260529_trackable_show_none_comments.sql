/* ============================================================
   Comentarios show:none en columnas de auditoría / soft delete
   Aplicado en BD vía: php scripts/apply_trackable_column_comments.php
   (este archivo documenta la convención; no re-ejecutar ALTER masivos aquí)
============================================================ */

-- Convención: created_at, created_by, updated_at, updated_by, deleted_at, deleted_by
-- COMMENT 'show:none'  →  ocultas en CRUD (formulario y tabla).
-- Ver plantilla: database/snippets/trackable_columns.sql
