/* ============================================================
   Ibagué: barrios del listado oficial que faltaban considerando el
   par (comuna, barrio), no solo el nombre.

   Motivo: en Ibagué hay barrios distintos que COMPARTEN nombre y se
   diferencian por la comuna — el propio listado de la Alcaldía trae
   "Villa María" en las comunas 9 y 11, y "La Floresta" en la 7 y la 9
   (SAVID ya tenía correctamente las dos Florestas). Una comparación
   solo por nombre daba por presentes casos que en realidad faltaban.

   Se insertan únicamente los que faltan de verdad. Se descartaron los
   que ya existen con otra grafía o con el numeral implícito:
     - LIMONAR V SECTOR   -> ya está como "Limonar Sector"
     - CAÑAVERAL I        -> ya está como "Cañaveral" (existen II y III)
     - RINCON DEL PEDREGAL I -> ya está como "Rincon del Pedregal"
     - MODELIA I          -> ya está como "Modelia" (existe Modelia II)
     - VALPARAISO I       -> ya está como "Valparaíso" (existen II y III)
     - EL REFUGIO I       -> ya está como "El Refugio"
   ============================================================ */

/* comuna 06 — Vergel */
SET @c := (SELECT cm.id FROM comuna cm JOIN zona z ON z.id=cm.zona_id
           WHERE cm.municipio_id=960 AND z.tipo='urbana' AND cm.codigo='06');
INSERT INTO barrio (comuna_id, codigo, nombre, estado_id)
VALUES (@c, 'P001', 'Rincón del Pedregal II', 1);

/* comuna 08 — Simón Bolívar */
SET @c := (SELECT cm.id FROM comuna cm JOIN zona z ON z.id=cm.zona_id
           WHERE cm.municipio_id=960 AND z.tipo='urbana' AND cm.codigo='08');
INSERT INTO barrio (comuna_id, codigo, nombre, estado_id)
VALUES (@c, 'P001', 'Urbanización las Acacias', 1);

/* comuna 09 — Picaleña — Mirolindo */
SET @c := (SELECT cm.id FROM comuna cm JOIN zona z ON z.id=cm.zona_id
           WHERE cm.municipio_id=960 AND z.tipo='urbana' AND cm.codigo='09');
INSERT INTO barrio (comuna_id, codigo, nombre, estado_id)
VALUES (@c, 'P001', 'Valparaíso IV', 1);

/* comuna 11 — Ferias */
SET @c := (SELECT cm.id FROM comuna cm JOIN zona z ON z.id=cm.zona_id
           WHERE cm.municipio_id=960 AND z.tipo='urbana' AND cm.codigo='11');
INSERT INTO barrio (comuna_id, codigo, nombre, estado_id)
VALUES (@c, 'P001', 'El Refugio II', 1);
