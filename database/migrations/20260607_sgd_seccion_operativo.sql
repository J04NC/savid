/* ============================================================
   F3b — Bloques operativos: clase `operativo` en sgd_seccion
   Ejecutar: mysql savid < database/migrations/20260607_sgd_seccion_operativo.sql
============================================================ */

ALTER TABLE `sgd_seccion`
    MODIFY COLUMN `clase` ENUM('auto','contenido','sistema','operativo') NOT NULL DEFAULT 'contenido'
        COMMENT 'auto=PDF maestro; contenido=redacción M4; sistema=datos SAVID; operativo=bloque formulario F/R|type:select';
