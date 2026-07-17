/* ============================================================
   F4 — Expedientes, registros diligenciados y compromisos
   Sin duplicar maestros: persona vía usuario / terceroidentificacion.
   Ejecutar: mysql savid < database/migrations/20260612_sgd_registro_f4.sql
============================================================ */

CREATE TABLE IF NOT EXISTS `sgd_expediente` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `empresa_id` INT NOT NULL,
    `sede_id` INT UNSIGNED NULL COMMENT 'rel:sede|label:nombre',
    `dependencia_id` INT UNSIGNED NULL COMMENT 'rel:sgd_dependencia|label:nombre',
    `serie_id` INT UNSIGNED NULL COMMENT 'rel:sgd_serie|label:nombre',
    `subserie_id` INT UNSIGNED NULL COMMENT 'rel:sgd_subserie|label:nombre',
    `codigo` VARCHAR(64) NULL COMMENT 'Código carpeta / expediente',
    `titulo` VARCHAR(500) NOT NULL,
    `estado_id` SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'label:Estado|reltipo:GENERAL',
    `created_at` DATETIME(3) NULL DEFAULT NULL,
    `created_by` INT UNSIGNED NULL,
    `updated_at` DATETIME(3) NULL DEFAULT NULL,
    `updated_by` INT UNSIGNED NULL,
    `deleted_at` DATETIME(3) NULL DEFAULT NULL,
    `deleted_by` INT UNSIGNED NULL,
    PRIMARY KEY (`id`),
    KEY `idx_sgd_expediente_empresa` (`empresa_id`),
    KEY `idx_sgd_expediente_ccd` (`dependencia_id`, `serie_id`, `subserie_id`),
    CONSTRAINT `fk_sgd_expediente_empresa`
        FOREIGN KEY (`empresa_id`) REFERENCES `empresa` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_sgd_expediente_dependencia`
        FOREIGN KEY (`dependencia_id`) REFERENCES `sgd_dependencia` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_sgd_expediente_serie`
        FOREIGN KEY (`serie_id`) REFERENCES `sgd_serie` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_sgd_expediente_subserie`
        FOREIGN KEY (`subserie_id`) REFERENCES `sgd_subserie` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sgd_registro` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `empresa_id` INT NOT NULL,
    `sede_id` INT UNSIGNED NULL COMMENT 'rel:sede|label:nombre',
    `expediente_id` INT UNSIGNED NULL COMMENT 'rel:sgd_expediente|label:titulo',
    `documento_id` INT UNSIGNED NOT NULL COMMENT 'rel:sgd_documento|label:nombre — formato F/R',
    `formulario_version_id` INT UNSIGNED NOT NULL COMMENT 'rel:sgd_formulario_version|label:numero — plantilla usada',
    `documento_version_id` INT UNSIGNED NULL COMMENT 'rel:sgd_documento_version|label:numero — esqueleto publicado',
    `arquetipo` VARCHAR(32) NOT NULL DEFAULT 'libre' COMMENT 'Copia de esquema al abrir',
    `titulo` VARCHAR(500) NULL,
    `estado` ENUM('borrador','en_firma','firmado','cerrado','anulado') NOT NULL DEFAULT 'borrador',
    `datos_json` JSON NOT NULL COMMENT 'Valores en edición {bloques, campos_sueltos}',
    `contenido_publicado_json` JSON NULL COMMENT 'Snapshot inmutable al cerrar/firmar',
    `archivo_generado_ruta` VARCHAR(500) NULL,
    `created_at` DATETIME(3) NULL DEFAULT NULL,
    `created_by` INT UNSIGNED NULL,
    `updated_at` DATETIME(3) NULL DEFAULT NULL,
    `updated_by` INT UNSIGNED NULL,
    `deleted_at` DATETIME(3) NULL DEFAULT NULL,
    `deleted_by` INT UNSIGNED NULL,
    PRIMARY KEY (`id`),
    KEY `idx_sgd_registro_empresa` (`empresa_id`),
    KEY `idx_sgd_registro_documento` (`documento_id`),
    KEY `idx_sgd_registro_expediente` (`expediente_id`),
    KEY `idx_sgd_registro_form_ver` (`formulario_version_id`),
    KEY `idx_sgd_registro_estado` (`estado`),
    CONSTRAINT `fk_sgd_registro_empresa`
        FOREIGN KEY (`empresa_id`) REFERENCES `empresa` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_sgd_registro_expediente`
        FOREIGN KEY (`expediente_id`) REFERENCES `sgd_expediente` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_sgd_registro_documento`
        FOREIGN KEY (`documento_id`) REFERENCES `sgd_documento` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_sgd_registro_formulario_version`
        FOREIGN KEY (`formulario_version_id`) REFERENCES `sgd_formulario_version` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_sgd_registro_documento_version`
        FOREIGN KEY (`documento_version_id`) REFERENCES `sgd_documento_version` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sgd_registro_archivo` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `registro_id` INT UNSIGNED NOT NULL COMMENT 'rel:sgd_registro|label:titulo',
    `empresa_id` INT NOT NULL,
    `orden` INT NOT NULL DEFAULT 0,
    `nombre` VARCHAR(255) NULL,
    `archivo_ruta` VARCHAR(500) NOT NULL,
    `archivo_tipo` VARCHAR(16) NULL,
    `created_at` DATETIME(3) NULL DEFAULT NULL,
    `created_by` INT UNSIGNED NULL,
    `deleted_at` DATETIME(3) NULL DEFAULT NULL,
    `deleted_by` INT UNSIGNED NULL,
    PRIMARY KEY (`id`),
    KEY `idx_sgd_reg_archivo_registro` (`registro_id`),
    CONSTRAINT `fk_sgd_reg_archivo_registro`
        FOREIGN KEY (`registro_id`) REFERENCES `sgd_registro` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_sgd_reg_archivo_empresa`
        FOREIGN KEY (`empresa_id`) REFERENCES `empresa` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sgd_registro_compromiso` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `registro_id` INT UNSIGNED NOT NULL COMMENT 'rel:sgd_registro|label:titulo',
    `empresa_id` INT NOT NULL,
    `orden` INT NOT NULL DEFAULT 0,
    `descripcion` TEXT NOT NULL,
    `fecha_limite` DATE NULL,
    `estado` ENUM('pendiente','en_proceso','cumplido','vencido','cancelado') NOT NULL DEFAULT 'pendiente',
    `fecha_cumplimiento` DATE NULL,
    `observaciones` TEXT NULL,
    `responsable_usuario_id` INT UNSIGNED NULL COMMENT 'rel:usuario|label:username',
    `responsable_terceroidentificacion_id` INT UNSIGNED NULL COMMENT 'rel:terceroidentificacion — persona externa',
    `created_at` DATETIME(3) NULL DEFAULT NULL,
    `created_by` INT UNSIGNED NULL,
    `updated_at` DATETIME(3) NULL DEFAULT NULL,
    `updated_by` INT UNSIGNED NULL,
    `deleted_at` DATETIME(3) NULL DEFAULT NULL,
    `deleted_by` INT UNSIGNED NULL,
    PRIMARY KEY (`id`),
    KEY `idx_sgd_reg_comp_registro` (`registro_id`),
    KEY `idx_sgd_reg_comp_responsable_user` (`responsable_usuario_id`),
    KEY `idx_sgd_reg_comp_responsable_ti` (`responsable_terceroidentificacion_id`),
    CONSTRAINT `fk_sgd_reg_comp_registro`
        FOREIGN KEY (`registro_id`) REFERENCES `sgd_registro` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_sgd_reg_comp_empresa`
        FOREIGN KEY (`empresa_id`) REFERENCES `empresa` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @mod_sgd_id := (
    SELECT id FROM modulo
    WHERE LOWER(TRIM(nombre)) IN ('gestión documental', 'gestion documental')
    ORDER BY id LIMIT 1
);

SET @item_sgd_id := (
    SELECT id FROM item
    WHERE modulo_id = @mod_sgd_id AND (item_padre_id IS NULL OR item_padre_id = 0)
    ORDER BY id LIMIT 1
);

INSERT INTO item (modulo_id, nombre, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_sgd_id, 'Registros operativos', 'sgd/registros', '📋', 94, @item_sgd_id, 1
WHERE @mod_sgd_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM item WHERE ruta = 'sgd/registros' LIMIT 1);

INSERT INTO item_accion (item_id, accion_id, estado_id)
SELECT i.id, a.id, 1
FROM item i
CROSS JOIN accion a
WHERE i.ruta = 'sgd/registros'
  AND a.codigo IN ('ver', 'guardar', 'eliminar')
  AND NOT EXISTS (
      SELECT 1 FROM item_accion ia WHERE ia.item_id = i.id AND ia.accion_id = a.id
  );

INSERT INTO rol_permiso (rol_id, empresa_id, sede_id, item_accion_id, estado_id)
SELECT 1, NULL, NULL, ia.id, 5
FROM item_accion ia
INNER JOIN item i ON i.id = ia.item_id
WHERE i.ruta = 'sgd/registros'
  AND NOT EXISTS (
      SELECT 1 FROM rol_permiso rp
      WHERE rp.rol_id = 1 AND rp.item_accion_id = ia.id AND rp.estado_id = 5
  );
