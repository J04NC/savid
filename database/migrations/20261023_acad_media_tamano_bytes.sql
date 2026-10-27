/* ============================================================
   acad_exercise_reference_audio / acad_attempt: agrega tamano_bytes.

   Para poder sumar cuánto pesa hoy el storage de audios de Acad (TTS de
   referencia + grabaciones de estudiantes) y bloquear nuevos audios antes
   de llegar al tier gratis de R2 (10 GB) si en algún momento se activa
   ACAD_MEDIA_STORAGE_DRIVER=s3 — ver AcadMediaQuotaService.

   Las filas existentes quedan en NULL; se rellenan por separado con
   scripts/apply_acad_media_tamano_backfill.php (necesita leer el tamaño
   real del archivo en disco, SQL solo no alcanza).
============================================================ */

ALTER TABLE acad_exercise_reference_audio
    ADD COLUMN tamano_bytes BIGINT UNSIGNED NULL COMMENT 'show:none' AFTER ruta;

ALTER TABLE acad_attempt
    ADD COLUMN tamano_bytes BIGINT UNSIGNED NULL COMMENT 'show:none' AFTER audio_ruta;
