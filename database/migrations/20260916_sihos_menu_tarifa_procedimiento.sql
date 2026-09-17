/* ============================================================
   Módulo SIHOS — nueva pantalla "Tarifa Procedimientos"
   (sihos/tarifaProcedimiento): carga masiva de tarifas de TariProc a
   partir de un archivo Excel, con vista previa (nuevo/actualizar/
   sin cambio/no encontrado) antes de confirmar. Escribe en SIHOS
   (INSERT o UPDATE explícito, elegido por el usuario) — requiere
   credenciales de escritura configuradas para la empresa (Conexión
   SIHOS), igual que sihos/cruce y sihos/nominaPilaCorreccion.

   Resultado:
     SIHOS
     └── PROCESOS                              (carpeta nueva)
         └── TARIFA PROCEDIMIENTOS  (sihos/tarifaProcedimiento)
============================================================ */

SET @mod_sihos_id := (SELECT id FROM modulo WHERE LOWER(TRIM(nombre)) = 'sihos' ORDER BY id LIMIT 1);

INSERT INTO item (modulo_id, nombre, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_sihos_id, 'PROCESOS', '', '⚙️', 20, NULL, 1
WHERE @mod_sihos_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM item WHERE modulo_id = @mod_sihos_id AND item_padre_id IS NULL AND nombre = 'PROCESOS');

SET @item_procesos_id := (SELECT id FROM item WHERE modulo_id = @mod_sihos_id AND item_padre_id IS NULL AND nombre = 'PROCESOS' LIMIT 1);

INSERT INTO item (modulo_id, nombre, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_sihos_id, 'TARIFA PROCEDIMIENTOS', 'sihos/tarifaProcedimiento', '💲', 10, @item_procesos_id, 1
WHERE @item_procesos_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM item WHERE ruta = 'sihos/tarifaProcedimiento' LIMIT 1);

/* item_accion: 'ver' (abrir la pantalla, ver el grid actual, cargar y
   previsualizar el archivo) + 'confirmar' (ejecutar la escritura real
   sobre TariProc) — mismo criterio que sihos/nominaPilaCorreccion
   ('ver' + 'guardar') y sihos/cruce ('ver' + acciones finas propias). */
INSERT INTO item_accion (item_id, accion_id, estado_id)
SELECT i.id, a.id, 1
FROM item i
INNER JOIN accion a ON a.codigo = 'ver'
WHERE i.ruta = 'sihos/tarifaProcedimiento'
  AND NOT EXISTS (SELECT 1 FROM item_accion ia WHERE ia.item_id = i.id AND ia.accion_id = a.id);

INSERT INTO item_accion (item_id, accion_id, estado_id)
SELECT i.id, a.id, 1
FROM item i
INNER JOIN accion a ON a.codigo = 'confirmar'
WHERE i.ruta = 'sihos/tarifaProcedimiento'
  AND NOT EXISTS (SELECT 1 FROM item_accion ia WHERE ia.item_id = i.id AND ia.accion_id = a.id);
