/* ============================================================
   Catálogo general `tipo_tercero` (módulo ADMINISTRACION) y tabla
   operativa `tercero_nomina` (módulo NOMINA, carpeta CONFIGURACION):
   administradoras (AFP/EPS/ARL/CCF), fondos de cesantías y entidades
   gubernamentales que intervienen en la nómina, con el nombre exacto
   que exige el operador de "aportes en línea" (PILA) — ver
   SihosNominaPilaService/SihosExternalRepository::fetchNominaPila().

   `tipo_tercero` queda deliberadamente en ADMINISTRACION y no en
   NOMINA: es un catálogo general reutilizable por cualquier módulo
   futuro, no exclusivo de nómina.

   La identidad de cada tercero_nomina se ancla en
   `terceroidentificacion` (no en `tercero` directo) — mismo patrón que
   `usuario.terceroidentificacion_id` — así se hereda el
   UNIQUE(tipodocumento_id, numero) que ya evita duplicar un NIT.

   `empresa_id` es NULLABLE: NULL = catálogo compartido entre todas las
   empresas (AFP/EPS/ARL/CCF/ICBF/SENA/DIAN nacionales); con valor =
   específico de una empresa (cooperativa o fondo propio de ese
   cliente). `empresa_dedup` (columna generada) convierte NULL->0
   solo para el índice único: MySQL trata cada NULL como distinto en un
   UNIQUE normal, así que sin esto dos filas "globales" del mismo
   tercero+tipo no chocarían entre sí.

   No se filtran los tipos "sindicato"/"banco"/"cooperativa" en el
   catálogo inicial (decisión del usuario): quedan bajo OTRO hasta que
   el módulo de nómina real los necesite distinguidos — agregar un
   tipo nuevo más adelante no rompe las filas ya existentes.

   LIMITACIÓN CONOCIDA (verificada con pruebas, no se corrigió aquí por
   quedar fuera del alcance de esta migración): CrudService::save() trae
   una regla genérica para TODA tabla con columna `empresa_id` — si el
   POST llega con ese campo vacío, lo autocompleta con la empresa de la
   sesión activa (pensada para altas nuevas). Eso significa que editar
   una fila GLOBAL (empresa_id NULL) desde el formulario CRUD genérico,
   sin tocar el campo Empresa, la reasigna en silencio a la empresa de
   quien edita. Las filas globales (AFP/EPS/ARL/CCF/ICBF/SENA/DIAN
   nacionales) deben crearse/corregirse por el script de importación o
   por SQL directo, no editando otros campos desde este formulario.
============================================================ */

/* ------------------------------------------------------------------ */
/* tipo_tercero — catálogo general (ADMINISTRACION)                    */
/* ------------------------------------------------------------------ */

CREATE TABLE IF NOT EXISTS `tipo_tercero` (
    `id` SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `codigo` VARCHAR(30) NOT NULL COMMENT 'uppercase|order:10',
    `nombre` VARCHAR(100) NOT NULL COMMENT 'order:20',
    `estado_id` SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'label:Estado|reltipo:GENERAL|order:30',
    `created_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `created_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    `updated_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `updated_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    `deleted_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `deleted_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_tipo_tercero_codigo` (`codigo`),
    KEY `idx_tipo_tercero_deleted` (`deleted_at`),
    CONSTRAINT `fk_tipo_tercero_estado` FOREIGN KEY (`estado_id`) REFERENCES `estado` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tipo_tercero (codigo, nombre)
SELECT 'AFP', 'Administradora de Fondos de Pensiones' WHERE NOT EXISTS (SELECT 1 FROM tipo_tercero WHERE codigo = 'AFP');
INSERT INTO tipo_tercero (codigo, nombre)
SELECT 'FONDO_CESANTIAS', 'Fondo de Cesantías' WHERE NOT EXISTS (SELECT 1 FROM tipo_tercero WHERE codigo = 'FONDO_CESANTIAS');
INSERT INTO tipo_tercero (codigo, nombre)
SELECT 'EPS', 'Entidad Promotora de Salud' WHERE NOT EXISTS (SELECT 1 FROM tipo_tercero WHERE codigo = 'EPS');
INSERT INTO tipo_tercero (codigo, nombre)
SELECT 'ARL', 'Administradora de Riesgos Laborales' WHERE NOT EXISTS (SELECT 1 FROM tipo_tercero WHERE codigo = 'ARL');
INSERT INTO tipo_tercero (codigo, nombre)
SELECT 'CCF', 'Caja de Compensación Familiar' WHERE NOT EXISTS (SELECT 1 FROM tipo_tercero WHERE codigo = 'CCF');
INSERT INTO tipo_tercero (codigo, nombre)
SELECT 'GUBERNAMENTAL', 'Entidad gubernamental (ICBF, SENA, DIAN...)' WHERE NOT EXISTS (SELECT 1 FROM tipo_tercero WHERE codigo = 'GUBERNAMENTAL');
INSERT INTO tipo_tercero (codigo, nombre)
SELECT 'OTRO', 'Otro' WHERE NOT EXISTS (SELECT 1 FROM tipo_tercero WHERE codigo = 'OTRO');

/* Ítem plano en ADMINISTRACION, hermano de TERCERO (mismo patrón: EMPRESA/USUARIO/TERCERO/ITEM/ROL sin carpeta contenedora). */
INSERT INTO item (modulo_id, nombre, ruta, icono, orden, item_padre_id, estado_id)
SELECT 1, 'TIPO TERCERO', 'tipo_tercero', '🏷️', 5, NULL, 1
WHERE NOT EXISTS (SELECT 1 FROM item WHERE ruta = 'tipo_tercero');

INSERT INTO item_accion (item_id, accion_id, estado_id)
SELECT i.id, a.id, 1
FROM item i
INNER JOIN accion a ON a.codigo IN ('ver', 'guardar', 'eliminar')
WHERE i.ruta = 'tipo_tercero'
  AND NOT EXISTS (SELECT 1 FROM item_accion ia WHERE ia.item_id = i.id AND ia.accion_id = a.id);

/* ------------------------------------------------------------------ */
/* tercero_nomina — operativa (NOMINA > CONFIGURACION)                 */
/* ------------------------------------------------------------------ */

CREATE TABLE IF NOT EXISTS `tercero_nomina` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `terceroidentificacion_id` BIGINT UNSIGNED NOT NULL COMMENT 'label:Tercero|rel:tercero|label:razon_social|relmode:autocomplete|order:10',
    `tipo_tercero_id` SMALLINT UNSIGNED NOT NULL COMMENT 'label:Tipo|rel:tipo_tercero|label:nombre|order:20',
    `empresa_id` INT NULL COMMENT 'label:Empresa (vacío = compartido)|rel:empresa|label:tercero_id|order:30|title:Vacío = catálogo nacional (AFP/EPS/ARL/CCF/ICBF/SENA/DIAN...). Con valor = específico de esa empresa (cooperativa o fondo propio). OJO al editar una fila global desde este formulario: CrudService::save() autocompleta empresa_id con la empresa de la sesión activa si llega vacío en el POST (comportamiento genérico para toda tabla con esta columna) — editar CUALQUIER otro campo de una fila global sin querer la vuelve específica de tu empresa. Para tocar filas globales, hazlo por script/SQL directo, no por este formulario.',
    `empresa_dedup` INT GENERATED ALWAYS AS (COALESCE(`empresa_id`, 0)) STORED COMMENT 'show:none',
    `nombre_pila` VARCHAR(100) NULL COMMENT 'order:40|uppercase|title:Nombre exacto exigido por el operador de aportes en línea (PILA). Solo aplica a AFP/EPS/ARL/CCF.',
    `estado_id` SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'label:Estado|reltipo:GENERAL|order:50',
    `created_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `created_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    `updated_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `updated_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    `deleted_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `deleted_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_tercero_nomina` (`terceroidentificacion_id`, `tipo_tercero_id`, `empresa_dedup`),
    KEY `idx_tercero_nomina_tipo` (`tipo_tercero_id`),
    KEY `idx_tercero_nomina_deleted` (`deleted_at`),
    CONSTRAINT `fk_tercero_nomina_terceroident` FOREIGN KEY (`terceroidentificacion_id`) REFERENCES `terceroidentificacion` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_tercero_nomina_tipo` FOREIGN KEY (`tipo_tercero_id`) REFERENCES `tipo_tercero` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    -- empresa_id es columna base de empresa_dedup (generada): MySQL no permite
    -- ON UPDATE CASCADE (ni ON DELETE SET NULL) sobre una FK que alimenta una
    -- columna generada, por eso aquí va RESTRICT y no CASCADE como las demás.
    CONSTRAINT `fk_tercero_nomina_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresa` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_tercero_nomina_estado` FOREIGN KEY (`estado_id`) REFERENCES `estado` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @mod_nomina_id := (SELECT id FROM modulo WHERE UPPER(TRIM(nombre)) = 'NOMINA' ORDER BY id LIMIT 1);
SET @item_nomina_config_id := (SELECT id FROM item WHERE modulo_id = @mod_nomina_id AND item_padre_id IS NULL AND UPPER(TRIM(nombre)) = 'CONFIGURACION' LIMIT 1);

INSERT INTO item (modulo_id, nombre, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_nomina_id, 'Terceros Nómina', 'tercero_nomina', '🏦', 10, @item_nomina_config_id, 1
WHERE @mod_nomina_id IS NOT NULL AND @item_nomina_config_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM item WHERE ruta = 'tercero_nomina');

INSERT INTO item_accion (item_id, accion_id, estado_id)
SELECT i.id, a.id, 1
FROM item i
INNER JOIN accion a ON a.codigo IN ('ver', 'guardar', 'eliminar')
WHERE i.ruta = 'tercero_nomina'
  AND NOT EXISTS (SELECT 1 FROM item_accion ia WHERE ia.item_id = i.id AND ia.accion_id = a.id);
