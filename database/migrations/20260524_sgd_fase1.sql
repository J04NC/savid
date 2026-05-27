/* ============================================================
   SGD Fase 1 — Catálogos multiempresa + menú
   Ejecutar una vez en la BD savid.
============================================================ */

/* ------------------------------------------------------------------ */
/* Catálogos y configuración                                           */
/* ------------------------------------------------------------------ */

CREATE TABLE IF NOT EXISTS `sgd_empresa_config` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `empresa_id` INT UNSIGNED NOT NULL,
    `sgd_activo` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=SGD habilitado para la empresa',
    `patron_documento` VARCHAR(120) NULL COMMENT 'Patrón legible ej. {proceso}-{tipo}{n}',
    `patron_carpeta` VARCHAR(80) NULL COMMENT 'Patrón legible ej. {dep}.{serie}.{subserie}',
    `ccd_vigencia` VARCHAR(32) NULL COMMENT 'Etiqueta vigencia CCD activa',
    `ccd_anio` SMALLINT UNSIGNED NULL,
    `config_json` JSON NULL COMMENT 'Reglas importación, perfiles, flags',
    `estado_id` INT UNSIGNED NOT NULL DEFAULT 1,
    `created_at` DATETIME(3) NULL DEFAULT NULL,
    `created_by` INT UNSIGNED NULL,
    `updated_at` DATETIME(3) NULL DEFAULT NULL,
    `updated_by` INT UNSIGNED NULL,
    `deleted_at` DATETIME(3) NULL DEFAULT NULL,
    `deleted_by` INT UNSIGNED NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_sgd_empresa_config_empresa` (`empresa_id`),
    KEY `idx_sgd_empresa_config_estado` (`estado_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sgd_proceso` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `empresa_id` INT UNSIGNED NOT NULL,
    `codigo` VARCHAR(32) NOT NULL COMMENT 'Prefijo proceso|rel:sgd_proceso|label:codigo',
    `nombre` VARCHAR(255) NOT NULL,
    `tipo_proceso` VARCHAR(64) NULL COMMENT 'Estratégico, misional, apoyo…',
    `orden` INT NOT NULL DEFAULT 0,
    `estado_id` INT UNSIGNED NOT NULL DEFAULT 1,
    `created_at` DATETIME(3) NULL DEFAULT NULL,
    `created_by` INT UNSIGNED NULL,
    `updated_at` DATETIME(3) NULL DEFAULT NULL,
    `updated_by` INT UNSIGNED NULL,
    `deleted_at` DATETIME(3) NULL DEFAULT NULL,
    `deleted_by` INT UNSIGNED NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_sgd_proceso_empresa_codigo` (`empresa_id`, `codigo`),
    KEY `idx_sgd_proceso_empresa` (`empresa_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sgd_tipo_documental` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `empresa_id` INT UNSIGNED NOT NULL,
    `codigo` VARCHAR(16) NOT NULL COMMENT 'PD, F, R, M…|rel:sgd_tipo_documental|label:codigo',
    `nombre` VARCHAR(120) NOT NULL,
    `modo` ENUM('maestro','dinamico','hibrido') NOT NULL DEFAULT 'dinamico' COMMENT 'type:select',
    `perfil_elaboracion_json` JSON NULL,
    `orden` INT NOT NULL DEFAULT 0,
    `estado_id` INT UNSIGNED NOT NULL DEFAULT 1,
    `created_at` DATETIME(3) NULL DEFAULT NULL,
    `created_by` INT UNSIGNED NULL,
    `updated_at` DATETIME(3) NULL DEFAULT NULL,
    `updated_by` INT UNSIGNED NULL,
    `deleted_at` DATETIME(3) NULL DEFAULT NULL,
    `deleted_by` INT UNSIGNED NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_sgd_tipo_doc_empresa_codigo` (`empresa_id`, `codigo`),
    KEY `idx_sgd_tipo_doc_empresa` (`empresa_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sgd_dependencia` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `empresa_id` INT UNSIGNED NOT NULL,
    `codigo` VARCHAR(32) NOT NULL COMMENT 'Código sección CCD|rel:sgd_dependencia|label:codigo',
    `nombre` VARCHAR(255) NOT NULL,
    `orden` INT NOT NULL DEFAULT 0,
    `estado_id` INT UNSIGNED NOT NULL DEFAULT 1,
    `created_at` DATETIME(3) NULL DEFAULT NULL,
    `created_by` INT UNSIGNED NULL,
    `updated_at` DATETIME(3) NULL DEFAULT NULL,
    `updated_by` INT UNSIGNED NULL,
    `deleted_at` DATETIME(3) NULL DEFAULT NULL,
    `deleted_by` INT UNSIGNED NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_sgd_dependencia_empresa_codigo` (`empresa_id`, `codigo`),
    KEY `idx_sgd_dependencia_empresa` (`empresa_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sgd_serie` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `empresa_id` INT UNSIGNED NOT NULL,
    `dependencia_id` INT UNSIGNED NOT NULL COMMENT 'rel:sgd_dependencia|label:nombre',
    `codigo` VARCHAR(32) NOT NULL,
    `nombre` VARCHAR(255) NOT NULL,
    `orden` INT NOT NULL DEFAULT 0,
    `estado_id` INT UNSIGNED NOT NULL DEFAULT 1,
    `created_at` DATETIME(3) NULL DEFAULT NULL,
    `created_by` INT UNSIGNED NULL,
    `updated_at` DATETIME(3) NULL DEFAULT NULL,
    `updated_by` INT UNSIGNED NULL,
    `deleted_at` DATETIME(3) NULL DEFAULT NULL,
    `deleted_by` INT UNSIGNED NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_sgd_serie_dep_codigo` (`dependencia_id`, `codigo`),
    KEY `idx_sgd_serie_empresa` (`empresa_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sgd_subserie` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `empresa_id` INT UNSIGNED NOT NULL,
    `serie_id` INT UNSIGNED NOT NULL COMMENT 'rel:sgd_serie|label:nombre',
    `codigo` VARCHAR(32) NOT NULL,
    `nombre` VARCHAR(255) NOT NULL,
    `orden` INT NOT NULL DEFAULT 0,
    `estado_id` INT UNSIGNED NOT NULL DEFAULT 1,
    `created_at` DATETIME(3) NULL DEFAULT NULL,
    `created_by` INT UNSIGNED NULL,
    `updated_at` DATETIME(3) NULL DEFAULT NULL,
    `updated_by` INT UNSIGNED NULL,
    `deleted_at` DATETIME(3) NULL DEFAULT NULL,
    `deleted_by` INT UNSIGNED NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_sgd_subserie_serie_codigo` (`serie_id`, `codigo`),
    KEY `idx_sgd_subserie_empresa` (`empresa_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sgd_documento` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `empresa_id` INT UNSIGNED NOT NULL,
    `codigo` VARCHAR(64) NOT NULL COMMENT 'Código listado maestro|rel:sgd_documento|label:codigo',
    `nombre` VARCHAR(500) NOT NULL,
    `proceso_id` INT UNSIGNED NULL COMMENT 'rel:sgd_proceso|label:codigo',
    `tipo_documental_id` INT UNSIGNED NULL COMMENT 'rel:sgd_tipo_documental|label:codigo',
    `modo` ENUM('maestro','dinamico','hibrido') NULL COMMENT 'Override opcional|type:select',
    `version_actual` VARCHAR(32) NULL,
    `fecha_primera_aprobacion` DATE NULL,
    `fecha_ultima_aprobacion` DATE NULL,
    `estado_documental` VARCHAR(32) NOT NULL DEFAULT 'vigente',
    `estado_id` INT UNSIGNED NOT NULL DEFAULT 1,
    `created_at` DATETIME(3) NULL DEFAULT NULL,
    `created_by` INT UNSIGNED NULL,
    `updated_at` DATETIME(3) NULL DEFAULT NULL,
    `updated_by` INT UNSIGNED NULL,
    `deleted_at` DATETIME(3) NULL DEFAULT NULL,
    `deleted_by` INT UNSIGNED NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_sgd_documento_empresa_codigo` (`empresa_id`, `codigo`),
    KEY `idx_sgd_documento_proceso` (`proceso_id`),
    KEY `idx_sgd_documento_tipo` (`tipo_documental_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sgd_ccd_entrada` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `empresa_id` INT UNSIGNED NOT NULL,
    `dependencia_id` INT UNSIGNED NOT NULL COMMENT 'rel:sgd_dependencia|label:codigo',
    `serie_id` INT UNSIGNED NULL COMMENT 'rel:sgd_serie|label:nombre',
    `subserie_id` INT UNSIGNED NULL COMMENT 'rel:sgd_subserie|label:nombre',
    `codigo_carpeta` VARCHAR(32) NOT NULL COMMENT 'Ej. 40.1.7',
    `nombre_serie` VARCHAR(255) NULL,
    `nombre_subserie` VARCHAR(500) NULL,
    `soporte_formato` VARCHAR(64) NULL,
    `codigo_calidad` VARCHAR(64) NULL COMMENT 'Vínculo listado maestro',
    `documento_id` INT UNSIGNED NULL COMMENT 'rel:sgd_documento|label:codigo',
    `ccd_vigencia` VARCHAR(32) NULL,
    `ccd_anio` SMALLINT UNSIGNED NULL,
    `orden` INT NOT NULL DEFAULT 0,
    `estado_id` INT UNSIGNED NOT NULL DEFAULT 1,
    `created_at` DATETIME(3) NULL DEFAULT NULL,
    `created_by` INT UNSIGNED NULL,
    `updated_at` DATETIME(3) NULL DEFAULT NULL,
    `updated_by` INT UNSIGNED NULL,
    `deleted_at` DATETIME(3) NULL DEFAULT NULL,
    `deleted_by` INT UNSIGNED NULL,
    PRIMARY KEY (`id`),
    KEY `idx_sgd_ccd_empresa` (`empresa_id`),
    KEY `idx_sgd_ccd_dependencia` (`dependencia_id`),
    KEY `idx_sgd_ccd_codigo_carpeta` (`empresa_id`, `codigo_carpeta`),
    KEY `idx_sgd_ccd_codigo_calidad` (`empresa_id`, `codigo_calidad`),
    KEY `idx_sgd_ccd_documento` (`documento_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sgd_import_log` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `empresa_id` INT UNSIGNED NOT NULL,
    `tipo` VARCHAR(32) NOT NULL COMMENT 'ccd|maestro|sgi_index',
    `archivo` VARCHAR(500) NULL,
    `resumen_json` JSON NULL,
    `usuario_id` INT UNSIGNED NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`id`),
    KEY `idx_sgd_import_log_empresa` (`empresa_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* ------------------------------------------------------------------ */
/* Menú SGD                                                            */
/* ------------------------------------------------------------------ */

INSERT INTO modulo (nombre, icono, orden, estado_id)
SELECT 'Gestión documental', '📁', 350, 1
WHERE NOT EXISTS (
    SELECT 1 FROM modulo WHERE LOWER(TRIM(nombre)) = 'gestión documental'
       OR LOWER(TRIM(nombre)) = 'gestion documental'
    LIMIT 1
);

SET @mod_sgd_id := (
    SELECT id FROM modulo
    WHERE LOWER(TRIM(nombre)) IN ('gestión documental', 'gestion documental')
    ORDER BY id LIMIT 1
);

INSERT INTO item (modulo_id, nombre, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_sgd_id, 'Gestión documental', '', '📁', 10, NULL, 1
WHERE @mod_sgd_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM item
      WHERE modulo_id = @mod_sgd_id AND (item_padre_id IS NULL OR item_padre_id = 0)
        AND LOWER(TRIM(nombre)) IN ('gestión documental', 'gestion documental')
      LIMIT 1
  );

SET @item_sgd_id := (
    SELECT id FROM item
    WHERE modulo_id = @mod_sgd_id AND (item_padre_id IS NULL OR item_padre_id = 0)
    ORDER BY id LIMIT 1
);

INSERT INTO item (modulo_id, nombre, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_sgd_id, 'Configuración SGD', 'sgd/config', '⚙️', 20, @item_sgd_id, 1
WHERE @mod_sgd_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM item WHERE ruta = 'sgd/config' LIMIT 1);

INSERT INTO item (modulo_id, nombre, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_sgd_id, 'Importar datos', 'sgd/importar', '📥', 30, @item_sgd_id, 1
WHERE @mod_sgd_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM item WHERE ruta = 'sgd/importar' LIMIT 1);

INSERT INTO item (modulo_id, nombre, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_sgd_id, 'Procesos', 'sgd_proceso', '🗂️', 40, @item_sgd_id, 1
WHERE @mod_sgd_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM item WHERE ruta = 'sgd_proceso' LIMIT 1);

INSERT INTO item (modulo_id, nombre, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_sgd_id, 'Tipos documentales', 'sgd_tipo_documental', '📋', 50, @item_sgd_id, 1
WHERE @mod_sgd_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM item WHERE ruta = 'sgd_tipo_documental' LIMIT 1);

INSERT INTO item (modulo_id, nombre, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_sgd_id, 'Dependencias', 'sgd_dependencia', '🏢', 60, @item_sgd_id, 1
WHERE @mod_sgd_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM item WHERE ruta = 'sgd_dependencia' LIMIT 1);

INSERT INTO item (modulo_id, nombre, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_sgd_id, 'Series', 'sgd_serie', '📂', 70, @item_sgd_id, 1
WHERE @mod_sgd_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM item WHERE ruta = 'sgd_serie' LIMIT 1);

INSERT INTO item (modulo_id, nombre, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_sgd_id, 'Subseries', 'sgd_subserie', '📂', 80, @item_sgd_id, 1
WHERE @mod_sgd_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM item WHERE ruta = 'sgd_subserie' LIMIT 1);

INSERT INTO item (modulo_id, nombre, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_sgd_id, 'Documentos (listado maestro)', 'sgd_documento', '📄', 90, @item_sgd_id, 1
WHERE @mod_sgd_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM item WHERE ruta = 'sgd_documento' LIMIT 1);

INSERT INTO item (modulo_id, nombre, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_sgd_id, 'Entradas CCD', 'sgd_ccd_entrada', '🗃️', 100, @item_sgd_id, 1
WHERE @mod_sgd_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM item WHERE ruta = 'sgd_ccd_entrada' LIMIT 1);

/* Acciones: ver, guardar, eliminar, importar, configurar */
INSERT INTO accion (codigo, nombre, estado_id)
SELECT 'importar', 'Importar', 1
WHERE NOT EXISTS (SELECT 1 FROM accion WHERE codigo = 'importar' LIMIT 1);

INSERT INTO accion (codigo, nombre, estado_id)
SELECT 'configurar', 'Configurar', 1
WHERE NOT EXISTS (SELECT 1 FROM accion WHERE codigo = 'configurar' LIMIT 1);

INSERT INTO item_accion (item_id, accion_id, estado_id)
SELECT i.id, a.id, 1
FROM item i
CROSS JOIN accion a
WHERE i.ruta IN (
    'sgd', 'sgd/config', 'sgd/importar',
    'sgd_proceso', 'sgd_tipo_documental', 'sgd_dependencia',
    'sgd_serie', 'sgd_subserie', 'sgd_documento', 'sgd_ccd_entrada'
)
  AND a.codigo IN ('ver', 'guardar', 'eliminar')
  AND NOT EXISTS (
      SELECT 1 FROM item_accion ia
      WHERE ia.item_id = i.id AND ia.accion_id = a.id
  );

INSERT INTO item_accion (item_id, accion_id, estado_id)
SELECT i.id, a.id, 1
FROM item i
INNER JOIN accion a ON a.codigo = 'importar'
WHERE i.ruta = 'sgd/importar'
  AND NOT EXISTS (
      SELECT 1 FROM item_accion ia
      WHERE ia.item_id = i.id AND ia.accion_id = a.id
  );

INSERT INTO item_accion (item_id, accion_id, estado_id)
SELECT i.id, a.id, 1
FROM item i
INNER JOIN accion a ON a.codigo = 'configurar'
WHERE i.ruta = 'sgd/config'
  AND NOT EXISTS (
      SELECT 1 FROM item_accion ia
      WHERE ia.item_id = i.id AND ia.accion_id = a.id
  );

INSERT INTO rol_permiso (rol_id, empresa_id, sede_id, item_accion_id, estado_id)
SELECT 1, NULL, NULL, ia.id, 5
FROM item_accion ia
INNER JOIN item i ON i.id = ia.item_id
WHERE i.ruta IN (
    'sgd', 'sgd/config', 'sgd/importar',
    'sgd_proceso', 'sgd_tipo_documental', 'sgd_dependencia',
    'sgd_serie', 'sgd_subserie', 'sgd_documento', 'sgd_ccd_entrada'
)
  AND NOT EXISTS (
      SELECT 1 FROM rol_permiso rp
      WHERE rp.rol_id = 1 AND rp.item_accion_id = ia.id AND rp.estado_id = 5
  );
