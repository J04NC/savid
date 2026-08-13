/* ============================================================
   Módulo SIHOS — corrige la jerarquía de menú de Reportes:
   "Presupuesto" pasa de ser una hoja con página propia
   (sihos/presupuesto, nunca desarrollada con reporte real) a ser
   una carpeta contenedora, igual que CONFIGURACIÓN/REPORTES, y
   "Cruce reconocimientos vs contabilidad" pasa a vivir DENTRO de
   ella en vez de como hermana directa de REPORTES.

   Resultado:
     SIHOS
     ├── Configuración
     │   └── Conexión SIHOS       (sihos)
     └── Reportes
         └── Presupuesto           (carpeta, sin ruta propia)
             └── Cruce reconocimientos vs contabilidad  (sihos/cruce)
============================================================ */

/* "Presupuesto" pasa a carpeta: sin ruta propia. */
UPDATE item
SET ruta = ''
WHERE ruta = 'sihos/presupuesto';

/* "Cruce..." pasa a ser hijo de "Presupuesto" en vez de hijo de REPORTES. */
UPDATE item i
INNER JOIN item padre ON padre.nombre = 'Presupuesto' AND padre.modulo_id = i.modulo_id
SET i.item_padre_id = padre.id
WHERE i.ruta = 'sihos/cruce';

/* Las carpetas puras no llevan item_accion propio (mismo criterio que
   CONFIGURACIÓN/REPORTES, que nunca tuvieron uno) — se retira el 'ver'
   que tenía la antigua hoja "Presupuesto". No hay grants activos sobre
   él (verificado: sin filas en rol_permiso ni permiso), así que no deja
   nada huérfano. */
DELETE ia FROM item_accion ia
INNER JOIN item i ON i.id = ia.item_id
WHERE i.nombre = 'Presupuesto' AND i.ruta = '';
