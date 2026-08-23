/* ============================================================
   Ibagué: verificación contra el listado oficial de comunas y barrios
   de la Alcaldía Municipal de Ibagué — Secretaría de Planeación
   (441 barrios en 13 comunas), aportado por el usuario.

   1. Corrige los nombres donde la "ñ" quedó como "?": SIHOS almacena el
      byte 0x3F literal (comprobado leyendo la fuente tanto en utf8mb4
      como en latin1: en ambos devuelve 'PICALE?A'), así que la pérdida
      viene de origen. Se repara con la grafía del listado oficial.
   2. Agrega los barrios del listado oficial que faltaban, cada uno en
      la comuna que indica la Alcaldía.

   No se tocan los barrios de SAVID ausentes del listado oficial:
   provienen de SIHOS y suelen ser sectores o conjuntos con un detalle
   mayor que el del listado municipal.
   ============================================================ */

/* --- 1. Nombres con la eñe perdida --- */
UPDATE barrio SET nombre = 'Antonio Nariño' WHERE id = 327;
UPDATE barrio SET nombre = 'La Campiña' WHERE id = 382;
UPDATE barrio SET nombre = 'Urbanización Rincón de la Campiña' WHERE id = 392;
UPDATE barrio SET nombre = 'Ñancahuazú' WHERE id = 393;
UPDATE barrio SET nombre = 'Cañaveral' WHERE id = 397;
UPDATE barrio SET nombre = 'Cañaveral II' WHERE id = 398;
UPDATE barrio SET nombre = 'Cañaveral III' WHERE id = 399;
UPDATE barrio SET nombre = 'La Cabaña Comfacopi' WHERE id = 444;
UPDATE barrio SET nombre = 'Sector la Cabaña' WHERE id = 459;
UPDATE barrio SET nombre = 'Estación Picaleña' WHERE id = 537;
UPDATE barrio SET nombre = 'Picaleña' WHERE id = 546;
UPDATE barrio SET nombre = 'Picaleña Parte Alta' WHERE id = 547;
UPDATE barrio SET nombre = 'Picaleñita' WHERE id = 548;
UPDATE barrio SET nombre = 'San Martín Picaleña' WHERE id = 550;
UPDATE barrio SET nombre = 'El Piñón' WHERE id = 601;
UPDATE barrio SET nombre = 'Briceño' WHERE id = 663;
UPDATE barrio SET nombre = 'Peñaranda Parte Baja' WHERE id = 684;
UPDATE barrio SET nombre = 'Cañadas Potrerito' WHERE id = 714;
UPDATE barrio SET nombre = 'La Montaña' WHERE id = 719;

/* --- 2. Barrios oficiales ausentes en SAVID --- */

/* comuna 02 */
SET @c := (SELECT cm.id FROM comuna cm JOIN zona z ON z.id=cm.zona_id WHERE cm.municipio_id=960 AND z.tipo='urbana' AND cm.codigo='02');
SET @n := (SELECT COALESCE(MAX(CAST(SUBSTRING(codigo,2) AS UNSIGNED)),0) FROM barrio WHERE comuna_id=@c AND codigo LIKE 'O%');
INSERT INTO barrio (comuna_id, codigo, nombre, estado_id) VALUES
  (@c, CONCAT('O', LPAD(@n+1,3,'0')), 'Cerro de Pan de Azucar', 1),
  (@c, CONCAT('O', LPAD(@n+2,3,'0')), 'Malavar', 1),
  (@c, CONCAT('O', LPAD(@n+3,3,'0')), 'VI Brigada', 1);

/* comuna 03 */
SET @c := (SELECT cm.id FROM comuna cm JOIN zona z ON z.id=cm.zona_id WHERE cm.municipio_id=960 AND z.tipo='urbana' AND cm.codigo='03');
SET @n := (SELECT COALESCE(MAX(CAST(SUBSTRING(codigo,2) AS UNSIGNED)),0) FROM barrio WHERE comuna_id=@c AND codigo LIKE 'O%');
INSERT INTO barrio (comuna_id, codigo, nombre, estado_id) VALUES
  (@c, CONCAT('O', LPAD(@n+1,3,'0')), 'Calambeo', 1),
  (@c, CONCAT('O', LPAD(@n+2,3,'0')), 'La Esperanza', 1),
  (@c, CONCAT('O', LPAD(@n+3,3,'0')), 'Las Acacias', 1),
  (@c, CONCAT('O', LPAD(@n+4,3,'0')), 'Villa Valentina', 1);

/* comuna 04 */
SET @c := (SELECT cm.id FROM comuna cm JOIN zona z ON z.id=cm.zona_id WHERE cm.municipio_id=960 AND z.tipo='urbana' AND cm.codigo='04');
SET @n := (SELECT COALESCE(MAX(CAST(SUBSTRING(codigo,2) AS UNSIGNED)),0) FROM barrio WHERE comuna_id=@c AND codigo LIKE 'O%');
INSERT INTO barrio (comuna_id, codigo, nombre, estado_id) VALUES
  (@c, CONCAT('O', LPAD(@n+1,3,'0')), 'Cambulos', 1),
  (@c, CONCAT('O', LPAD(@n+2,3,'0')), 'Gaitán', 1),
  (@c, CONCAT('O', LPAD(@n+3,3,'0')), 'Jakaranda', 1),
  (@c, CONCAT('O', LPAD(@n+4,3,'0')), 'Jesús María Cordoba', 1),
  (@c, CONCAT('O', LPAD(@n+5,3,'0')), 'José María Cordoba Parte Baja', 1),
  (@c, CONCAT('O', LPAD(@n+6,3,'0')), 'Limonar', 1),
  (@c, CONCAT('O', LPAD(@n+7,3,'0')), 'Rincon Piedra Pintada', 1),
  (@c, CONCAT('O', LPAD(@n+8,3,'0')), 'Toscana', 1),
  (@c, CONCAT('O', LPAD(@n+9,3,'0')), 'Triunfo', 1);

/* comuna 05 */
SET @c := (SELECT cm.id FROM comuna cm JOIN zona z ON z.id=cm.zona_id WHERE cm.municipio_id=960 AND z.tipo='urbana' AND cm.codigo='05');
SET @n := (SELECT COALESCE(MAX(CAST(SUBSTRING(codigo,2) AS UNSIGNED)),0) FROM barrio WHERE comuna_id=@c AND codigo LIKE 'O%');
INSERT INTO barrio (comuna_id, codigo, nombre, estado_id) VALUES
  (@c, CONCAT('O', LPAD(@n+1,3,'0')), '4 Etapa del Jordán', 1),
  (@c, CONCAT('O', LPAD(@n+2,3,'0')), '6 Etapa del Jordán', 1),
  (@c, CONCAT('O', LPAD(@n+3,3,'0')), '7 Etapa del Jordán', 1),
  (@c, CONCAT('O', LPAD(@n+4,3,'0')), '8 Etapa del Jordán', 1),
  (@c, CONCAT('O', LPAD(@n+5,3,'0')), '9 Etapa del Jordán', 1),
  (@c, CONCAT('O', LPAD(@n+6,3,'0')), 'Andalucía', 1),
  (@c, CONCAT('O', LPAD(@n+7,3,'0')), 'Arboleda Margaritas', 1),
  (@c, CONCAT('O', LPAD(@n+8,3,'0')), 'Conjunto Residencial la Alameda', 1),
  (@c, CONCAT('O', LPAD(@n+9,3,'0')), 'La Ladera', 1),
  (@c, CONCAT('O', LPAD(@n+10,3,'0')), 'Multifamiliares el Jordán', 1),
  (@c, CONCAT('O', LPAD(@n+11,3,'0')), 'Multifamiliares las Margaritas', 1),
  (@c, CONCAT('O', LPAD(@n+12,3,'0')), 'Prados del Norte', 1),
  (@c, CONCAT('O', LPAD(@n+13,3,'0')), 'Rincón de la Campiña', 1),
  (@c, CONCAT('O', LPAD(@n+14,3,'0')), 'San Jacinto', 1),
  (@c, CONCAT('O', LPAD(@n+15,3,'0')), 'Torre Ladera', 1),
  (@c, CONCAT('O', LPAD(@n+16,3,'0')), 'Urbanización Aimara I', 1),
  (@c, CONCAT('O', LPAD(@n+17,3,'0')), 'Urbanización Aimara II', 1),
  (@c, CONCAT('O', LPAD(@n+18,3,'0')), 'Urbanización Rincon de las Margaritas', 1),
  (@c, CONCAT('O', LPAD(@n+19,3,'0')), 'Urbanización Milenium I y II', 1),
  (@c, CONCAT('O', LPAD(@n+20,3,'0')), 'Urbanización Yacaira', 1);

/* comuna 06 */
SET @c := (SELECT cm.id FROM comuna cm JOIN zona z ON z.id=cm.zona_id WHERE cm.municipio_id=960 AND z.tipo='urbana' AND cm.codigo='06');
SET @n := (SELECT COALESCE(MAX(CAST(SUBSTRING(codigo,2) AS UNSIGNED)),0) FROM barrio WHERE comuna_id=@c AND codigo LIKE 'O%');
INSERT INTO barrio (comuna_id, codigo, nombre, estado_id) VALUES
  (@c, CONCAT('O', LPAD(@n+1,3,'0')), 'Agua Viva', 1),
  (@c, CONCAT('O', LPAD(@n+2,3,'0')), 'Altos de San Francisco', 1),
  (@c, CONCAT('O', LPAD(@n+3,3,'0')), 'Balcones del Vergel', 1),
  (@c, CONCAT('O', LPAD(@n+4,3,'0')), 'Brisas del Pedregal', 1),
  (@c, CONCAT('O', LPAD(@n+5,3,'0')), 'Caminos de Juan Pablo II', 1),
  (@c, CONCAT('O', LPAD(@n+6,3,'0')), 'Caminos de San Francisco', 1),
  (@c, CONCAT('O', LPAD(@n+7,3,'0')), 'Condominio Ronda del Vergel', 1),
  (@c, CONCAT('O', LPAD(@n+8,3,'0')), 'Condominio Tierra Alta', 1),
  (@c, CONCAT('O', LPAD(@n+9,3,'0')), 'Conjunto Cerrado Ambala', 1),
  (@c, CONCAT('O', LPAD(@n+10,3,'0')), 'Conjunto Cerrado los Balsos', 1),
  (@c, CONCAT('O', LPAD(@n+11,3,'0')), 'El Mirador', 1),
  (@c, CONCAT('O', LPAD(@n+12,3,'0')), 'Estancia del Vergel', 1),
  (@c, CONCAT('O', LPAD(@n+13,3,'0')), 'Fuente de los Rosales', 1),
  (@c, CONCAT('O', LPAD(@n+14,3,'0')), 'Fuente de los Rosales II', 1),
  (@c, CONCAT('O', LPAD(@n+15,3,'0')), 'Montemadero', 1),
  (@c, CONCAT('O', LPAD(@n+16,3,'0')), 'Monteverde del Vergel', 1),
  (@c, CONCAT('O', LPAD(@n+17,3,'0')), 'Palma del Vergel', 1),
  (@c, CONCAT('O', LPAD(@n+18,3,'0')), 'Paseo de San Francisco', 1),
  (@c, CONCAT('O', LPAD(@n+19,3,'0')), 'Plazas del Bosque', 1),
  (@c, CONCAT('O', LPAD(@n+20,3,'0')), 'Portal del Vergel', 1),
  (@c, CONCAT('O', LPAD(@n+21,3,'0')), 'Primavera de Entre Rios', 1),
  (@c, CONCAT('O', LPAD(@n+22,3,'0')), 'Reservas del Pedregal', 1),
  (@c, CONCAT('O', LPAD(@n+23,3,'0')), 'Rincon de San Francisco', 1),
  (@c, CONCAT('O', LPAD(@n+24,3,'0')), 'Tierra Linda del Vergel', 1),
  (@c, CONCAT('O', LPAD(@n+25,3,'0')), 'Torre Fuente de los Rosales', 1),
  (@c, CONCAT('O', LPAD(@n+26,3,'0')), 'Torres de la Calleja', 1),
  (@c, CONCAT('O', LPAD(@n+27,3,'0')), 'Urbanización Altos de Ambala', 1),
  (@c, CONCAT('O', LPAD(@n+28,3,'0')), 'Urbanización Altos del Pedregal', 1),
  (@c, CONCAT('O', LPAD(@n+29,3,'0')), 'Urbanización Antares I', 1),
  (@c, CONCAT('O', LPAD(@n+30,3,'0')), 'Urbanización Antares II', 1),
  (@c, CONCAT('O', LPAD(@n+31,3,'0')), 'Urbanización Arkalá I', 1),
  (@c, CONCAT('O', LPAD(@n+32,3,'0')), 'Urbanización Arkalá II', 1),
  (@c, CONCAT('O', LPAD(@n+33,3,'0')), 'Urbanización Arkambuco I', 1),
  (@c, CONCAT('O', LPAD(@n+34,3,'0')), 'Urbanización Chicala', 1),
  (@c, CONCAT('O', LPAD(@n+35,3,'0')), 'Urbanización Entre Rios II', 1),
  (@c, CONCAT('O', LPAD(@n+36,3,'0')), 'Urbanización Fuente de los Rosales I', 1),
  (@c, CONCAT('O', LPAD(@n+37,3,'0')), 'Urbanización Pedregal', 1),
  (@c, CONCAT('O', LPAD(@n+38,3,'0')), 'Urbanización Villa Patricia', 1),
  (@c, CONCAT('O', LPAD(@n+39,3,'0')), 'Urbanización Villa Vanesa', 1);

/* comuna 07 */
SET @c := (SELECT cm.id FROM comuna cm JOIN zona z ON z.id=cm.zona_id WHERE cm.municipio_id=960 AND z.tipo='urbana' AND cm.codigo='07');
SET @n := (SELECT COALESCE(MAX(CAST(SUBSTRING(codigo,2) AS UNSIGNED)),0) FROM barrio WHERE comuna_id=@c AND codigo LIKE 'O%');
INSERT INTO barrio (comuna_id, codigo, nombre, estado_id) VALUES
  (@c, CONCAT('O', LPAD(@n+1,3,'0')), 'Alamos', 1),
  (@c, CONCAT('O', LPAD(@n+2,3,'0')), 'Hacienda el Recreo', 1),
  (@c, CONCAT('O', LPAD(@n+3,3,'0')), 'Mirador de Cantabria', 1),
  (@c, CONCAT('O', LPAD(@n+4,3,'0')), 'Modelia II', 1),
  (@c, CONCAT('O', LPAD(@n+5,3,'0')), 'Nueva Bilbao', 1),
  (@c, CONCAT('O', LPAD(@n+6,3,'0')), 'Pedro Ignacio Villa Marin', 1),
  (@c, CONCAT('O', LPAD(@n+7,3,'0')), 'Rosales de Tahilandia', 1),
  (@c, CONCAT('O', LPAD(@n+8,3,'0')), 'Sector los Alpes', 1),
  (@c, CONCAT('O', LPAD(@n+9,3,'0')), 'Timaka', 1),
  (@c, CONCAT('O', LPAD(@n+10,3,'0')), 'Urbanización Alameda', 1),
  (@c, CONCAT('O', LPAD(@n+11,3,'0')), 'Urbanización Alberto Lleras C', 1),
  (@c, CONCAT('O', LPAD(@n+12,3,'0')), 'Urbanización Ambikaima', 1),
  (@c, CONCAT('O', LPAD(@n+13,3,'0')), 'Urbanización Diana Milaidy', 1),
  (@c, CONCAT('O', LPAD(@n+14,3,'0')), 'Urbanización el Dorado', 1),
  (@c, CONCAT('O', LPAD(@n+15,3,'0')), 'Urbanización el Limon', 1),
  (@c, CONCAT('O', LPAD(@n+16,3,'0')), 'Urbanización el Palmar', 1),
  (@c, CONCAT('O', LPAD(@n+17,3,'0')), 'Urbanización Fuente del Salado', 1),
  (@c, CONCAT('O', LPAD(@n+18,3,'0')), 'Urbanización Fuente Santa', 1),
  (@c, CONCAT('O', LPAD(@n+19,3,'0')), 'Urbanización la Cabaña', 1),
  (@c, CONCAT('O', LPAD(@n+20,3,'0')), 'Urbanización la Candelaria', 1),
  (@c, CONCAT('O', LPAD(@n+21,3,'0')), 'Urbanización la Ceiba Norte', 1),
  (@c, CONCAT('O', LPAD(@n+22,3,'0')), 'Urbanización Lady Di', 1),
  (@c, CONCAT('O', LPAD(@n+23,3,'0')), 'Urbanización los Lagos', 1),
  (@c, CONCAT('O', LPAD(@n+24,3,'0')), 'Urbanización Monte Carlos II', 1),
  (@c, CONCAT('O', LPAD(@n+25,3,'0')), 'Urbanización Palma del Rio', 1),
  (@c, CONCAT('O', LPAD(@n+26,3,'0')), 'Urbanización Portales del Norte', 1),
  (@c, CONCAT('O', LPAD(@n+27,3,'0')), 'Urbanización Praderas del Norte', 1),
  (@c, CONCAT('O', LPAD(@n+28,3,'0')), 'Urbanización Reservas de Cantabria', 1),
  (@c, CONCAT('O', LPAD(@n+29,3,'0')), 'Urbanización San Luisu', 1),
  (@c, CONCAT('O', LPAD(@n+30,3,'0')), 'Urbanización San Sebastián', 1),
  (@c, CONCAT('O', LPAD(@n+31,3,'0')), 'Urbanización Santa Catalina I', 1),
  (@c, CONCAT('O', LPAD(@n+32,3,'0')), 'Urbanización Santa Coloma', 1),
  (@c, CONCAT('O', LPAD(@n+33,3,'0')), 'Urbanización Santa Mónica', 1),
  (@c, CONCAT('O', LPAD(@n+34,3,'0')), 'Urbanización Shaddi', 1),
  (@c, CONCAT('O', LPAD(@n+35,3,'0')), 'Urbanización Territorio de Paz', 1),
  (@c, CONCAT('O', LPAD(@n+36,3,'0')), 'Urbanización Villa Brasilia', 1),
  (@c, CONCAT('O', LPAD(@n+37,3,'0')), 'Urbanización Villa Camila', 1),
  (@c, CONCAT('O', LPAD(@n+38,3,'0')), 'Urbanización Villa Cara II', 1),
  (@c, CONCAT('O', LPAD(@n+39,3,'0')), 'Urbanización Villa Clara I', 1),
  (@c, CONCAT('O', LPAD(@n+40,3,'0')), 'Urbanización Villa Julieta', 1),
  (@c, CONCAT('O', LPAD(@n+41,3,'0')), 'Urbanización Villa Rocio', 1),
  (@c, CONCAT('O', LPAD(@n+42,3,'0')), 'Urbanización Villa Sulay', 1),
  (@c, CONCAT('O', LPAD(@n+43,3,'0')), 'Villa Salomé', 1),
  (@c, CONCAT('O', LPAD(@n+44,3,'0')), 'Villa Suiza', 1);

/* comuna 08 */
SET @c := (SELECT cm.id FROM comuna cm JOIN zona z ON z.id=cm.zona_id WHERE cm.municipio_id=960 AND z.tipo='urbana' AND cm.codigo='08');
SET @n := (SELECT COALESCE(MAX(CAST(SUBSTRING(codigo,2) AS UNSIGNED)),0) FROM barrio WHERE comuna_id=@c AND codigo LIKE 'O%');
INSERT INTO barrio (comuna_id, codigo, nombre, estado_id) VALUES
  (@c, CONCAT('O', LPAD(@n+1,3,'0')), 'Caminos del Bosque', 1),
  (@c, CONCAT('O', LPAD(@n+2,3,'0')), 'Conj Residencial San Joaquin', 1),
  (@c, CONCAT('O', LPAD(@n+3,3,'0')), 'El Bunde I II y III', 1),
  (@c, CONCAT('O', LPAD(@n+4,3,'0')), 'El Bunde IV', 1),
  (@c, CONCAT('O', LPAD(@n+5,3,'0')), 'Jardín I', 1),
  (@c, CONCAT('O', LPAD(@n+6,3,'0')), 'Jardín Parte Baja', 1),
  (@c, CONCAT('O', LPAD(@n+7,3,'0')), 'Jardín Santander I II y III', 1),
  (@c, CONCAT('O', LPAD(@n+8,3,'0')), 'Jardín Valparaíso', 1),
  (@c, CONCAT('O', LPAD(@n+9,3,'0')), 'La Cima I', 1),
  (@c, CONCAT('O', LPAD(@n+10,3,'0')), 'La Cima II', 1),
  (@c, CONCAT('O', LPAD(@n+11,3,'0')), 'Portal del Jardín', 1),
  (@c, CONCAT('O', LPAD(@n+12,3,'0')), 'Reservas del Jardín', 1),
  (@c, CONCAT('O', LPAD(@n+13,3,'0')), 'Roberto Augusto Calderon', 1),
  (@c, CONCAT('O', LPAD(@n+14,3,'0')), 'San Gelato', 1),
  (@c, CONCAT('O', LPAD(@n+15,3,'0')), 'Unidad Residencial Carabineros', 1),
  (@c, CONCAT('O', LPAD(@n+16,3,'0')), 'Urbanización 2 de Junio', 1),
  (@c, CONCAT('O', LPAD(@n+17,3,'0')), 'Urbanización Agua Marina', 1),
  (@c, CONCAT('O', LPAD(@n+18,3,'0')), 'Urbanización Altos de Vasconia', 1),
  (@c, CONCAT('O', LPAD(@n+19,3,'0')), 'Urbanización Antonio María', 1),
  (@c, CONCAT('O', LPAD(@n+20,3,'0')), 'Urbanización Brisas de Vasconia', 1),
  (@c, CONCAT('O', LPAD(@n+21,3,'0')), 'Urbanización Buenaventura Garcia', 1),
  (@c, CONCAT('O', LPAD(@n+22,3,'0')), 'Urbanización el Palmar I', 1),
  (@c, CONCAT('O', LPAD(@n+23,3,'0')), 'Urbanización el Palmar II', 1),
  (@c, CONCAT('O', LPAD(@n+24,3,'0')), 'Urbanización el Prado I', 1),
  (@c, CONCAT('O', LPAD(@n+25,3,'0')), 'Urbanización el Prado II', 1),
  (@c, CONCAT('O', LPAD(@n+26,3,'0')), 'Urbanización Jardín Atolsure', 1),
  (@c, CONCAT('O', LPAD(@n+27,3,'0')), 'Urbanización Jardín AV', 1),
  (@c, CONCAT('O', LPAD(@n+28,3,'0')), 'Urbanización Jardín Chipalo', 1),
  (@c, CONCAT('O', LPAD(@n+29,3,'0')), 'Urbanización Jardín Chipalo II', 1),
  (@c, CONCAT('O', LPAD(@n+30,3,'0')), 'Urbanización Jardín Porvenir', 1),
  (@c, CONCAT('O', LPAD(@n+31,3,'0')), 'Urbanización Jardín VI', 1),
  (@c, CONCAT('O', LPAD(@n+32,3,'0')), 'Urbanización Jardines del Campo', 1),
  (@c, CONCAT('O', LPAD(@n+33,3,'0')), 'Urbanización la Esmeralda', 1),
  (@c, CONCAT('O', LPAD(@n+34,3,'0')), 'Urbanización los Comuneros', 1),
  (@c, CONCAT('O', LPAD(@n+35,3,'0')), 'Urbanización los Laureles', 1),
  (@c, CONCAT('O', LPAD(@n+36,3,'0')), 'Urbanización Nueva Castilla', 1),
  (@c, CONCAT('O', LPAD(@n+37,3,'0')), 'Urbanización Nueva Colombia', 1),
  (@c, CONCAT('O', LPAD(@n+38,3,'0')), 'Urbanización Portal de Arkalá', 1),
  (@c, CONCAT('O', LPAD(@n+39,3,'0')), 'Urbanización Quinta AV', 1),
  (@c, CONCAT('O', LPAD(@n+40,3,'0')), 'Urbanización Vasconia', 1),
  (@c, CONCAT('O', LPAD(@n+41,3,'0')), 'Urbanización Vasconia Reservado', 1),
  (@c, CONCAT('O', LPAD(@n+42,3,'0')), 'Urbanización Villa Esperanza', 1),
  (@c, CONCAT('O', LPAD(@n+43,3,'0')), 'Urbanización Villa Jardín', 1),
  (@c, CONCAT('O', LPAD(@n+44,3,'0')), 'Urbanización Villa la Paz', 1),
  (@c, CONCAT('O', LPAD(@n+45,3,'0')), 'Urbanización Villa Vicentina', 1),
  (@c, CONCAT('O', LPAD(@n+46,3,'0')), 'Villa Cristales', 1),
  (@c, CONCAT('O', LPAD(@n+47,3,'0')), 'Yerbabuena', 1);

/* comuna 09 */
SET @c := (SELECT cm.id FROM comuna cm JOIN zona z ON z.id=cm.zona_id WHERE cm.municipio_id=960 AND z.tipo='urbana' AND cm.codigo='09');
SET @n := (SELECT COALESCE(MAX(CAST(SUBSTRING(codigo,2) AS UNSIGNED)),0) FROM barrio WHERE comuna_id=@c AND codigo LIKE 'O%');
INSERT INTO barrio (comuna_id, codigo, nombre, estado_id) VALUES
  (@c, CONCAT('O', LPAD(@n+1,3,'0')), '2 Etapa del Jordán', 1),
  (@c, CONCAT('O', LPAD(@n+2,3,'0')), 'Altamira', 1),
  (@c, CONCAT('O', LPAD(@n+3,3,'0')), 'Aparco', 1),
  (@c, CONCAT('O', LPAD(@n+4,3,'0')), 'Arboleda', 1),
  (@c, CONCAT('O', LPAD(@n+5,3,'0')), 'Arkaniza I', 1),
  (@c, CONCAT('O', LPAD(@n+6,3,'0')), 'Arkaniza II', 1),
  (@c, CONCAT('O', LPAD(@n+7,3,'0')), 'Carrenales', 1),
  (@c, CONCAT('O', LPAD(@n+8,3,'0')), 'Conj Residencial Valparaíso', 1),
  (@c, CONCAT('O', LPAD(@n+9,3,'0')), 'El Tunal', 1),
  (@c, CONCAT('O', LPAD(@n+10,3,'0')), 'Hacienda Piedra Pintada', 1),
  (@c, CONCAT('O', LPAD(@n+11,3,'0')), 'Los Tunjos', 1),
  (@c, CONCAT('O', LPAD(@n+12,3,'0')), 'Picañelita', 1),
  (@c, CONCAT('O', LPAD(@n+13,3,'0')), 'Portal de los Tunjos', 1),
  (@c, CONCAT('O', LPAD(@n+14,3,'0')), 'Reservas del Campestre', 1),
  (@c, CONCAT('O', LPAD(@n+15,3,'0')), 'Rincon de las Américas', 1),
  (@c, CONCAT('O', LPAD(@n+16,3,'0')), 'Rincon del Campestre', 1),
  (@c, CONCAT('O', LPAD(@n+17,3,'0')), 'San Francisco', 1),
  (@c, CONCAT('O', LPAD(@n+18,3,'0')), 'San Remo', 1),
  (@c, CONCAT('O', LPAD(@n+19,3,'0')), 'Urbanización Bosque de Varsovia', 1),
  (@c, CONCAT('O', LPAD(@n+20,3,'0')), 'Urbanización Chaquén', 1),
  (@c, CONCAT('O', LPAD(@n+21,3,'0')), 'Urbanización Comfenalco', 1),
  (@c, CONCAT('O', LPAD(@n+22,3,'0')), 'Urbanización Coopdiasam', 1),
  (@c, CONCAT('O', LPAD(@n+23,3,'0')), 'Urbanización las Américas', 1),
  (@c, CONCAT('O', LPAD(@n+24,3,'0')), 'Urbanización las Flores', 1),
  (@c, CONCAT('O', LPAD(@n+25,3,'0')), 'Urbanización los Remansos', 1),
  (@c, CONCAT('O', LPAD(@n+26,3,'0')), 'Urbanización Nuevo Horizonte', 1),
  (@c, CONCAT('O', LPAD(@n+27,3,'0')), 'Urbanización Portal Campestre', 1),
  (@c, CONCAT('O', LPAD(@n+28,3,'0')), 'Urbanización Praderas de Santa Rita', 1),
  (@c, CONCAT('O', LPAD(@n+29,3,'0')), 'Urbanización Tahití', 1),
  (@c, CONCAT('O', LPAD(@n+30,3,'0')), 'Urbanización Varsovia', 1),
  (@c, CONCAT('O', LPAD(@n+31,3,'0')), 'Urbanización Villa Café', 1),
  (@c, CONCAT('O', LPAD(@n+32,3,'0')), 'Urbanización Villa de la Candelaria', 1),
  (@c, CONCAT('O', LPAD(@n+33,3,'0')), 'Urbanización Villa Luz', 1),
  (@c, CONCAT('O', LPAD(@n+34,3,'0')), 'Urbanización Villa Yuli', 1),
  (@c, CONCAT('O', LPAD(@n+35,3,'0')), 'Villa Carvajalita', 1),
  (@c, CONCAT('O', LPAD(@n+36,3,'0')), 'Villa Natalia', 1);

/* comuna 10 */
SET @c := (SELECT cm.id FROM comuna cm JOIN zona z ON z.id=cm.zona_id WHERE cm.municipio_id=960 AND z.tipo='urbana' AND cm.codigo='10');
SET @n := (SELECT COALESCE(MAX(CAST(SUBSTRING(codigo,2) AS UNSIGNED)),0) FROM barrio WHERE comuna_id=@c AND codigo LIKE 'O%');
INSERT INTO barrio (comuna_id, codigo, nombre, estado_id) VALUES
  (@c, CONCAT('O', LPAD(@n+1,3,'0')), 'Bosques de Santa Helena', 1),
  (@c, CONCAT('O', LPAD(@n+2,3,'0')), 'Macarena Parte Alta', 1),
  (@c, CONCAT('O', LPAD(@n+3,3,'0')), 'Macarena Parte Baja', 1),
  (@c, CONCAT('O', LPAD(@n+4,3,'0')), 'Metaima Alta', 1),
  (@c, CONCAT('O', LPAD(@n+5,3,'0')), 'Metaima Parte Baja', 1);

/* comuna 11 */
SET @c := (SELECT cm.id FROM comuna cm JOIN zona z ON z.id=cm.zona_id WHERE cm.municipio_id=960 AND z.tipo='urbana' AND cm.codigo='11');
SET @n := (SELECT COALESCE(MAX(CAST(SUBSTRING(codigo,2) AS UNSIGNED)),0) FROM barrio WHERE comuna_id=@c AND codigo LIKE 'O%');
INSERT INTO barrio (comuna_id, codigo, nombre, estado_id) VALUES
  (@c, CONCAT('O', LPAD(@n+1,3,'0')), 'América', 1),
  (@c, CONCAT('O', LPAD(@n+2,3,'0')), 'Arado', 1),
  (@c, CONCAT('O', LPAD(@n+3,3,'0')), 'Independiente', 1),
  (@c, CONCAT('O', LPAD(@n+4,3,'0')), 'La Martinica', 1),
  (@c, CONCAT('O', LPAD(@n+5,3,'0')), 'Peñón', 1);

/* comuna 12 */
SET @c := (SELECT cm.id FROM comuna cm JOIN zona z ON z.id=cm.zona_id WHERE cm.municipio_id=960 AND z.tipo='urbana' AND cm.codigo='12');
SET @n := (SELECT COALESCE(MAX(CAST(SUBSTRING(codigo,2) AS UNSIGNED)),0) FROM barrio WHERE comuna_id=@c AND codigo LIKE 'O%');
INSERT INTO barrio (comuna_id, codigo, nombre, estado_id) VALUES
  (@c, CONCAT('O', LPAD(@n+1,3,'0')), 'Andrés López de Galarza', 1),
  (@c, CONCAT('O', LPAD(@n+2,3,'0')), 'Colonias de Asprovi', 1),
  (@c, CONCAT('O', LPAD(@n+3,3,'0')), 'Keneddy', 1),
  (@c, CONCAT('O', LPAD(@n+4,3,'0')), 'Matayana', 1),
  (@c, CONCAT('O', LPAD(@n+5,3,'0')), 'Rosa Badillo', 1),
  (@c, CONCAT('O', LPAD(@n+6,3,'0')), 'Santofimio', 1),
  (@c, CONCAT('O', LPAD(@n+7,3,'0')), 'Urbanización Arkaima', 1),
  (@c, CONCAT('O', LPAD(@n+8,3,'0')), 'Urbanización Divino Niño', 1),
  (@c, CONCAT('O', LPAD(@n+9,3,'0')), 'Urbanización Terrazas del Tejar', 1);

/* comuna 13 */
SET @c := (SELECT cm.id FROM comuna cm JOIN zona z ON z.id=cm.zona_id WHERE cm.municipio_id=960 AND z.tipo='urbana' AND cm.codigo='13');
SET @n := (SELECT COALESCE(MAX(CAST(SUBSTRING(codigo,2) AS UNSIGNED)),0) FROM barrio WHERE comuna_id=@c AND codigo LIKE 'O%');
INSERT INTO barrio (comuna_id, codigo, nombre, estado_id) VALUES
  (@c, CONCAT('O', LPAD(@n+1,3,'0')), 'Albania', 1),
  (@c, CONCAT('O', LPAD(@n+2,3,'0')), 'Cerros de Granate', 1),
  (@c, CONCAT('O', LPAD(@n+3,3,'0')), 'Isla', 1),
  (@c, CONCAT('O', LPAD(@n+4,3,'0')), 'Jazmín', 1),
  (@c, CONCAT('O', LPAD(@n+5,3,'0')), 'Las Colinas 1', 1),
  (@c, CONCAT('O', LPAD(@n+6,3,'0')), 'Las Colinas 2', 1),
  (@c, CONCAT('O', LPAD(@n+7,3,'0')), 'Terrazas de Boquerón', 1),
  (@c, CONCAT('O', LPAD(@n+8,3,'0')), 'Villa Mery', 1);
