/* ============================================================
   Catálogo territorial de Roldanillo (76622) y La Unión (76400).

   Fuentes y verificación:
   - SIHOS (bases de Roldanillo y La Unión): 173 barrios/veredas reales
     de la operación. Es la fuente principal.
   - DANE DIVIPOLA centros poblados (datos.gov.co, xaxy-8nri): confirmó
     13 nombres rurales y aportó 2 que faltaban (Palmar Guayabal, El
     Guásimo). Los códigos de departamento y municipio de SAVID ya
     coincidían al 100% con DANE, así que esos niveles no se tocan.
   - OpenStreetMap (Overpass): confirmó 24 nombres y aportó 8 barrios
     urbanos ausentes en SIHOS. Se descartaron marcadores viales y
     negocios mal etiquetados (K0+000, Puente, Bocatoma, Mundo Bovino…).

   Advertencia honesta: no existe catálogo nacional de barrios urbanos
   ni de comunas (DIVIPOLA solo llega a centros poblados), por lo que la
   mayoría de nombres urbanos no son verificables contra fuente oficial;
   provienen de la operación en SIHOS y del cruce con OSM.

   Comunas: SIHOS no tiene comunas reales en estos municipios (sus
   "comunas" solo repetían el nombre del municipio o la palabra
   urbano/rural), así que se crea un contenedor por zona. El formulario
   los muestra como "Comuna: Área urbana" y "Corregimiento: Área rural".
   ============================================================ */


/* ---------- ROLDANILLO (76622) ---------- */

INSERT INTO comuna (municipio_id, zona_id, codigo, nombre, estado_id)
SELECT 1037, 1, 'URB', 'Área urbana', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM comuna WHERE municipio_id=1037 AND zona_id=1 AND codigo='URB');

SET @c := (SELECT id FROM comuna WHERE municipio_id=1037 AND zona_id=1 AND codigo='URB');
INSERT INTO barrio (comuna_id, codigo, nombre, estado_id) VALUES
  (@c, 'X001', '3 de Mayo', 1),
  (@c, '125', 'Adolfo León Gómez', 1),
  (@c, '113', 'Arrayanes I', 1),
  (@c, '114', 'Arrayanes II', 1),
  (@c, '124', 'Barbosa', 1),
  (@c, '155', 'Canaverasles', 1),
  (@c, 'X002', 'Carlos Holguin Sardi', 1),
  (@c, '138', 'Carlos Holguin Sardi I', 1),
  (@c, '151', 'Ciudad Jardín', 1),
  (@c, '147', 'Ciudad Verde', 1),
  (@c, '122', 'Condominio Prados de la Ermita', 1),
  (@c, '123', 'Condominio Prados del Norte', 1),
  (@c, '145', 'Condominio Villa Emma', 1),
  (@c, '165', 'Conjunto Acasias', 1),
  (@c, '136', 'El Alcázar', 1),
  (@c, '143', 'El Centro', 1),
  (@c, '106', 'El Hatico', 1),
  (@c, '127', 'El Huachal', 1),
  (@c, '152', 'El Oasis', 1),
  (@c, '154', 'El Paraíso', 1),
  (@c, '115', 'El Prado', 1),
  (@c, '110', 'El Rey', 1),
  (@c, '144', 'El Rincon', 1),
  (@c, '101', 'Humberto González Narváez', 1),
  (@c, '111', 'Ipira', 1),
  (@c, '107', 'José Joaquín Jaramillo', 1),
  (@c, '141', 'José María Barbosa', 1),
  (@c, 'X003', 'José María Torrijos', 1),
  (@c, '128', 'La Asuncion', 1),
  (@c, '159', 'La Campina', 1),
  (@c, '112', 'La Ceiba', 1),
  (@c, '120', 'La Ermita', 1),
  (@c, '119', 'La Nueva Ermita', 1),
  (@c, '108', 'La Planeta', 1),
  (@c, '126', 'La Playita', 1),
  (@c, '142', 'Las Colinas', 1),
  (@c, '121', 'Las Cruces', 1),
  (@c, '153', 'Los Alamos', 1),
  (@c, '149', 'Los Alpes', 1),
  (@c, 'X004', 'Los Arrayanes', 1),
  (@c, '103', 'Los Llanitos', 1),
  (@c, '139', 'Los Pinos', 1),
  (@c, '104', 'Obrero', 1),
  (@c, '118', 'Omar Torrijos', 1),
  (@c, '078', 'Otros Barrios', 1),
  (@c, '160', 'Portal del Valle', 1),
  (@c, '109', 'Rodrigo Lloreda', 1),
  (@c, '102', 'San José Obrero', 1),
  (@c, '140', 'San Nicolás', 1),
  (@c, '135', 'San Sebastián', 1),
  (@c, '105', 'Simón Bolívar', 1),
  (@c, '116', 'Tres de Mayo', 1),
  (@c, 'X005', 'Unión de Vivienda', 1),
  (@c, '137', 'Unión de Vivienda Popular', 1),
  (@c, '162', 'Urbanización 26 de Octubre', 1),
  (@c, 'X006', 'Urbanización Anización el Rey', 1),
  (@c, '132', 'Urbanización Chiminangos', 1),
  (@c, '129', 'Urbanización Club de Leones', 1),
  (@c, '117', 'Urbanización Dona Emma', 1),
  (@c, '130', 'Urbanización Sindical', 1),
  (@c, '133', 'Urbanización Villa Rosa', 1),
  (@c, '163', 'Urbanización Villa Sofia', 1),
  (@c, '134', 'Urbanización el Aguacatal', 1),
  (@c, '166', 'Urbanización el Jardín', 1),
  (@c, '146', 'Urbanización el Portal', 1),
  (@c, '131', 'Urbanización las Brisas', 1),
  (@c, '157', 'Urbanización las Veraneras', 1),
  (@c, '148', 'Villa Campestre', 1),
  (@c, '161', 'Villa Fatima', 1),
  (@c, '158', 'Villas de Santa María', 1),
  (@c, '150', 'Álvaro Uribe', 1);

INSERT INTO comuna (municipio_id, zona_id, codigo, nombre, estado_id)
SELECT 1037, 2, 'RUR', 'Área rural', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM comuna WHERE municipio_id=1037 AND zona_id=2 AND codigo='RUR');

SET @c := (SELECT id FROM comuna WHERE municipio_id=1037 AND zona_id=2 AND codigo='RUR');
INSERT INTO barrio (comuna_id, codigo, nombre, estado_id) VALUES
  (@c, '228', 'Belgica', 1),
  (@c, '204', 'Buena Vista', 1),
  (@c, '231', 'Caceres', 1),
  (@c, '226', 'Cajamarca', 1),
  (@c, '221', 'Candelaria', 1),
  (@c, '229', 'Cascarillo', 1),
  (@c, '234', 'Coloradas', 1),
  (@c, '230', 'El Aguacate', 1),
  (@c, '227', 'El Castillo', 1),
  (@c, '201', 'El Ciruelo', 1),
  (@c, '225', 'El Hobo', 1),
  (@c, '223', 'El Mandarino', 1),
  (@c, '202', 'El Oregano', 1),
  (@c, '208', 'El Palmar', 1),
  (@c, '235', 'El Pie', 1),
  (@c, '211', 'El Retiro', 1),
  (@c, '203', 'El Silencio', 1),
  (@c, '209', 'Guayabal', 1),
  (@c, '222', 'Higueroncito', 1),
  (@c, '210', 'Irrupa', 1),
  (@c, '232', 'Isugu', 1),
  (@c, '213', 'La Armenia', 1),
  (@c, '218', 'La Batea', 1),
  (@c, 'X001', 'La Esperanza', 1),
  (@c, '214', 'La Soledad', 1),
  (@c, '217', 'Mateguadua', 1),
  (@c, '200', 'Montanuela', 1),
  (@c, '219', 'Morelia', 1),
  (@c, 'X002', 'Palmar Guayabal', 1),
  (@c, '206', 'Paramillo', 1),
  (@c, '233', 'Parcelas', 1),
  (@c, '215', 'Puerto Quintero', 1),
  (@c, '220', 'Remolino', 1),
  (@c, '205', 'San Isidro', 1),
  (@c, '224', 'Santa Rita', 1),
  (@c, '207', 'Tierra Blanca', 1),
  (@c, '212', 'Vda Cruces', 1);

/* ---------- LA UNIÓN (76400) ---------- */

INSERT INTO comuna (municipio_id, zona_id, codigo, nombre, estado_id)
SELECT 1030, 1, 'URB', 'Área urbana', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM comuna WHERE municipio_id=1030 AND zona_id=1 AND codigo='URB');

SET @c := (SELECT id FROM comuna WHERE municipio_id=1030 AND zona_id=1 AND codigo='URB');
INSERT INTO barrio (comuna_id, codigo, nombre, estado_id) VALUES
  (@c, '023', 'Bella Vista', 1),
  (@c, '05', 'Belén', 1),
  (@c, '058', 'Campo Alegre', 1),
  (@c, '016', 'El Carmen', 1),
  (@c, '034', 'El Guasimo Bajo', 1),
  (@c, '06', 'El Jardín', 1),
  (@c, '032', 'El Lucero', 1),
  (@c, '035', 'El Paraíso', 1),
  (@c, '027', 'El Prado', 1),
  (@c, '026', 'Fatima', 1),
  (@c, '072', 'Guasimo Alto', 1),
  (@c, '020', 'Hato de Lemos', 1),
  (@c, '001', 'La Campesina', 1),
  (@c, '029', 'La Ciudadela', 1),
  (@c, '018', 'La Cruz', 1),
  (@c, '030', 'La Floresta', 1),
  (@c, '056', 'La Peña', 1),
  (@c, '02', 'La Unión', 1),
  (@c, '012', 'Las Brisas', 1),
  (@c, '019', 'Las Lajas', 1),
  (@c, '033', 'Las Palmas', 1),
  (@c, '022', 'Pajaro de Oro', 1),
  (@c, '025', 'Paso Ancho', 1),
  (@c, '013', 'Popular', 1),
  (@c, '028', 'San Miguel', 1),
  (@c, '011', 'San Pablo', 1),
  (@c, '04', 'San Pedro', 1),
  (@c, '006', 'Siloé', 1),
  (@c, '017', 'Urbanización Anizacion Caminos', 1),
  (@c, '068', 'Urbanización Bosques de la Acuarela', 1),
  (@c, '003', 'Urbanización Prados del Norte', 1),
  (@c, '055', 'Urbanización Villa Bethel', 1),
  (@c, '069', 'Urbanización Villa de la Paz', 1),
  (@c, '065', 'Urbanización Villa del Sol', 1),
  (@c, '021', 'Urbanización el Amparo', 1),
  (@c, '074', 'Urbanización la Ermita', 1),
  (@c, '063', 'Urbanización la Esperanza', 1),
  (@c, '004', 'Urbanización la Milagrosa', 1),
  (@c, '066', 'Urbanización la Primavera', 1),
  (@c, '073', 'Urbanización los Hateños', 1);

INSERT INTO comuna (municipio_id, zona_id, codigo, nombre, estado_id)
SELECT 1030, 2, 'RUR', 'Área rural', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM comuna WHERE municipio_id=1030 AND zona_id=2 AND codigo='RUR');

SET @c := (SELECT id FROM comuna WHERE municipio_id=1030 AND zona_id=2 AND codigo='RUR');
INSERT INTO barrio (comuna_id, codigo, nombre, estado_id) VALUES
  (@c, '057', 'Córcega', 1),
  (@c, '042', 'Despensa', 1),
  (@c, '041', 'Despensita', 1),
  (@c, '009', 'El Agizal', 1),
  (@c, '051', 'El Banco', 1),
  (@c, '059', 'El Castillo', 1),
  (@c, '047', 'El Espinal', 1),
  (@c, 'X001', 'El Guasimo', 1),
  (@c, '067', 'El Oso', 1),
  (@c, '008', 'El Rincon', 1),
  (@c, '046', 'El Rodeo', 1),
  (@c, '054', 'El Tigre', 1),
  (@c, '024', 'Hoyo Hondo', 1),
  (@c, '002', 'La Aguada', 1),
  (@c, 'X002', 'La Despensa', 1),
  (@c, '050', 'La Isla', 1),
  (@c, '061', 'La Sonora', 1),
  (@c, '043', 'La Trinidad', 1),
  (@c, '049', 'Linderos', 1),
  (@c, '01', 'Los Viñedos', 1),
  (@c, '014', 'Matin Doza', 1),
  (@c, '053', 'Ojedas', 1),
  (@c, '040', 'Paramillo', 1),
  (@c, '036', 'Portachuelo', 1),
  (@c, '062', 'Potreritos', 1),
  (@c, '037', 'Quebrada Grande', 1),
  (@c, '038', 'Sabanazo', 1),
  (@c, '044', 'San Luis', 1),
  (@c, '052', 'San Rafael', 1),
  (@c, '010', 'Tamboral', 1),
  (@c, '045', 'Tejedas', 1),
  (@c, '031', 'Vallecitos', 1),
  (@c, '048', 'Veraguas', 1),
  (@c, '071', 'Vereda el Jardín', 1),
  (@c, '039', 'Violetas', 1);
