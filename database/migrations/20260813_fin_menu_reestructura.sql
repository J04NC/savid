/* ============================================================
   Módulo FIN — corrige el error "Table 'savid.fin' doesn't exist"
   y reestructura el menú según decisión del usuario (2026-08-21):

   - `fin`/`ope` como hubs "clicables" (ruta='fin'/'ope') causaban
     que ModuleController intentara `SELECT * FROM fin` (no es una
     tabla). Se eliminan.
   - Se adopta el patrón ya usado por ACADEMIC: encabezados de
     sección con `ruta=''` (no navegables, solo agrupan visualmente),
     y los ítems reales (con tabla/controller) cuelgan de ellos.
   - Se separan FACTURACION y TESORERIA como módulos propios
     (top-level, con su propio ícono), en vez de vivir dentro de
     "Financiero". Sus ítems de gestión (EVENTO, FACTURA, NOTA,
     COMPROBANTE DE EGRESO, CAJA) se agregan en los checkpoints que
     construyan esas pantallas (2, 3, 4) — aquí solo se crean el
     módulo y los encabezados de sección, para no dejar enlaces
     rotos a tablas que todavía no existen.
   - No se crea hub "Operación": el registro de eventos vivirá bajo
     Facturación > Gestión > Evento cuando se implemente (checkpoint 2).
============================================================ */

/* ------------------------------------------------------------------ */
/* 1. Financiero: encabezado "CONFIGURACION" (ruta vacía) reemplaza el  */
/*    hub 'fin'; los 7 ítems del checkpoint 1 se re-anidan bajo él      */
/*    ANTES de borrar 'fin' (si no, la FK item_padre_id lo bloquea).    */
/* ------------------------------------------------------------------ */

SET @mod_fin_id := (SELECT id FROM modulo WHERE nombre = 'Financiero' LIMIT 1);

INSERT INTO item (modulo_id, nombre, nombre_es, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_fin_id, 'CONFIGURACION', 'Configuración', '', '⚙️', 10, NULL, 1
WHERE NOT EXISTS (
    SELECT 1 FROM item WHERE modulo_id = @mod_fin_id AND item_padre_id IS NULL AND ruta = '' AND nombre = 'CONFIGURACION'
);

SET @item_fin_config_id := (
    SELECT id FROM item WHERE modulo_id = @mod_fin_id AND item_padre_id IS NULL AND ruta = '' AND nombre = 'CONFIGURACION' LIMIT 1
);

UPDATE item
SET item_padre_id = @item_fin_config_id
WHERE ruta IN (
    'fin_empresa_config', 'fin_catalogo', 'fin_catalogo_item',
    'fin_impuesto_tipo', 'fin_tipo_documento', 'fin_producto_servicio',
    'fin_numeracion'
);

/* Renombro las etiquetas visibles para acercarlas al boceto del usuario
   (GENERAL / TIPOS DE DOCUMENTO / CATALOGO / PRODUCTO); impuestos y
   numeración no estaban en el boceto pero son config, quedan también
   aquí — avisar si se prefieren en otro lugar. */
UPDATE item SET nombre_es = 'General' WHERE ruta = 'fin_empresa_config';
UPDATE item SET nombre_es = 'Tipos de documento' WHERE ruta = 'fin_tipo_documento';
UPDATE item SET nombre_es = 'Catalogo' WHERE ruta = 'fin_catalogo';
UPDATE item SET nombre_es = 'Items de catalogo' WHERE ruta = 'fin_catalogo_item';
UPDATE item SET nombre_es = 'Producto' WHERE ruta = 'fin_producto_servicio';
UPDATE item SET nombre_es = 'Impuestos' WHERE ruta = 'fin_impuesto_tipo';
UPDATE item SET nombre_es = 'Numeracion' WHERE ruta = 'fin_numeracion';

/* Permiso 'ver' del encabezado (mismo patrón que ACADEMIC > CONFIGURACIÓN):
   controla si la sección aparece en el árbol de menú para el rol. */
INSERT INTO item_accion (item_id, accion_id, estado_id)
SELECT @item_fin_config_id, a.id, 1
FROM accion a
WHERE a.codigo = 'ver'
  AND NOT EXISTS (SELECT 1 FROM item_accion ia WHERE ia.item_id = @item_fin_config_id AND ia.accion_id = a.id);

SET @rol_admin_fin_id := (SELECT id FROM rol WHERE nombre = 'Admin FIN' LIMIT 1);
SET @rol_facturador_id := (SELECT id FROM rol WHERE nombre = 'Facturador' LIMIT 1);
SET @rol_cajero_id := (SELECT id FROM rol WHERE nombre = 'Cajero Tesorero' LIMIT 1);

INSERT INTO rol_permiso (rol_id, empresa_id, sede_id, item_accion_id, estado_id)
SELECT rol_id, NULL, NULL, ia.id, 5
FROM (
    SELECT @rol_admin_fin_id AS rol_id UNION ALL
    SELECT @rol_facturador_id UNION ALL
    SELECT @rol_cajero_id
) roles
INNER JOIN item_accion ia ON ia.item_id = @item_fin_config_id
WHERE roles.rol_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM rol_permiso rp WHERE rp.rol_id = roles.rol_id AND rp.item_accion_id = ia.id AND rp.estado_id = 5);

/* ------------------------------------------------------------------ */
/* 2. Limpieza: ahora sí se puede quitar 'fin'/'ope' como ítems         */
/*    clicables — sus hijos ya no apuntan a ellos.                      */
/* ------------------------------------------------------------------ */

DELETE rp FROM rol_permiso rp
INNER JOIN item_accion ia ON ia.id = rp.item_accion_id
INNER JOIN item i ON i.id = ia.item_id
WHERE i.ruta IN ('fin', 'ope');

DELETE ia FROM item_accion ia
INNER JOIN item i ON i.id = ia.item_id
WHERE i.ruta IN ('fin', 'ope');

DELETE FROM item WHERE ruta IN ('fin', 'ope');

/* ------------------------------------------------------------------ */
/* 3. Módulos nuevos: Facturación y Tesorería (solo el módulo + los     */
/*    encabezados de sección; los ítems de gestión llegan cuando se     */
/*    construyan sus pantallas en los siguientes checkpoints).          */
/* ------------------------------------------------------------------ */

INSERT INTO modulo (nombre, icono, orden, estado_id)
SELECT 'Facturación', '🧾', 420, 1
WHERE NOT EXISTS (SELECT 1 FROM modulo WHERE nombre = 'Facturación');

INSERT INTO modulo (nombre, icono, orden, estado_id)
SELECT 'Tesorería', '🏦', 430, 1
WHERE NOT EXISTS (SELECT 1 FROM modulo WHERE nombre = 'Tesorería');

SET @mod_facturacion_id := (SELECT id FROM modulo WHERE nombre = 'Facturación' LIMIT 1);
SET @mod_tesoreria_id := (SELECT id FROM modulo WHERE nombre = 'Tesorería' LIMIT 1);

/* Encabezados de sección de Facturación: GESTION, CONFIGURACION, REPORTE.
   Sin item_accion todavía (sin hijos aún, no hay nada que mostrar/ocultar) —
   se agrega cuando el primer hijo real llegue en su checkpoint. */
INSERT INTO item (modulo_id, nombre, nombre_es, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_facturacion_id, 'GESTION', 'Gestión', '', '🗂️', 10, NULL, 1
WHERE NOT EXISTS (SELECT 1 FROM item WHERE modulo_id = @mod_facturacion_id AND item_padre_id IS NULL AND ruta = '' AND nombre = 'GESTION');

INSERT INTO item (modulo_id, nombre, nombre_es, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_facturacion_id, 'CONFIGURACION', 'Configuración', '', '⚙️', 20, NULL, 1
WHERE NOT EXISTS (SELECT 1 FROM item WHERE modulo_id = @mod_facturacion_id AND item_padre_id IS NULL AND ruta = '' AND nombre = 'CONFIGURACION');

INSERT INTO item (modulo_id, nombre, nombre_es, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_facturacion_id, 'REPORTE', 'Reportes', '', '📊', 30, NULL, 1
WHERE NOT EXISTS (SELECT 1 FROM item WHERE modulo_id = @mod_facturacion_id AND item_padre_id IS NULL AND ruta = '' AND nombre = 'REPORTE');

/* Encabezado de sección de Tesorería: GESTION. */
INSERT INTO item (modulo_id, nombre, nombre_es, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_tesoreria_id, 'GESTION', 'Gestión', '', '🗂️', 10, NULL, 1
WHERE NOT EXISTS (SELECT 1 FROM item WHERE modulo_id = @mod_tesoreria_id AND item_padre_id IS NULL AND ruta = '' AND nombre = 'GESTION');
