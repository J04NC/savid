/* ============================================================
   Módulo FIN — Checkpoint 1: cimientos.
   Crea el módulo "Financiero", los hubs de menú `fin`/`ope`, y los
   catálogos base 100% CRUD genérico (sin controller propio):
   fin_empresa_config, fin_catalogo, fin_catalogo_item,
   fin_impuesto_tipo, fin_tipo_documento, fin_producto_servicio,
   fin_numeracion.

   Cubre historias FIN-101 (simplificada: sin wizard/importador de
   perfiles, se agrega en checkpoint aparte si hace falta), FIN-102,
   FIN-103, FIN-104 de docs/fin/BACKLOG_MODULO_FIN.md.

   Convenciones seguidas (ver docs/fin/FICHA_MODULO_FIN.md v1.14):
   - empresa_id INT (signed, como empresa.id) con
     COMMENT 'rel:tercero|label:razon_social|...' (mismo patrón que
     acad_module.empresa_id, ver 20260722_acad_module.sql).
   - estado_id SMALLINT UNSIGNED FK a `estado`, reltipo:GENERAL para
     toggles simples activo/inactivo.
   - Ciclos de vida de dominio ricos (fin_documento, etc.) NO van en
     esta migración — usan ENUM propio, llegan en checkpoints
     posteriores.
   - Todas las tablas cierran con las columnas trackable estándar
     (database/snippets/trackable_columns.sql).
============================================================ */

/* ------------------------------------------------------------------ */
/* Módulo y hubs de menú                                               */
/* ------------------------------------------------------------------ */

INSERT INTO modulo (nombre, icono, orden, estado_id)
SELECT 'Financiero', '💰', 410, 1
WHERE NOT EXISTS (SELECT 1 FROM modulo WHERE nombre = 'Financiero');

SET @mod_fin_id := (SELECT id FROM modulo WHERE nombre = 'Financiero' LIMIT 1);

INSERT INTO item (modulo_id, nombre, nombre_es, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_fin_id, 'Financiero', 'Financiero', 'fin', '💰', 10, NULL, 1
WHERE NOT EXISTS (SELECT 1 FROM item WHERE ruta = 'fin');

INSERT INTO item (modulo_id, nombre, nombre_es, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_fin_id, 'Operacion', 'Operación', 'ope', '🧾', 20, NULL, 1
WHERE NOT EXISTS (SELECT 1 FROM item WHERE ruta = 'ope');

SET @item_fin_id := (SELECT id FROM item WHERE ruta = 'fin' LIMIT 1);

/* ------------------------------------------------------------------ */
/* Roles nuevos del módulo (verificado: no existían)                   */
/* ------------------------------------------------------------------ */

INSERT INTO rol (nombre, estado_id)
SELECT 'Admin FIN', 1 WHERE NOT EXISTS (SELECT 1 FROM rol WHERE nombre = 'Admin FIN');

INSERT INTO rol (nombre, estado_id)
SELECT 'Facturador', 1 WHERE NOT EXISTS (SELECT 1 FROM rol WHERE nombre = 'Facturador');

INSERT INTO rol (nombre, estado_id)
SELECT 'Cajero Tesorero', 1 WHERE NOT EXISTS (SELECT 1 FROM rol WHERE nombre = 'Cajero Tesorero');

SET @rol_admin_fin_id := (SELECT id FROM rol WHERE nombre = 'Admin FIN' LIMIT 1);
SET @rol_facturador_id := (SELECT id FROM rol WHERE nombre = 'Facturador' LIMIT 1);
SET @rol_cajero_id := (SELECT id FROM rol WHERE nombre = 'Cajero Tesorero' LIMIT 1);

/* ------------------------------------------------------------------ */
/* fin_empresa_config — una fila por empresa (FIN-101 simplificada)    */
/* ------------------------------------------------------------------ */

CREATE TABLE IF NOT EXISTS `fin_empresa_config` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `empresa_id` INT NOT NULL COMMENT 'label:Empresa|rel:tercero|label:razon_social|title:Razón social de la empresa (tercero vinculado)|order:10',
    `perfil_operativo` ENUM('comercio_retail','servicios','salud_ips','salud_publica','mixto') NOT NULL COMMENT 'label:Perfil operativo|order:20|title:Referencia de configuración inicial (ficha §3.3); en este checkpoint no dispara importación automática de catálogos',
    `estado_id` SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'label:Módulo activo|reltipo:GENERAL|order:30|title:Financiero activo/inactivo para esta empresa',
    `config_json` JSON NULL COMMENT 'show:none',
    `created_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `created_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    `updated_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `updated_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    `deleted_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `deleted_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_fin_empresa_config_empresa` (`empresa_id`),
    KEY `idx_fin_empresa_config_deleted` (`deleted_at`),
    CONSTRAINT `fk_fin_empresa_config_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresa` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_fin_empresa_config_estado` FOREIGN KEY (`estado_id`) REFERENCES `estado` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* ------------------------------------------------------------------ */
/* fin_catalogo / fin_catalogo_item — catálogos genéricos configurables */
/* ------------------------------------------------------------------ */

CREATE TABLE IF NOT EXISTS `fin_catalogo` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `empresa_id` INT NOT NULL COMMENT 'label:Empresa|rel:tercero|label:razon_social|title:Razón social de la empresa (tercero vinculado)|order:10',
    `codigo` VARCHAR(40) NOT NULL COMMENT 'uppercase|order:20|title:Ej. UNIDAD_MEDIDA, MODALIDAD_PAGO',
    `nombre` VARCHAR(150) NOT NULL COMMENT 'order:30',
    `estado_id` SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'label:Estado|reltipo:GENERAL|order:40',
    `created_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `created_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    `updated_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `updated_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    `deleted_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `deleted_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_fin_catalogo_empresa_codigo` (`empresa_id`,`codigo`),
    KEY `idx_fin_catalogo_deleted` (`deleted_at`),
    CONSTRAINT `fk_fin_catalogo_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresa` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_fin_catalogo_estado` FOREIGN KEY (`estado_id`) REFERENCES `estado` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fin_catalogo_item` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `catalogo_id` INT UNSIGNED NOT NULL COMMENT 'rel:fin_catalogo|label:nombre|order:10',
    `codigo` VARCHAR(40) NOT NULL COMMENT 'uppercase|order:20',
    `nombre` VARCHAR(150) NOT NULL COMMENT 'order:30',
    `orden` SMALLINT NOT NULL DEFAULT 0 COMMENT 'order:40',
    `estado_id` SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'label:Estado|reltipo:GENERAL|order:50',
    `created_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `created_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    `updated_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `updated_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    `deleted_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `deleted_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_fin_catalogo_item_cat_codigo` (`catalogo_id`,`codigo`),
    KEY `idx_fin_catalogo_item_deleted` (`deleted_at`),
    CONSTRAINT `fk_fin_catalogo_item_catalogo` FOREIGN KEY (`catalogo_id`) REFERENCES `fin_catalogo` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_fin_catalogo_item_estado` FOREIGN KEY (`estado_id`) REFERENCES `estado` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* ------------------------------------------------------------------ */
/* fin_impuesto_tipo — catálogo tributario configurable                */
/* ------------------------------------------------------------------ */

CREATE TABLE IF NOT EXISTS `fin_impuesto_tipo` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `empresa_id` INT NOT NULL COMMENT 'label:Empresa|rel:tercero|label:razon_social|title:Razón social de la empresa (tercero vinculado)|order:10',
    `codigo` VARCHAR(20) NOT NULL COMMENT 'uppercase|order:20|title:Ej. IVA19, IVA5, EXENTO',
    `nombre` VARCHAR(100) NOT NULL COMMENT 'order:30',
    `tipo` ENUM('iva','inc','retencion','otro') NOT NULL DEFAULT 'iva' COMMENT 'order:40',
    `tarifa` DECIMAL(6,3) NOT NULL DEFAULT 0 COMMENT 'order:50|title:Porcentaje, ej 19.000',
    `estado_id` SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'label:Estado|reltipo:GENERAL|order:60',
    `created_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `created_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    `updated_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `updated_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    `deleted_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `deleted_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_fin_impuesto_tipo_empresa_codigo` (`empresa_id`,`codigo`),
    KEY `idx_fin_impuesto_tipo_deleted` (`deleted_at`),
    CONSTRAINT `fk_fin_impuesto_tipo_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresa` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_fin_impuesto_tipo_estado` FOREIGN KEY (`estado_id`) REFERENCES `estado` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* ------------------------------------------------------------------ */
/* fin_tipo_documento — catálogo de tipos de documento financiero      */
/* ------------------------------------------------------------------ */

CREATE TABLE IF NOT EXISTS `fin_tipo_documento` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `empresa_id` INT NOT NULL COMMENT 'label:Empresa|rel:tercero|label:razon_social|title:Razón social de la empresa (tercero vinculado)|order:10',
    `codigo` VARCHAR(10) NOT NULL COMMENT 'uppercase|order:20|title:FV, FC, NC, ND, RC, CE...',
    `nombre` VARCHAR(100) NOT NULL COMMENT 'order:30',
    `naturaleza` ENUM('ingreso','egreso','neutro','memorando') NOT NULL COMMENT 'order:40',
    `afecta` ENUM('cartera','caja','banco','inventario','nomina','ninguno') NOT NULL DEFAULT 'ninguno' COMMENT 'order:50',
    `categoria` ENUM('comercial','notas','tesoreria','ajustes') NOT NULL COMMENT 'order:60|title:Determina en qué grilla aparece (ficha §14.1a): comercial=FV/FC, notas=NC/ND, tesoreria=RC/CE',
    `requiere_tercero` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'type:checkbox|order:70',
    `requiere_detalle` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'type:checkbox|order:80',
    `genera_contabilidad` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'type:checkbox|order:90|title:Sin efecto hasta F2 (motor NIIF)',
    `genera_fe` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'type:checkbox|order:100|title:Sin efecto hasta F3 (DIAN)',
    `genera_rips` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'type:checkbox|order:110|title:Sin efecto hasta F4 (salud)',
    `documento_referencia_obligatorio` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'type:checkbox|order:120|title:NC/ND deben apuntar a un documento origen (validación cruzada pendiente, ver nota abajo)',
    `reglas_json` JSON NULL COMMENT 'show:none',
    `estado_id` SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'label:Estado|reltipo:GENERAL|order:130',
    `created_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `created_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    `updated_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `updated_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    `deleted_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `deleted_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_fin_tipo_documento_empresa_codigo` (`empresa_id`,`codigo`),
    KEY `idx_fin_tipo_documento_deleted` (`deleted_at`),
    CONSTRAINT `fk_fin_tipo_documento_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresa` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_fin_tipo_documento_estado` FOREIGN KEY (`estado_id`) REFERENCES `estado` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* NOTA (decisión confirmada 2026-08-12): `documento_referencia_obligatorio`
   no tiene validación cruzada automática en este checkpoint — CrudService::save()
   no soporta hooks por tabla. Se guarda por convención del Admin FIN. Se revisa
   si hace falta un controller propio cuando F1b (NC/ND reales) esté implementado. */

/* ------------------------------------------------------------------ */
/* fin_producto_servicio — catálogo de ítems facturables                */
/* ------------------------------------------------------------------ */

CREATE TABLE IF NOT EXISTS `fin_producto_servicio` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `empresa_id` INT NOT NULL COMMENT 'label:Empresa|rel:tercero|label:razon_social|title:Razón social de la empresa (tercero vinculado)|order:10',
    `codigo` VARCHAR(30) NOT NULL COMMENT 'uppercase|order:20',
    `nombre` VARCHAR(150) NOT NULL COMMENT 'order:30',
    `descripcion` VARCHAR(500) NULL COMMENT 'type:textarea|order:40|span:full',
    `unidad_medida` VARCHAR(20) NOT NULL DEFAULT 'UND' COMMENT 'order:50|title:Texto libre en este checkpoint; candidato a fin_catalogo_item(UNIDAD_MEDIDA) más adelante',
    `impuesto_tipo_id` INT UNSIGNED NULL COMMENT 'rel:fin_impuesto_tipo|label:nombre|relmode:autocomplete|order:60|title:IVA/INC por defecto de este producto',
    `valor_unitario_defecto` DECIMAL(14,2) NULL COMMENT 'type:number|order:70|title:Precio sugerido; el tarifario por contrato llega en F2b',
    `es_inventariable` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'type:checkbox|order:80|title:Kardex llega en F5; el campo se deja listo',
    `estado_id` SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'label:Estado|reltipo:GENERAL|order:90',
    `created_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `created_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    `updated_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `updated_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    `deleted_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `deleted_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_fin_producto_servicio_empresa_codigo` (`empresa_id`,`codigo`),
    KEY `idx_fin_producto_servicio_deleted` (`deleted_at`),
    CONSTRAINT `fk_fin_producto_servicio_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresa` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_fin_producto_servicio_impuesto` FOREIGN KEY (`impuesto_tipo_id`) REFERENCES `fin_impuesto_tipo` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_fin_producto_servicio_estado` FOREIGN KEY (`estado_id`) REFERENCES `estado` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* ------------------------------------------------------------------ */
/* fin_numeracion — consecutivos internos por tipo de documento         */
/* ------------------------------------------------------------------ */

CREATE TABLE IF NOT EXISTS `fin_numeracion` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `empresa_id` INT NOT NULL COMMENT 'label:Empresa|rel:tercero|label:razon_social|title:Razón social de la empresa (tercero vinculado)|order:10',
    `sede_id` INT NULL COMMENT 'rel:sede|label:nombre|order:20',
    `tipo_documento_id` INT UNSIGNED NOT NULL COMMENT 'rel:fin_tipo_documento|label:codigo|order:30',
    `anio` SMALLINT NOT NULL COMMENT 'type:number|order:40|title:Año fiscal de este rango',
    `prefijo` VARCHAR(10) NULL COMMENT 'uppercase|order:50',
    `consecutivo_desde` INT UNSIGNED NULL COMMENT 'type:number|order:60',
    `consecutivo_hasta` INT UNSIGNED NULL COMMENT 'type:number|order:70',
    `consecutivo_actual` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'type:number|order:80|title:Último consecutivo emitido; lo incrementa FinNumeracionService con bloqueo, no se edita a mano en producción',
    `estado_id` SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'label:Estado|reltipo:GENERAL|order:90',
    `created_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `created_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    `updated_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `updated_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    `deleted_at` DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    `deleted_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    PRIMARY KEY (`id`),
    KEY `idx_fin_numeracion_lookup` (`empresa_id`,`tipo_documento_id`,`anio`),
    KEY `idx_fin_numeracion_deleted` (`deleted_at`),
    CONSTRAINT `fk_fin_numeracion_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresa` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_fin_numeracion_sede` FOREIGN KEY (`sede_id`) REFERENCES `sede` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_fin_numeracion_tipo_doc` FOREIGN KEY (`tipo_documento_id`) REFERENCES `fin_tipo_documento` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_fin_numeracion_estado` FOREIGN KEY (`estado_id`) REFERENCES `estado` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* ------------------------------------------------------------------ */
/* Menú: 7 ítems CRUD genérico hijos de `fin`                          */
/* ------------------------------------------------------------------ */

INSERT INTO item (modulo_id, nombre, nombre_es, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_fin_id, 'Empresa Config', 'Configuración empresa', 'fin_empresa_config', '⚙️', 10, @item_fin_id, 1
WHERE NOT EXISTS (SELECT 1 FROM item WHERE ruta = 'fin_empresa_config');

INSERT INTO item (modulo_id, nombre, nombre_es, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_fin_id, 'Catalogos', 'Catálogos', 'fin_catalogo', '📚', 20, @item_fin_id, 1
WHERE NOT EXISTS (SELECT 1 FROM item WHERE ruta = 'fin_catalogo');

INSERT INTO item (modulo_id, nombre, nombre_es, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_fin_id, 'Items de catalogo', 'Ítems de catálogo', 'fin_catalogo_item', '🏷️', 30, @item_fin_id, 1
WHERE NOT EXISTS (SELECT 1 FROM item WHERE ruta = 'fin_catalogo_item');

INSERT INTO item (modulo_id, nombre, nombre_es, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_fin_id, 'Impuestos', 'Impuestos', 'fin_impuesto_tipo', '🧮', 40, @item_fin_id, 1
WHERE NOT EXISTS (SELECT 1 FROM item WHERE ruta = 'fin_impuesto_tipo');

INSERT INTO item (modulo_id, nombre, nombre_es, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_fin_id, 'Tipos de documento', 'Tipos de documento', 'fin_tipo_documento', '📄', 50, @item_fin_id, 1
WHERE NOT EXISTS (SELECT 1 FROM item WHERE ruta = 'fin_tipo_documento');

INSERT INTO item (modulo_id, nombre, nombre_es, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_fin_id, 'Productos y servicios', 'Productos y servicios', 'fin_producto_servicio', '📦', 60, @item_fin_id, 1
WHERE NOT EXISTS (SELECT 1 FROM item WHERE ruta = 'fin_producto_servicio');

INSERT INTO item (modulo_id, nombre, nombre_es, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_fin_id, 'Numeracion', 'Numeración', 'fin_numeracion', '🔢', 70, @item_fin_id, 1
WHERE NOT EXISTS (SELECT 1 FROM item WHERE ruta = 'fin_numeracion');

/* ------------------------------------------------------------------ */
/* item_accion: ver en los hubs; ver/guardar/eliminar en los 7 CRUD    */
/* ------------------------------------------------------------------ */

INSERT INTO item_accion (item_id, accion_id, estado_id)
SELECT i.id, a.id, 1
FROM item i CROSS JOIN accion a
WHERE i.ruta IN ('fin', 'ope')
  AND a.codigo = 'ver'
  AND NOT EXISTS (SELECT 1 FROM item_accion ia WHERE ia.item_id = i.id AND ia.accion_id = a.id);

INSERT INTO item_accion (item_id, accion_id, estado_id)
SELECT i.id, a.id, 1
FROM item i CROSS JOIN accion a
WHERE i.ruta IN (
        'fin_empresa_config', 'fin_catalogo', 'fin_catalogo_item',
        'fin_impuesto_tipo', 'fin_tipo_documento', 'fin_producto_servicio',
        'fin_numeracion'
    )
  AND a.codigo IN ('ver', 'guardar', 'eliminar')
  AND NOT EXISTS (SELECT 1 FROM item_accion ia WHERE ia.item_id = i.id AND ia.accion_id = a.id);

/* ------------------------------------------------------------------ */
/* rol_permiso: Admin FIN (todo), Facturador/Cajero (solo lectura de   */
/* catálogos de referencia para autocomplete al capturar documentos)   */
/* ------------------------------------------------------------------ */

INSERT INTO rol_permiso (rol_id, empresa_id, sede_id, item_accion_id, estado_id)
SELECT @rol_admin_fin_id, NULL, NULL, ia.id, 5
FROM item_accion ia INNER JOIN item i ON i.id = ia.item_id
WHERE @rol_admin_fin_id IS NOT NULL
  AND i.ruta IN (
        'fin', 'ope', 'fin_empresa_config', 'fin_catalogo', 'fin_catalogo_item',
        'fin_impuesto_tipo', 'fin_tipo_documento', 'fin_producto_servicio',
        'fin_numeracion'
    )
  AND NOT EXISTS (SELECT 1 FROM rol_permiso rp WHERE rp.rol_id = @rol_admin_fin_id AND rp.item_accion_id = ia.id AND rp.estado_id = 5);

INSERT INTO rol_permiso (rol_id, empresa_id, sede_id, item_accion_id, estado_id)
SELECT @rol_facturador_id, NULL, NULL, ia.id, 5
FROM item_accion ia
INNER JOIN item i ON i.id = ia.item_id
INNER JOIN accion a ON a.id = ia.accion_id
WHERE @rol_facturador_id IS NOT NULL
  AND i.ruta IN ('fin', 'ope', 'fin_tipo_documento', 'fin_producto_servicio', 'fin_numeracion')
  AND a.codigo = 'ver'
  AND NOT EXISTS (SELECT 1 FROM rol_permiso rp WHERE rp.rol_id = @rol_facturador_id AND rp.item_accion_id = ia.id AND rp.estado_id = 5);

INSERT INTO rol_permiso (rol_id, empresa_id, sede_id, item_accion_id, estado_id)
SELECT @rol_cajero_id, NULL, NULL, ia.id, 5
FROM item_accion ia
INNER JOIN item i ON i.id = ia.item_id
INNER JOIN accion a ON a.id = ia.accion_id
WHERE @rol_cajero_id IS NOT NULL
  AND i.ruta IN ('fin', 'ope', 'fin_tipo_documento', 'fin_producto_servicio', 'fin_numeracion')
  AND a.codigo = 'ver'
  AND NOT EXISTS (SELECT 1 FROM rol_permiso rp WHERE rp.rol_id = @rol_cajero_id AND rp.item_accion_id = ia.id AND rp.estado_id = 5);

/* Nota sobre asignación de permisos sensibles (ej. `anular`, F1 checkpoint 4):
   por decisión del usuario (2026-08-12), acciones de alto impacto no se
   preasignan a un rol fijo en la semilla — se conceden vía la UI existente
   de permisos (rol_permiso o permiso directo por usuario), caso a caso. */
