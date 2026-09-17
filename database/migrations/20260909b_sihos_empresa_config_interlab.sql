/* ============================================================
   SIHOS — credenciales de ESCRITURA para "Interfaz Laboratorio", separadas
   tanto de las de solo lectura como de las de escritura contable
   (usuario_escritura/password_escritura_cifrado — ver 20260810). Deliberado:
   esta es la única credencial de este módulo con permiso de escritura sobre
   tablas clínicas (HojaProc, DetaPrue, Admision) y sobre las tablas de
   interfaz Roche (Interfaz_resultados_Roche, Interfaz_solicitudes_SIHOS,
   Interfaz_homologacion_lab) — nunca debe compartir grant/usuario de BD con
   la credencial de correcciones contables. Opcional: sin ella configurada,
   el botón "Procesar" y el CRUD de Homologación no aparecen para esa
   empresa (mismo criterio que usuario_escritura).
============================================================ */

ALTER TABLE `sihos_empresa_config`
    ADD COLUMN `usuario_interlab` VARCHAR(120) NULL DEFAULT NULL COMMENT 'show:none' AFTER `password_escritura_cifrado`,
    ADD COLUMN `password_interlab_cifrado` TEXT NULL DEFAULT NULL COMMENT 'show:none' AFTER `usuario_interlab`;
