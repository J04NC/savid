<?php

/**
 * Paginación (DataTables server-side) y filas de exportación de las dos
 * tablas cacheadas del reporte SIHOS "Facturación" (ver SihosFacturacionService).
 */
class SihosFacturacionTabla
{
    /** Encabezados por tabla, en el orden del <thead> de la vista (clave de la fila => título). */
    public const COLUMNAS = [
        'facturas' => [
            'Documento' => 'Factura', 'FechDocu' => 'Fecha doc.', 'FechEnvi' => 'Fecha envío DIAN',
            'NombTipo' => 'Tipo usuario', 'NombTerc' => 'Tercero', 'Nit' => 'Documento tercero',
            'ValorCartera' => 'Valor cartera', 'ValoTota' => 'ValoTota', 'ValoEven' => 'ValoEven',
            'Diferencia' => 'Cartera distinta de ValoTota', 'Estado' => 'Estado envío', 'CUFE' => 'CUFE',
        ],
        'notas' => [
            'Documento' => 'Nota', 'FechDocu' => 'Fecha doc.', 'Electronico' => 'Consecutivo electrónico',
            'ConcDian' => 'Concepto DIAN', 'FechEnvi' => 'Fecha envío DIAN', 'Estado' => 'Estado envío',
            'Factura' => 'Factura afectada', 'FechFactura' => 'Fecha factura', 'NombTipo' => 'Tipo usuario',
            'NombTerc' => 'Tercero', 'Nit' => 'Documento tercero', 'ValorFactura' => 'Valor en la factura', 'CUFE' => 'CUFE',
        ],
    ];

    private const MONEDA = ['ValorCartera', 'ValoTota', 'ValoEven', 'ValorFactura'];
    /**
     * Pagina/ordena/filtra una tabla cacheada para DataTables server-side. El
     * filtro de columna que empieza por "=" es exacto; sin "=", "contiene".
     *
     * @param list<array<string, mixed>> $filas
     * @param array<int, string> $columnSearches índice de columna => texto
     * @return array{data: list<list<string>>, recordsFiltered: int}
     */
    public static function paginarParaDataTable(string $tabla, array $filas, int $start, int $length, int $orderCol, string $orderDir, string $globalSearch, array $columnSearches): array
    {
        $claves = array_keys(self::COLUMNAS[$tabla] ?? []);
        $global = mb_strtolower(trim($globalSearch));
        $columnSearches = array_filter(array_map('trim', $columnSearches), static fn (string $v): bool => $v !== '');

        if ($global !== '' || $columnSearches !== []) {
            $filas = array_values(array_filter($filas, static function (array $fila) use ($claves, $global, $columnSearches): bool {
                foreach ($columnSearches as $idx => $texto) {
                    $valor = (string)($fila[$claves[$idx] ?? ''] ?? '');
                    if (str_starts_with($texto, '=') ? strcasecmp($valor, substr($texto, 1)) !== 0 : mb_stripos($valor, $texto) === false) {
                        return false;
                    }
                }

                return $global === '' || mb_stripos(implode(' ', array_map(static fn (string $k): string => (string)($fila[$k] ?? ''), $claves)), $global) !== false;
            }));
        }

        $clave = $claves[$orderCol] ?? $claves[0];
        $dir = strtolower($orderDir) === 'asc' ? 1 : -1;
        usort($filas, static fn (array $a, array $b): int => (in_array($clave, self::MONEDA, true)
            ? $a[$clave] <=> $b[$clave]
            : strcasecmp((string)$a[$clave], (string)$b[$clave])) * $dir);

        $pagina = $length > 0 ? array_slice($filas, $start, $length) : $filas;

        return [
            'data' => array_map(static fn (array $fila): array => array_map(
                static fn (string $k): string => in_array($k, self::MONEDA, true)
                    ? '$' . number_format((float)$fila[$k], 0, ',', '.')
                    : htmlspecialchars((string)$fila[$k], ENT_QUOTES, 'UTF-8'),
                $claves
            ), $pagina),
            'recordsFiltered' => count($filas),
        ];
    }

    /**
     * Escribe el CSV completo de una tabla en $fh, igual que el "por CUPS" de
     * Liquidación: sin BOM y con la línea "sep=;" primero (Excel ignora el BOM
     * cuando está esa línea), cifras con coma decimal
     * (SihosLiquidacionService::num), textos que empiezan por = + @ protegidos
     * de fórmulas y cada línea convertida a Windows-1252 (Excel es-CO muestra
     * bien tildes y ñ). Línea a línea: un rango grande no se duplica en memoria.
     *
     * @param resource $fh
     * @param list<array<string, mixed>> $filas
     */
    public static function escribirCsv($fh, string $tabla, array $filas): void
    {
        $claves = array_keys(self::COLUMNAS[$tabla] ?? []);
        $linea = static function (array $celdas) use ($fh): void {
            $m = fopen('php://memory', 'w+');
            SihosLiquidacionService::escribirFila($m, $celdas);
            rewind($m);
            fwrite($fh, (string)iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', (string)stream_get_contents($m)));
            fclose($m);
        };

        fwrite($fh, "sep=;\r\n");
        $linea(array_values(self::COLUMNAS[$tabla] ?? []));
        foreach ($filas as $fila) {
            $linea(array_map(
                static fn (string $k) => in_array($k, self::MONEDA, true)
                    ? SihosLiquidacionService::num((float)$fila[$k])
                    : (string)($fila[$k] ?? ''),
                $claves
            ));
        }
    }
}
