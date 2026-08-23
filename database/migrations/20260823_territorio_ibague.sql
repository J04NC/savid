/* ============================================================
   Barrios y veredas de Ibagué (73001) desde SIHOS.

   Complementa el catálogo: SAVID ya tenía las 13 comunas urbanas y 17
   corregimientos con nombres reales, más 84 barrios. Aquí solo entran
   los que faltaban; los 31 que solo tiene SAVID quedan intactos.

   Mapeo de comunas:
   - Urbanas: los códigos 01..13 coinciden (SIHOS solo los nombra
     UNO..TRECE; se conservan los nombres reales de SAVID).
     Verificado con OpenStreetMap, que sitúa "Ciudadela Simón Bolívar"
     en la Comuna 8, igual que SIHOS.
   - Rurales: la numeración NO coincide (SIHOS 21..37 vs SAVID 01..17),
     se mapean por nombre. 12 coinciden y se crean 5 que faltaban
     (Gamboa, Cay, Buenos Aires, Carmen de Bulira, Calambeo rural).

   Limpieza de nombres: abreviaturas con punto (CIUD./CUID. -> Ciudadela,
   URB. -> Urbanización, SEC. -> Sector, FCO. -> Francisco, CON. ->
   Conjunto, HOSP. -> Hospital), erratas (JARDUIN -> Jardín, SAN
   BERNANDO -> San Bernardo), formato Título y tildes.

   Códigos: se reasignan cuando el de SIHOS ya estaba ocupado en esa
   comuna de SAVID (uk_barrio_comuna_codigo).
   ============================================================ */


/* comuna 01 — Centro */
SET @c := 49;
INSERT INTO barrio (comuna_id, codigo, nombre, estado_id) VALUES
  (@c, '014', 'Brisas del Combeima', 1),
  (@c, 'B001', 'Chapeton', 1),
  (@c, '015', 'La Pola Sector los Tanques la Coqueta', 1),
  (@c, 'B002', 'La Vega', 1),
  (@c, '013', 'Pueblo Nuevo Parte Baja', 1),
  (@c, '012', 'Villa María', 1);

/* comuna 02 — Calambeo */
SET @c := 48;
INSERT INTO barrio (comuna_id, codigo, nombre, estado_id) VALUES
  (@c, '052', 'Alaskita', 1),
  (@c, '078', 'Ancon Tesorito', 1),
  (@c, '055', 'Belén Parte Alta', 1),
  (@c, '081', 'Conjunto Cerrado Fontenova', 1),
  (@c, '080', 'Conjunto Cerrado Pablo VI', 1),
  (@c, '079', 'El Oasis', 1),
  (@c, '073', 'Los Alpes', 1),
  (@c, '072', 'Los Pinos', 1),
  (@c, '083', 'Muiltifamiliares la Aurora', 1),
  (@c, '075', 'Pan de Azucar', 1),
  (@c, '068', 'Siete de Agosto', 1),
  (@c, '082', 'Torres del Libano', 1),
  (@c, '069', 'Trinidad', 1),
  (@c, '058', 'Urbanización Himalaya', 1),
  (@c, '059', 'Urbanización Irazu', 1),
  (@c, '064', 'Urbanización Pablo VI', 1),
  (@c, '060', 'Urbanización la Aurora', 1);

/* comuna 03 — San Simón */
SET @c := 47;
INSERT INTO barrio (comuna_id, codigo, nombre, estado_id) VALUES
  (@c, '101', 'Antonio Nari?o', 1),
  (@c, '102', 'Belalcazar', 1),
  (@c, '104', 'Carmenza Rocha', 1),
  (@c, '117', 'Diamante', 1),
  (@c, '122', 'El Cafetal', 1),
  (@c, '105', 'El Carmen', 1),
  (@c, '106', 'Fenalco', 1),
  (@c, '107', 'Gaitan Parte Alta', 1),
  (@c, '108', 'Inem', 1),
  (@c, '121', 'La Ceiba', 1),
  (@c, '112', 'San Jorge', 1),
  (@c, '114', 'San Simón Parte Baja', 1),
  (@c, '118', 'Santa Lucia', 1),
  (@c, '111', 'Sector las Acacias', 1),
  (@c, '119', 'Torre de los Periodistas', 1),
  (@c, '123', 'Urbanización Hacienda Calambeo', 1),
  (@c, '115', 'Urbanización Villa Pinzon', 1),
  (@c, '120', 'Villa Ilusion', 1),
  (@c, '116', 'Viveros', 1);

/* comuna 04 — Piedrapintada */
SET @c := 46;
INSERT INTO barrio (comuna_id, codigo, nombre, estado_id) VALUES
  (@c, '151', 'Alfonso López', 1),
  (@c, '152', 'Calarca', 1),
  (@c, '153', 'Caracoli', 1),
  (@c, '154', 'Castilla', 1),
  (@c, '155', 'Cordoba', 1),
  (@c, '156', 'Cordoba Parte Baja', 1),
  (@c, '157', 'Cordobita', 1),
  (@c, '163', 'El Limonar', 1),
  (@c, '159', 'El Triunfo', 1),
  (@c, '160', 'Gaitan Parte Baja', 1),
  (@c, '161', 'Jardines de Navarra', 1),
  (@c, '162', 'Las Viudas', 1),
  (@c, '158', 'Limonar Sector', 1),
  (@c, '173', 'Onzaga', 1),
  (@c, '164', 'Piedra Pintada Parte Baja', 1),
  (@c, '165', 'Pijao', 1),
  (@c, '166', 'Restrepo', 1),
  (@c, '167', 'San Carlos', 1),
  (@c, '168', 'San Luis', 1),
  (@c, '169', 'Sorrento', 1),
  (@c, '170', 'Urbanización Villa Teresa', 1),
  (@c, '174', 'Urbanización el Pijao', 1),
  (@c, '171', 'Villa Marlen I', 1),
  (@c, '172', 'Villa Marlen II', 1);

/* comuna 05 — Jordán */
SET @c := 45;
INSERT INTO barrio (comuna_id, codigo, nombre, estado_id) VALUES
  (@c, '222', 'Apartamentos', 1),
  (@c, '202', 'Arkacentro', 1),
  (@c, '203', 'Arkalucia', 1),
  (@c, '204', 'Arkamonica', 1),
  (@c, '206', 'El Eden', 1),
  (@c, '208', 'El Prado', 1),
  (@c, '209', 'Jordán IV Etapa', 1),
  (@c, '213', 'Jordán IX Etapa', 1),
  (@c, '207', 'Jordán Multifamiliares', 1),
  (@c, '210', 'Jordán VI Etapa', 1),
  (@c, '211', 'Jordán VII Etapa', 1),
  (@c, '212', 'Jordán VIII Etapa', 1),
  (@c, '214', 'La Campi?a', 1),
  (@c, '215', 'Las Margaritas', 1),
  (@c, '216', 'Las Orquideas', 1),
  (@c, '217', 'Los Arrayanes', 1),
  (@c, '218', 'Los Ocobos', 1),
  (@c, '219', 'Los Parrales', 1),
  (@c, '220', 'Macadamia', 1),
  (@c, '201', 'Urbanización Almeria', 1),
  (@c, '223', 'Urbanización Anda Lucia', 1),
  (@c, '205', 'Urbanización Calatayud', 1),
  (@c, '221', 'Urbanización Rincon de la Campi?a', 1);

/* comuna 06 — Vergel */
SET @c := 44;
INSERT INTO barrio (comuna_id, codigo, nombre, estado_id) VALUES
  (@c, '288', '?ancahuazu', 1),
  (@c, '251', 'Ambalá', 1),
  (@c, '280', 'Apartamentos Entre Rios', 1),
  (@c, '279', 'Arkalucia Madacamia', 1),
  (@c, '278', 'Ca?averal', 1),
  (@c, '293', 'Ca?averal II', 1),
  (@c, '294', 'Ca?averal III', 1),
  (@c, '285', 'Carandu', 1),
  (@c, '260', 'El Vergel', 1),
  (@c, '261', 'Entre Rios', 1),
  (@c, '296', 'Girasol', 1),
  (@c, '281', 'Ibagué 2000', 1),
  (@c, '262', 'La Arboleda', 1),
  (@c, '282', 'La Balsa', 1),
  (@c, '264', 'Lagaviota', 1),
  (@c, '272', 'Las Delicias', 1),
  (@c, '274', 'Los Angeles', 1),
  (@c, '273', 'Los Ciruelos', 1),
  (@c, '267', 'Los Gualandayes', 1),
  (@c, '277', 'Los Mandarinos', 1),
  (@c, '297', 'Miraflores', 1),
  (@c, '283', 'Parque Residencial la Primavera', 1),
  (@c, '299', 'Portal del Bosque', 1),
  (@c, '286', 'Primavera', 1),
  (@c, '287', 'Quinto el Vergel', 1),
  (@c, '289', 'Rincon del Bosque', 1),
  (@c, '259', 'Rincon del Pedregal', 1),
  (@c, '270', 'Rincon del Vergel', 1),
  (@c, '268', 'San Antonio', 1),
  (@c, '298', 'Tierra Linda', 1),
  (@c, '284', 'Torres del Vergel', 1),
  (@c, '276', 'Triunfo Bella Vista', 1),
  (@c, '252', 'Urbanización Ambalá', 1),
  (@c, '253', 'Urbanización Antares', 1),
  (@c, '255', 'Urbanización Arkacentro', 1),
  (@c, '254', 'Urbanización Arkala', 1),
  (@c, '265', 'Urbanización Colinas del Norte', 1),
  (@c, '290', 'Urbanización Pedregal II', 1),
  (@c, '291', 'Urbanización Pedregal III', 1),
  (@c, '292', 'Urbanización Pedregal IV', 1),
  (@c, '258', 'Urbanización el Pedregal', 1),
  (@c, '263', 'Urbanización la Esperenza', 1),
  (@c, '295', 'Urbanización los Alpes', 1),
  (@c, '266', 'Urbanización los Cambulos', 1),
  (@c, '275', 'Villa Gloria', 1),
  (@c, '271', 'Yurupari', 1);

/* comuna 07 — Salado */
SET @c := 43;
INSERT INTO barrio (comuna_id, codigo, nombre, estado_id) VALUES
  (@c, '333', 'Cantabria', 1),
  (@c, '324', 'Ceiba Norte', 1),
  (@c, '315', 'Chico', 1),
  (@c, '320', 'Darien San Lucas', 1),
  (@c, '301', 'El Salado', 1),
  (@c, '328', 'La Caba?a Comfacopi', 1),
  (@c, '303', 'La Ceiba', 1),
  (@c, '305', 'Lod Lagos', 1),
  (@c, '317', 'Los Alpez', 1),
  (@c, '322', 'Los Musicos', 1),
  (@c, '316', 'Modelia', 1),
  (@c, '306', 'Montecarlo', 1),
  (@c, '325', 'Montecarlos II', 1),
  (@c, '307', 'Oviedo', 1),
  (@c, '308', 'Pacande', 1),
  (@c, '319', 'Palo Grande', 1),
  (@c, '309', 'Parcelacion Ibagué', 1),
  (@c, '327', 'Salado Seccion Ceiba Sur', 1),
  (@c, '318', 'San Tropel', 1),
  (@c, '331', 'Santa Ana', 1),
  (@c, '302', 'Sector la Caba?a', 1),
  (@c, '332', 'Tierra Firme', 1),
  (@c, '334', 'Urbanización Comfatolima', 1),
  (@c, '310', 'Urbanización Pedro Villamarin', 1),
  (@c, '311', 'Urbanización Protecho', 1),
  (@c, '330', 'Urbanización San Luis Gonzaga', 1),
  (@c, '312', 'Urbanización San Pablo', 1),
  (@c, '313', 'Urbanización Villa Clara', 1),
  (@c, '314', 'Urbanización Villa Martha', 1),
  (@c, '326', 'Urbanización el Salado', 1),
  (@c, '329', 'Urbanización la Floresta', 1),
  (@c, '304', 'Urbanización la Victoria', 1),
  (@c, '323', 'Villa Cindy', 1);

/* comuna 08 — Simón Bolívar */
SET @c := 42;
INSERT INTO barrio (comuna_id, codigo, nombre, estado_id) VALUES
  (@c, '379', 'Acasias', 1),
  (@c, '382', 'Aguamarina', 1),
  (@c, '351', 'Altosure', 1),
  (@c, '383', 'Buenaventura', 1),
  (@c, '376', 'Carlos Pizarro', 1),
  (@c, '352', 'Ciudad Blanca', 1),
  (@c, '390', 'Ciudad Simón Bolívar IV Etapa Datecho', 1),
  (@c, '368', 'Ciudadela Simón Bolívar I Etapa', 1),
  (@c, '386', 'Ciudadela Simón Bolívar II Etapa', 1),
  (@c, '702', 'Ciudadela Simón Bolívar III Etapa', 1),
  (@c, '387', 'Ciudadela Simón Bolívar Sector Baltazar', 1),
  (@c, '353', 'Comuneros', 1),
  (@c, '397', 'Condominio Nueva Andalucía', 1),
  (@c, '373', 'Diamante', 1),
  (@c, '385', 'El Bunde', 1),
  (@c, '355', 'Francisco Paula Santander', 1),
  (@c, '356', 'Germán Huertas C', 1),
  (@c, '358', 'Jardín II Etapa', 1),
  (@c, '393', 'Jardín II Etapa Sector el Porvenir', 1),
  (@c, '359', 'Jardín III Etapa', 1),
  (@c, '389', 'Jardín Parte Alta Sector Carabineros', 1),
  (@c, '384', 'Jardín Santander', 1),
  (@c, '388', 'Jardín Sector Diamante', 1),
  (@c, '395', 'Jardín Sector las Acacias', 1),
  (@c, '394', 'Jardín Sector los Pinos', 1),
  (@c, '357', 'Jardín V Etapa', 1),
  (@c, '360', 'Jardín del Campo', 1),
  (@c, '381', 'Mi Tolima', 1),
  (@c, '361', 'Musicalia', 1),
  (@c, '399', 'Nuevo Armero', 1),
  (@c, '378', 'Nuevo Combeima', 1),
  (@c, '362', 'Nuevo Palermo', 1),
  (@c, '363', 'Palermo', 1),
  (@c, '364', 'Palmar', 1),
  (@c, '365', 'Protecho II', 1),
  (@c, '366', 'Roberto Calderon', 1),
  (@c, '367', 'San Vicente de Paul', 1),
  (@c, '369', 'Sintratolima', 1),
  (@c, '370', 'Topacio', 1),
  (@c, '391', 'Topacio Plan D', 1),
  (@c, '371', 'Tulio Varon', 1),
  (@c, '396', 'Urbanización Martin Reyes', 1),
  (@c, '701', 'Urbanización Quinta Avenida', 1),
  (@c, '398', 'Urbanización San Luisa', 1),
  (@c, '392', 'Urbanización Tolima Grande', 1),
  (@c, '372', 'Urbanización Villa Marcela', 1),
  (@c, '354', 'Urbanización el Prado', 1),
  (@c, '377', 'Valparaíso', 1),
  (@c, '375', 'Villa Magdalena', 1),
  (@c, '400', 'Villa del Norte', 1),
  (@c, '700', 'Villa del Palmar', 1),
  (@c, '380', 'Villa del Sol', 1),
  (@c, '374', 'Villa los Rios', 1);

/* comuna 09 — Picaleña — Mirolindo */
SET @c := 41;
INSERT INTO barrio (comuna_id, codigo, nombre, estado_id) VALUES
  (@c, '401', 'Alfonso Uribe Badillo', 1),
  (@c, '402', 'Aparco San Francisco', 1),
  (@c, '404', 'Arkaparaiso', 1),
  (@c, '429', 'Arkiza II Etapa', 1),
  (@c, '405', 'Bello Horizonte', 1),
  (@c, '435', 'Bosque de la Alameda', 1),
  (@c, '406', 'Ciudad Luz', 1),
  (@c, '445', 'Ciudadela Comfenalco', 1),
  (@c, '446', 'Ciudadela las Américas', 1),
  (@c, '407', 'Cutucumay', 1),
  (@c, '408', 'El Campestre Ciudadela', 1),
  (@c, '409', 'El Poblado', 1),
  (@c, '434', 'Estacion Picale?a', 1),
  (@c, '425', 'Fabiolandia', 1),
  (@c, '411', 'Jordán I Etapa', 1),
  (@c, '437', 'Jordán I Etapa detrás de la Presentacion', 1),
  (@c, '438', 'Jordán II Etapa', 1),
  (@c, '439', 'Jordán III Etapa', 1),
  (@c, '413', 'La Sorbona', 1),
  (@c, '436', 'Miraflores Conjunto Cerrado', 1),
  (@c, '417', 'Papayo', 1),
  (@c, '418', 'Picale?a', 1),
  (@c, '427', 'Picale?a Parte Alta', 1),
  (@c, '442', 'Picale?ita', 1),
  (@c, '419', 'Piedra Pintada Parte Baja', 1),
  (@c, '426', 'San Martin Picale?a', 1),
  (@c, '444', 'Terrazas del Campestre', 1),
  (@c, '410', 'Tunal', 1),
  (@c, '403', 'Urbanización Arkaniza', 1),
  (@c, '416', 'Urbanización Niza', 1),
  (@c, '443', 'Urbanización Taiti', 1),
  (@c, '423', 'Urbanización Villa Arkadia', 1),
  (@c, '432', 'Urbanización Villa Caf', 1),
  (@c, '412', 'Urbanización la Floresta', 1),
  (@c, '414', 'Urbanización las Palmeras', 1),
  (@c, '421', 'Valparaíso', 1),
  (@c, '428', 'Valparaíso II Etapa', 1),
  (@c, '433', 'Valparaíso III Etapa', 1),
  (@c, '430', 'Varsovia I Etapa', 1),
  (@c, '431', 'Varsovia II Etapa', 1),
  (@c, '422', 'Versalles', 1),
  (@c, '424', 'Villa Marina', 1),
  (@c, '441', 'Villa del Pilar', 1);

/* comuna 10 — Estadio */
SET @c := 40;
INSERT INTO barrio (comuna_id, codigo, nombre, estado_id) VALUES
  (@c, '453', 'Arkalena', 1),
  (@c, '454', 'Boyaca', 1),
  (@c, '456', 'Casa Club', 1),
  (@c, '455', 'Cádiz', 1),
  (@c, '457', 'Departamental', 1),
  (@c, '458', 'Federico Lleras', 1),
  (@c, '459', 'Hipodromo', 1),
  (@c, '460', 'Hospital Federico Lleras', 1),
  (@c, '461', 'La Castellana', 1),
  (@c, '463', 'Las Palmas', 1),
  (@c, '464', 'Laureles', 1),
  (@c, '465', 'Macarena', 1),
  (@c, '466', 'Magisterio', 1),
  (@c, '467', 'Metaima 1', 1),
  (@c, '468', 'Metaima 2', 1),
  (@c, '469', 'Montealegre', 1),
  (@c, '470', 'Nacional', 1),
  (@c, '471', 'Naciones Unidas', 1),
  (@c, '472', 'Primero de Mayo', 1),
  (@c, '473', 'San Cayetano', 1),
  (@c, '475', 'San Fernando', 1),
  (@c, '474', 'San María Claret', 1),
  (@c, '478', 'Santander', 1),
  (@c, '480', 'Torres del Ferrocarril', 1),
  (@c, '479', 'Urbanización Martinica', 1);

/* comuna 11 — Ferias */
SET @c := 39;
INSERT INTO barrio (comuna_id, codigo, nombre, estado_id) VALUES
  (@c, '503', '12 de Octubre', 1),
  (@c, '501', 'Alto de la Cruz', 1),
  (@c, '502', 'America Parte Baja', 1),
  (@c, '528', 'Claret', 1),
  (@c, '527', 'Departamental', 1),
  (@c, '504', 'El Arado', 1),
  (@c, '505', 'El Bosque Parte Alta', 1),
  (@c, '506', 'El Bosque Parte Baja', 1),
  (@c, '507', 'El Pi?on', 1),
  (@c, '508', 'El Playon', 1),
  (@c, '526', 'El Poblado', 1),
  (@c, '509', 'El Refugio', 1),
  (@c, '510', 'Garzon', 1),
  (@c, '511', 'Independiente Parte Alta', 1),
  (@c, '512', 'Independiente Parte Baja', 1),
  (@c, '529', 'La Cartagena', 1),
  (@c, '524', 'La Castellana', 1),
  (@c, '513', 'La Libertad', 1),
  (@c, '514', 'Las Brisas', 1),
  (@c, '515', 'Las Ferias', 1),
  (@c, '516', 'Los Martires', 1),
  (@c, '523', 'Martinica', 1),
  (@c, '517', 'Popular', 1),
  (@c, '525', 'Primero de Mayo', 1),
  (@c, '518', 'Rodríguez Andrade', 1),
  (@c, '522', 'San Vicente de Paul', 1),
  (@c, '519', 'Uribe Uribe', 1),
  (@c, '521', 'Villa Marina', 1),
  (@c, '520', 'Villa del Rio', 1);

/* comuna 12 — Ricaurte */
SET @c := 38;
INSERT INTO barrio (comuna_id, codigo, nombre, estado_id) VALUES
  (@c, '551', 'Alberto Santofimio', 1),
  (@c, '576', 'Andrés López de Galarza Sector la Pradera', 1),
  (@c, '574', 'Avenida', 1),
  (@c, '553', 'Avenida Cerro Gordo', 1),
  (@c, '581', 'Avenida Parte Baja', 1),
  (@c, '575', 'Cural', 1),
  (@c, '554', 'Eduardo Santos', 1),
  (@c, '555', 'Galan', 1),
  (@c, '556', 'Industrial', 1),
  (@c, '557', 'Kennedy', 1),
  (@c, '577', 'La Comuna de los Cova', 1),
  (@c, '558', 'La Gaitana', 1),
  (@c, '582', 'La Pradera', 1),
  (@c, '571', 'La Reforma', 1),
  (@c, '583', 'La Reforma Vella Vista', 1),
  (@c, '559', 'Las Vegas', 1),
  (@c, '561', 'Los Cambulos', 1),
  (@c, '584', 'Los Guaduales', 1),
  (@c, '580', 'Los Nogales', 1),
  (@c, '560', 'López de Galarza', 1),
  (@c, '562', 'Matallana', 1),
  (@c, '563', 'Murillo Toro', 1),
  (@c, '565', 'Ricaurte Parte Alta', 1),
  (@c, '566', 'Ricaurte Parte Baja', 1),
  (@c, '567', 'San José', 1),
  (@c, '552', 'Urbanización Arkaina', 1),
  (@c, '573', 'Urbanización Rosa Badillo de Uribe', 1),
  (@c, '568', 'Urbanización Venecia', 1),
  (@c, '569', 'Urbanización Villa Luces', 1),
  (@c, '579', 'Urbanización el Danubio', 1),
  (@c, '564', 'Urbanización la Primavera', 1),
  (@c, '572', 'Villa Claudia', 1),
  (@c, '570', 'Yuldaima', 1);

/* comuna 13 — Boquerón */
SET @c := 37;
INSERT INTO barrio (comuna_id, codigo, nombre, estado_id) VALUES
  (@c, '604', 'Dario Echandia', 1),
  (@c, '615', 'El Jazmin', 1),
  (@c, '602', 'Granada', 1),
  (@c, '614', 'La Florida', 1),
  (@c, '605', 'La Isla', 1),
  (@c, '603', 'La Unión', 1),
  (@c, '610', 'Miramar', 1),
  (@c, '611', 'San Isidro', 1);

/* corregimiento nuevo: Buenos Aires */
INSERT INTO comuna (municipio_id, zona_id, codigo, nombre, estado_id)
SELECT 960, (SELECT id FROM zona WHERE tipo='rural'), 'R91', 'Buenos Aires', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM comuna WHERE municipio_id=960 AND nombre='Buenos Aires');
SET @c := (SELECT id FROM comuna WHERE municipio_id=960 AND nombre='Buenos Aires');
INSERT INTO barrio (comuna_id, codigo, nombre, estado_id) VALUES
  (@c, '171', 'Brice?o', 1),
  (@c, '173', 'Buenoa Aires Sector Alto de Gualanday', 1),
  (@c, '172', 'Buenos Aires', 1),
  (@c, '175', 'La Miel', 1);

/* corregimiento nuevo: Calambeo (rural) */
INSERT INTO comuna (municipio_id, zona_id, codigo, nombre, estado_id)
SELECT 960, (SELECT id FROM zona WHERE tipo='rural'), 'R92', 'Calambeo (rural)', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM comuna WHERE municipio_id=960 AND nombre='Calambeo (rural)');
SET @c := (SELECT id FROM comuna WHERE municipio_id=960 AND nombre='Calambeo (rural)');
INSERT INTO barrio (comuna_id, codigo, nombre, estado_id) VALUES
  (@c, '121', 'Ambalá Parte Alta', 1),
  (@c, '122', 'Ambalá Sector el Triunfo', 1),
  (@c, '124', 'Ancon Tesorito Parte Alta', 1),
  (@c, '123', 'Ancon Tesorito Parte Baja', 1),
  (@c, '125', 'Bellavista', 1),
  (@c, '127', 'La Pedregosa', 1);

/* corregimiento nuevo: Carmen de Bulira */
INSERT INTO comuna (municipio_id, zona_id, codigo, nombre, estado_id)
SELECT 960, (SELECT id FROM zona WHERE tipo='rural'), 'R93', 'Carmen de Bulira', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM comuna WHERE municipio_id=960 AND nombre='Carmen de Bulira');
SET @c := (SELECT id FROM comuna WHERE municipio_id=960 AND nombre='Carmen de Bulira');
INSERT INTO barrio (comuna_id, codigo, nombre, estado_id) VALUES
  (@c, '180', 'Carmen de Bulira', 1),
  (@c, '183', 'Los Cauchos Parte Baja', 1);

/* corregimiento nuevo: Cay */
INSERT INTO comuna (municipio_id, zona_id, codigo, nombre, estado_id)
SELECT 960, (SELECT id FROM zona WHERE tipo='rural'), 'R94', 'Cay', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM comuna WHERE municipio_id=960 AND nombre='Cay');
SET @c := (SELECT id FROM comuna WHERE municipio_id=960 AND nombre='Cay');
INSERT INTO barrio (comuna_id, codigo, nombre, estado_id) VALUES
  (@c, '111', 'Cay Parte Alta', 1),
  (@c, '110', 'Cay Parte Baja', 1),
  (@c, '112', 'El Gallo', 1),
  (@c, '113', 'La Cascada', 1),
  (@c, '116', 'Pie de Cuesta las Amarillas', 1),
  (@c, '117', 'Santa Teresa', 1);

/* corregimiento nuevo: Gamboa */
INSERT INTO comuna (municipio_id, zona_id, codigo, nombre, estado_id)
SELECT 960, (SELECT id FROM zona WHERE tipo='rural'), 'R95', 'Gamboa', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM comuna WHERE municipio_id=960 AND nombre='Gamboa');
SET @c := (SELECT id FROM comuna WHERE municipio_id=960 AND nombre='Gamboa');
INSERT INTO barrio (comuna_id, codigo, nombre, estado_id) VALUES
  (@c, '50', 'Curalito', 1),
  (@c, '52', 'El Porvenir', 1),
  (@c, '51', 'El Tambo', 1),
  (@c, '54', 'Pe?aranda Parte Baja', 1),
  (@c, '55', 'Perico', 1);

/* corregimiento Cocora */
SET @c := 68;
INSERT INTO barrio (comuna_id, codigo, nombre, estado_id) VALUES
  (@c, '30', 'Cataimita', 1),
  (@c, '31', 'Coello Cocora', 1),
  (@c, '32', 'Honduras', 1),
  (@c, '34', 'La Cima', 1),
  (@c, '33', 'La Linda', 1),
  (@c, '35', 'Loma de Cocora', 1),
  (@c, '36', 'Morro Chusco', 1),
  (@c, '39', 'San Cristobal Parte Alta', 1),
  (@c, '40', 'San Cristobal Parte Baja', 1),
  (@c, '41', 'San Isidro', 1),
  (@c, '42', 'San Simón', 1),
  (@c, '37', 'Santa Ana', 1);

/* corregimiento Dantas */
SET @c := 67;
INSERT INTO barrio (comuna_id, codigo, nombre, estado_id) VALUES
  (@c, '10', 'Dantas', 1),
  (@c, '11', 'Dantas las Pavas', 1),
  (@c, '14', 'El Corazon', 1),
  (@c, '12', 'Peru Corozal', 1);

/* corregimiento El Salado */
SET @c := 60;
INSERT INTO barrio (comuna_id, codigo, nombre, estado_id) VALUES
  (@c, '150', 'Carrizales', 1),
  (@c, '151', 'Chembe', 1),
  (@c, '152', 'China Alta', 1),
  (@c, '153', 'Chucuni', 1),
  (@c, '154', 'El Colegio', 1),
  (@c, '155', 'El Jaguo', 1),
  (@c, '156', 'La Belleza', 1),
  (@c, '159', 'La Elena', 1),
  (@c, '164', 'La María', 1),
  (@c, '158', 'La Palmilla', 1);

/* corregimiento El Totumo */
SET @c := 63;
INSERT INTO barrio (comuna_id, codigo, nombre, estado_id) VALUES
  (@c, '190', 'Alto del Combeima', 1),
  (@c, '203', 'Aparco', 1),
  (@c, '191', 'Ca?adas Potrerito', 1),
  (@c, '193', 'Charco Rico', 1),
  (@c, '192', 'Cural Combeima', 1),
  (@c, '195', 'El Rodeo', 1),
  (@c, '194', 'El Totumo', 1),
  (@c, '196', 'La Monta?a', 1),
  (@c, '197', 'Llanos del Combeima', 1),
  (@c, '199', 'Martinica Parte Baja', 1),
  (@c, '198', 'Martinica Parte Media y Alta', 1);

/* corregimiento La Florida */
SET @c := 62;
INSERT INTO barrio (comuna_id, codigo, nombre, estado_id) VALUES
  (@c, '211', 'El Cedral', 1),
  (@c, '212', 'El Cural', 1),
  (@c, '213', 'El Tejar', 1),
  (@c, '215', 'Florida Alta', 1),
  (@c, '214', 'Florida Baja', 1);

/* corregimiento Laureles */
SET @c := 66;
INSERT INTO barrio (comuna_id, codigo, nombre, estado_id) VALUES
  (@c, '21', 'El Salitre Cocora', 1),
  (@c, '25', 'La Chapa', 1),
  (@c, '22', 'Laureles', 1),
  (@c, '26', 'Los Naranjales', 1),
  (@c, '23', 'Los Pastos Cocora', 1),
  (@c, '24', 'San Rafael', 1);

/* corregimiento San Bernardo */
SET @c := 59;
INSERT INTO barrio (comuna_id, codigo, nombre, estado_id) VALUES
  (@c, '140', 'El Ecuador', 1),
  (@c, '141', 'La Flor', 1),
  (@c, '142', 'Rodeito', 1),
  (@c, '146', 'San Antonio Sector San Bernardo', 1),
  (@c, '143', 'San Bernardo', 1),
  (@c, '145', 'San Cayetano Bajo', 1),
  (@c, '148', 'Yatay', 1);

/* corregimiento San Juan de la China */
SET @c := 58;
INSERT INTO barrio (comuna_id, codigo, nombre, estado_id) VALUES
  (@c, '130', 'Aures', 1),
  (@c, '131', 'China Media', 1),
  (@c, '132', 'El Rubi', 1),
  (@c, '135', 'La Pluma', 1),
  (@c, '136', 'La Violeta', 1),
  (@c, '133', 'Laveta', 1),
  (@c, '137', 'San Juan de la China', 1);

/* corregimiento Tapias */
SET @c := 56;
INSERT INTO barrio (comuna_id, codigo, nombre, estado_id) VALUES
  (@c, '61', 'El Guayco', 1),
  (@c, '62', 'El Ingenio', 1),
  (@c, '63', 'El Moral', 1),
  (@c, '64', 'Los Naranjos', 1),
  (@c, '65', 'Tapias', 1);

/* corregimiento Toche */
SET @c := 55;
INSERT INTO barrio (comuna_id, codigo, nombre, estado_id) VALUES
  (@c, '71', 'Coello San Juan', 1),
  (@c, '72', 'Toche', 1);

/* corregimiento Villa Restrepo */
SET @c := 69;
INSERT INTO barrio (comuna_id, codigo, nombre, estado_id) VALUES
  (@c, '90', 'Astilleros Parte Alta', 1),
  (@c, '91', 'Berlin', 1),
  (@c, '93', 'El Secreto', 1),
  (@c, '94', 'La María Sector Combeima', 1),
  (@c, '101', 'La Plata el Brillante', 1),
  (@c, '95', 'Lamaria Piedra Grande', 1),
  (@c, '96', 'Llanitos', 1),
  (@c, '97', 'Pastales', 1),
  (@c, '105', 'Pico de Oro', 1),
  (@c, '98', 'Ramos Astilleros', 1),
  (@c, '99', 'Tres Esquinas', 1),
  (@c, '100', 'Villa Restrepo', 1);
