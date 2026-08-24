/* ============================================================
   Ibagué: deja activos solo los barrios respaldados por el listado
   oficial de la Alcaldía Municipal (Secretaría de Planeación).

   De los 646 barrios urbanos de Ibagué, 435 figuran en el listado y 211
   no. Esos 211 vienen de SIHOS y son de tres clases: subdivisiones más
   finas que las del listado (La Pola Parte Alta, Belén Parte Alta),
   nombres que el listado no recoge (Chapetón, La Vega, Ancón Tesorito)
   y unos pocos que no son barrios.

   Se usa BAJA LÓGICA (deleted_at) en lugar de DELETE porque el listado
   oficial no está fechado y su imagen institucional corresponde a una
   administración anterior: es probable que no incluya urbanizaciones
   posteriores que SIHOS, sistema en operación, sí conoce. Con
   deleted_at desaparecen de los formularios —el motor CRUD filtra por
   esa columna— pero siguen siendo recuperables.

   RESGUARDO: nunca se da de baja un barrio que algún tercero tenga
   asignado, aunque falte en el listado. Ese caso ya se presentó al
   probar en un clon: "Simón Bolívar" (comuna 8) está en uso, y no
   aparece en el listado porque allí ese nombre designa a la comuna
   entera y se enumeran sus ciudadelas.

   Excepción: cuatro registros que no son barrios (un hospital, dos
   conjuntos de apartamentos y una torre) se eliminan de verdad, también
   solo si nadie los referencia.

   No se toca lo rural (138 registros): el listado oficial solo cubre
   las comunas urbanas 1 a 13.
   ============================================================ */

/* --- Baja lógica de los barrios sin respaldo oficial y sin uso --- */
UPDATE barrio SET deleted_at = NOW(3)
 WHERE deleted_at IS NULL
 AND id NOT IN (SELECT barrio_id FROM (SELECT barrio_id FROM tercero WHERE barrio_id IS NOT NULL) AS usados)
   AND id IN (7,26,35,39,40,41,44,45,46,47,49,50,52,53,54,57,304,305,306,307,308,310,311,312,313,314,315,318,319,320,321,322,323,324,325,330,331,336,337,339);
UPDATE barrio SET deleted_at = NOW(3)
 WHERE deleted_at IS NULL
 AND id NOT IN (SELECT barrio_id FROM (SELECT barrio_id FROM tercero WHERE barrio_id IS NOT NULL) AS usados)
   AND id IN (340,342,350,351,353,355,356,357,358,360,367,369,372,375,376,377,378,379,380,381,385,389,390,393,396,397,399,400,401,405,407,414,415,416,417,418,419,422,423,424);
UPDATE barrio SET deleted_at = NOW(3)
 WHERE deleted_at IS NULL
 AND id NOT IN (SELECT barrio_id FROM (SELECT barrio_id FROM tercero WHERE barrio_id IS NOT NULL) AS usados)
   AND id IN (426,428,430,431,432,433,434,438,440,442,444,445,446,447,449,450,451,455,456,457,459,462,466,472,473,474,475,476,478,482,483,484,485,486,487,488,490,492,493,494);
UPDATE barrio SET deleted_at = NOW(3)
 WHERE deleted_at IS NULL
 AND id NOT IN (SELECT barrio_id FROM (SELECT barrio_id FROM tercero WHERE barrio_id IS NOT NULL) AS usados)
   AND id IN (495,496,497,498,499,503,505,506,507,509,511,513,514,515,518,519,524,526,528,532,533,535,537,538,540,541,543,544,545,547,548,549,550,551,552,553,554,555,557,560);
UPDATE barrio SET deleted_at = NOW(3)
 WHERE deleted_at IS NULL
 AND id NOT IN (SELECT barrio_id FROM (SELECT barrio_id FROM tercero WHERE barrio_id IS NOT NULL) AS usados)
   AND id IN (563,564,576,579,581,582,586,588,589,591,592,595,598,599,600,601,602,604,606,607,608,609,610,614,616,622,623,625,626,627,628,631,632,636,639,640,641,642,644,645);
UPDATE barrio SET deleted_at = NOW(3)
 WHERE deleted_at IS NULL
 AND id NOT IN (SELECT barrio_id FROM (SELECT barrio_id FROM tercero WHERE barrio_id IS NOT NULL) AS usados)
   AND id IN (646,647,648,651,652,656,658);

/* --- Borrado real: registros que no son barrios --- */
DELETE FROM barrio
 WHERE id IN (341,370,395,575)
 AND id NOT IN (SELECT barrio_id FROM (SELECT barrio_id FROM tercero WHERE barrio_id IS NOT NULL) AS usados);

/* ------------------------------------------------------------
   Cierre del resguardo.

   Al aplicar lo anterior, "Simón Bolívar" (id 46, comuna 8) quedó activo
   porque un tercero lo tenía asignado. Ese tercero ya fue reasignado a
   "Ciudadela Simón Bolívar I Etapa", así que el registro queda sin uso y
   se le aplica la misma baja lógica que al resto de los no oficiales.
   La condición de uso se mantiene por seguridad.
   ------------------------------------------------------------ */

UPDATE barrio SET deleted_at = NOW(3)
 WHERE id = 46
   AND deleted_at IS NULL
   AND id NOT IN (SELECT barrio_id FROM (SELECT barrio_id FROM tercero
                  WHERE barrio_id IS NOT NULL) AS usados);
