<?php

/**
 * Corrección de nómina en SIHOS a partir del CSV de "posibles correcciones"
 * que entrega el portal de aportes en línea después de validar el archivo
 * PILA (ver SihosNominaPilaService). Cruza cada corrección de cotización
 * obligatoria (pensión/salud/CCF/SENA/ICBF) contra la línea real de
 * `DetaNomi` en SIHOS y, si el usuario la confirma, ajusta el aporte
 * PATRONAL (nunca el del empleado, ya descontado de su pago) para que el
 * total coincida con lo que exige el operador — ANTES de que la nómina se
 * confirme ("cause") en SIHOS, evitando la nota de ajuste contable manual
 * que hoy hace el usuario después.
 *
 * Deliberadamente FUERA de alcance en esta versión (ver conversación con el
 * usuario): errores de ARL/riesgos, IBC "mal calculado", clase de riesgo,
 * fondo de solidaridad pensional, nombre no coincide con RUAF, cantidad de
 * empleados — todos quedan listados como "no aplicable" en la vista previa,
 * nunca se intenta corregir.
 */
class SihosNominaPilaCorreccionService
{
    /**
     * codigo_error del CSV del operador => clave de
     * SihosExternalRepository::FLAGS_CONCEPTO_CORRECCION. Todas
     * "Cotización obligatoria <concepto> debe ser $X" — el total (ValoEmpe +
     * ValoPatr) del concepto debe quedar en `cadena_correccion`.
     *
     * 836/837 ("F.S.P para cotizante..."/"F.S para cotizante...") son las dos
     * variantes de error del operador para Fondo de Solidaridad Pensional —
     * ambas se mapean al mismo concepto 'fondo_solidaridad' y se SUMAN entre
     * sí en construirVistaPrevia() (mismo mecanismo ya existente de sumar
     * "valores_esperados" de un grupo), consistente con que en SIHOS es un
     * único CodiConc (`EsSolPen`). A diferencia de los demás conceptos, FSP
     * se corrige ajustando ValoEmpe, no ValoPatr — ver evaluarGrupo() y
     * aplicarCorrecciones(). También, fetchNominaPila() no trae una columna
     * "COTIZACION FSP" (no está en COLUMNA_COTIZACION_POR_CONCEPTO), así que
     * para este concepto no se reconstruye la "suma esperada real" con la
     * lógica multi-porción — se usa directamente lo que sumó el CSV
     * (fallback ya documentado en evaluarGrupo(), aceptable porque FSP solo
     * aplica a un subconjunto pequeño de empleados de salario alto).
     */
    private const MAPA_CODIGO_ERROR = [
        191 => 'pension',
        190 => 'salud',
        195 => 'ccf',
        196 => 'sena',
        198 => 'icbf',
        836 => 'fondo_solidaridad',
        837 => 'fondo_solidaridad',
    ];

    /**
     * clave de concepto => columna cruda de `SihosExternalRepository::fetchNominaPila()`
     * (la MISMA consulta que arma el archivo PILA) donde vive el valor de
     * cotización de ese concepto en cada fila exportada.
     *
     * Se usa en construirVistaPrevia() para reconstruir el TOTAL real que
     * exportamos para un empleado+concepto — sumando TODAS sus filas
     * (normal, vacaciones, incapacidad, licencia — SihosNominaPilaService),
     * no solo las que el CSV del operador reportó como error. El CSV del
     * portal SOLO lista errores: si un empleado tiene 2 filas exportadas y
     * únicamente 1 tiene un problema, la otra NUNCA aparece en el CSV — pero
     * su valor SÍ forma parte del total real que hay que comparar contra
     * SIHOS. Comparar el total de SIHOS solo contra la fila reportada
     * produce una "diferencia" fantasma del tamaño de la fila no reportada
     * (caso real verificado: empleado con $31.000 en una fila sin error y
     * $116.800→$116.900 en la otra — comparar solo esta última contra el
     * total de SIHOS ($147.800) daba una diferencia falsa de -$30.900).
     */
    private const COLUMNA_COTIZACION_POR_CONCEPTO = [
        'pension' => 'COTIZACION AFP',
        'salud' => 'COTIZACION EPS',
        'ccf' => 'COTIZACION CCF',
        'sena' => 'COTIZACION SENA',
        'icbf' => 'COTIZACION ICBF',
    ];

    /**
     * clave de concepto => columna de BASE (I.B.C.) de `fetchNominaPila()`
     * que multiplicada por la tarifa produce la columna de
     * `COLUMNA_COTIZACION_POR_CONCEPTO` correspondiente — para que la vista
     * pueda mostrar "base × tarifa = cotización" y facilitar la auditoría
     * (a pedido del usuario, 2026-09-01). SENA e ICBF comparten la misma
     * base ('IBC Otros Parafiscales', igual que CCF pero en columna propia
     * para CCF). `fondo_solidaridad` no tiene columna de base propia en
     * `fetchNominaPila()` (usa la misma base de pensión) y queda fuera de
     * este mapa a propósito — ver docblock de `MAPA_CODIGO_ERROR`.
     */
    private const COLUMNA_BASE_POR_CONCEPTO = [
        'pension' => 'I.B.C. PENSION',
        'salud' => 'I.B.C. EPS',
        'ccf' => 'I.B.C. CCF',
        'sena' => 'IBC Otros Parafiscales',
        'icbf' => 'IBC Otros Parafiscales',
    ];

    /**
     * clave de concepto => tarifa fija (texto, igual formato que
     * `fetchNominaPila()`). Pensión no está aquí porque su tarifa varía por
     * clase de riesgo del cargo (16%/26%, columna `Tarifa_AFP` de
     * `fetchNominaPila()`) — se toma de ahí en vez de un valor fijo.
     */
    private const TARIFA_POR_CONCEPTO = [
        'salud' => '12.50%',
        'ccf' => '4.00%',
        'sena' => '2.00%',
        'icbf' => '3.00%',
    ];

    private SihosEmpresaConfigRepository $configRepository;
    private SihosUsuaDigiResolver $usuaDigiResolver;

    public function __construct()
    {
        $this->configRepository = new SihosEmpresaConfigRepository();
        $this->usuaDigiResolver = new SihosUsuaDigiResolver();
    }

    /**
     * Parsea el CSV tal cual lo entrega el portal (unas líneas de cabecera
     * libre antes de la fila de nombres de columna real, que se busca por
     * contenido en vez de por número de línea fijo — más robusto a variantes
     * del export). Convierte a UTF-8 si el archivo viene en otra codificación
     * (export típico de Windows).
     *
     * @return list<array<string,string>> una fila por línea de datos, con las claves del encabezado real del CSV
     */
    public function parseCsv(string $rutaArchivo): array
    {
        $contenido = file_get_contents($rutaArchivo);
        if ($contenido === false) {
            throw new \RuntimeException('No se pudo leer el archivo CSV.');
        }

        if (!mb_check_encoding($contenido, 'UTF-8')) {
            $convertido = mb_convert_encoding($contenido, 'UTF-8', 'Windows-1252');
            if ($convertido !== false) {
                $contenido = $convertido;
            }
        }
        $contenido = preg_replace('/^\xEF\xBB\xBF/', '', $contenido) ?? $contenido;

        $lineas = preg_split('/\r\n|\r|\n/', $contenido) ?: [];

        $indiceEncabezado = null;
        foreach ($lineas as $i => $linea) {
            if (str_starts_with(trim($linea), 'No_Linea')) {
                $indiceEncabezado = $i;
                break;
            }
        }

        if ($indiceEncabezado === null) {
            throw new \RuntimeException('El archivo no tiene el formato esperado del reporte de validación (falta la fila de encabezados "No_Linea,...").');
        }

        $encabezados = str_getcsv($lineas[$indiceEncabezado]);
        $encabezados = array_map(static fn (string $h): string => trim($h), $encabezados);

        $filas = [];
        for ($i = $indiceEncabezado + 1; $i < count($lineas); $i++) {
            if (trim($lineas[$i]) === '') {
                continue;
            }
            $valores = str_getcsv($lineas[$i]);
            if (count($valores) < count($encabezados)) {
                continue;
            }
            $filas[] = array_combine($encabezados, array_slice($valores, 0, count($encabezados)));
        }

        return $filas;
    }

    /**
     * Cruza las filas ya parseadas del CSV contra SIHOS y arma la vista
     * previa: una entrada por cada corrección de cotización obligatoria
     * (pensión/salud/CCF/SENA/ICBF) encontrada en el CSV, indicando si es
     * aplicable y por qué no cuando no lo es. Nunca escribe nada — es una
     * consulta de solo lectura contra `SihosExternalRepository`.
     *
     * @param list<array<string,string>> $filasCsv
     * @return array{ok:bool,error?:string,empleados?:list<array<string,mixed>>}
     */
    public function construirVistaPrevia(int $empresaId, string $codiAno, string $codiMes, array $filasCsv): array
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

        /*
         * 1) Agrupar las filas del CSV por (empleado, concepto) ANTES de
         * tocar SIHOS. Un mismo empleado puede aparecer varias veces en el
         * archivo PILA (una fila por novedad — vacaciones, incapacidad,
         * licencia — ver SihosNominaPilaService), porque el archivo calcula
         * la cotización de cada porción del mes por separado (según los días
         * que afectó cada novedad) en vez de sobre el mes completo. Por eso
         * el operador puede reportar varios "valores esperados" DISTINTOS
         * para el mismo empleado+concepto: cada uno es una PARTE del total,
         * no una alternativa — hay que sumarlos para comparar contra el
         * total real de SIHOS (ver evaluarGrupo()). Agrupar aquí, en vez de
         * tratar cada fila del CSV como independiente, también evita
         * consultar SIHOS más veces de las necesarias para el mismo
         * empleado+concepto.
         */
        $grupos = [];
        foreach ($filasCsv as $fila) {
            $tipoRegistro = trim((string)($fila['Tipo_Registro'] ?? ''));
            if ($tipoRegistro !== '2') {
                continue;
            }

            $codigoError = (int)($fila['codigo_error'] ?? 0);
            if (!isset(self::MAPA_CODIGO_ERROR[$codigoError])) {
                continue;
            }

            $tipoDocu = trim((string)($fila['Tipo_id'] ?? ''));
            $numePers = trim((string)($fila['No_id'] ?? ''));
            $cadenaCorreccion = trim((string)($fila['cadena_correccion'] ?? ''));

            if ($tipoDocu === '' || $numePers === '' || $cadenaCorreccion === '' || !is_numeric($cadenaCorreccion)) {
                continue;
            }

            $concepto = self::MAPA_CODIGO_ERROR[$codigoError];
            $claveGrupo = "{$tipoDocu}|{$numePers}|{$concepto}";

            $grupos[$claveGrupo] ??= [
                'tipo_docu' => $tipoDocu,
                'no_id' => $numePers,
                'concepto' => $concepto,
                'valores_esperados' => [],
            ];

            $grupos[$claveGrupo]['valores_esperados'][] = [
                'linea_csv' => trim((string)($fila['No_Linea'] ?? '')),
                'valor' => round((float)$cadenaCorreccion, 2),
                // Columna "Error" del CSV: el valor que NOSOTROS exportamos en
                // esa fila (antes de la corrección del operador) — no es un
                // texto de error, es el dato ofensivo tal cual venía en el
                // archivo. Se usa solo para diagnóstico (ver evaluarGrupo() y
                // la vista): mostrar, fila por fila, "lo que exportamos" vs.
                // "lo que pide el operador" ayuda a ver en cuál porción del
                // mes (normal/vacaciones/incapacidad/licencia) se concentra
                // la diferencia real, en vez de solo el total agregado.
                // `null` si no se pudo interpretar como moneda (no afecta el
                // cálculo del ajuste, que sigue basado en `valor`).
                'valor_original' => self::parsearValorMoneda((string)($fila['Error'] ?? '')),
                'descripcion' => trim((string)($fila['Descripción'] ?? '')),
            ];
        }

        // 2) Traer el reporte PILA fresco UNA sola vez (la misma consulta que
        // arma el archivo — fetchNominaPila()) para poder reconstruir, por
        // empleado+concepto, el total real que exportamos incluyendo las
        // filas que el operador NO reportó como error (ver docblock de
        // COLUMNA_COTIZACION_POR_CONCEPTO). Se indexa una sola vez y se
        // reutiliza para todos los grupos, para no repetir esta consulta
        // pesada (toda la nómina de la empresa) por cada empleado+concepto.
        $sumaExportadaPorEmpleadoConcepto = [];
        // Base (I.B.C.) usada para calcular cada cotización — misma suma por
        // porciones que $sumaExportadaPorEmpleadoConcepto, para auditoría
        // (ver COLUMNA_BASE_POR_CONCEPTO). $tarifaPorEmpleadoConcepto solo
        // aplica a 'pension' (única tarifa variable, 16%/26% según clase de
        // riesgo del cargo) — se toma el primer valor no vacío encontrado,
        // es constante para el mismo empleado en el período.
        $sumaBasePorEmpleadoConcepto = [];
        $tarifaPorEmpleadoConcepto = [];
        try {
            $filasReporte = $repositorio->fetchNominaPila(
                $codiAno,
                $codiMes,
                $this->configRepository->municipioEmpresa($empresaId),
                $this->configRepository->salarioMinimoMensual($empresaId, (int)$codiAno)
            );
            foreach ($filasReporte as $filaReporte) {
                $claveEmpleado = trim((string)($filaReporte['TipoDocu'] ?? '')) . '|' . trim((string)($filaReporte['NumePers'] ?? ''));
                foreach (self::COLUMNA_COTIZACION_POR_CONCEPTO as $concepto => $columna) {
                    $valor = $filaReporte[$columna] ?? null;
                    if ($valor === null || $valor === '' || !is_numeric($valor)) {
                        continue;
                    }
                    $claveConcepto = "{$claveEmpleado}|{$concepto}";
                    $sumaExportadaPorEmpleadoConcepto[$claveConcepto] = ($sumaExportadaPorEmpleadoConcepto[$claveConcepto] ?? 0.0) + (float)$valor;
                }
                foreach (self::COLUMNA_BASE_POR_CONCEPTO as $concepto => $columnaBase) {
                    $valorBase = $filaReporte[$columnaBase] ?? null;
                    if ($valorBase === null || $valorBase === '' || !is_numeric($valorBase)) {
                        continue;
                    }
                    $claveConcepto = "{$claveEmpleado}|{$concepto}";
                    $sumaBasePorEmpleadoConcepto[$claveConcepto] = ($sumaBasePorEmpleadoConcepto[$claveConcepto] ?? 0.0) + (float)$valorBase;
                }
                $claveConceptoPension = "{$claveEmpleado}|pension";
                if (!isset($tarifaPorEmpleadoConcepto[$claveConceptoPension])) {
                    $tarifaAfp = trim((string)($filaReporte['Tarifa_AFP'] ?? ''));
                    if ($tarifaAfp !== '') {
                        $tarifaPorEmpleadoConcepto[$claveConceptoPension] = $tarifaAfp;
                    }
                }
            }
        } catch (PDOException $e) {
            return ['ok' => false, 'error' => 'No se pudo consultar SIHOS: ' . $e->getMessage()];
        }

        // 3) Consultar SIHOS UNA vez por grupo (empleado+concepto) y evaluar.
        $itemsPorConcepto = [];
        try {
            foreach ($grupos as $g) {
                $claveGrupoConcepto = "{$g['tipo_docu']}|{$g['no_id']}|{$g['concepto']}";
                $sumaExportadaTotalAhora = isset($sumaExportadaPorEmpleadoConcepto[$claveGrupoConcepto])
                    ? round($sumaExportadaPorEmpleadoConcepto[$claveGrupoConcepto], 2)
                    : null;
                $baseIbcTotalAhora = isset($sumaBasePorEmpleadoConcepto[$claveGrupoConcepto])
                    ? round($sumaBasePorEmpleadoConcepto[$claveGrupoConcepto], 2)
                    : null;
                $tarifaTexto = $g['concepto'] === 'pension'
                    ? ($tarifaPorEmpleadoConcepto[$claveGrupoConcepto] ?? null)
                    : (self::TARIFA_POR_CONCEPTO[$g['concepto']] ?? null);

                $itemsPorConcepto[] = $this->evaluarGrupo($repositorio, $codiAno, $codiMes, $g, $sumaExportadaTotalAhora, $baseIbcTotalAhora, $tarifaTexto);
            }
        } catch (PDOException $e) {
            return ['ok' => false, 'error' => 'No se pudo consultar SIHOS: ' . $e->getMessage()];
        }

        // 4) Reagrupar por EMPLEADO para la vista (una tarjeta por persona).
        $porEmpleado = [];
        foreach ($itemsPorConcepto as $item) {
            $claveEmp = $item['tipo_docu'] . '|' . $item['no_id'];

            if (!isset($porEmpleado[$claveEmp])) {
                try {
                    $nombre = $repositorio->nombreEmpleado($item['tipo_docu'], $item['no_id']);
                } catch (PDOException $e) {
                    $nombre = null;
                }

                $porEmpleado[$claveEmp] = [
                    'tipo_docu' => $item['tipo_docu'],
                    'no_id' => $item['no_id'],
                    'nombre' => $nombre,
                    'conceptos' => [],
                ];
            }

            $porEmpleado[$claveEmp]['conceptos'][] = $item;
        }

        // 5) Ordenar de mayor a menor diferencia total (a pedido del
        // usuario, 2026-09-01, para priorizar la revisión) — suma del valor
        // absoluto de la diferencia (suma_esperada - suma_actual) de TODOS
        // los conceptos del empleado, no solo el más grande, así un
        // empleado con varias diferencias medianas queda por encima de uno
        // con una sola diferencia grande si la suma total es mayor. Los
        // conceptos sin `suma_actual` (estado 'no_encontrado', nada con qué
        // comparar) no aportan a la suma.
        $diferenciaTotalPorEmpleado = [];
        foreach ($porEmpleado as $claveEmp => $emp) {
            $total = 0.0;
            foreach ($emp['conceptos'] as $c) {
                if ($c['suma_actual'] !== null) {
                    $total += abs($c['suma_esperada'] - $c['suma_actual']);
                }
            }
            $diferenciaTotalPorEmpleado[$claveEmp] = $total;
        }
        uksort($porEmpleado, static fn (string $a, string $b): int => $diferenciaTotalPorEmpleado[$b] <=> $diferenciaTotalPorEmpleado[$a]);

        return ['ok' => true, 'empleados' => array_values($porEmpleado)];
    }

    /**
     * Evalúa un grupo (empleado + concepto, con TODOS los valores esperados
     * que el CSV trajo para esa combinación) contra SIHOS.
     *
     * El archivo PILA calcula la cotización de cada porción del mes por
     * separado (normal, vacaciones, incapacidad, licencia — ver
     * SihosNominaPilaService), así que el operador puede reportar varios
     * valores esperados para el mismo empleado+concepto: son PARTES de un
     * mismo total, no alternativas.
     *
     * IMPORTANTE: el CSV del operador SOLO lista errores — si un empleado
     * tiene varias filas exportadas para este concepto y únicamente algunas
     * tienen problema, las demás NUNCA aparecen en el CSV (el operador las
     * considera correctas). Por eso NO basta con sumar los valores del CSV y
     * compararlos contra el total de SIHOS: eso ignora las filas "buenas" no
     * reportadas y genera una diferencia fantasma del tamaño de esas filas
     * (caso real verificado: empleado con una fila de $31.000 sin error y
     * otra de $116.800→$116.900 con error — sumar solo la reportada contra
     * el total real de SIHOS ($147.800) daba una diferencia falsa de
     * -$30.900, cuando la diferencia real era de $100).
     *
     * La suma esperada REAL se reconstruye así (ver también
     * construirVistaPrevia(), que trae `$sumaExportadaTotalAhora` con una
     * sola llamada fresca a `fetchNominaPila()` — la MISMA consulta que arma
     * el archivo — sumando TODAS las filas de este empleado+concepto, con o
     * sin error reportado):
     *
     *   suma esperada real = (total que exportamos AHORA, todas las filas)
     *                       − (lo que exportamos en las filas SÍ reportadas)
     *                       + (lo que el operador dice que deben ser esas filas)
     *
     * Si no se pudo reconstruir esto (falta el reporte fresco, o no se pudo
     * leer el valor original de alguna fila reportada), se cae de vuelta a
     * sumar solo los valores del CSV — mismo comportamiento que antes, con
     * el riesgo de diferencia fantasma ya documentado, preferible a no
     * mostrar nada.
     *
     * Esa suma esperada real se compara contra la SUMA de todas las líneas
     * reales encontradas en SIHOS para ese concepto (sin importar si viven
     * en uno o varios documentos — Nómina de Empleados / Nómina de
     * Vacaciones — o repetidas dentro de uno solo).
     *
     * La corrección solo se puede ESCRIBIR en una línea que todavía no esté
     * causada ("abierta") — las demás, si las hay, ya están confirmadas y no
     * se tocan. Por eso lo que de verdad determina si hay algo que aplicar
     * no es cuántas líneas reales existen en total, sino cuántas siguen
     * abiertas:
     *
     *  - 'no_encontrado': no hay ninguna línea de este concepto en SIHOS
     *    para el período (ni en Nómina de Empleados ni en Vacaciones).
     *  - 'confirmada': todas las líneas reales encontradas ya están
     *    causadas — se muestra la suma esperada vs. la suma actual y la
     *    diferencia, pero no se puede aplicar desde aquí.
     *  - 'ya_coincide': hay una línea abierta (la elegida, ver abajo) y la
     *    suma esperada ya coincide con la suma actual — nada que corregir.
     *  - 'aplicable': la suma esperada difiere de la suma actual — se ofrece
     *    el checkbox de corrección, que ajusta el aporte patronal de LA
     *    LÍNEA ELEGIDA para que la suma total quede igual a lo que exige el
     *    operador (sin tocar las líneas ya causadas, que son inmodificables).
     *
     *  'ambiguo' YA NO SE DEVUELVE (2026-09-02, a pedido explícito del
     *  usuario, riesgo aceptado): antes, más de una línea abierta bloqueaba
     *  la corrección por completo. Ahora se elige automáticamente la línea
     *  abierta de MAYOR valor (`ValoEmpe+ValoPatr`) entre todas las abiertas
     *  y se le aplica el ajuste completo, dejando las demás líneas abiertas
     *  intactas — es una SUPOSICIÓN, no una certeza (la diferencia real
     *  podría corresponder a una línea más pequeña). `elegida_entre_varias`
     *  (bool) y `total_lineas_abiertas` (int) quedan en el resultado para
     *  que la vista avise cuando se usó este criterio, y la persona pueda
     *  verificar antes de aplicar.
     *
     * @param array{tipo_docu:string,no_id:string,concepto:string,valores_esperados:list<array{linea_csv:string,valor:float,valor_original:?float,descripcion:string}>} $grupo
     * @param ?float $sumaExportadaTotalAhora suma de TODAS las filas exportadas ahora para este empleado+concepto (con o sin error), o null si no se pudo determinar
     * @param ?float $baseIbcTotalAhora suma de la BASE (I.B.C.) de TODAS las filas exportadas ahora para este empleado+concepto (mismo criterio que $sumaExportadaTotalAhora) — solo para mostrar en la vista, no participa en ningún cálculo de corrección
     * @param ?string $tarifaTexto tarifa usada para calcular la cotización de este concepto (fija, salvo pensión que varía por clase de riesgo) — solo para mostrar "base × tarifa = cotización" en la vista
     * @return array<string,mixed>
     */
    private function evaluarGrupo(SihosExternalRepository $repositorio, string $codiAno, string $codiMes, array $grupo, ?float $sumaExportadaTotalAhora, ?float $baseIbcTotalAhora = null, ?string $tarifaTexto = null): array
    {
        $tipoDocu = $grupo['tipo_docu'];
        $numePers = $grupo['no_id'];
        $concepto = $grupo['concepto'];
        $flagConcepto = SihosExternalRepository::FLAGS_CONCEPTO_CORRECCION[$concepto];

        $gruposSihos = $repositorio->buscarConceptoCorreccionNomina($tipoDocu, $numePers, $codiAno, $codiMes, $flagConcepto);

        // Aplanar todas las líneas reales encontradas (sin importar el
        // documento) a una sola lista, cada una marcada con si su documento
        // ya está causado.
        $lineasSihos = [];
        foreach ($gruposSihos as $g) {
            $causado = $repositorio->nominaEstaCausada($g['codiDocu'], $g['numeDocu']);
            foreach ($g['lineas'] as $linea) {
                $lineasSihos[] = [
                    'codi_docu' => $g['codiDocu'],
                    'nume_docu' => $g['numeDocu'],
                    'nombre_docu' => $g['nombreDocu'],
                    'codi_conc' => $g['codiConc'],
                    'causado' => $causado,
                    'valo_empe' => $linea['valoEmpe'],
                    'valo_patr' => $linea['valoPatr'],
                    'total' => round($linea['valoEmpe'] + $linea['valoPatr'], 2),
                ];
            }
        }

        $sumaActual = $lineasSihos === [] ? null : round(array_sum(array_column($lineasSihos, 'total')), 2);

        // Suma de "lo que nosotros exportamos" en las filas QUE SÍ aparecen
        // en el CSV (columna Error) y suma de lo que el operador pide para
        // esas mismas filas — solo diagnóstico/insumo, ver más abajo.
        $valoresOriginales = array_column($grupo['valores_esperados'], 'valor_original');
        $sumaOriginalReportada = in_array(null, $valoresOriginales, true)
            ? null
            : round(array_sum($valoresOriginales), 2);
        $sumaEsperadaReportada = round(array_sum(array_column($grupo['valores_esperados'], 'valor')), 2);

        // Suma esperada REAL: total que exportamos ahora (todas las filas,
        // con o sin error) menos lo reportado como incorrecto, más lo que el
        // operador dice que debe ser — ver docblock. Si falta algún insumo,
        // se cae de vuelta a sumar solo lo reportado (comportamiento previo).
        if ($sumaExportadaTotalAhora !== null && $sumaOriginalReportada !== null) {
            $sumaEsperada = round($sumaExportadaTotalAhora - $sumaOriginalReportada + $sumaEsperadaReportada, 2);
        } else {
            $sumaEsperada = $sumaEsperadaReportada;
        }

        $base = [
            'tipo_docu' => $tipoDocu,
            'no_id' => $numePers,
            'concepto' => $concepto,
            'valores_esperados' => $grupo['valores_esperados'],
            'lineas_sihos' => $lineasSihos,
            'suma_esperada' => $sumaEsperada,
            'suma_esperada_reportada' => $sumaEsperadaReportada,
            'suma_actual' => $sumaActual,
            'suma_original' => $sumaOriginalReportada,
            'suma_exportada_total' => $sumaExportadaTotalAhora,
            'base_ibc_total' => $baseIbcTotalAhora,
            'tarifa_texto' => $tarifaTexto,
            'aplicable' => false,
        ];

        if ($lineasSihos === []) {
            return $base + ['estado' => 'no_encontrado'];
        }

        $abiertas = array_values(array_filter($lineasSihos, static fn (array $l): bool => !$l['causado']));

        if ($abiertas === []) {
            return $base + ['estado' => 'confirmada'];
        }

        // Antes: más de una línea abierta => 'ambiguo' (bloqueado, había que
        // decidir a mano en SIHOS). Ahora, a pedido explícito del usuario
        // (2026-09-02, riesgo aceptado): se elige automáticamente la línea
        // abierta de MAYOR valor (`ValoEmpe+ValoPatr`) y se le aplica todo
        // el ajuste, dejando las demás líneas abiertas SIN TOCAR. Es una
        // SUPOSICIÓN, no una certeza — la diferencia real podría
        // corresponder a la línea más pequeña (p. ej. si un "cambio de
        // cargo" dejó mal liquidada justo la nómina nueva, no la vieja) —
        // por eso se marca `elegida_entre_varias` para que la vista avise y
        // la persona pueda verificar antes de aplicar. El cálculo de abajo
        // no cambia: `sumaCerradas` = sumaActual - total de la elegida ya
        // incluye automáticamente tanto las líneas causadas como las demás
        // abiertas no elegidas, tratándolas como fijas.
        usort($abiertas, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);
        $elegidaEntreVarias = count($abiertas) > 1;
        $abierta = $abiertas[0];
        $sumaCerradas = round($sumaActual - $abierta['total'], 2);
        $totalObjetivoLinea = round($sumaEsperada - $sumaCerradas, 2);
        $diferencia = round($sumaEsperada - $sumaActual, 2);

        $base += [
            'codi_docu' => $abierta['codi_docu'],
            'nume_docu' => $abierta['nume_docu'],
            'nombre_docu' => $abierta['nombre_docu'],
            'codi_conc' => $abierta['codi_conc'],
            'elegida_entre_varias' => $elegidaEntreVarias,
            'total_lineas_abiertas' => count($abiertas),
        ];

        if ($concepto === 'fondo_solidaridad') {
            // Excepción: FSP no tiene componente patronal (ValoPatr SIEMPRE
            // $0 en SIHOS para este concepto — verificado con datos reales),
            // así que aquí el lado ajustable es ValoEmpe, no ValoPatr — ver
            // docblock de SihosExternalWriteRepository::corregirValoPatrNomina().
            $base += [
                'ajusta_valo_empe' => true,
                'valo_patr' => $abierta['valo_patr'],
                'valo_empe_actual' => $abierta['valo_empe'],
                'valo_empe_propuesto' => round($totalObjetivoLinea - $abierta['valo_patr'], 2),
            ];
        } else {
            $base += [
                'ajusta_valo_empe' => false,
                'valo_empe' => $abierta['valo_empe'],
                'valo_patr_actual' => $abierta['valo_patr'],
                'valo_patr_propuesto' => round($totalObjetivoLinea - $abierta['valo_empe'], 2),
            ];
        }

        if (abs($diferencia) < 0.01) {
            return $base + ['estado' => 'ya_coincide'];
        }

        $base['aplicable'] = true;

        return $base + ['estado' => 'aplicable'];
    }

    /**
     * Aplica las correcciones que el usuario marcó y confirmó en la vista
     * previa. Cada una se re-resuelve y re-verifica desde cero contra SIHOS
     * — nunca confía en los datos de la vista previa, que pueden haber
     * quedado desactualizados:
     *
     *  1) Re-busca las líneas reales del concepto (buscarConceptoCorreccionNomina)
     *     y recalcula cuántas siguen sin causar. Si en este momento ya no
     *     hay EXACTAMENTE una línea abierta, se rechaza (el estado cambió
     *     entre la vista previa y este clic — p. ej. alguien confirmó la
     *     nómina mientras tanto).
     *  2) Con la única línea abierta identificada, calcula el total que le
     *     corresponde a ESA línea (`suma_esperada` recibida del cliente,
     *     menos la suma de las demás líneas ya causadas — que son
     *     inmodificables — recién releída, no la de la vista previa vieja).
     *  3) Delega el UPDATE a SihosExternalWriteRepository::corregirValoPatrNomina(),
     *     que vuelve a re-verificar todo (causado, línea única, ValoEmpe
     *     fresco) dentro de su propia transacción con bloqueo nombrado.
     *
     * Registra cada intento (éxito o rechazo) en la auditoría de SAVID.
     *
     * @param list<array{tipo_docu:string,no_id:string,concepto:string,suma_esperada:string|float}> $seleccion
     * @return array{ok:bool,error?:string,resultados?:list<array<string,mixed>>}
     */
    public function aplicarCorrecciones(int $empresaId, string $codiAno, string $codiMes, array $seleccion): array
    {
        if ($seleccion === []) {
            return ['ok' => false, 'error' => 'No hay ninguna corrección seleccionada.'];
        }

        $configFila = $this->configRepository->findByEmpresaId($empresaId);
        if ($configFila === null || $configFila['host'] === '' || $configFila['base_datos'] === '') {
            return ['ok' => false, 'error' => 'Esta empresa no tiene conexión a SIHOS configurada.'];
        }

        $codiInst = trim((string)($configFila['codi_inst'] ?? ''));
        $usuarioEscritura = trim((string)($configFila['usuario_escritura'] ?? ''));
        if ($codiInst === '' || $usuarioEscritura === '' || empty($configFila['password_escritura_cifrado'])) {
            return [
                'ok' => false,
                'error' => 'Configure las credenciales de escritura de esta empresa en Conexión SIHOS antes de usar esta acción.',
            ];
        }

        $repositorioLectura = new SihosExternalRepository([
            'host' => $configFila['host'],
            'port' => (string)$configFila['puerto'],
            'database' => $configFila['base_datos'],
            'username' => $configFila['usuario'],
            'codiInst' => $codiInst,
            'password' => SihosCredentialCipher::decrypt($configFila['password_cifrado'] ?? null),
            'charset' => $configFila['charset'],
        ]);

        $usuaDigi = $this->usuaDigiResolver->resolver((int)($_SESSION['user_id'] ?? 0), $repositorioLectura);

        $repositorioEscritura = new SihosExternalWriteRepository([
            'host' => $configFila['host'],
            'port' => (string)$configFila['puerto'],
            'database' => $configFila['base_datos'],
            'username' => $usuarioEscritura,
            'codiInst' => $codiInst,
            'password' => SihosCredentialCipher::decrypt($configFila['password_escritura_cifrado']),
            'charset' => $configFila['charset'],
        ]);

        $resultados = [];

        foreach ($seleccion as $item) {
            $tipoDocu = trim((string)($item['tipo_docu'] ?? ''));
            $numePers = trim((string)($item['no_id'] ?? ''));
            $concepto = trim((string)($item['concepto'] ?? ''));
            $sumaEsperada = is_numeric($item['suma_esperada'] ?? null) ? round((float)$item['suma_esperada'], 2) : null;

            $etiqueta = "{$tipoDocu} {$numePers} — {$concepto}";

            // Lista blanca DELIBERADAMENTE más estricta que
            // SihosExternalRepository::FLAGS_CONCEPTO_CORRECCION (que
            // también incluye 'arl' para SihosPlanillaIntegradaService, de
            // solo lectura): esta acción SÍ escribe en SIHOS, y ARL/riesgos
            // sigue fuera de alcance aquí (ver docblock de la clase) — un
            // POST forjado con concepto=arl no debe poder colarse solo
            // porque ese flag ya exista en el catálogo compartido.
            if ($tipoDocu === '' || $numePers === '' || !in_array($concepto, self::MAPA_CODIGO_ERROR, true) || $sumaEsperada === null) {
                $resultados[] = ['etiqueta' => $etiqueta, 'ok' => false, 'motivo' => 'Datos incompletos o inválidos.'];
                continue;
            }

            $flagConcepto = SihosExternalRepository::FLAGS_CONCEPTO_CORRECCION[$concepto];

            try {
                $gruposSihos = $repositorioLectura->buscarConceptoCorreccionNomina($tipoDocu, $numePers, $codiAno, $codiMes, $flagConcepto);
            } catch (PDOException $e) {
                $resultados[] = ['etiqueta' => $etiqueta, 'ok' => false, 'motivo' => 'No se pudo consultar SIHOS: ' . $e->getMessage()];
                continue;
            }

            if ($gruposSihos === []) {
                $resultados[] = ['etiqueta' => $etiqueta, 'ok' => false, 'motivo' => 'Ya no se encuentra esta línea en SIHOS.'];
                continue;
            }

            // Re-aplanar y re-verificar cuántas líneas siguen abiertas —
            // mismo criterio que evaluarGrupo(), pero con datos frescos: no
            // se reutiliza nada de la vista previa.
            $lineasSihos = [];
            try {
                foreach ($gruposSihos as $g) {
                    $causado = $repositorioLectura->nominaEstaCausada($g['codiDocu'], $g['numeDocu']);
                    foreach ($g['lineas'] as $linea) {
                        $lineasSihos[] = [
                            'codi_docu' => $g['codiDocu'],
                            'nume_docu' => $g['numeDocu'],
                            'codi_conc' => $g['codiConc'],
                            'causado' => $causado,
                            'total' => round($linea['valoEmpe'] + $linea['valoPatr'], 2),
                        ];
                    }
                }
            } catch (PDOException $e) {
                $resultados[] = ['etiqueta' => $etiqueta, 'ok' => false, 'motivo' => 'No se pudo consultar SIHOS: ' . $e->getMessage()];
                continue;
            }

            $abiertas = array_values(array_filter($lineasSihos, static fn (array $l): bool => !$l['causado']));

            if ($abiertas === []) {
                $resultados[] = [
                    'etiqueta' => $etiqueta,
                    'ok' => false,
                    'motivo' => 'Todas las líneas de este concepto ya están confirmadas en SIHOS — no se corrige automáticamente.',
                ];
                continue;
            }

            // Mismo criterio que evaluarGrupo() (ver su docblock, 2026-09-02):
            // con más de una línea abierta, se elige la de MAYOR valor y se
            // le aplica el ajuste completo, tratando el resto (causadas Y
            // las demás abiertas no elegidas) como fijo.
            usort($abiertas, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);
            $abierta = $abiertas[0];
            $sumaCerradas = round(
                array_sum(array_column($lineasSihos, 'total')) - $abierta['total'],
                2
            );
            $totalObjetivoLinea = round($sumaEsperada - $sumaCerradas, 2);

            // Excepción deliberada: Fondo de Solidaridad Pensional no tiene
            // componente patronal en SIHOS (ValoPatr siempre $0 — verificado
            // con datos reales), así que aquí se ajusta ValoEmpe en vez de
            // ValoPatr — ver docblock de corregirValoPatrNomina(). Es la
            // ÚNICA excepción; los otros 5 conceptos siguen sin tocar jamás
            // ValoEmpe.
            $ajustarValoEmpe = $concepto === 'fondo_solidaridad';

            try {
                $resultado = $repositorioEscritura->corregirValoPatrNomina(
                    $abierta['codi_docu'],
                    $abierta['nume_docu'],
                    $codiAno,
                    $codiMes,
                    $tipoDocu,
                    $numePers,
                    $abierta['codi_conc'],
                    $totalObjetivoLinea,
                    $usuaDigi,
                    $ajustarValoEmpe
                );
            } catch (SihosOperacionEnCursoException $e) {
                $resultados[] = ['etiqueta' => $etiqueta, 'ok' => false, 'motivo' => $e->getMessage()];
                continue;
            } catch (PDOException $e) {
                $resultados[] = ['etiqueta' => $etiqueta, 'ok' => false, 'motivo' => 'No se pudo corregir en SIHOS: ' . $e->getMessage()];
                continue;
            }

            if (!$resultado['ok']) {
                $resultados[] = ['etiqueta' => $etiqueta, 'ok' => false, 'motivo' => $resultado['motivo'] ?? 'Rechazado por SIHOS.'];
                continue;
            }

            if ($ajustarValoEmpe) {
                $sueldoAjustado = !empty($resultado['sueldoValoDeduValoNetoAjustado']);

                $this->registrarAuditoria(
                    $empresaId,
                    'sihos.DetaNomi',
                    "{$codiInst}-{$abierta['codi_docu']}-{$abierta['nume_docu']}-{$tipoDocu}-{$numePers}-{$abierta['codi_conc']}",
                    json_encode(['ValoEmpe' => $resultado['valoEmpeAntes'], 'ValoPatr' => $resultado['valoPatrAntes']], JSON_UNESCAPED_UNICODE),
                    json_encode(['ValoEmpe' => $resultado['valoEmpe'], 'ValoPatr' => $resultado['valoPatrDespues']], JSON_UNESCAPED_UNICODE),
                    "Corrección de aporte del EMPLEADO ({$concepto}) en DetaNomi de SIHOS, nómina {$abierta['codi_docu']}-{$abierta['nume_docu']}, "
                        . "empleado {$tipoDocu} {$numePers}, a partir del reporte de validación del operador de aportes en línea "
                        . "(suma esperada del concepto: {$sumaEsperada}). Este concepto no tiene aporte patronal en SIHOS, "
                        . "por eso se ajusta ValoEmpe en vez de ValoPatr. "
                        . ($sueldoAjustado
                            ? "ValoDedu/ValoNeto de la línea de SUELDO del mismo documento se ajustaron en la misma diferencia, para mantener el neto a pagar consistente."
                            : "No se encontró (o había más de una) línea de SUELDO en el documento — ValoDedu/ValoNeto NO se ajustaron, revisar manualmente.")
                );

                $resultados[] = [
                    'etiqueta' => $etiqueta,
                    'ok' => true,
                    'valo_empe_antes' => $resultado['valoEmpeAntes'],
                    'valo_empe_despues' => $resultado['valoEmpe'],
                    'sueldo_valo_dedu_valo_neto_ajustado' => $sueldoAjustado,
                ];
                continue;
            }

            $this->registrarAuditoria(
                $empresaId,
                'sihos.DetaNomi',
                "{$codiInst}-{$abierta['codi_docu']}-{$abierta['nume_docu']}-{$tipoDocu}-{$numePers}-{$abierta['codi_conc']}",
                json_encode(['ValoEmpe' => $resultado['valoEmpe'], 'ValoPatr' => $resultado['valoPatrAntes']], JSON_UNESCAPED_UNICODE),
                json_encode(['ValoEmpe' => $resultado['valoEmpe'], 'ValoPatr' => $resultado['valoPatrDespues']], JSON_UNESCAPED_UNICODE),
                "Corrección de aporte patronal ({$concepto}) en DetaNomi de SIHOS, nómina {$abierta['codi_docu']}-{$abierta['nume_docu']}, "
                    . "empleado {$tipoDocu} {$numePers}, a partir del reporte de validación del operador de aportes en línea "
                    . "(suma esperada del concepto: {$sumaEsperada})."
            );

            $resultados[] = [
                'etiqueta' => $etiqueta,
                'ok' => true,
                'valo_patr_antes' => $resultado['valoPatrAntes'],
                'valo_patr_despues' => $resultado['valoPatrDespues'],
            ];
        }

        return ['ok' => true, 'resultados' => $resultados];
    }

    /**
     * Registro manual en la auditoría de SAVID: AuditingPDO solo cubre
     * escrituras en la BD propia de SAVID, no ésta contra SIHOS. Mismo
     * patrón que SihosPresupuestoEliminacionService::registrarAuditoria().
     */
    private function registrarAuditoria(
        int $empresaId,
        string $tabla,
        string $registroId,
        ?string $datosAnteriores,
        ?string $datosNuevos,
        string $sqlResumen
    ): void {
        $pdo = (new Database())->connect();

        $stmt = $pdo->prepare('
            INSERT INTO auditoria (
                accion, tabla, registro_id,
                datos_anteriores, datos_nuevos, campos_cambiados,
                sql_resumen, usuario_id, empresa_id, sede_id,
                ip, user_agent, request_url
            ) VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?)
        ');

        $stmt->execute([
            'UPDATE',
            $tabla,
            $registroId,
            $datosAnteriores,
            $datosNuevos,
            $sqlResumen,
            $_SESSION['user_id'] ?? null,
            $empresaId,
            $_SESSION['sede_id'] ?? null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
            $_SERVER['REQUEST_URI'] ?? null,
        ]);
    }

    /** "$207,500" => 207500.0; "" o formato no numérico => null (nunca lanza excepción, es dato diagnóstico). */
    private static function parsearValorMoneda(string $valor): ?float
    {
        $limpio = str_replace(['$', ',', ' '], '', trim($valor));
        if ($limpio === '' || !is_numeric($limpio)) {
            return null;
        }

        return round((float)$limpio, 2);
    }
}
