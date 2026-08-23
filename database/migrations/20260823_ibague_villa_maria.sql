/* ============================================================
   Ibagué: corrige "Villa Marina" -> "Villa María" en las comunas 9 y 11.

   El listado oficial de la Alcaldía Municipal (Secretaría de Planeación)
   registra "VILLA MARIA" exactamente en las comunas 9 y 11 — es uno de
   los casos en que dos barrios distintos comparten nombre y los
   diferencia la comuna. SAVID tenía "Villa Marina" justo en esas dos
   comunas, procedente de SIHOS: la coincidencia de ambas ubicaciones
   indica un error de transcripción en el origen, no un barrio distinto.

   Queda sin tocar el "Villa María" de la comuna 1 (id 309), que no
   figura en el listado oficial de esa comuna.
   ============================================================ */

UPDATE barrio b
JOIN comuna c ON c.id = b.comuna_id
JOIN zona   z ON z.id = c.zona_id
SET b.nombre = 'Villa María'
WHERE c.municipio_id = 960
  AND z.tipo = 'urbana'
  AND c.codigo IN ('09', '11')
  AND b.nombre = 'Villa Marina';
