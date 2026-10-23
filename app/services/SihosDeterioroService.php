<?php

/**
 * Reporte SIHOS "Cartera — Deterioro": valor aplicado por los documentos de
 * deterioro de cartera sobre cada factura, visto de tres maneras que salen
 * de UNA sola consulta a SIHOS (SihosExternalRepository::fetchDeterioroCartera):
 * agrupado por tipo de usuario, agrupado por entidad (tercero) y el detalle
 * factura por factura. El detalle se cachea (SihosAuditoriaGlosaCache con
 * prefijo 'deterioro') para paginarlo del lado del servidor — puede pasar
 * de 30.000 filas.
 *
 * Nada de CodiDocu literal ('DCA', 'FE', ...): cada institución de SIHOS
 * puede llamarlos distinto, así que se resuelven por DocuApli, el catálogo
 * fijo de "rol" del documento (verificado en MaesDocu de las dos
 * instalaciones configuradas).
 */
class SihosDeterioroService
{
    /** "DETERIORO DE LA CARTERA" (DCA). */
    private const DOCU_APLI_DETERIORO = [108];

    /**
     * Documentos que el deterioro puede afectar: facturas de venta (28:
     * FE/FV/FVA/FVC), facturas de contado de otra instalación (59: FVC) y
     * "Recibo responsabilidad de glosa" (70: RGl). Se exige ManeCont=1 para
     * descartar tipos que comparten DocuApli pero no son contables (p. ej.
     * RCE "Relación de cobro", DocuApli=28 con ManeCont=0).
     */
    private const DOCU_APLI_LIQUIDACION = [28, 59, 70];

    /** Columnas de la tabla de detalle, en el orden del <thead> de la vista. */
    private const COLUMNAS_DETALLE = [
        0 => 'NombTipo',
        1 => 'FechDocu',
        2 => 'Documento',
        3 => 'Factura',
        4 => 'NombTerc',
        5 => 'NuDoTerc',
        6 => 'Valor',
    ];

    private SihosEmpresaConfigRepository $configRepository;

    public function __construct()
    {
        $this->configRepository = new SihosEmpresaConfigRepository();
    }

    /**
     * @return array{ok:bool,error?:string,filas?:list<array<string,mixed>>,porTipoUsuario?:list<array<string,mixed>>,porEntidad?:list<array<string,mixed>>,total?:float,codigos?:array<string,list<string>>}
     */
    public function buildReporte(int $empresaId, string $fechaCorte): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaCorte)) {
            return ['ok' => false, 'error' => 'La fecha de corte es inválida.'];
        }

        $configFila = $this->configRepository->findByEmpresaId($empresaId);
        if ($configFila === null || $configFila['host'] === '' || $configFila['base_datos'] === '' || $configFila['usuario'] === '') {
            return ['ok' => false, 'error' => 'Esta empresa no tiene conexión a SIHOS configurada.'];
        }

        $codiInst = trim((string)($configFila['codi_inst'] ?? ''));
        if ($codiInst === '') {
            return ['ok' => false, 'error' => 'Falta configurar el CodiInst de esta empresa en Conexión SIHOS.'];
        }

        $repository = new SihosExternalRepository([
            'host' => $configFila['host'],
            'port' => (string)$configFila['puerto'],
            'database' => $configFila['base_datos'],
            'username' => $configFila['usuario'],
            'codiInst' => $codiInst,
            'password' => SihosCredentialCipher::decrypt($configFila['password_cifrado'] ?? null),
            'charset' => $configFila['charset'],
        ]);

        try {
            $codigosDeterioro = $repository->resolveCodigosDocumentoPorAplicacionContable(self::DOCU_APLI_DETERIORO);
            $codigosLiquidacion = $repository->resolveCodigosDocumentoPorAplicacionContable(self::DOCU_APLI_LIQUIDACION);

            if ($codigosDeterioro === []) {
                return ['ok' => false, 'error' => 'Esta institución no tiene configurado en SIHOS el tipo de documento "Deterioro de la cartera" (DocuApli=108).'];
            }

            $filas = $repository->fetchDeterioroCartera($codigosDeterioro, $codigosLiquidacion, $fechaCorte);
        } catch (PDOException $e) {
            error_log('SihosDeterioroService: ' . $e->getMessage());

            return ['ok' => false, 'error' => 'No se pudo consultar SIHOS. Verifique la conexión e intente de nuevo.'];
        }

        $total = 0.0;
        foreach ($filas as &$fila) {
            $fila['Documento'] = $fila['CodiDocu'] . '-' . $fila['NumeDocu'];
            $fila['Factura'] = $fila['CodiLiqu'] . '-' . $fila['NumeLiqu'];
            $total += $fila['Valor'];
        }
        unset($fila);

        return [
            'ok' => true,
            'filas' => $filas,
            'porTipoUsuario' => $this->agruparPorTipoUsuario($filas),
            'porEntidad' => $this->agruparPorEntidad($filas),
            'total' => $total,
            'codigos' => ['deterioro' => $codigosDeterioro, 'liquidacion' => $codigosLiquidacion],
        ];
    }

    /**
     * @param list<array<string, mixed>> $filas
     * @return list<array{TipoUsua:string,NombTipo:string,facturas:int,Valor:float}>
     */
    private function agruparPorTipoUsuario(array $filas): array
    {
        $grupos = [];
        foreach ($filas as $fila) {
            $clave = (string)$fila['TipoUsua'];
            $grupos[$clave] ??= ['TipoUsua' => $clave, 'NombTipo' => (string)$fila['NombTipo'], 'facturas' => 0, 'Valor' => 0.0];
            $grupos[$clave]['facturas']++;
            $grupos[$clave]['Valor'] += $fila['Valor'];
        }

        return array_values($grupos);
    }

    /**
     * @param list<array<string, mixed>> $filas
     * @return list<array{TiDoTerc:string,NuDoTerc:string,NombTerc:string,facturas:int,Valor:float}>
     */
    private function agruparPorEntidad(array $filas): array
    {
        $grupos = [];
        foreach ($filas as $fila) {
            $clave = $fila['TiDoTerc'] . '|' . $fila['NuDoTerc'];
            $grupos[$clave] ??= [
                'TiDoTerc' => (string)$fila['TiDoTerc'],
                'NuDoTerc' => (string)$fila['NuDoTerc'],
                'NombTerc' => (string)$fila['NombTerc'],
                'facturas' => 0,
                'Valor' => 0.0,
            ];
            $grupos[$clave]['facturas']++;
            $grupos[$clave]['Valor'] += $fila['Valor'];
        }

        return array_values($grupos);
    }

    /**
     * Pagina/ordena/filtra el detalle cacheado para DataTables server-side
     * (SihosController::deterioroDatos()). El filtro de columna que empieza
     * por "=" es una coincidencia exacta (lo usan los clics en las tablas
     * resumen); sin "=", es "contiene".
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
                    $clave = self::COLUMNAS_DETALLE[$idx] ?? null;
                    if ($clave === null) {
                        continue;
                    }
                    $valor = (string)($fila[$clave] ?? '');
                    if (str_starts_with($texto, '=')) {
                        if (strcasecmp($valor, substr($texto, 1)) !== 0) {
                            return false;
                        }
                    } elseif (mb_stripos($valor, $texto) === false) {
                        return false;
                    }
                }

                if ($globalSearch !== '') {
                    $haystack = mb_strtolower(implode(' ', array_map(
                        static fn (string $clave): string => (string)($fila[$clave] ?? ''),
                        self::COLUMNAS_DETALLE
                    )));
                    if (mb_stripos($haystack, $globalSearch) === false) {
                        return false;
                    }
                }

                return true;
            }));
        }

        $recordsFiltered = count($filas);

        $clave = self::COLUMNAS_DETALLE[$orderColIndex] ?? 'NombTipo';
        $dir = strtolower($orderDir) === 'asc' ? 1 : -1;

        usort($filas, static function (array $a, array $b) use ($clave, $dir): int {
            if ($clave === 'Valor') {
                return ($a['Valor'] <=> $b['Valor']) * $dir;
            }

            return strcasecmp((string)($a[$clave] ?? ''), (string)($b[$clave] ?? '')) * $dir;
        });

        $pagina = $length > 0 ? array_slice($filas, $start, $length) : $filas;

        return [
            'data' => array_map(static fn (array $fila): array => [
                htmlspecialchars((string)$fila['NombTipo'], ENT_QUOTES, 'UTF-8'),
                htmlspecialchars((string)$fila['FechDocu'], ENT_QUOTES, 'UTF-8'),
                htmlspecialchars((string)$fila['Documento'], ENT_QUOTES, 'UTF-8'),
                htmlspecialchars((string)$fila['Factura'], ENT_QUOTES, 'UTF-8'),
                htmlspecialchars((string)$fila['NombTerc'], ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($fila['TiDoTerc'] . ' ' . $fila['NuDoTerc'], ENT_QUOTES, 'UTF-8'),
                self::formatoMoneda($fila['Valor']),
            ], $pagina),
            'recordsFiltered' => $recordsFiltered,
        ];
    }

    public static function formatoMoneda(float $valor): string
    {
        return '$' . number_format($valor, 0, ',', '.');
    }

    /**
     * Filas planas (sin HTML) del detalle completo para el CSV.
     *
     * @param list<array<string, mixed>> $filas
     * @return list<list<string|float>>
     */
    public function filasParaExportar(array $filas): array
    {
        return array_map(
            static fn (array $fila): array => [
                $fila['NombTipo'], $fila['FechDocu'], $fila['Documento'], $fila['Factura'],
                $fila['NombTerc'], $fila['TiDoTerc'], $fila['NuDoTerc'], $fila['Valor'],
            ],
            $filas
        );
    }
}
