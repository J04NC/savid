<?php

/**
 * Tabla en pantalla del reporte SIHOS "Auxiliar" en diseño horizontal: las
 * mismas filas del Excel (cuenta + movimientos) guardadas como JSON por línea
 * (gzip; el concepto, solo sus primeros 200 caracteres) al generar el reporte, y paginadas para DataTables server-side leyendo el
 * archivo línea a línea (un rango de 9 meses son ~175 mil filas: no se cargan
 * en memoria). Sin reordenar: se muestran en el orden del informe.
 *
 * Los textos largos (nombre de la cuenta, del tercero y concepto) se cortan
 * con "…" en pantalla y llevan el texto completo en el title, como las
 * celdas savid-dt-fit-text de Liquidación/Cruce/Cartera 2193.
 */
class SihosAuxiliarTabla
{
    /** Títulos de la tabla (sin las columnas de valores). */
    public const COLUMNAS = [
        'Código', 'Nombre', 'Documento', 'Número', 'Fecha', 'Documento tercero', 'Nombre tercero',
        'Centro de costo', 'Doc. ref.', 'Núm. ref.', 'Doc. ext.', 'Cheque', 'Concepto',
    ];
    /** Índices de columnas de texto largo que se cortan con "…". */
    private const LARGAS = [1, 6, 12];
    /** Caracteres del concepto que se guardan para la pantalla. */
    private const MAX_CONCEPTO = 200;

    /** @return list<string> títulos completos según si lleva columnas de saldo */
    public static function encabezado(bool $conSaldos): array
    {
        return [...self::COLUMNAS, ...($conSaldos ? ['Saldo anterior', 'Débito', 'Crédito', 'Nuevo saldo'] : ['Débito', 'Crédito'])];
    }

    /**
     * Línea JSON de una fila del Excel horizontal (tipo 'cuenta' o 'detalle'/'anulado').
     *
     * @param list<mixed> $valores
     */
    public static function linea(string $tipo, array $valores): string
    {
        $celdas = array_map(static fn ($v) => $v instanceof DateTimeInterface ? $v->format('d/m/Y') : $v, $valores);
        // El concepto promedia ~2.600 caracteres (hasta 32.767): en pantalla basta el inicio; el Excel lo trae completo.
        if (is_string($celdas[12] ?? null) && mb_strlen($celdas[12]) > self::MAX_CONCEPTO) {
            $celdas[12] = mb_substr($celdas[12], 0, self::MAX_CONCEPTO) . '…';
        }

        return json_encode([$tipo === 'cuenta' ? 'c' : ($tipo === 'anulado' ? 'a' : 'd'), $celdas], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
    }

    /**
     * Página para DataTables. Búsqueda global y por columna: "contiene", sin
     * distinguir mayúsculas; si empieza por "=", exacta.
     *
     * @param array<int,string> $porColumna índice => texto
     * @return array{data:list<list<string>>,recordsTotal:int,recordsFiltered:int}
     */
    public static function pagina(string $ruta, int $start, int $length, string $global, array $porColumna): array
    {
        $global = trim($global);
        $porColumna = array_filter(array_map('trim', $porColumna), static fn (string $v): bool => $v !== '');
        $total = 0;
        $coinciden = 0;
        $data = [];
        $fh = gzopen($ruta, 'rb');
        if ($fh === false) {
            return ['data' => [], 'recordsTotal' => 0, 'recordsFiltered' => 0];
        }
        while (($raw = gzgets($fh)) !== false) {
            $total++;
            // Filtro rápido sobre el texto crudo antes de decodificar.
            if ($global !== '' && mb_stripos($raw, $global) === false) {
                continue;
            }
            [$tipo, $celdas] = json_decode($raw, true);
            if (!self::cumple($celdas, $global, $porColumna)) {
                continue;
            }
            if ($coinciden >= $start && ($length <= 0 || count($data) < $length)) {
                $data[] = self::html($tipo, $celdas);
            }
            $coinciden++;
        }
        gzclose($fh);

        return ['data' => $data, 'recordsTotal' => $total, 'recordsFiltered' => $coinciden];
    }

    private static function cumple(array $celdas, string $global, array $porColumna): bool
    {
        foreach ($porColumna as $i => $texto) {
            $valor = self::texto($celdas[$i] ?? '');
            if (str_starts_with($texto, '=') ? strcasecmp($valor, substr($texto, 1)) !== 0 : mb_stripos($valor, $texto) === false) {
                return false;
            }
        }
        if ($global === '') {
            return true;
        }

        return mb_stripos(implode(' ', array_map([self::class, 'texto'], $celdas)), $global) !== false;
    }

    /** Valor para buscar (los números, sin formato). */
    private static function texto(mixed $v): string
    {
        return (string)$v;
    }

    /** @return list<string> celdas HTML ya escapadas */
    private static function html(string $tipo, array $celdas): array
    {
        $n = count(self::COLUMNAS);
        $out = [];
        foreach ($celdas as $i => $v) {
            if ($i >= $n) {
                $out[] = $v === '' || $v === null ? '' : '<span class="sihos-aux-num">$' . number_format((float)$v, 2, ',', '.') . '</span>';
                continue;
            }
            $t = htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
            if (in_array($i, self::LARGAS, true) && $t !== '') {
                $t = '<span class="sihos-aux-corto" title="' . $t . '">' . $t . '</span>';
            }
            $out[] = $t;
        }
        if ($tipo === 'c') {
            $out = array_map(static fn (string $c): string => $c === '' ? '' : '<strong>' . $c . '</strong>', $out);
        } elseif ($tipo === 'a') {
            $out[0] = '<span class="sihos-aux-anulado" title="Documento anulado">' . $out[0] . '</span>';
        }

        return $out;
    }
}
