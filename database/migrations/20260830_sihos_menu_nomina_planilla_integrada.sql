/* ============================================================
   Módulo SIHOS — nueva pantalla "Planilla Integrada (PILA) vs SIHOS"
   (sihos/nominaPlanillaIntegrada): carga la Planilla Integrada de
   Liquidación de Aportes (el archivo YA liquidado que genera el
   operador al terminar el cargue completo) y la compara de solo
   lectura contra DetaNomi — por empleado+concepto y agregado por
   administradora. Nunca escribe en SIHOS.

   Resultado:
     SIHOS
     └── REPORTES
         └── NÓMINA
             ├── APORTES EN LÍNEA (PILA)          (sihos/nominaPila)
             ├── CORRECCIÓN NÓMINA (PILA)          (sihos/nominaPilaCorreccion)
             └── PLANILLA INTEGRADA VS SIHOS       (nueva)
============================================================ */

SET @mod_sihos_id := (SELECT id FROM modulo WHERE LOWER(TRIM(nombre)) = 'sihos' ORDER BY id LIMIT 1);
SET @item_reportes_id := (SELECT id FROM item WHERE modulo_id = @mod_sihos_id AND item_padre_id IS NULL AND nombre = 'REPORTES' LIMIT 1);
SET @item_nomina_id := (SELECT id FROM item WHERE modulo_id = @mod_sihos_id AND item_padre_id = @item_reportes_id AND nombre = 'NÓMINA' LIMIT 1);

INSERT INTO item (modulo_id, nombre, ruta, icono, orden, item_padre_id, estado_id)
SELECT @mod_sihos_id, 'PLANILLA INTEGRADA VS SIHOS', 'sihos/nominaPlanillaIntegrada', '🧮', 30, @item_nomina_id, 1
WHERE @item_nomina_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM item WHERE ruta = 'sihos/nominaPlanillaIntegrada' LIMIT 1);

/* item_accion: solo 'ver' — pantalla de solo lectura, sin CRUD ni escritura a SIHOS. */
INSERT INTO item_accion (item_id, accion_id, estado_id)
SELECT i.id, a.id, 1
FROM item i
INNER JOIN accion a ON a.codigo = 'ver'
WHERE i.ruta = 'sihos/nominaPlanillaIntegrada'
  AND NOT EXISTS (SELECT 1 FROM item_accion ia WHERE ia.item_id = i.id AND ia.accion_id = a.id);
