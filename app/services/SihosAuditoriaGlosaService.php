<?php

/**
 * Reporte SIHOS "Auditoría Glosa": universo de glosas (EncaCont+DetaGlos) de
 * una empresa, cruzado contra la factura que referencian para calcular
 * cuánto sigue "en curso" (a partir de AnotGlos) y compararlo contra la
 * cartera real (cuenta 14%), la cuenta de orden de glosas en trámite
 * (cuenta 8333%, NIIF y no NIIF) y el valor que SIHOS ya trae nativo en la
 * cuenta abierta de la factura (DetaFaCr.GlosCurs).
 *
 * Hallazgo principal que motivó este reporte (ver docs/plan): glosas que
 * siguen "en curso" sobre facturas cuya cartera ya está saldada — la cuenta
 * de orden de la glosa dice "todavía en discusión" pero la cartera real
 * dice "ya no hay nada pendiente de cobrar". Solo de lectura contra SIHOS
 * (nunca escribe ahí) — pero sí genera un CSV en el formato exacto de
 * "Importar Glosas → Detalle" de SIHOS (ver prepararConclusionSaldoCero()/
 * filasParaConcluirCsv()) para que el usuario cierre esas glosas cargando
 * el archivo manualmente en SIHOS. Verificado contra el código fuente real
 * de SIHOS (clases/importarglosas.class.php) que esa vía SÍ concluye la
 * glosa con factura en saldo $0 — a diferencia del formulario manual
 * (modulos/glosas/anotglos.php), que se bloquea en ese caso porque
 * depende de ConsultaCuentaSaldo() encontrando una cuenta de cartera con
 * saldo > 0, cosa que la carga masiva no necesita (usa directamente
 * TipoUsua.GlosDebe/GlosHabe).
 *
 * El universo de "qué documento es una glosa" se resuelve vía
 * SihosExternalRepository::resolveCodigosGlosaConDetalle() (lo que
 * REALMENTE tiene detalle en DetaGlos), no vía DocuApli — verificado con
 * datos reales que el "rol" de negocio (DocuApli=68, "glosa aceptada o
 * concluida") y el documento que de verdad lleva el detalle valorizado
 * pueden ser tipos de documento distintos dentro de la misma instalación.
 */
class SihosAuditoriaGlosaService
{
    /** Mismo criterio de SihosCruceReconocimientoService: cortar antes de un rango que reventaría la consulta. */
    private const RANGO_MAXIMO_DIAS = 400;

    /**
     * Colchón para el universo sin límite de fecha (mismo criterio de
     * SihosCruceReconocimientoService). Las consultas "por factura"/"por
     * glosa" ya no acotan a un lote de claves (ver docblocks en
     * SihosExternalRepository — acotar multiplicaba los round-trips de red
     * y era más lento), así que traen el universo completo de la
     * institución: medido en una institución real de ~86.000 glosas, el
     * pico fue 501 MB — 1024M da margen para instalaciones más grandes.
     */
    private const MEMORY_LIMIT_MB = 1024;

    private SihosEmpresaConfigRepository $configRepository;

    public function __construct()
    {
        $this->configRepository = new SihosEmpresaConfigRepository();
    }

    public function buildReporte(
        int $empresaId,
        string $fechaIni,
        string $fechaFin,
        string $fechaCorte,
        string $codigoAdministradora,
        string $tipoUsuario,
        bool $soloEnCurso = true
    ): array {
        $fechaIni = trim($fechaIni);

        if (!$this->fechaValida($fechaFin)) {
            return ['ok' => false, 'error' => 'Fecha "Hasta" inválida.'];
        }

        // "Desde" es opcional a propósito (pedido del usuario): sin ella, el
        // universo es "todo el historial hasta Hasta", sin el tope de
        // RANGO_MAXIMO_DIAS de abajo — el usuario asume el costo de una
        // consulta más pesada a cambio de no tener que acotar el inicio.
        if ($fechaIni !== '') {
            if (!$this->fechaValida($fechaIni) || $fechaIni > $fechaFin) {
                return ['ok' => false, 'error' => 'Rango de fechas inválido.'];
            }

            $dias = (new DateTime($fechaIni))->diff(new DateTime($fechaFin))->days;
            if ($dias > self::RANGO_MAXIMO_DIAS) {
                return [
                    'ok' => false,
                    'error' => sprintf(
                        'El rango seleccionado es de %d días. Este reporte admite hasta %d días (~%d meses) '
                            . 'por consulta. Divida el rango en partes más pequeñas.',
                        $dias,
                        self::RANGO_MAXIMO_DIAS,
                        (int)round(self::RANGO_MAXIMO_DIAS / 30)
                    ),
                ];
            }
        }

        $fechaCorte = trim($fechaCorte) !== '' ? trim($fechaCorte) : $fechaFin;
        if (!$this->fechaValida($fechaCorte)) {
            return ['ok' => false, 'error' => 'Fecha de corte inválida.'];
        }

        // No tiene sentido evaluar el estado de una glosa a una fecha
        // anterior al fin del rango que se está consultando.
        if ($fechaCorte < $fechaFin) {
            return ['ok' => false, 'error' => 'La fecha de corte no puede ser anterior a "Hasta".'];
        }

        $repository = $this->crearRepository($empresaId);
        if ($repository === null) {
            return ['ok' => false, 'error' => 'Esta empresa no tiene conexión a SIHOS configurada.'];
        }

        // Sin "Desde", el universo puede superar 80.000 documentos y varios
        // minutos de consultas por lote contra SIHOS (verificado: ~4 min en
        // una institución real) — muy por encima del max_execution_time por
        // defecto (30s en este servidor). set_time_limit(0) = sin límite
        // solo para esta petición pesada.
        $tiempoPrevio = (int)ini_get('max_execution_time');
        set_time_limit(0);

        try {
            return SihosMemoryGuard::ejecutar(
                self::MEMORY_LIMIT_MB,
                fn () => $this->buildReporteInterno($repository, $fechaIni, $fechaFin, $fechaCorte, $codigoAdministradora, $tipoUsuario, $soloEnCurso)
            );
        } finally {
            set_time_limit($tiempoPrevio);
        }
    }

    private function buildReporteInterno(
        SihosExternalRepository $repository,
        string $fechaIni,
        string $fechaFin,
        string $fechaCorte,
        string $codigoAdministradora,
        string $tipoUsuario,
        bool $soloEnCurso
    ): array {
        try {
            $tiposUsuario = $repository->fetchTipoUsuarioNombres();
            $codigosGlosa = $repository->resolveCodigosGlosaConDetalle();

            if ($codigosGlosa === []) {
                return $this->resultadoVacio($tiposUsuario);
            }

            $base = $repository->fetchGlosasBase(
                $codigosGlosa,
                $fechaIni !== '' ? $fechaIni : null,
                $fechaFin,
                trim($codigoAdministradora),
                trim($tipoUsuario)
            );
            if ($base === []) {
                return $this->resultadoVacio($tiposUsuario);
            }

            // Una sola consulta agregada por cada fuente (sin acotar a un
            // lote de claves) — ver el docblock de cada fetch* en
            // SihosExternalRepository: acotar por lote multiplicó los
            // round-trips de red hacia SIHOS y fue más lento que traer de
            // más en una sola consulta.
            $anotaciones = $repository->fetchAnotacionesGlosaAgregadas($codigosGlosa, $fechaCorte);
            // Prefijo(s) de familia de cuenta de cartera resueltos por
            // TipoUsua, no un prefijo fijo — varía por institución (14% en
            // unas, 13% en otras). Prefijo, no código exacto: la cartera se
            // reclasifica a subcuentas de la misma familia que no están en
            // el catálogo de asignación inicial (ver docblock de
            // resolvePrefijosCuentaCarteraPorTipoUsuario(), caso real
            // FE-508020).
            $prefijosCartera = $repository->resolvePrefijosCuentaCarteraPorTipoUsuario();
            $saldoCartera = $repository->fetchSaldoCarteraPorFactura($prefijosCartera);
            $saldo8333 = $repository->fetchSaldoCuentaPorFactura('8333%');
            $saldo8333Niif = $repository->fetchSaldoNiifPorFactura('8333%');
            $glosaOficial = $repository->fetchGlosaEnCursoOficial();

            $filas = [];
            $resumen = ['saldoCero' => 0, 'divergencia' => 0];

            foreach ($base as $fila) {
                $claveGlosa = $fila['CodiDocu'] . '-' . $fila['NumeDocu'];
                $claveFactura = $fila['TiDoRefe'] . '-' . $fila['NuDoRefe'];

                $anot = $anotaciones[$claveGlosa] ?? ['AcepIPS' => 0.0, 'AcepEPS' => 0.0, 'UltimaFechaAnot' => null];
                $valorGlosa = (float)$fila['Valor'];
                $acepIPS = $anot['AcepIPS'];
                $acepEPS = $anot['AcepEPS'];
                $enCurso = round($valorGlosa - $acepIPS - $acepEPS, 2);

                if ($soloEnCurso && abs($enCurso) < 0.005) {
                    continue;
                }

                $diasEnCurso = 0;
                if (abs($enCurso) >= 0.005) {
                    $fechaBase = $anot['UltimaFechaAnot'] ?? $fila['FechDocu'];
                    $diasEnCurso = (new DateTime($fechaCorte))->diff(new DateTime($fechaBase))->days;
                }

                $saldo = $saldoCartera[$claveFactura] ?? null;
                $valor8333 = $saldo8333[$claveFactura] ?? 0.0;
                $valor8333Niif = $saldo8333Niif[$claveFactura] ?? 0.0;
                $valorOficial = $glosaOficial[$claveFactura] ?? 0.0;

                $saldoEsCero = $saldo === null || abs($saldo) < 0.005;
                $alertaSaldoCero = $saldoEsCero
                    && (($enCurso >= 0.005) || $valor8333 >= 0.005 || $valorOficial >= 0.005);
                $alertaDivergencia = abs($enCurso - $valorOficial) > 0.01;

                if ($alertaSaldoCero) {
                    $resumen['saldoCero']++;
                }
                if ($alertaDivergencia) {
                    $resumen['divergencia']++;
                }

                $filas[] = [
                    'TipoUsua' => $fila['TipoUsua'],
                    'NombTipo' => $fila['NombTipo'],
                    'NombTerc' => $fila['NombTerc'],
                    'NuDoTerc' => $fila['NuDoTerc'],
                    'CodiAdmi' => $fila['CodiAdmi'],
                    'NombAdmi' => $fila['NombAdmi'],
                    'FechDocu' => $fila['FechDocu'],
                    'CodiDocu' => $fila['CodiDocu'],
                    'NumeDocu' => $fila['NumeDocu'],
                    'TiDoRefe' => $fila['TiDoRefe'],
                    'NuDoRefe' => $fila['NuDoRefe'],
                    'Refe' => $fila['TiDoRefe'] . $fila['NuDoRefe'],
                    'Saldo' => $saldo,
                    'Dias' => $diasEnCurso,
                    'Valor' => $valorGlosa,
                    'EnCurso' => $enCurso,
                    'Valor8333' => $valor8333,
                    'Valor8333niif' => $valor8333Niif,
                    'ValorDF' => $valorOficial,
                    'AcepIPS' => $acepIPS,
                    'AcepEPS' => $acepEPS,
                    'alertaSaldoCero' => $alertaSaldoCero,
                    'alertaDivergencia' => $alertaDivergencia,
                ];
            }

            return [
                'ok' => true,
                'filas' => $filas,
                'resumenAlertas' => $resumen,
                'tiposUsuario' => $tiposUsuario,
            ];
        } catch (PDOException $e) {
            return ['ok' => false, 'error' => 'No se pudo consultar SIHOS: ' . $e->getMessage()];
        }
    }

    /**
     * Catálogo de tipos de usuario/pagador de la empresa, para poblar el
     * filtro sin necesidad de haber ejecutado ya el reporte completo.
     *
     * @return array<int|string, string> CodiTipo => NombTipo
     */
    public function listarTiposUsuario(int $empresaId): array
    {
        $repository = $this->crearRepository($empresaId);
        if ($repository === null) {
            return [];
        }

        try {
            return $repository->fetchTipoUsuarioNombres();
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Autocompletado del filtro de administradora (EPS/aseguradora): busca
     * en el catálogo CodiAdmi por código, NIT o nombre.
     *
     * @return list<array{codigo:string,nit:?string,nombre:?string}>
     */
    public function buscarAdministradoras(int $empresaId, string $termino): array
    {
        $repository = $this->crearRepository($empresaId);
        if ($repository === null) {
            return [];
        }

        try {
            $filas = $repository->buscarAdministradoras($termino);

            return array_map(
                static fn (array $f): array => [
                    'codigo' => $f['CodiAdmi'],
                    'nit' => $f['NitAdmin'],
                    'nombre' => $f['NombAdmi'],
                ],
                $filas
            );
        } catch (PDOException $e) {
            return [];
        }
    }

    private function crearRepository(int $empresaId): ?SihosExternalRepository
    {
        $configFila = $this->configRepository->findByEmpresaId($empresaId);
        if ($configFila === null || $configFila['host'] === '' || $configFila['base_datos'] === '' || $configFila['usuario'] === '') {
            return null;
        }

        $codiInst = trim((string)($configFila['codi_inst'] ?? ''));
        if ($codiInst === '') {
            return null;
        }

        return new SihosExternalRepository([
            'host' => $configFila['host'],
            'port' => (string)$configFila['puerto'],
            'database' => $configFila['base_datos'],
            'username' => $configFila['usuario'],
            'codiInst' => $codiInst,
            'password' => SihosCredentialCipher::decrypt($configFila['password_cifrado'] ?? null),
            'charset' => $configFila['charset'],
        ]);
    }

    /**
     * Índice de columna DataTables (0-based, según <th> en la vista) → clave
     * real en cada fila para ordenar/buscar. Mismo patrón que
     * AuditQueryService::ORDERABLE_COLUMNS.
     */
    private const COLUMNAS = [
        0 => 'NombTipo',
        1 => 'NombTerc',
        2 => 'NombAdmi',
        3 => 'FechDocu',
        4 => 'NumeDocu',
        5 => 'Refe',
        6 => 'Saldo',
        7 => 'Valor',
        8 => 'EnCurso',
        9 => 'Dias',
        10 => 'Valor8333',
        11 => 'Valor8333niif',
        12 => 'ValorDF',
        13 => 'AcepIPS',
        14 => 'AcepEPS',
    ];

    private const COLUMNAS_NUMERICAS = [
        'NumeDocu', 'Saldo', 'Valor', 'EnCurso', 'Dias', 'Valor8333', 'Valor8333niif', 'ValorDF', 'AcepIPS', 'AcepEPS',
    ];

    /**
     * Pagina/ordena/filtra el array ya calculado y cacheado (ver
     * SihosAuditoriaGlosaCache) para el endpoint server-side de DataTables
     * (SihosController::auditoriaGlosaDatos()) — sin volver a consultar
     * SIHOS ni recalcular EnCurso/alertas.
     *
     * @param list<array<string, mixed>> $filas
     * @param array<int, string> $columnSearches búsqueda por columna, index => texto
     * @return array{data: list<list<string>>, recordsFiltered: int}
     */
    public function paginarParaDataTable(
        array $filas,
        int $start,
        int $length,
        int $orderColIndex,
        string $orderDir,
        string $globalSearch,
        array $columnSearches
    ): array {
        $globalSearch = mb_strtolower(trim($globalSearch));
        $columnSearches = array_filter(array_map('trim', $columnSearches), static fn (string $v): bool => $v !== '');

        if ($globalSearch !== '' || $columnSearches !== []) {
            $filas = array_values(array_filter($filas, function (array $fila) use ($globalSearch, $columnSearches): bool {
                foreach ($columnSearches as $idx => $texto) {
                    $clave = self::COLUMNAS[$idx] ?? null;
                    if ($clave === null) {
                        continue;
                    }
                    if (mb_stripos($this->textoColumna($fila, $clave), $texto) === false) {
                        return false;
                    }
                }

                if ($globalSearch !== '') {
                    $haystack = mb_strtolower(implode(' ', array_map(
                        fn (string $clave): string => $this->textoColumna($fila, $clave),
                        self::COLUMNAS
                    )));
                    if (mb_stripos($haystack, $globalSearch) === false) {
                        return false;
                    }
                }

                return true;
            }));
        }

        $recordsFiltered = count($filas);

        $clave = self::COLUMNAS[$orderColIndex] ?? 'FechDocu';
        $esNumerica = in_array($clave, self::COLUMNAS_NUMERICAS, true);
        $dir = strtolower($orderDir) === 'asc' ? 1 : -1;

        usort($filas, static function (array $a, array $b) use ($clave, $esNumerica, $dir): int {
            $va = $a[$clave] ?? null;
            $vb = $b[$clave] ?? null;
            if ($esNumerica) {
                return (((float)$va) <=> ((float)$vb)) * $dir;
            }

            return strcasecmp((string)$va, (string)$vb) * $dir;
        });

        $pagina = $length > 0 ? array_slice($filas, $start, $length) : $filas;

        return [
            'data' => array_map([$this, 'formatRowForDataTable'], $pagina),
            'recordsFiltered' => $recordsFiltered,
        ];
    }

    private function textoColumna(array $fila, string $clave): string
    {
        return match ($clave) {
            'NombTerc' => (string)($fila['NombTerc'] ?? '') . ' ' . (string)($fila['NuDoTerc'] ?? ''),
            'NombAdmi' => (string)($fila['NombAdmi'] ?? $fila['CodiAdmi'] ?? ''),
            'NumeDocu' => $fila['CodiDocu'] . '-' . $fila['NumeDocu'],
            default => (string)($fila[$clave] ?? ''),
        };
    }

    /**
     * @return list<string>
     */
    private function formatRowForDataTable(array $fila): array
    {
        $alerta = $fila['alertaSaldoCero']
            ? ['style' => 'color:#c0392b;font-weight:600;', 'title' => 'Glosa en curso con factura saldada']
            : ($fila['alertaDivergencia']
                ? ['style' => 'color:#b7791f;font-weight:600;', 'title' => 'Divergencia motor vs. SIHOS']
                : null);

        $glosaTexto = htmlspecialchars($fila['CodiDocu'] . '-' . $fila['NumeDocu'], ENT_QUOTES, 'UTF-8');
        $glosaCelda = $alerta !== null
            ? '<span style="' . $alerta['style'] . '" title="' . htmlspecialchars($alerta['title'], ENT_QUOTES, 'UTF-8') . '">⚠️ ' . $glosaTexto . '</span>'
            : $glosaTexto;

        return [
            htmlspecialchars((string)($fila['NombTipo'] ?? $fila['TipoUsua'] ?? ''), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars((string)($fila['NombTerc'] ?? ''), ENT_QUOTES, 'UTF-8') . ' (' . htmlspecialchars((string)($fila['NuDoTerc'] ?? ''), ENT_QUOTES, 'UTF-8') . ')',
            htmlspecialchars((string)($fila['NombAdmi'] ?? $fila['CodiAdmi'] ?? ''), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars((string)$fila['FechDocu'], ENT_QUOTES, 'UTF-8'),
            $glosaCelda,
            htmlspecialchars((string)$fila['Refe'], ENT_QUOTES, 'UTF-8'),
            $fila['Saldo'] === null ? '—' : self::formatoMoneda($fila['Saldo']),
            self::formatoMoneda($fila['Valor']),
            self::formatoMoneda($fila['EnCurso']),
            (string)(int)$fila['Dias'],
            self::formatoMoneda($fila['Valor8333']),
            self::formatoMoneda($fila['Valor8333niif']),
            self::formatoMoneda($fila['ValorDF']),
            self::formatoMoneda($fila['AcepIPS']),
            self::formatoMoneda($fila['AcepEPS']),
        ];
    }

    public static function formatoMoneda($valor): string
    {
        return '$' . number_format((float)$valor, 0, ',', '.');
    }

    /**
     * Filas planas (sin HTML) para exportar a CSV — ver
     * SihosController::auditoriaGlosaExportar().
     *
     * @param list<array<string, mixed>> $filas
     * @return list<list<string|int|float>>
     */
    public function filasParaExportar(array $filas): array
    {
        return array_map(
            static fn (array $fila): array => [
                $fila['NombTipo'] ?? $fila['TipoUsua'] ?? '',
                trim(($fila['NombTerc'] ?? '') . ' (' . ($fila['NuDoTerc'] ?? '') . ')'),
                $fila['NombAdmi'] ?? $fila['CodiAdmi'] ?? '',
                $fila['FechDocu'],
                $fila['CodiDocu'] . '-' . $fila['NumeDocu'],
                $fila['Refe'],
                $fila['Saldo'] ?? '',
                $fila['Valor'],
                $fila['EnCurso'],
                $fila['Dias'],
                $fila['Valor8333'],
                $fila['Valor8333niif'],
                $fila['ValorDF'],
                $fila['AcepIPS'],
                $fila['AcepEPS'],
            ],
            $filas
        );
    }

    /**
     * Separa las filas con alertaSaldoCero en "exportables" (una sola
     * glosa en tránsito para su factura) y "excluidas" (más de una glosa
     * en tránsito para la misma factura) — SIHOS rechazaría la fila igual
     * (ver ValidarGlosasTransito() en importarglosas.class.php: "el
     * documento presenta más de una glosa en tránsito"), así que se
     * excluyen aquí de forma proactiva en vez de dejar que falle al
     * cargar. Base para la sección seleccionable de "concluir por CSV".
     *
     * @param list<array<string, mixed>> $filas
     * @return array{exportables: list<array<string, mixed>>, excluidas: list<array<string, mixed>>}
     */
    public function prepararConclusionSaldoCero(array $filas): array
    {
        $candidatas = array_values(array_filter(
            $filas,
            static fn (array $f): bool => $f['alertaSaldoCero']
        ));

        $porFactura = [];
        foreach ($candidatas as $fila) {
            $porFactura[$fila['Refe']][] = $fila;
        }

        $exportables = [];
        $excluidas = [];
        foreach ($porFactura as $refe => $filasFactura) {
            if (count($filasFactura) === 1) {
                $fila = $filasFactura[0];
                $fila['clave'] = $fila['CodiDocu'] . '-' . $fila['NumeDocu'];
                $exportables[] = $fila;
            } else {
                foreach ($filasFactura as $fila) {
                    $fila['clave'] = $fila['CodiDocu'] . '-' . $fila['NumeDocu'];
                    $fila['motivoExclusion'] = 'Más de una glosa en tránsito para la factura ' . $refe;
                    $excluidas[] = $fila;
                }
            }
        }

        return ['exportables' => $exportables, 'excluidas' => $excluidas];
    }

    /**
     * Filas en el formato exacto que espera "Importar Glosas → Detalle" de
     * SIHOS (7 columnas, ver clases/importarglosas.class.php GuardarPlano()
     * — modo DetaDocu): TiDoRefe, NuDoRefe, NumeOfic, FechOfic (dd/mm/aaaa),
     * ValoIPS, ValoEAPB, Observación.
     *
     * TipoCond=3 "Aceptado EAPB" (ValoIPS=0, ValoEAPB=EnCurso) — decisión
     * de negocio confirmada por el usuario 2026-09-14: estas glosas siguen
     * "en curso" solo administrativamente, la cartera real de la factura
     * ya está en $0.
     *
     * Descripción en ASCII puro a propósito: AnotGlos.ObseGlos en SIHOS es
     * collation latin1_swedish_ci y GuardarPlano() no hace ninguna
     * conversión de charset al leer el CSV — evita el riesgo de tildes/ñ
     * mal codificadas en vez de adivinar el charset exacto de esa tubería.
     *
     * @param list<array<string, mixed>> $filasSeleccionadas
     * @return list<list<int|string>>
     */
    public function filasParaConcluirCsv(array $filasSeleccionadas, string $fechaCorte): array
    {
        $fechaCorteDate = DateTime::createFromFormat('Y-m-d', $fechaCorte) ?: new DateTime();
        $numeOfic = 'SAVID' . $fechaCorteDate->format('Ymd');
        $fechOfic = $fechaCorteDate->format('d/m/Y');

        return array_map(
            static function (array $fila) use ($numeOfic, $fechOfic, $fechaCorte): array {
                $enCurso = (int)round((float)$fila['EnCurso']);
                $descripcion = sprintf(
                    'Auditoria SAVID: factura saldada (saldo cartera 0 al %s), glosa %s-%s en curso %d sin resolver formalmente.',
                    $fechaCorte,
                    $fila['CodiDocu'],
                    $fila['NumeDocu'],
                    $enCurso
                );

                return [
                    $fila['TiDoRefe'],
                    $fila['NuDoRefe'],
                    $numeOfic,
                    $fechOfic,
                    0,
                    $enCurso,
                    $descripcion,
                ];
            },
            $filasSeleccionadas
        );
    }

    private function resultadoVacio(array $tiposUsuario): array
    {
        return [
            'ok' => true,
            'filas' => [],
            'resumenAlertas' => ['saldoCero' => 0, 'divergencia' => 0],
            'tiposUsuario' => $tiposUsuario,
        ];
    }

    private function fechaValida(string $fecha): bool
    {
        $d = DateTime::createFromFormat('Y-m-d', $fecha);

        return $d !== false && $d->format('Y-m-d') === $fecha;
    }
}
