-- =============================================================================
-- Refactor: datos de persona en `tercero` + `terceroidentificacion`, no en `usuario`
-- =============================================================================
-- Objetivo (modelo limpio):
--   • `usuario` = cuenta de acceso: vínculo a persona (`tercero_id`), credenciales,
--     políticas de sesión, estado de la CUENTA, activos opcionales de cuenta.
--   • `tercero` = persona / sujeto maestro: nombres, apellidos, email de contacto, etc.
--   • `terceroidentificacion` = documentos (tipo, número, DV, principal, …).
--
-- NO ejecutar ciego: ajuste nombres de columnas según su `SHOW CREATE TABLE usuario`.
-- Haga backup y pruebe en copia de BD.
--
-- Formulario usuario deseado (mapeo recomendado):
--
--   Campo UI                    Dónde guardarlo (canónico)     ¿Común con tercero/ident.?
--   -------------------------   -----------------------------   --------------------------
--   Tipo de documento           terceroidentificacion.tipodocumento_id   Sí (identificación)
--   Número de documento         terceroidentificacion.numero           Sí
--   (DV si aplica)              terceroidentificacion.dv               Sí
--   Nombres                     tercero.nombres                        Sí
--   Apellidos                   tercero.apellidos                      Sí
--   Email                       tercero.email                          Sí (contacto persona)
--   Username                    usuario.username                       No (solo cuenta)
--   Password                    usuario.password                       No
--   Confirmar password          (solo validación en UI)                No columna
--   Tiempo sesión activa        usuario.* (nueva columna sugerida)     No
--   Foto                        tercero.foto_ruta (recomendado) o usuario si solo avatar de login
--   Firma                       tercero.firma_ruta (recomendado)       No en ident.; puede ser persona
--   Estado                      usuario.estado_id (cuenta)             No igual que tercero.estado_id(*)
--
-- (*) tercero.estado_id = estado del registro maestro (catálogo). usuario.estado_id = estado de la
--     cuenta (activo/bloqueado). Son conceptos distintos: mantenga ambos con etiquetas claras en UI.
--
-- Si hoy `usuario` tiene columnas duplicadas (nombre, email, documento…), después de migrar datos
-- a `tercero` / `terceroidentificacion` y asegurar `usuario.tercero_id`, puede eliminarlas de `usuario`.
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------------
-- 0) Columnas sugeridas en `tercero` para foto/firma (rutas a archivo o URL)
--    Omita si prefiere guardar foto/firma solo en `usuario`.
-- ---------------------------------------------------------------------------
-- ALTER TABLE `tercero`
--     ADD COLUMN `foto_ruta` VARCHAR(512) NULL DEFAULT NULL COMMENT 'Ruta o URL foto persona' AFTER `email`,
--     ADD COLUMN `firma_ruta` VARCHAR(512) NULL DEFAULT NULL COMMENT 'Ruta o URL firma' AFTER `foto_ruta`;

-- ---------------------------------------------------------------------------
-- 1) Columna en `usuario` para tiempo de sesión (ejemplo: minutos de inactividad hasta cierre)
-- ---------------------------------------------------------------------------
-- ALTER TABLE `usuario`
--     ADD COLUMN `sesion_idle_minutos` SMALLINT UNSIGNED NULL DEFAULT NULL
--         COMMENT 'Tiempo máx. inactividad sesión (minutos); NULL = usar política por defecto' AFTER `password`;

-- ---------------------------------------------------------------------------
-- 2) Asegurar `usuario.tercero_id` (FK ya puede existir vía add_usuario_tercero_id.sql)
-- ---------------------------------------------------------------------------
-- ALTER TABLE `usuario`
--     ADD COLUMN `tercero_id` INT UNSIGNED NULL DEFAULT NULL AFTER `estado_id`;
-- ALTER TABLE `usuario` ADD CONSTRAINT `fk_usuario_tercero` FOREIGN KEY (`tercero_id`) REFERENCES `tercero`(`id`);

-- ---------------------------------------------------------------------------
-- 3) Migración de datos (EJEMPLO genérico — ADAPTE nombres de columnas de `usuario`)
--
--    Escenario: cada usuario tiene o tendrá un tercero; copiamos datos comunes a `tercero` y creamos
--    una fila principal en `terceroidentificacion`.
--
--    Si ya tiene `tercero_id` poblado, solo actualice `tercero` / `terceroidentificacion` desde `usuario`
--    en lugar de crear terceros nuevos.
-- ---------------------------------------------------------------------------

-- Ejemplo: crear tercero mínimo para usuarios sin `tercero_id` (descomente y adapte columnas fuente):
/*
INSERT INTO `tercero` (`tipopersona_id`, `nombres`, `apellidos`, `email`, `estado_id`)
SELECT 1, u.`nombre`, u.`apellido`, u.`email`, 1
FROM `usuario` u
WHERE u.`tercero_id` IS NULL
  AND (u.`nombre` IS NOT NULL OR u.`email` IS NOT NULL);
-- Luego enlazar: UPDATE usuario u JOIN ... SET u.tercero_id = ...
*/

-- Ejemplo: insertar identificación principal (una por tercero) desde columnas viejos en usuario:
/*
INSERT INTO `terceroidentificacion`
    (`tercero_id`, `tipodocumento_id`, `numero`, `dv`, `principal`, `estado_id`)
SELECT u.`tercero_id`, u.`tipodocumento_id`, u.`documento`, NULL, 1, 1
FROM `usuario` u
WHERE u.`tercero_id` IS NOT NULL
  AND u.`tipodocumento_id` IS NOT NULL
  AND u.`documento` IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM `terceroidentificacion` ti
      WHERE ti.`tercero_id` = u.`tercero_id` AND ti.`principal` = 1
  );
*/

-- ---------------------------------------------------------------------------
-- 4) Eliminar de `usuario` columnas que quedaron duplicadas (EJEMPLOS — comente lo que NO tenga)
--
--    Orden sugerido: quitar FKs/índices que usen la columna, luego DROP COLUMN.
-- ---------------------------------------------------------------------------

-- ALTER TABLE `usuario` DROP INDEX `idx_usuario_email`;   -- si existía
-- ALTER TABLE `usuario` DROP COLUMN `nombre`;
-- ALTER TABLE `usuario` DROP COLUMN `apellido`;
-- ALTER TABLE `usuario` DROP COLUMN `apellidos`;
-- ALTER TABLE `usuario` DROP COLUMN `email`;
-- ALTER TABLE `usuario` DROP COLUMN `correo`;
-- ALTER TABLE `usuario` DROP COLUMN `tipodocumento_id`;
-- ALTER TABLE `usuario` DROP COLUMN `tipo_documento_id`;
-- ALTER TABLE `usuario` DROP COLUMN `documento`;
-- ALTER TABLE `usuario` DROP COLUMN `doc_numero`;
-- ALTER TABLE `usuario` DROP COLUMN `numero_documento`;
-- ALTER TABLE `usuario` DROP COLUMN `dv`;

-- Si movió foto/firma a tercero y antes estaban en usuario:
-- ALTER TABLE `usuario` DROP COLUMN `foto`;
-- ALTER TABLE `usuario` DROP COLUMN `firma`;

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- Resumen de viabilidad
-- =============================================================================
-- • Es totalmente posible y es un patrón estándar (cuenta ↔ persona maestra).
-- • La app debe guardar en una transacción: tercero + identificación principal + usuario (opción B).
-- • "Confirmar contraseña" nunca es columna; validación solo en servidor/cliente.
-- • Mantenga dos "estados" con nombres distintos en pantalla para no confundir al usuario final.
-- =============================================================================
