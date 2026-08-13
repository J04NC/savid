/* ============================================================
   Módulo SIHOS — reportes de cruce con el sistema hospitalario
   externo SIHOS (BD MySQL propia, servidor distinto al de SAVID).
   Fase 1: solo estructura de menú + pantalla de conexión;
   los reportes (Presupuesto, Cruce reconocimientos vs contabilidad)
   quedan con controller propio y consulta pendiente de mapear
   contra el esquema real de SIHOS (EncaCont/DetaCont/DetaPlan...).
============================================================ */

INSERT INTO modulo (nombre, icono, orden, estado_id)
SELECT 'SIHOS', '🏥', 500, 1
WHERE NOT EXISTS (SELECT 1 FROM modulo WHERE LOWER(TRIM(nombre)) = 'sihos' LIMIT 1);

SET @mod_sihos_id := (SELECT id FROM modulo WHERE LOWER(TRIM(nombre)) = 'sihos' ORDER BY id LIMIT 1);

/* Carpetas raíz */
INSERT INTO item (modulo_id, nombre, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_sihos_id, 'CONFIGURACIÓN', '', '⚙️', 10, NULL, 1
WHERE @mod_sihos_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM item WHERE modulo_id = @mod_sihos_id AND item_padre_id IS NULL AND nombre = 'CONFIGURACIÓN');

INSERT INTO item (modulo_id, nombre, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_sihos_id, 'REPORTES', '', '📊', 20, NULL, 1
WHERE @mod_sihos_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM item WHERE modulo_id = @mod_sihos_id AND item_padre_id IS NULL AND nombre = 'REPORTES');

SET @item_config_id := (SELECT id FROM item WHERE modulo_id = @mod_sihos_id AND item_padre_id IS NULL AND nombre = 'CONFIGURACIÓN' LIMIT 1);
SET @item_reportes_id := (SELECT id FROM item WHERE modulo_id = @mod_sihos_id AND item_padre_id IS NULL AND nombre = 'REPORTES' LIMIT 1);

/* Conexión (ruta corta 'sihos' a propósito: permite que las acciones AJAX
   tipo 'sihos/configProbar' hereden el permiso por resolución de raíz,
   igual que 'sistema/correoProbar' hereda de 'sistema'). */
INSERT INTO item (modulo_id, nombre, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_sihos_id, 'Conexión SIHOS', 'sihos', '🔗', 10, @item_config_id, 1
WHERE @item_config_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM item WHERE ruta = 'sihos' LIMIT 1);

/* Reportes */
INSERT INTO item (modulo_id, nombre, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_sihos_id, 'Presupuesto', 'sihos/presupuesto', '💰', 10, @item_reportes_id, 1
WHERE @item_reportes_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM item WHERE ruta = 'sihos/presupuesto' LIMIT 1);

INSERT INTO item (modulo_id, nombre, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_sihos_id, 'Cruce reconocimientos vs contabilidad', 'sihos/cruce', '🔀', 20, @item_reportes_id, 1
WHERE @item_reportes_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM item WHERE ruta = 'sihos/cruce' LIMIT 1);

/* item_accion: solo 'ver', pantallas de solo lectura con controller propio (no CRUD genérico) */
INSERT INTO item_accion (item_id, accion_id, estado_id)
SELECT i.id, a.id, 1
FROM item i
INNER JOIN accion a ON a.codigo = 'ver'
WHERE i.ruta IN ('sihos', 'sihos/presupuesto', 'sihos/cruce')
  AND NOT EXISTS (SELECT 1 FROM item_accion ia WHERE ia.item_id = i.id AND ia.accion_id = a.id);
