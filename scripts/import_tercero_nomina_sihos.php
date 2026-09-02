<?php

/**
 * Importa a `tercero`/`terceroidentificacion`/`tercero_nomina` las
 * administradoras (AFP/EPS/ARL/CCF), fondos de cesantías, entidades
 * gubernamentales y demás terceros reales que aparecen en la nómina de
 * las instalaciones SIHOS configuradas — lee DetaNomi/Concepto/CodiTerc
 * de SOLO LECTURA (vía SihosExternalRepository, sesión READ ONLY),
 * nunca escribe en SIHOS.
 *
 * Idempotente: se puede correr de nuevo sin duplicar — upsert por NIT
 * (tipodocumento_id=NIT + numero, la UNIQUE de terceroidentificacion) y
 * por (terceroidentificacion_id, tipo_tercero_id, empresa_id) en
 * tercero_nomina.
 *
 * Solo se importan terceros con TipoDocu='NI' (persona jurídica) que NO
 * sean autoreferencia del propio empleado (TiDoTerc/NuDoTerc = su propio
 * TipoDocu/NumePers en DetaNomi — eso son conceptos de sueldo/vacaciones,
 * no un tercero real). Los casos residuales de un TipoDocu de persona
 * natural (CC) usado como tercero (visto en las pruebas: prima/retroactivo
 * apuntando a la cédula de otro empleado, ruido de digitación en SIHOS)
 * quedan fuera deliberadamente.
 *
 * `nombre_pila` (el nombre EXACTO que exige el operador de "aportes en
 * línea"/PILA) solo se llena para AFP/EPS/ARL/CCF, contra el mapa
 * NIT_A_NOMBRE_PILA de abajo — verificado a mano contra el catálogo
 * oficial de la propia plantilla de cargue (listas con nombre definido
 * RGAFP/RGEPS/RGARL/RGCCF, hoja oculta "DatosPruebaEmp") y los NIT reales
 * encontrados en SIHOS Unión/Roldanillo el 2026-08-26. NO se adivina por
 * coincidencia de texto: un NIT sin entrada en el mapa queda con
 * nombre_pila NULL a propósito, y el script lo reporta al final para
 * revisión manual (ver el caso COOSALUD: el catálogo oficial tiene
 * "COOSALUD EPS" y "COOSALUD MOVILIDAD" como opciones distintas y no hay
 * evidencia en SIHOS de cuál aplica).
 *
 * `empresa_id` es SIEMPRE obligatorio en tercero_nomina — no hay filas
 * "compartidas": si el mismo NIT+tipo aparece en varias instalaciones, se
 * crea una fila por cada empresa (misma terceroidentificacion, distinto
 * empresa_id) — cada empresa administra su propio vínculo/nombre_pila sin
 * poder tocar el de otra, y nunca puede alterar el tercero de otra empresa.
 *
 * Uso:
 *   php scripts/import_tercero_nomina_sihos.php            (aplica cambios)
 *   php scripts/import_tercero_nomina_sihos.php --dry-run  (solo reporta)
 */

require __DIR__ . '/../core/AppBootstrap.php';
AppBootstrap::initCore();

$dryRun = in_array('--dry-run', $argv, true);

/** empresa_id (SAVID) => etiqueta para el reporte. */
const EMPRESAS_SIHOS = [
    17 => 'Hospital Gonzalo Contreras (La Unión)',
    18 => 'Hospital Departamental San Antonio (Roldanillo)',
];

/**
 * "NIT:codigoTipo" (sin dígito de verificación) => nombre_pila. Clave
 * compuesta a propósito: un mismo NIT puede tener más de un rol (ej.
 * 890303093 aparece como CCF Y como EPS en el histórico de Unión — es
 * válido como CCF en el catálogo oficial pero NO como EPS, así que sin la
 * clave compuesta el import le habría puesto "COMFENALCO VALLE" también a
 * la fila EPS, un nombre que el operador de PILA rechazaría).
 * Solo entidades AFP/EPS/ARL/CCF; ver docblock del archivo.
 */
const NIT_TIPO_A_NOMBRE_PILA = [
    // AFP
    '900336004:AFP' => 'COLPENSIONES',
    '800144331:AFP' => 'PORVENIR',
    // NIT distinto al anterior, pero también rotulado "Porvenir" en el propio
    // CodiTerc de Unión — posible duplicado de datos de SIHOS, se deja tal cual.
    '800224808:AFP' => 'PORVENIR',
    '800229739:AFP' => 'PROTECCION',
    '800227940:AFP' => 'COLFONDOS',
    '800148514:AFP' => 'SKANDIA',
    // ARL
    '800226175:ARL' => 'COLMENA',
    '860011153:ARL' => 'POSITIVA COMPAÑIA DE SEGUROS',
    // CCF
    '890303093:CCF' => 'COMFENALCO VALLE',
    // Caja de Compensación Familiar del Valle del Cauca = nombre comercial COMFANDI.
    '890303208:CCF' => 'COMFANDI',
    // EPS
    '900156264:EPS' => 'NUEVA E.P.S.',
    '800088702:EPS' => 'EPS SURA (ANTES SUSALUD)',
    '805001157:EPS' => 'S.O.S. SERVICIO OCCIDENTAL DE SALUD S.A.',
    '800251440:EPS' => 'SANITAS',
    '837000084:EPS' => 'MALLAMAS',
    '830003564:EPS' => 'FAMISANAR',
    '901021565:EPS' => 'EMSSANAR',
    '891600091:EPS' => 'COMFACHOCÓ',
    '800130907:EPS' => 'SALUD TOTAL',
    '860066942:EPS' => 'COMPENSAR',
    // "ASOCIACION MUTUAL ESS DE NARIÑO EMSSANAR" — NIT distinto al de "EMSSANAR EPS SAS"
    // (901021565), aparenta ser la razón social anterior de la misma EPS (antes de
    // constituirse como S.A.S.); el catálogo oficial solo trae una opción "EMSSANAR".
    '814000337:EPS' => 'EMSSANAR',
    // NIT 890303093 también quedó clasificado EPS en el histórico (razón social en SIHOS:
    // "COMFENALCO VALLE EPS") pero "COMFENALCO VALLE" NO existe en el catálogo oficial de
    // EPS (RGEPS) — solo en el de CCF (RGCCF). Se deja sin mapear a propósito.
    //
    // Deliberadamente SIN mapear (ver reporte final del script):
    // - NIT 805000427 COOMEVA EPS S.A. — no existe en el catálogo oficial actual (EPS liquidada/absorbida).
    // - NIT 830074184 SALUD VIDA EPS EN LIQUI — "en liquidación", no existe en el catálogo oficial actual.
    // - NIT 900226715 COOSALUD (ambas instalaciones) — el catálogo oficial trae "COOSALUD EPS" y
    //   "COOSALUD MOVILIDAD" como opciones distintas; no hay evidencia en SIHOS de cuál corresponde.
    // - NIT 860002183 AXA COLPATRIA SEGUROS DE VIDA (clasificado ARL) — el nombre sugiere seguros de
    //   vida, no el ARL propiamente dicho; podría ser "COLPATRIA ARP" del catálogo oficial pero no es
    //   seguro sin confirmarlo con el cliente.
];

/** Palabras clave (mayúsculas) en Concepto.NombConc => codigo de tipo_tercero. Orden = prioridad. */
const REGLAS_TIPO = [
    'CESANTIA' => 'FONDO_CESANTIAS',
    'A.F.P' => 'AFP',
    'PENSION' => 'AFP',
    'A.R.L' => 'ARL',
    'A.R.P' => 'ARL',
    'RIESGOS' => 'ARL',
    'CAJA DE COMPENSACION' => 'CCF',
    'E.P.S' => 'EPS',
    'SALUD' => 'EPS',
    'ICBF' => 'GUBERNAMENTAL',
    'SENA' => 'GUBERNAMENTAL',
    'RETEFUENTE' => 'GUBERNAMENTAL',
    'ESAP' => 'GUBERNAMENTAL',
];

/**
 * @return list<string> códigos de tipo_tercero que aplican a este tercero (uno o más).
 */
function clasificarTipos(string $conceptosConcatenados): array
{
    $conceptos = mb_strtoupper($conceptosConcatenados);
    $tipos = [];

    foreach (REGLAS_TIPO as $palabraClave => $codigoTipo) {
        if (str_contains($conceptos, $palabraClave) && !in_array($codigoTipo, $tipos, true)) {
            $tipos[] = $codigoTipo;
        }
    }

    return $tipos !== [] ? $tipos : ['OTRO'];
}

/** "800144331-3" => ['numero' => '800144331', 'dv' => 3]; null si no tiene forma de NIT. */
function partirNit(string $numeTerc): ?array
{
    if (!preg_match('/^(\d+)-(\d)$/', trim($numeTerc), $m)) {
        return null;
    }

    return ['numero' => $m[1], 'dv' => (int)$m[2]];
}

$savid = new Database();
$pdo = $savid->connect();

$tipoTerceroIds = [];
foreach ($pdo->query('SELECT id, codigo FROM tipo_tercero')->fetchAll() as $fila) {
    $tipoTerceroIds[$fila['codigo']] = (int)$fila['id'];
}

$TIPODOCUMENTO_NIT_ID = 9;

/**
 * Consulta SIHOS: terceros externos reales usados en DetaNomi de la
 * institución (excluye autoreferencia del empleado), solo personas
 * jurídicas (NI), con los nombres de concepto donde aparecen.
 */
function consultarTercerosSihos(PDO $pdoSihos, string $codiInst): array
{
    $sql = "
        SELECT d.TiDoTerc, d.NuDoTerc, ct.NombTerc,
               GROUP_CONCAT(DISTINCT c.NombConc ORDER BY c.NombConc SEPARATOR ' | ') AS conceptos
        FROM DetaNomi d
        LEFT JOIN CodiTerc ct ON ct.TipoDocu = d.TiDoTerc AND ct.NumeTerc = d.NuDoTerc
        LEFT JOIN Concepto c ON c.CodiInst = d.CodiInst AND c.CodiConc = d.CodiConc
        WHERE d.CodiInst = ?
          AND d.TiDoTerc = 'NI'
          AND d.NuDoTerc IS NOT NULL AND d.NuDoTerc <> ''
          AND NOT (d.TiDoTerc = d.TipoDocu AND d.NuDoTerc = d.NumePers)
        GROUP BY d.TiDoTerc, d.NuDoTerc, ct.NombTerc
    ";
    $stmt = $pdoSihos->prepare($sql);
    $stmt->execute([$codiInst]);

    return $stmt->fetchAll();
}

// 1) Recolectar de ambas instalaciones: nit => ['nombreSihos', 'tipos' => [...], 'empresas' => [17, 18]]
$configRepo = new SihosEmpresaConfigRepository();
$porNit = [];
$sinNitValido = [];
$reporteConexion = [];

foreach (EMPRESAS_SIHOS as $empresaId => $etiqueta) {
    $configFila = $configRepo->findByEmpresaId($empresaId);
    if ($configFila === null || $configFila['host'] === '') {
        $reporteConexion[] = "  - {$etiqueta} (empresa {$empresaId}): SIN conexión SIHOS configurada, se omite.";
        continue;
    }

    $repository = new SihosExternalRepository([
        'host' => $configFila['host'],
        'port' => (string)$configFila['puerto'],
        'database' => $configFila['base_datos'],
        'username' => $configFila['usuario'],
        'codiInst' => (string)$configFila['codi_inst'],
        'password' => SihosCredentialCipher::decrypt($configFila['password_cifrado'] ?? null),
        'charset' => $configFila['charset'],
    ]);

    try {
        $pdoSihos = $repository->connect();
        $filas = consultarTercerosSihos($pdoSihos, (string)$configFila['codi_inst']);
    } catch (PDOException $e) {
        $reporteConexion[] = "  - {$etiqueta} (empresa {$empresaId}): ERROR de conexión: " . $e->getMessage();
        continue;
    }

    $reporteConexion[] = "  - {$etiqueta} (empresa {$empresaId}): " . count($filas) . ' terceros distintos encontrados.';

    foreach ($filas as $fila) {
        $partes = partirNit((string)$fila['NuDoTerc']);
        if ($partes === null) {
            $sinNitValido[] = "{$etiqueta}: NIT '{$fila['NuDoTerc']}' no tiene forma válida (NNNNNNNNN-D), se omite.";
            continue;
        }

        $nit = $partes['numero'];
        $tipos = clasificarTipos((string)($fila['conceptos'] ?? ''));

        if (!isset($porNit[$nit])) {
            $porNit[$nit] = [
                'dv' => $partes['dv'],
                'nombreSihos' => trim((string)($fila['NombTerc'] ?? '')),
                'tipos' => [],
                'empresas' => [],
            ];
        }

        foreach ($tipos as $tipo) {
            $porNit[$nit]['tipos'][$tipo] = true;
        }
        $porNit[$nit]['empresas'][$empresaId] = true;

        if ($porNit[$nit]['nombreSihos'] === '' && trim((string)($fila['NombTerc'] ?? '')) !== '') {
            $porNit[$nit]['nombreSihos'] = trim((string)$fila['NombTerc']);
        }
    }
}

echo "=== Conexión SIHOS ===\n" . implode("\n", $reporteConexion) . "\n\n";

if ($sinNitValido !== []) {
    echo "=== NIT con formato inválido (omitidos) ===\n" . implode("\n", $sinNitValido) . "\n\n";
}

// 2) Upsert en SAVID
$creadosTercero = 0;
$creadosTerceroNomina = 0;
$actualizadosTerceroNomina = 0;
$sinNombrePila = [];

if (!$dryRun) {
    $pdo->beginTransaction();
}

try {
    foreach ($porNit as $nit => $info) {
        $empresaIds = array_keys($info['empresas']);

        // --- terceroidentificacion (busca por NIT; crea tercero+terceroidentificacion si no existe) ---
        $stmt = $pdo->prepare('SELECT id, tercero_id FROM terceroidentificacion WHERE tipodocumento_id = ? AND numero = ? LIMIT 1');
        $stmt->execute([$TIPODOCUMENTO_NIT_ID, $nit]);
        $identificacion = $stmt->fetch();

        if ($identificacion === false) {
            $razonSocial = mb_strtoupper($info['nombreSihos'] !== '' ? $info['nombreSihos'] : ('NIT ' . $nit));

            if ($dryRun) {
                echo "[crear] tercero + terceroidentificacion NIT {$nit} — {$razonSocial}\n";
                $terceroIdentificacionId = -1; // placeholder para seguir el dry-run
            } else {
                $pdo->prepare('INSERT INTO tercero (tipopersona_id, razon_social, estado_id) VALUES (2, ?, 1)')
                    ->execute([$razonSocial]);
                $terceroId = (int)$pdo->lastInsertId();

                $pdo->prepare('INSERT INTO terceroidentificacion (tercero_id, tipodocumento_id, numero, dv, principal, estado_id) VALUES (?, ?, ?, ?, 1, 1)')
                    ->execute([$terceroId, $TIPODOCUMENTO_NIT_ID, $nit, $info['dv']]);
                $terceroIdentificacionId = (int)$pdo->lastInsertId();

                $creadosTercero++;
            }
        } else {
            $terceroIdentificacionId = (int)$identificacion['id'];
        }

        // --- tercero_nomina: una fila por tipo detectado, POR CADA empresa que
        // realmente lo usa (ya no hay fila "compartida" — cada empresa tiene su
        // propia fila aunque apunten al mismo tercero/terceroidentificacion). ---
        foreach (array_keys($info['tipos']) as $tipoCodigo) {
            $tipoTerceroId = $tipoTerceroIds[$tipoCodigo] ?? null;
            if ($tipoTerceroId === null) {
                continue;
            }

            $nombrePila = null;
            if (in_array($tipoCodigo, ['AFP', 'EPS', 'ARL', 'CCF'], true)) {
                $nombrePila = NIT_TIPO_A_NOMBRE_PILA["{$nit}:{$tipoCodigo}"] ?? null;
                if ($nombrePila === null) {
                    $sinNombrePila[] = "NIT {$nit} ({$info['nombreSihos']}) tipo {$tipoCodigo} — sin nombre_pila mapeado, revisar a mano.";
                }
            }

            foreach ($empresaIds as $empresaId) {
                if ($dryRun) {
                    echo "[tercero_nomina] NIT {$nit} tipo={$tipoCodigo} empresa={$empresaId} nombre_pila=" . ($nombrePila ?? 'NULL') . "\n";

                    continue;
                }

                $stmt = $pdo->prepare('SELECT id, nombre_pila FROM tercero_nomina WHERE terceroidentificacion_id = ? AND tipo_tercero_id = ? AND empresa_id = ? LIMIT 1');
                $stmt->execute([$terceroIdentificacionId, $tipoTerceroId, $empresaId]);
                $existente = $stmt->fetch();

                if ($existente === false) {
                    $pdo->prepare('INSERT INTO tercero_nomina (terceroidentificacion_id, tipo_tercero_id, empresa_id, nombre_pila, estado_id) VALUES (?, ?, ?, ?, 1)')
                        ->execute([$terceroIdentificacionId, $tipoTerceroId, $empresaId, $nombrePila]);
                    $creadosTerceroNomina++;
                } elseif ($nombrePila !== null && $existente['nombre_pila'] === null) {
                    // Solo actualiza nombre_pila si el mapa trae un valor y la fila no lo tiene aún —
                    // nunca pisa un nombre_pila ya corregido a mano en el CRUD. Se compara en PHP
                    // (no con rowCount()) porque este conector reporta filas COINCIDENTES, no filas
                    // realmente cambiadas — con COALESCE puro el contador quedaba inflado en las
                    // corridas repetidas aunque el valor no cambiara.
                    $pdo->prepare('UPDATE tercero_nomina SET nombre_pila = ? WHERE id = ?')
                        ->execute([$nombrePila, $existente['id']]);
                    $actualizadosTerceroNomina++;
                }
            }
        }
    }

    if (!$dryRun) {
        $pdo->commit();
    }
} catch (Throwable $e) {
    if (!$dryRun && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "\n=== Resumen ===\n";
echo 'Terceros distintos (NIT) procesados: ' . count($porNit) . "\n";
if ($dryRun) {
    echo "(--dry-run: no se escribió nada, solo se listó arriba lo que se haría)\n";
} else {
    echo "tercero/terceroidentificacion creados: {$creadosTercero}\n";
    echo "tercero_nomina creados: {$creadosTerceroNomina}\n";
    echo "tercero_nomina con nombre_pila completado en una fila ya existente: {$actualizadosTerceroNomina}\n";
}

if ($sinNombrePila !== []) {
    echo "\n=== AFP/EPS/ARL/CCF sin nombre_pila (requieren revisión manual en Terceros Nómina) ===\n";
    foreach (array_unique($sinNombrePila) as $linea) {
        echo "  - {$linea}\n";
    }
}
