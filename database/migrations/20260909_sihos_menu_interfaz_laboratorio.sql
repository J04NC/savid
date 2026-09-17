/* ============================================================
   SIHOS — nuevo ítem "PROCESOS > INTERFAZ LABORATORIO": migración nativa
   del código legado docs/sihos/interlab{union,rolda}/ (interfaz Roche <->
   SIHOS) a SAVID. 3 datatables (Homologación, Solicitudes, Resultados) en
   un solo ítem, diferenciados por empresa vía la conexión SIHOS ya
   existente (sihos_empresa_config), no por código separado por cliente.
   Acción 'procesar' es nueva (escribe en SIHOS: HojaProc/DetaPrue/Enviada/
   Interfaz_solicitudes_SIHOS) — excepción deliberada y acotada a la regla
   general de solo-lectura clínica, ver SihosInterlabWriteRepository.
============================================================ */

SET @mod_sihos_id := (SELECT id FROM modulo WHERE LOWER(TRIM(nombre)) = 'sihos' ORDER BY id LIMIT 1);

INSERT INTO item (modulo_id, nombre, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_sihos_id, 'PROCESOS', '', '⚙️', 15, NULL, 1
WHERE @mod_sihos_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM item WHERE modulo_id = @mod_sihos_id AND item_padre_id IS NULL AND nombre = 'PROCESOS');

SET @item_procesos_id := (SELECT id FROM item WHERE modulo_id = @mod_sihos_id AND item_padre_id IS NULL AND nombre = 'PROCESOS' LIMIT 1);

INSERT INTO item (modulo_id, nombre, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_sihos_id, 'Interfaz Laboratorio', 'sihos/interfazLaboratorio', '🧪', 10, @item_procesos_id, 1
WHERE @item_procesos_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM item WHERE ruta = 'sihos/interfazLaboratorio' LIMIT 1);

/* Acción nueva: 'procesar' (dispara la escritura real en SIHOS). 'ver',
   'guardar' y 'eliminar' ya existen en el catálogo (se reutilizan). */
INSERT INTO accion (codigo, nombre)
SELECT 'procesar', 'Procesar'
WHERE NOT EXISTS (SELECT 1 FROM accion WHERE codigo = 'procesar');

/* item_accion: 'ver' (consulta de los 3 datatables), 'procesar' (botón
   Procesar/Procesar seleccionados sobre Resultados y Solicitudes),
   'guardar'/'eliminar' (CRUD del catálogo de Homologación). */
INSERT INTO item_accion (item_id, accion_id, estado_id)
SELECT i.id, a.id, 1
FROM item i
INNER JOIN accion a ON a.codigo IN ('ver', 'procesar', 'guardar', 'eliminar')
WHERE i.ruta = 'sihos/interfazLaboratorio'
  AND NOT EXISTS (SELECT 1 FROM item_accion ia WHERE ia.item_id = i.id AND ia.accion_id = a.id);
