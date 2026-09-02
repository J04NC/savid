<?php

/**
 * Comparación de solo lectura entre la "Planilla Integrada de Liquidación
 * de Aportes" (el archivo que genera el operador de aportes en línea al
 * finalizar el cargue completo — a diferencia de SihosNominaPilaCorreccionService,
 * que trabaja con el reporte de "posibles correcciones" ANTES de liquidar,
 * este archivo refleja lo que YA quedó liquidado/pagado) y los valores
 * reales en `DetaNomi` de SIHOS. Nunca escribe nada — es un reporte de
 * diferencias para revisión manual.
 *
 * Dos niveles de comparación, ambos contra SIHOS en fresco (nunca contra
 * datos ya calculados de otro reporte):
 *  1) Por empleado + concepto (pensión/salud/CCF/SENA/ICBF/ARL) — igual
 *     principio de "sumar todas las porciones del período" que
 *     SihosNominaPilaCorreccionService, porque este archivo también trae
 *     una fila por porción (normal/vacaciones/incapacidad/licencia) cuando
 *     un empleado tuvo una novedad — ver parseCsv().
 *  2) Por administradora (agregado de TODA la nómina) — cruza la tabla de
 *     "totales por administradora" del archivo contra la suma real de
 *     `DetaNomi` agrupada por la administradora asignada a cada empleado
 *     (SihosExternalRepository::sumaCotizacionPorAdministradora()).
 */
class SihosPlanillaIntegradaService
{
    /**
     * Concepto => offset relativo a la columna 'Tipo_Id_1' (columna ancla,
     * única en el archivo) donde vive el valor de cotización de ese
     * concepto en la matriz de detalle por afiliado. Verificado contra un
     * archivo real (ver conversación) — el archivo es un export de un
     * reporte SSRS con nombres de columna poco fiables (alguno, como el de
     * SENA, viene mal etiquetado como "textbox29" en el propio archivo), así
     * que se usa POSICIÓN relativa al ancla, no el nombre de columna.
     */
    private const OFFSET_COTIZACION_POR_CONCEPTO = [
        'pension' => 48,
        'salud' => 60,
        'ccf' => 68,
        'arl' => 74,
        'sena' => 78,
        'icbf' => 80,
    ];

    /** Otros campos del detalle por afiliado que se leen, mismo esquema de offsets relativos. */
    private const OFFSETS_DETALLE = [
        'no_id' => 1,
        'apellido' => 2,
        'salario' => 40,
        'nombre_afp' => 43,
        'nombre_eps' => 56,
        'nombre_ccf' => 64,
        'nombre_arp' => 69,
    ];

    /**
     * Offset de 'total_pension' — a diferencia de 'cotizacion_pension'
     * (OFFSET_COTIZACION_POR_CONCEPTO), este SÍ incluye el Fondo de
     * Solidaridad Pensional cuando aplica (empleados de salario alto — ver
     * OFFSETS_FSP). Se usa SOLO para el chequeo de consistencia interna del
     * archivo (compararAdministradoras()): la suma por AFP debe cuadrar con
     * el TOTAL liquidado a esa AFP, que en SIHOS incluye tanto `PENSION
     * A.F.P` como `FONDO DE SOLIDARIDAD PENSIONAL` (dos conceptos DISTINTOS
     * — verificado contra un caso real).
     */
    private const OFFSET_TOTAL_PENSION_CON_FSP = 54;

    /**
     * Offsets de las dos columnas del archivo que, sumadas, dan el total
     * real del Fondo de Solidaridad Pensional (SIHOS lo guarda como un
     * único concepto — `CodiConc` distinto de "PENSION A.F.P.", flag
     * `EsSolPen` — pero el archivo lo reporta partido en dos columnas:
     * "fon_sol_pensional" y "fon_subsistencia"). Solo tiene valor ≠ $0 para
     * empleados de salario alto. Corresponde a los códigos de error 836
     * ("F.S.P para cotizante...") y 837 ("F.S para cotizante...") del CSV de
     * SihosNominaPilaCorreccionService.
     */
    private const OFFSETS_FSP = [51, 52];

    /** Offsets relativos a la columna 'Riesgo' (ancla) en la tabla de totales por administradora. */
    private const OFFSETS_ADMINISTRADORA = [
        'nombre' => 3,
        'nit' => 5,
        'afiliados' => 7,
        'valor_liquidado' => 8,
    ];

    /** "AFP"/"EPS"/"ARL"/"CCF"/"ICBF"/"SENA" (texto antes de " (ADMINISTRADORAS") => clave de concepto. */
    private const RUBRO_A_CONCEPTO = [
        'AFP' => 'pension',
        'EPS' => 'salud',
        'ARL' => 'arl',
        'CCF' => 'ccf',
        'ICBF' => 'icbf',
        'SENA' => 'sena',
    ];

    /**
     * Conceptos SIN administradora elegible por empleado (entidades fijas de
     * gobierno) — mismo criterio que
     * SihosExternalRepository::CAMPO_ADMINISTRADORA_POR_CONCEPTO (privada
     * allá, replicada aquí porque el consumidor es otra clase). El archivo
     * SÍ imprime un NIT en su fila de "ICBF (ADMINISTRADORAS: 1)"/"SENA
     * (ADMINISTRADORAS: 1)" (se conserva para mostrarlo), pero
     * `sumaCotizacionPorAdministradora()` para estos dos conceptos siempre
     * agrupa bajo una única clave `''` — hay que buscar por esa clave fija,
     * no por el NIT que trae el archivo, o nunca calzarían.
     */
    private const CONCEPTOS_SIN_ADMINISTRADORA_POR_NIT = ['icbf', 'sena'];

    /**
     * Concepto => campo de OFFSETS_DETALLE con el nombre de la
     * administradora que el archivo asignó a ESE empleado — para el
     * chequeo de consistencia interna del archivo (ver
     * compararAdministradoras()): la suma de los empleados que el propio
     * archivo marca con esta administradora, comparada contra el total que
     * el propio archivo reporta para ella en su tabla de resumen. Un
     * archivo bien formado debería dar $0 de diferencia aquí siempre — si
     * no da $0, el problema NO es de SIHOS, es del archivo mismo (caso real
     * encontrado: un administradora con $129.200 de diferencia interna).
     * ICBF/SENA no tienen este campo (administradora fija, no elegida por
     * empleado).
     */
    private const CAMPO_NOMBRE_ADMINISTRADORA_POR_CONCEPTO = [
        'pension' => 'nombre_afp',
        'salud' => 'nombre_eps',
        'ccf' => 'nombre_ccf',
        'arl' => 'nombre_arp',
    ];

    /**
     * Conceptos que SÍ tienen su propia fila en la tabla "totales por
     * administradora" del archivo (ver RUBRO_A_CONCEPTO) — usados para
     * decidir por cuáles conceptos iterar en compararAdministradoras().
     * 'fondo_solidaridad' NO está aquí a propósito: el archivo no le da fila
     * propia, lo liquida junto con 'pension' a la misma AFP (ver el bloque
     * que suma $sumasFsp dentro de compararAdministradoras()).
     */
    private const CONCEPTOS_ADMINISTRADORA = ['pension', 'salud', 'ccf', 'arl', 'sena', 'icbf'];

    /** Los 6 conceptos de OFFSET_COTIZACION_POR_CONCEPTO + 'fondo_solidaridad' — todos los que se comparan por empleado. */
    private const CONCEPTOS_EMPLEADO = ['pension', 'salud', 'ccf', 'arl', 'sena', 'icbf', 'fondo_solidaridad'];

    /** Nombres de despliegue — incluye 'fondo_solidaridad' para el detalle por empleado (no tiene fila propia en la tabla de administradoras). */
    private const NOMBRE_CONCEPTO = [
        'pension' => 'Pensión',
        'salud' => 'Salud',
        'ccf' => 'CCF',
        'arl' => 'ARL',
        'sena' => 'SENA',
        'icbf' => 'ICBF',
        'fondo_solidaridad' => 'Fondo Solidaridad Pensional',
    ];

    private SihosEmpresaConfigRepository $configRepository;

    public function __construct()
    {
        $this->configRepository = new SihosEmpresaConfigRepository();
    }

    /**
     * Parsea el archivo tal cual lo entrega el operador: tres tablas dentro
     * del mismo CSV (encabezado de empresa/período, matriz de detalle por
     * afiliado, totales por administradora), cada una localizada por una
     * columna "ancla" única en vez de por número de línea fijo (más robusto
     * a variantes del export — p. ej. más o menos sucursales cambia cuántas
     * columnas de resumen preceden a la matriz de detalle).
     *
     * La matriz de detalle viene DUPLICADA por un artefacto del reporte
     * (cada afiliado aparece una vez en un "slot 1" de columnas y otra vez,
     * con el mismo dato, en un "slot 2" desplazado — verificado columna por
     * columna contra un archivo real) — se lee solo el slot 1 (las filas
     * donde la columna ancla NO está vacía).
     *
     * @return array{ok:bool,error?:string,empresa?:array{nit:string,razon_social:string},periodo_archivo?:array{cotizado:string,pago:string},empleados?:list<array<string,mixed>>,administradoras?:list<array<string,mixed>>}
     */
    public function parseCsv(string $rutaArchivo): array
    {
        $contenido = file_get_contents($rutaArchivo);
        if ($contenido === false) {
            return ['ok' => false, 'error' => 'No se pudo leer el archivo.'];
        }

        if (!mb_check_encoding($contenido, 'UTF-8')) {
            $convertido = mb_convert_encoding($contenido, 'UTF-8', 'Windows-1252');
            if ($convertido !== false) {
                $contenido = $convertido;
            }
        }
        $contenido = preg_replace('/^\xEF\xBB\xBF/', '', $contenido) ?? $contenido;

        $lineas = preg_split('/\r\n|\r|\n/', $contenido) ?: [];

        // --- Encabezado de empresa (primera fila con 2+ campos que no sea el ancla de detalle) ---
        $empresa = ['nit' => '', 'razon_social' => ''];
        $periodoArchivo = ['cotizado' => '', 'pago' => ''];
        foreach ($lineas as $linea) {
            if (trim($linea) === '') {
                continue;
            }
            $campos = str_getcsv($linea);
            if (str_starts_with(trim((string)($campos[0] ?? '')), 'NIT ')) {
                $empresa['nit'] = trim(str_replace('NIT ', '', (string)$campos[0]));
                $empresa['razon_social'] = trim((string)($campos[2] ?? ''));
            }
            if (preg_match('/^\d{4}-\d{2}$/', trim((string)($campos[0] ?? '')))) {
                $periodoArchivo['cotizado'] = trim((string)$campos[0]);
                $periodoArchivo['pago'] = trim((string)($campos[1] ?? ''));
                break;
            }
        }

        // --- Matriz de detalle por afiliado ---
        $baseIdx = null;
        $lineaHeaderDetalle = null;
        foreach ($lineas as $i => $linea) {
            if (trim($linea) === '') {
                continue;
            }
            $campos = str_getcsv($linea);
            $idx = array_search('Tipo_Id_1', $campos, true);
            if ($idx !== false) {
                $baseIdx = $idx;
                $lineaHeaderDetalle = $i;
                break;
            }
        }

        if ($baseIdx === null) {
            return ['ok' => false, 'error' => 'El archivo no tiene el formato esperado (falta la columna "Tipo_Id_1" del detalle por afiliado).'];
        }

        $empleados = [];
        for ($i = $lineaHeaderDetalle + 1; $i < count($lineas); $i++) {
            if (trim($lineas[$i]) === '') {
                break;
            }
            $campos = str_getcsv($lineas[$i]);
            $tipoDocu = trim((string)($campos[$baseIdx] ?? ''));
            if ($tipoDocu === '') {
                continue; // fila "slot 2" duplicada — se ignora
            }

            $fila = ['tipo_docu' => $tipoDocu];
            foreach (self::OFFSETS_DETALLE as $nombre => $off) {
                $fila[$nombre] = trim((string)($campos[$baseIdx + $off] ?? ''));
            }
            foreach (self::OFFSET_COTIZACION_POR_CONCEPTO as $concepto => $off) {
                $fila['cot_' . $concepto] = self::parsearValorMoneda((string)($campos[$baseIdx + $off] ?? ''));
            }
            $fila['total_pension_con_fsp'] = self::parsearValorMoneda((string)($campos[$baseIdx + self::OFFSET_TOTAL_PENSION_CON_FSP] ?? ''));

            $sumaFsp = 0.0;
            $huboFsp = false;
            foreach (self::OFFSETS_FSP as $offFsp) {
                $valorFsp = self::parsearValorMoneda((string)($campos[$baseIdx + $offFsp] ?? ''));
                if ($valorFsp !== null) {
                    $sumaFsp += $valorFsp;
                    $huboFsp = true;
                }
            }
            $fila['cot_fondo_solidaridad'] = $huboFsp ? round($sumaFsp, 2) : null;

            $empleados[] = $fila;
        }

        if ($empleados === []) {
            return ['ok' => false, 'error' => 'No se encontró ninguna fila de detalle por afiliado en el archivo.'];
        }

        // --- Totales por administradora ---
        $baseIdxAdmin = null;
        $lineaHeaderAdmin = null;
        foreach ($lineas as $i => $linea) {
            if (trim($linea) === '') {
                continue;
            }
            $campos = str_getcsv($linea);
            $idx = array_search('Riesgo', $campos, true);
            if ($idx !== false) {
                $baseIdxAdmin = $idx;
                $lineaHeaderAdmin = $i;
                break;
            }
        }

        $administradoras = [];
        if ($baseIdxAdmin !== null) {
            for ($i = $lineaHeaderAdmin + 1; $i < count($lineas); $i++) {
                if (trim($lineas[$i]) === '') {
                    break;
                }
                $campos = str_getcsv($lineas[$i]);
                $rubroRaw = trim((string)($campos[$baseIdxAdmin] ?? ''));
                if ($rubroRaw === '') {
                    continue;
                }

                $rubro = trim((string)preg_replace('/\s*\(ADMINISTRADORAS.*$/', '', $rubroRaw));
                $concepto = self::RUBRO_A_CONCEPTO[$rubro] ?? null;
                if ($concepto === null) {
                    continue; // rubro no reconocido, se ignora (no rompe el resto del parseo)
                }

                $administradoras[] = [
                    'concepto' => $concepto,
                    'nombre' => trim((string)($campos[$baseIdxAdmin + self::OFFSETS_ADMINISTRADORA['nombre']] ?? '')),
                    'nit' => self::limpiarNit((string)($campos[$baseIdxAdmin + self::OFFSETS_ADMINISTRADORA['nit']] ?? '')),
                    'afiliados' => (int)($campos[$baseIdxAdmin + self::OFFSETS_ADMINISTRADORA['afiliados']] ?? 0),
                    'valor_liquidado' => self::parsearValorMoneda((string)($campos[$baseIdxAdmin + self::OFFSETS_ADMINISTRADORA['valor_liquidado']] ?? '')) ?? 0.0,
                ];
            }
        }

        return [
            'ok' => true,
            'empresa' => $empresa,
            'periodo_archivo' => $periodoArchivo,
            'empleados' => $empleados,
            'administradoras' => $administradoras,
        ];
    }

    /**
     * Compara lo parseado por parseCsv() contra SIHOS y arma la vista de
     * diferencias — nunca escribe nada.
     *
     * @param list<array<string,mixed>> $empleadosArchivo
     * @param list<array<string,mixed>> $administradorasArchivo
     * @return array{ok:bool,error?:string,empleados?:list<array<string,mixed>>,administradoras?:list<array<string,mixed>>}
     */
    public function construirComparacion(int $empresaId, string $codiAno, string $codiMes, array $empleadosArchivo, array $administradorasArchivo): array
    {
        $configFila = $this->configRepository->findByEmpresaId($empresaId);
        if ($configFila === null || $configFila['host'] === '' || $configFila['base_datos'] === '') {
            return ['ok' => false, 'error' => 'Esta empresa no tiene conexión a SIHOS configurada.'];
        }

        $codiInst = trim((string)($configFila['codi_inst'] ?? ''));
        if ($codiInst === '') {
            return ['ok' => false, 'error' => 'Falta configurar el CodiInst de esta empresa en Conexión SIHOS.'];
        }

        $repositorio = new SihosExternalRepository([
            'host' => $configFila['host'],
            'port' => (string)$configFila['puerto'],
            'database' => $configFila['base_datos'],
            'username' => $configFila['usuario'],
            'codiInst' => $codiInst,
            'password' => SihosCredentialCipher::decrypt($configFila['password_cifrado'] ?? null),
            'charset' => $configFila['charset'],
        ]);

        try {
            $empleadosComparados = $this->compararEmpleados($repositorio, $codiAno, $codiMes, $empleadosArchivo);
            $administradorasComparadas = $this->compararAdministradoras($repositorio, $codiAno, $codiMes, $administradorasArchivo, $empleadosArchivo);
        } catch (PDOException $e) {
            return ['ok' => false, 'error' => 'No se pudo consultar SIHOS: ' . $e->getMessage()];
        }

        return ['ok' => true, 'empleados' => $empleadosComparados, 'administradoras' => $administradorasComparadas];
    }

    /**
     * @param list<array<string,mixed>> $empleadosArchivo
     * @return list<array{tipo_docu:string,no_id:string,nombre:string,conceptos:list<array<string,mixed>>}>
     */
    private function compararEmpleados(SihosExternalRepository $repositorio, string $codiAno, string $codiMes, array $empleadosArchivo): array
    {
        // 1) Agrupar las filas del archivo por empleado, sumando cada
        // concepto entre TODAS sus porciones del período (normal +
        // vacaciones/incapacidad/licencia) — cada fila del archivo ya trae
        // los 7 conceptos (ver CONCEPTOS_EMPLEADO), a diferencia del CSV de
        // errores. Fondo de Solidaridad Pensional queda en $0 para la
        // mayoría de empleados (solo aplica a salarios altos).
        $porEmpleado = [];
        foreach ($empleadosArchivo as $fila) {
            $clave = $fila['tipo_docu'] . '|' . $fila['no_id'];
            if (!isset($porEmpleado[$clave])) {
                $porEmpleado[$clave] = [
                    'tipo_docu' => $fila['tipo_docu'],
                    'no_id' => $fila['no_id'],
                    'nombre' => $fila['apellido'],
                    'sumas' => array_fill_keys(self::CONCEPTOS_EMPLEADO, 0.0),
                ];
            }
            foreach (self::CONCEPTOS_EMPLEADO as $concepto) {
                $valor = $fila['cot_' . $concepto] ?? null;
                if ($valor !== null) {
                    $porEmpleado[$clave]['sumas'][$concepto] += $valor;
                }
            }
        }

        $resultado = [];
        foreach ($porEmpleado as $emp) {
            $conceptos = [];
            foreach (self::CONCEPTOS_EMPLEADO as $concepto) {
                $flagConcepto = SihosExternalRepository::FLAGS_CONCEPTO_CORRECCION[$concepto];
                $gruposSihos = $repositorio->buscarConceptoCorreccionNomina(
                    $emp['tipo_docu'], $emp['no_id'], $codiAno, $codiMes, $flagConcepto
                );

                $lineasSihos = [];
                foreach ($gruposSihos as $g) {
                    $causado = $repositorio->nominaEstaCausada($g['codiDocu'], $g['numeDocu']);
                    foreach ($g['lineas'] as $linea) {
                        $lineasSihos[] = [
                            'codi_docu' => $g['codiDocu'],
                            'nume_docu' => $g['numeDocu'],
                            'nombre_docu' => $g['nombreDocu'],
                            'causado' => $causado,
                            'total' => round($linea['valoEmpe'] + $linea['valoPatr'], 2),
                        ];
                    }
                }

                $sumaArchivo = round($emp['sumas'][$concepto], 2);
                $sumaSihos = $lineasSihos === [] ? null : round(array_sum(array_column($lineasSihos, 'total')), 2);

                if ($sumaSihos === null) {
                    // 'fondo_solidaridad' solo aplica a salarios altos — que
                    // ni el archivo ni SIHOS tengan nada que reportar es lo
                    // normal para la mayoría de empleados, no un problema:
                    // no tiene sentido alarmar con "no encontrado" cuando
                    // ambos lados coinciden en que no aplica.
                    $estado = ($concepto === 'fondo_solidaridad' && abs($sumaArchivo) < 1.0)
                        ? 'coincide'
                        : 'no_encontrado';
                } elseif (abs($sumaArchivo - $sumaSihos) < 1.0) {
                    $estado = 'coincide';
                } else {
                    $estado = 'diferencia';
                }

                $conceptos[] = [
                    'concepto' => $concepto,
                    'suma_archivo' => $sumaArchivo,
                    'suma_sihos' => $sumaSihos,
                    'diferencia' => $sumaSihos === null ? null : round($sumaArchivo - $sumaSihos, 2),
                    'lineas_sihos' => $lineasSihos,
                    'estado' => $estado,
                ];
            }

            $resultado[] = [
                'tipo_docu' => $emp['tipo_docu'],
                'no_id' => $emp['no_id'],
                'nombre' => $emp['nombre'],
                'conceptos' => $conceptos,
            ];
        }

        // Ordenar de mayor a menor diferencia total (mismo criterio que
        // SihosNominaPilaCorreccionService::construirVistaPrevia(), a pedido
        // del usuario, 2026-09-02, para priorizar la revisión): suma del
        // valor absoluto de la diferencia de TODOS los conceptos del
        // empleado, no solo la más grande individual. Los conceptos sin
        // `suma_sihos` (estado 'no_encontrado', nada con qué comparar) no
        // aportan a la suma.
        usort($resultado, static function (array $a, array $b): int {
            $totalA = 0.0;
            foreach ($a['conceptos'] as $c) {
                if ($c['diferencia'] !== null) {
                    $totalA += abs($c['diferencia']);
                }
            }
            $totalB = 0.0;
            foreach ($b['conceptos'] as $c) {
                if ($c['diferencia'] !== null) {
                    $totalB += abs($c['diferencia']);
                }
            }

            return $totalB <=> $totalA;
        });

        return $resultado;
    }

    /**
     * @param list<array<string,mixed>> $administradorasArchivo
     * @param list<array<string,mixed>> $empleadosArchivo
     * @return list<array<string,mixed>>
     */
    private function compararAdministradoras(SihosExternalRepository $repositorio, string $codiAno, string $codiMes, array $administradorasArchivo, array $empleadosArchivo): array
    {
        // Traer la suma real de SIHOS UNA vez por concepto (no una vez por
        // fila del archivo) y cachearla — sumaCotizacionPorAdministradora()
        // ya recorre TODA la nómina del período por concepto.
        $sumasSihosPorConcepto = [];
        foreach (self::CONCEPTOS_ADMINISTRADORA as $concepto) {
            $flagConcepto = SihosExternalRepository::FLAGS_CONCEPTO_CORRECCION[$concepto];
            $sumasSihosPorConcepto[$concepto] = $repositorio->sumaCotizacionPorAdministradora($codiAno, $codiMes, $flagConcepto);
        }

        // El Fondo de Solidaridad Pensional es un CodiConc distinto de
        // "PENSION A.F.P" en SIHOS (ver docblock de
        // SihosExternalRepository::FLAGS_CONCEPTO_CORRECCION), pero el
        // archivo lo liquida junto con la pensión base a la MISMA AFP — se
        // suma aquí, por NIT, dentro del total de 'pension' antes de
        // comparar contra el archivo. Sin esto, cualquier empleado de
        // salario alto con FSP produce una diferencia fantasma del tamaño
        // exacto de su FSP en la AFP correspondiente (caso real verificado).
        $sumasFsp = $repositorio->sumaCotizacionPorAdministradora($codiAno, $codiMes, 'EsSolPen');
        foreach ($sumasFsp as $nit => $suma) {
            $sumasSihosPorConcepto['pension'][$nit] = ($sumasSihosPorConcepto['pension'][$nit] ?? 0.0) + $suma;
        }

        // Suma del propio detalle por empleado del archivo, agrupada por
        // (concepto, nombre de administradora) — para el chequeo de
        // consistencia interna del archivo, ver docblock de
        // CAMPO_NOMBRE_ADMINISTRADORA_POR_CONCEPTO. Para 'pension' se usa
        // `total_pension_con_fsp` (no `cot_pension`) — el total que la AFP
        // realmente recibe incluye el Fondo de Solidaridad Pensional cuando
        // aplica (ver OFFSET_TOTAL_PENSION_CON_FSP); para el resto de
        // conceptos no hay ese componente extra, se usa la cotización tal
        // cual.
        $sumaDetallePorConceptoYNombre = [];
        foreach ($empleadosArchivo as $emp) {
            foreach (self::CAMPO_NOMBRE_ADMINISTRADORA_POR_CONCEPTO as $concepto => $campoNombre) {
                $nombreAdmin = $emp[$campoNombre] ?? '';
                $valor = $concepto === 'pension'
                    ? ($emp['total_pension_con_fsp'] ?? $emp['cot_pension'] ?? null)
                    : ($emp['cot_' . $concepto] ?? null);
                if ($nombreAdmin === '' || $valor === null) {
                    continue;
                }
                $sumaDetallePorConceptoYNombre[$concepto][$nombreAdmin] =
                    ($sumaDetallePorConceptoYNombre[$concepto][$nombreAdmin] ?? 0.0) + $valor;
            }
        }

        $resultado = [];
        $nitsVistosPorConcepto = [];

        foreach ($administradorasArchivo as $fila) {
            $concepto = $fila['concepto'];
            $nit = $fila['nit'] ?? '';
            // ICBF/SENA: sumaCotizacionPorAdministradora() no agrupa por NIT
            // para estos dos (administradora única de gobierno) — busca
            // siempre bajo la clave fija '', aunque el archivo sí imprima un
            // NIT en su fila (se conserva solo para mostrarlo).
            $claveNit = in_array($concepto, self::CONCEPTOS_SIN_ADMINISTRADORA_POR_NIT, true) ? '' : $nit;
            $nitsVistosPorConcepto[$concepto][$claveNit] = true;

            $sumaSihos = $sumasSihosPorConcepto[$concepto][$claveNit] ?? null;
            $diferencia = $sumaSihos === null ? null : round($fila['valor_liquidado'] - $sumaSihos, 2);

            // Chequeo de consistencia INTERNA del archivo: ¿el total que su
            // propia tabla de resumen reporta para esta administradora
            // coincide con la suma de los empleados que el propio detalle
            // del archivo le asigna? Si no, la diferencia contra SIHOS de
            // arriba puede venir en realidad de un problema del archivo, no
            // de SIHOS — separar esto evita perseguir en SIHOS algo que ni
            // siquiera es real ahí. Sin este dato para ICBF/SENA (no tienen
            // administradora elegible por empleado en el detalle).
            $sumaDetalleArchivo = self::CAMPO_NOMBRE_ADMINISTRADORA_POR_CONCEPTO[$concepto] ?? null;
            $sumaDetalleArchivo = $sumaDetalleArchivo !== null
                ? ($sumaDetallePorConceptoYNombre[$concepto][$fila['nombre']] ?? null)
                : null;
            $diferenciaInterna = $sumaDetalleArchivo !== null
                ? round($fila['valor_liquidado'] - $sumaDetalleArchivo, 2)
                : null;

            $resultado[] = [
                'concepto' => $concepto,
                'nombre_concepto' => self::NOMBRE_CONCEPTO[$concepto] ?? $concepto,
                'nombre' => $fila['nombre'],
                'nit' => $nit,
                'afiliados' => $fila['afiliados'],
                'valor_archivo' => $fila['valor_liquidado'],
                'valor_sihos' => $sumaSihos,
                'diferencia' => $diferencia,
                'suma_detalle_archivo' => $sumaDetalleArchivo,
                'diferencia_interna' => $diferenciaInterna,
                'estado' => $sumaSihos === null ? 'no_encontrado' : (abs($diferencia) < 1.0 ? 'coincide' : 'diferencia'),
                'inconsistente_internamente' => $diferenciaInterna !== null && abs($diferenciaInterna) >= 1.0,
            ];
        }

        // Administradoras que SIHOS tiene cotización pero el archivo nunca
        // reportó — anomalía real que vale la pena mostrar (p. ej. un
        // empleado quedó afiliado a una administradora distinta en SIHOS a
        // la que se liquidó).
        foreach ($sumasSihosPorConcepto as $concepto => $porNit) {
            foreach ($porNit as $nit => $suma) {
                if (isset($nitsVistosPorConcepto[$concepto][$nit])) {
                    continue;
                }
                if (abs($suma) < 1.0) {
                    continue;
                }
                $resultado[] = [
                    'concepto' => $concepto,
                    'nombre_concepto' => self::NOMBRE_CONCEPTO[$concepto] ?? $concepto,
                    'nombre' => null,
                    'nit' => $nit,
                    'afiliados' => null,
                    'valor_archivo' => 0.0,
                    'valor_sihos' => $suma,
                    'diferencia' => round(0.0 - $suma, 2),
                    'suma_detalle_archivo' => null,
                    'diferencia_interna' => null,
                    'estado' => 'no_en_archivo',
                    'inconsistente_internamente' => false,
                ];
            }
        }

        return $resultado;
    }

    /** "$207,500" => 207500.0; "" o formato no numérico => null. */
    private static function parsearValorMoneda(string $valor): ?float
    {
        $limpio = str_replace(['$', ',', ' '], '', trim($valor));
        if ($limpio === '' || !is_numeric($limpio)) {
            return null;
        }

        return round((float)$limpio, 2);
    }

    /** "900,336,004" => "900336004"; "900336004-7" => "900336004". */
    private static function limpiarNit(string $nit): string
    {
        $limpio = str_replace([',', ' '], '', trim($nit));
        $limpio = preg_replace('/-\d$/', '', $limpio) ?? $limpio;

        return $limpio;
    }
}
