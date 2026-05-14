/*
    Ibagué (Tolima, DANE 73001): comunas urbanas, barrios, corregimientos y veredas.

    Requisitos previos (mismo orden que seed DIVIPOLA):
      - departamento Tolima (73), municipio 73001, zonas urbana + rural por municipio.

    Fuente principal nombres comunas / barrios: POT / listados públicos (p. ej. Wikipedia
    «Anexo:Comunas de Ibagué»). Corregimientos alineados con tabla UPR/corregimiento
    resumida en Wikipedia «Ibagué» (17 núcleos rurales).

    Idempotencia: ON DUPLICATE KEY UPDATE nombre.

    Otros municipios del Tolima: no tienen en general las mismas 13 comunas; si necesitas
    datos análogos, duplica el patrón cambiando m.`codigo` y el CROSS JOIN de comunas/barrios.
*/

SET NAMES utf8mb4;

/* =============================================================================
   Comunas — Ibagué 73001, zona urbana (13)
   ============================================================================= */

INSERT INTO `comuna` (`zona_id`, `codigo`, `nombre`, `estado_id`)
SELECT z.`id`, v.`codigo`, v.`nombre`, 1
FROM `zona` z
JOIN `municipio` m ON m.`id` = z.`municipio_id`
CROSS JOIN (
    SELECT '01' AS codigo, 'Centro' AS nombre UNION ALL
    SELECT '02', 'Calambeo' UNION ALL
    SELECT '03', 'San Simón' UNION ALL
    SELECT '04', 'Piedrapintada' UNION ALL
    SELECT '05', 'Jordán' UNION ALL
    SELECT '06', 'Vergel' UNION ALL
    SELECT '07', 'Salado' UNION ALL
    SELECT '08', 'Simón Bolívar' UNION ALL
    SELECT '09', 'Picaleña — Mirolindo' UNION ALL
    SELECT '10', 'Estadio' UNION ALL
    SELECT '11', 'Ferias' UNION ALL
    SELECT '12', 'Ricaurte' UNION ALL
    SELECT '13', 'Boquerón'
) AS v
WHERE m.`codigo` = '73001' AND z.`tipo` = 'urbana'
ON DUPLICATE KEY UPDATE `nombre` = VALUES(`nombre`);

/* =============================================================================
   Barrios — Comuna 1 Centro (11)
   ============================================================================= */

INSERT INTO `barrio` (`comuna_id`, `codigo`, `nombre`, `estado_id`)
SELECT c.`id`, v.`codigo`, v.`nombre`, 1
FROM `comuna` c
JOIN `zona` z ON z.`id` = c.`zona_id`
JOIN `municipio` m ON m.`id` = z.`municipio_id`
CROSS JOIN (
    SELECT '001' AS codigo, 'San Pedro Alejandrino' AS nombre UNION ALL
    SELECT '002', 'Pueblo Nuevo' UNION ALL
    SELECT '003', 'Libertador' UNION ALL
    SELECT '004', 'La Pola Parte Alta' UNION ALL
    SELECT '005', 'La Pola' UNION ALL
    SELECT '006', 'Interlaken' UNION ALL
    SELECT '007', 'Estación' UNION ALL
    SELECT '008', 'Combeima' UNION ALL
    SELECT '009', 'Centro' UNION ALL
    SELECT '010', 'Baltazar' UNION ALL
    SELECT '011', 'Augusto E. Medina'
) AS v
WHERE m.`codigo` = '73001' AND z.`tipo` = 'urbana' AND c.`codigo` = '01'
ON DUPLICATE KEY UPDATE `nombre` = VALUES(`nombre`);

/* =============================================================================
   Barrios — Comuna 2 Calambeo (21)
   ============================================================================= */

INSERT INTO `barrio` (`comuna_id`, `codigo`, `nombre`, `estado_id`)
SELECT c.`id`, v.`codigo`, v.`nombre`, 1
FROM `comuna` c
JOIN `zona` z ON z.`id` = c.`zona_id`
JOIN `municipio` m ON m.`id` = z.`municipio_id`
CROSS JOIN (
    SELECT '001' AS codigo, 'Centenario' AS nombre UNION ALL
    SELECT '002', 'Belencito' UNION ALL
    SELECT '003', 'Belén' UNION ALL
    SELECT '004', 'Ancón' UNION ALL
    SELECT '005', 'Alaska' UNION ALL
    SELECT '006', '7 de Agosto' UNION ALL
    SELECT '007', '20 de Julio' UNION ALL
    SELECT '008', 'La Trinidad' UNION ALL
    SELECT '009', 'La Paz' UNION ALL
    SELECT '010', 'La Sofía' UNION ALL
    SELECT '011', 'La Aurora' UNION ALL
    SELECT '012', 'Irazú' UNION ALL
    SELECT '013', 'Augusto E. Medina' UNION ALL
    SELECT '014', 'Clarita Botero' UNION ALL
    SELECT '015', 'Viña de Calambeo' UNION ALL
    SELECT '016', 'Villa Adriana' UNION ALL
    SELECT '017', 'Santa Cruz' UNION ALL
    SELECT '018', 'Santa Bárbara' UNION ALL
    SELECT '019', 'San Diego' UNION ALL
    SELECT '020', 'Paraíso' UNION ALL
    SELECT '021', 'Malabar'
) AS v
WHERE m.`codigo` = '73001' AND z.`tipo` = 'urbana' AND c.`codigo` = '02'
ON DUPLICATE KEY UPDATE `nombre` = VALUES(`nombre`);

/* =============================================================================
   Barrios — muestra Comunas 3 a 13 (2 barrios por comuna; ampliar según POT)
   ============================================================================= */

INSERT INTO `barrio` (`comuna_id`, `codigo`, `nombre`, `estado_id`)
SELECT c.`id`, v.`codigo`, v.`nombre`, 1
FROM `comuna` c
JOIN `zona` z ON z.`id` = c.`zona_id`
JOIN `municipio` m ON m.`id` = z.`municipio_id`
CROSS JOIN (
    SELECT '03' AS comuna_c, '001' AS codigo, 'San Simón Parte Alta' AS nombre UNION ALL
    SELECT '03', '002', 'La Granja' UNION ALL
    SELECT '04', '001', 'Piedra Pintada' UNION ALL
    SELECT '04', '002', 'Jorge Eliécer Gaitán' UNION ALL
    SELECT '05', '001', 'Arrayanes' UNION ALL
    SELECT '05', '002', 'Jordán Etapa VI' UNION ALL
    SELECT '06', '001', 'Bosques del Vergel' UNION ALL
    SELECT '06', '002', 'Caminos del Vergel' UNION ALL
    SELECT '07', '001', 'Salado' UNION ALL
    SELECT '07', '002', 'Chipalo' UNION ALL
    SELECT '08', '001', 'Simón Bolívar' UNION ALL
    SELECT '08', '002', 'Arrayanes del Sur' UNION ALL
    SELECT '09', '001', 'Picaleña' UNION ALL
    SELECT '09', '002', 'Mirolindo' UNION ALL
    SELECT '10', '001', 'La Independencia' UNION ALL
    SELECT '10', '002', 'La Francia' UNION ALL
    SELECT '11', '001', 'Ferias' UNION ALL
    SELECT '11', '002', 'La Nubia' UNION ALL
    SELECT '12', '001', 'Ricaurte' UNION ALL
    SELECT '12', '002', 'Santa Helena' UNION ALL
    SELECT '13', '001', 'Boquerón' UNION ALL
    SELECT '13', '002', 'La Sonora'
) AS v
WHERE m.`codigo` = '73001' AND z.`tipo` = 'urbana' AND c.`codigo` = v.`comuna_c`
ON DUPLICATE KEY UPDATE `nombre` = VALUES(`nombre`);

/* =============================================================================
   Corregimientos — Ibagué, zona rural (17)
   ============================================================================= */

INSERT INTO `corregimiento` (`zona_id`, `codigo`, `nombre`, `estado_id`)
SELECT z.`id`, v.`codigo`, v.`nombre`, 1
FROM `zona` z
JOIN `municipio` m ON m.`id` = z.`municipio_id`
CROSS JOIN (
    SELECT '01' AS codigo, 'Combeiba' AS nombre UNION ALL
    SELECT '02', 'Juntas' UNION ALL
    SELECT '03', 'Villa Restrepo' UNION ALL
    SELECT '04', 'Cocora' UNION ALL
    SELECT '05', 'Dantas' UNION ALL
    SELECT '06', 'Laureles' UNION ALL
    SELECT '07', 'Doima' UNION ALL
    SELECT '08', 'Gallego' UNION ALL
    SELECT '09', 'El Totumo' UNION ALL
    SELECT '10', 'La Florida' UNION ALL
    SELECT '11', 'La China' UNION ALL
    SELECT '12', 'El Salado' UNION ALL
    SELECT '13', 'San Bernardo' UNION ALL
    SELECT '14', 'San Juan de la China' UNION ALL
    SELECT '15', 'Tochecito' UNION ALL
    SELECT '16', 'Tapias' UNION ALL
    SELECT '17', 'Toche'
) AS v
WHERE m.`codigo` = '73001' AND z.`tipo` = 'rural'
ON DUPLICATE KEY UPDATE `nombre` = VALUES(`nombre`);

/* =============================================================================
   Veredas — ejemplos por corregimiento (ampliar con listados catastrales / POT rural)
   ============================================================================= */

INSERT INTO `vereda` (`corregimiento_id`, `codigo`, `nombre`, `estado_id`)
SELECT co.`id`, v.`codigo`, v.`nombre`, 1
FROM `corregimiento` co
JOIN `zona` z ON z.`id` = co.`zona_id`
JOIN `municipio` m ON m.`id` = z.`municipio_id`
CROSS JOIN (
    SELECT '01' AS cor_c, '001' AS codigo, 'La Coqueta' AS nombre UNION ALL
    SELECT '01', '002', 'La Victoria' UNION ALL
    SELECT '01', '003', 'Piedecuestas Las Amarillas' UNION ALL
    SELECT '02', '001', 'Juntas' UNION ALL
    SELECT '02', '002', 'La Esperanza' UNION ALL
    SELECT '03', '001', 'El Retiro' UNION ALL
    SELECT '03', '002', 'La María Combeima' UNION ALL
    SELECT '04', '001', 'San Francisco' UNION ALL
    SELECT '04', '002', 'Santa Bárbara' UNION ALL
    SELECT '05', '001', 'Dantas de las Pavas' UNION ALL
    SELECT '05', '002', 'Alaska' UNION ALL
    SELECT '06', '001', 'Altamira' UNION ALL
    SELECT '06', '002', 'Salitre Cocora' UNION ALL
    SELECT '07', '001', 'Picaleña Sector Rural' UNION ALL
    SELECT '08', '001', 'La Cueva' UNION ALL
    SELECT '08', '002', 'Los Cauchos Parte Alta' UNION ALL
    SELECT '09', '001', 'Alto de Combeima' UNION ALL
    SELECT '09', '002', 'Potrero Grande' UNION ALL
    SELECT '10', '001', 'La Florida Parte Alta' UNION ALL
    SELECT '10', '002', 'Charco Rico Alto' UNION ALL
    SELECT '11', '001', 'Calambeo' UNION ALL
    SELECT '11', '002', 'Ancón Tesorito Sector Los Pinos' UNION ALL
    SELECT '12', '001', 'La Esperanza' UNION ALL
    SELECT '12', '002', 'La María China' UNION ALL
    SELECT '13', '001', 'San Cayetano Alto' UNION ALL
    SELECT '13', '002', 'Santa Rita' UNION ALL
    SELECT '14', '001', 'La Isabela' UNION ALL
    SELECT '14', '002', 'Puente Tierra' UNION ALL
    SELECT '15', '001', 'Gamboa' UNION ALL
    SELECT '15', '002', 'Peñaranda Alta' UNION ALL
    SELECT '16', '001', 'Cataima' UNION ALL
    SELECT '16', '002', 'El Guaico' UNION ALL
    SELECT '17', '001', 'Alto de Toche' UNION ALL
    SELECT '17', '002', 'Quebradas'
) AS v
WHERE m.`codigo` = '73001' AND z.`tipo` = 'rural' AND co.`codigo` = v.`cor_c`
ON DUPLICATE KEY UPDATE `nombre` = VALUES(`nombre`);
