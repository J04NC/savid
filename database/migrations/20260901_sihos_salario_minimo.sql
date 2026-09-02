/* ============================================================
   Módulo SIHOS — configuración del salario mínimo mensual vigente
   por empresa/año, usado por SihosExternalRepository::fetchNominaPila()
   para el piso de 1 día de salario mínimo (SMLDV) del retroactivo de
   vacaciones que exige el operador de aportes en línea (ver docblock
   de esa sección).

   Resultado:
     SIHOS
     └── REPORTES
         └── NÓMINA
             └── SALARIO MÍNIMO  (tabla CRUD genérica sihos_salario_minimo)
============================================================ */

CREATE TABLE IF NOT EXISTS sihos_salario_minimo (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id INT NOT NULL COMMENT 'label:Empresa|rel:tercero|label:razon_social|title:Razón social de la empresa',
    ano INT NOT NULL COMMENT 'type:number|order:10|placeholder:Año (ej. 2026)|required',
    valor DECIMAL(14,2) NOT NULL COMMENT 'type:number|order:20|placeholder:Salario mínimo mensual vigente|required',
    created_at DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    created_by INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    updated_at DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    updated_by INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    deleted_at DATETIME(3) NULL DEFAULT NULL COMMENT 'show:none',
    deleted_by INT UNSIGNED NULL DEFAULT NULL COMMENT 'show:none',
    PRIMARY KEY (id),
    KEY idx_sihos_salario_minimo_empresa_ano (empresa_id, ano),
    CONSTRAINT fk_sihos_salario_minimo_empresa FOREIGN KEY (empresa_id) REFERENCES empresa (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @mod_sihos_id := (SELECT id FROM modulo WHERE LOWER(TRIM(nombre)) = 'sihos' ORDER BY id LIMIT 1);
SET @item_reportes_id := (SELECT id FROM item WHERE modulo_id = @mod_sihos_id AND item_padre_id IS NULL AND nombre = 'REPORTES' LIMIT 1);
SET @item_nomina_id := (SELECT id FROM item WHERE modulo_id = @mod_sihos_id AND item_padre_id = @item_reportes_id AND nombre = 'NÓMINA' LIMIT 1);

INSERT INTO item (modulo_id, nombre, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_sihos_id, 'SALARIO MÍNIMO', 'sihos_salario_minimo', '💵', 40, @item_nomina_id, 1
WHERE @item_nomina_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM item WHERE ruta = 'sihos_salario_minimo' LIMIT 1);

/* item_accion: CRUD genérico estándar (ver/guardar/eliminar), mismo set que
   sgd_serie y otros catálogos simples del motor CRUD genérico. */
INSERT INTO item_accion (item_id, accion_id, estado_id)
SELECT i.id, a.id, 1
FROM item i
INNER JOIN accion a ON a.codigo IN ('ver', 'guardar', 'eliminar')
WHERE i.ruta = 'sihos_salario_minimo'
  AND NOT EXISTS (SELECT 1 FROM item_accion ia WHERE ia.item_id = i.id AND ia.accion_id = a.id);
