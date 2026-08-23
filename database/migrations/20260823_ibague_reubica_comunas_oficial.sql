/* ============================================================
   Ibagué: reubica barrios cuya comuna en SAVID no coincide con el
   listado oficial de la Alcaldía Municipal — Secretaría de Planeación.

   Solo se mueven los casos inequívocos: el nombre aparece UNA sola vez
   en el listado oficial y UNA sola vez en SAVID. Quedaron fuera, a
   propósito:
     - Nombres que el listado oficial trae en dos comunas (Villa María
       en 9 y 11; La Esperanza en 3 y 6; Las Acacias en 3 y 8; San Luis
       en 4 y 7; La Floresta en 7 y 9).
     - Nombres duplicados en SAVID, donde una fila YA está en la comuna
       oficial y la otra sobra (Augusto E. Medina, Los Alpes, Arkacentro,
       San Vicente de Paúl, Departamental, El Poblado, Los Cambulos):
       moverlos crearía un duplicado dentro de la misma comuna.

   El código de barrio se reasigna en destino para no chocar con
   uk_barrio_comuna_codigo.
   ============================================================ */


/* Los Pinos: comuna 02 -> 08 */
SET @c := (SELECT cm.id FROM comuna cm JOIN zona z ON z.id=cm.zona_id WHERE cm.municipio_id=960 AND z.tipo='urbana' AND cm.codigo='08');
SET @k := (SELECT CONCAT('M', LPAD(COALESCE(MAX(CAST(SUBSTRING(codigo,2) AS UNSIGNED)),0)+1,3,'0')) FROM barrio WHERE comuna_id=@c AND codigo LIKE 'M%');
UPDATE barrio SET comuna_id = @c, codigo = @k WHERE id = 317;

/* Cordobita: comuna 04 -> 05 */
SET @c := (SELECT cm.id FROM comuna cm JOIN zona z ON z.id=cm.zona_id WHERE cm.municipio_id=960 AND z.tipo='urbana' AND cm.codigo='05');
SET @k := (SELECT CONCAT('M', LPAD(COALESCE(MAX(CAST(SUBSTRING(codigo,2) AS UNSIGNED)),0)+1,3,'0')) FROM barrio WHERE comuna_id=@c AND codigo LIKE 'M%');
UPDATE barrio SET comuna_id = @c, codigo = @k WHERE id = 352;

/* El Triunfo: comuna 04 -> 06 */
SET @c := (SELECT cm.id FROM comuna cm JOIN zona z ON z.id=cm.zona_id WHERE cm.municipio_id=960 AND z.tipo='urbana' AND cm.codigo='06');
SET @k := (SELECT CONCAT('M', LPAD(COALESCE(MAX(CAST(SUBSTRING(codigo,2) AS UNSIGNED)),0)+1,3,'0')) FROM barrio WHERE comuna_id=@c AND codigo LIKE 'M%');
UPDATE barrio SET comuna_id = @c, codigo = @k WHERE id = 354;

/* Miraflores: comuna 06 -> 09 */
SET @c := (SELECT cm.id FROM comuna cm JOIN zona z ON z.id=cm.zona_id WHERE cm.municipio_id=960 AND z.tipo='urbana' AND cm.codigo='09');
SET @k := (SELECT CONCAT('M', LPAD(COALESCE(MAX(CAST(SUBSTRING(codigo,2) AS UNSIGNED)),0)+1,3,'0')) FROM barrio WHERE comuna_id=@c AND codigo LIKE 'M%');
UPDATE barrio SET comuna_id = @c, codigo = @k WHERE id = 413;

/* Urbanización Protecho: comuna 07 -> 08 */
SET @c := (SELECT cm.id FROM comuna cm JOIN zona z ON z.id=cm.zona_id WHERE cm.municipio_id=960 AND z.tipo='urbana' AND cm.codigo='08');
SET @k := (SELECT CONCAT('M', LPAD(COALESCE(MAX(CAST(SUBSTRING(codigo,2) AS UNSIGNED)),0)+1,3,'0')) FROM barrio WHERE comuna_id=@c AND codigo LIKE 'M%');
UPDATE barrio SET comuna_id = @c, codigo = @k WHERE id = 463;

/* Claret: comuna 11 -> 10 */
SET @c := (SELECT cm.id FROM comuna cm JOIN zona z ON z.id=cm.zona_id WHERE cm.municipio_id=960 AND z.tipo='urbana' AND cm.codigo='10');
SET @k := (SELECT CONCAT('M', LPAD(COALESCE(MAX(CAST(SUBSTRING(codigo,2) AS UNSIGNED)),0)+1,3,'0')) FROM barrio WHERE comuna_id=@c AND codigo LIKE 'M%');
UPDATE barrio SET comuna_id = @c, codigo = @k WHERE id = 596;

/* Santa Helena: comuna 12 -> 10 */
SET @c := (SELECT cm.id FROM comuna cm JOIN zona z ON z.id=cm.zona_id WHERE cm.municipio_id=960 AND z.tipo='urbana' AND cm.codigo='10');
SET @k := (SELECT CONCAT('M', LPAD(COALESCE(MAX(CAST(SUBSTRING(codigo,2) AS UNSIGNED)),0)+1,3,'0')) FROM barrio WHERE comuna_id=@c AND codigo LIKE 'M%');
UPDATE barrio SET comuna_id = @c, codigo = @k WHERE id = 55;

/* La Isla: comuna 13 -> 11 */
SET @c := (SELECT cm.id FROM comuna cm JOIN zona z ON z.id=cm.zona_id WHERE cm.municipio_id=960 AND z.tipo='urbana' AND cm.codigo='11');
SET @k := (SELECT CONCAT('M', LPAD(COALESCE(MAX(CAST(SUBSTRING(codigo,2) AS UNSIGNED)),0)+1,3,'0')) FROM barrio WHERE comuna_id=@c AND codigo LIKE 'M%');
UPDATE barrio SET comuna_id = @c, codigo = @k WHERE id = 659;
