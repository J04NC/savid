<?php

/**
 * Filtros del reporte SIHOS "Auxiliar" (mismos del formulario de SIHOS):
 * validación, normalización y descripción para pantalla/Excel.
 *
 * Cada filtro de código es {modo, valores}: modo 'todos' | 'igual' | 'diferente'
 * | 'entre' (2 valores, se ordenan si vienen al revés) | 'inicia' (solo cuenta).
 * 'igual'/'diferente' aceptan varios códigos separados por coma.
 */
class SihosAuxiliarFiltros
{
    public const CONTABILIDADES = ['colgaap' => 'ColGAAP', 'niif' => 'NIIF'];
    /** filtro => [etiqueta, modos permitidos] */
    public const FILTROS = [
        'sede' => ['Sede', ['todos', 'igual', 'diferente', 'entre']],
        'cuenta' => ['Cuenta / Partida', ['todos', 'igual', 'diferente', 'inicia', 'entre']],
        'tercero' => ['Tercero', ['todos', 'igual', 'diferente', 'entre']],
        'centro' => ['Centro de costos', ['todos', 'igual', 'diferente', 'entre']],
        'recurso' => ['Recursos', ['todos', 'igual', 'diferente', 'entre']],
        'documento' => ['Documento', ['todos', 'igual', 'diferente']],
    ];
    public const MODOS = ['todos' => 'Todos', 'igual' => 'Igual a', 'diferente' => 'Diferente de', 'inicia' => 'Inicia por', 'entre' => 'Entre'];
    public const OPCIONES = [
        'anulados' => 'Documentos anulados', 'administradoras' => 'Solo administradoras', 'solo_movimientos' => 'Solo movimientos',
        'solo_terceros' => 'Solo terceros', 'saldo_cuenta' => 'Saldo x cuenta',
    ];
    private const MAX_VALORES = 500;

    /**
     * @param array<string,mixed> $e entrada del formulario (GET) o filtros ya validados
     * @return array{ok:bool,error?:string,filtros?:array<string,mixed>}
     */
    public static function validar(array $e): array
    {
        $f = [
            'contabilidad' => trim((string)($e['contabilidad'] ?? '')),
            'desde' => trim((string)($e['desde'] ?? '')),
            'hasta' => trim((string)($e['hasta'] ?? '')),
            'info' => ($e['info'] ?? 'detallado') === 'resumen' ? 'resumen' : 'detallado',
            'diseno' => ($e['diseno'] ?? 'vertical') === 'horizontal' ? 'horizontal' : 'vertical',
        ];
        foreach (self::OPCIONES as $op => $_) {
            $f[$op] = !empty($e[$op]);
        }
        if (!isset(self::CONTABILIDADES[$f['contabilidad']])) {
            return ['ok' => false, 'error' => 'Elija la contabilidad (ColGAAP o NIIF).'];
        }
        $error = SihosLiquidacionService::validarRango($f['desde'], $f['hasta']);
        if ($error !== null) {
            return ['ok' => false, 'error' => $error];
        }
        if (substr($f['desde'], 0, 4) !== substr($f['hasta'], 0, 4)) {
            return ['ok' => false, 'error' => 'La fecha inicial y la final deben ser del mismo año (el saldo anterior se calcula por año, como en SIHOS).'];
        }
        if ($f['diseno'] === 'horizontal') {
            $f['solo_terceros'] = false; // En SIHOS "Solo terceros" no aplica al diseño horizontal.
        }

        foreach (self::FILTROS as $clave => [$etiqueta, $modos]) {
            $crudo = $e[$clave] ?? null;
            [$modo, $valores] = is_array($crudo)
                ? [(string)($crudo['modo'] ?? 'todos'), (array)($crudo['valores'] ?? [])]
                : [trim((string)($e[$clave . '_modo'] ?? 'todos')), self::partir((string)($e[$clave . '_valores'] ?? ''), (string)($e[$clave . '_hasta'] ?? ''))];
            if (!in_array($modo, $modos, true)) {
                return ['ok' => false, 'error' => "Condición no válida en el filtro $etiqueta."];
            }
            $valores = array_values(array_filter(array_map(static fn ($v): string => trim((string)$v), $valores), static fn (string $v): bool => $v !== ''));
            if ($modo === 'igual' || $modo === 'diferente') {
                $valores = array_values(array_unique($valores));
            }
            foreach ($valores as $v) {
                if (preg_match('/^[A-Za-z0-9._-]{1,20}$/', $v) !== 1) {
                    return ['ok' => false, 'error' => "Código no válido en el filtro $etiqueta: use solo letras, números, punto o guion."];
                }
            }
            if ($modo === 'todos') {
                $valores = [];
            } elseif ($valores === []) {
                return ['ok' => false, 'error' => "Escriba el código del filtro $etiqueta (o elija Todos)."];
            } elseif (count($valores) > self::MAX_VALORES) {
                return ['ok' => false, 'error' => "Demasiados códigos en el filtro $etiqueta (máximo " . self::MAX_VALORES . ').'];
            } elseif ($modo === 'inicia' && count($valores) !== 1) {
                return ['ok' => false, 'error' => "«Inicia por» del filtro $etiqueta admite un solo código."];
            } elseif ($modo === 'entre') {
                if (count($valores) !== 2) {
                    return ['ok' => false, 'error' => "«Entre» del filtro $etiqueta necesita el código inicial y el final."];
                }
                $numericos = ctype_digit($valores[0]) && ctype_digit($valores[1]) && $clave === 'tercero';
                $alReves = $numericos ? (int)$valores[0] > (int)$valores[1] : strcmp($valores[0], $valores[1]) > 0;
                if ($alReves) {
                    $valores = [$valores[1], $valores[0]];
                }
            }
            if ($clave === 'cuenta' && $modo !== 'todos' && !self::sonDigitos($valores)) {
                return ['ok' => false, 'error' => 'La cuenta debe tener solo dígitos.'];
            }
            $f[$clave] = ['modo' => $modo, 'valores' => $valores];
        }

        return ['ok' => true, 'filtros' => $f];
    }

    /** Texto de los filtros activos, p. ej. "Sede igual a 1 · Cuenta / Partida entre 5 y 8". */
    public static function describir(array $f): string
    {
        $partes = [];
        foreach (self::FILTROS as $clave => [$etiqueta]) {
            $c = $f[$clave] ?? ['modo' => 'todos', 'valores' => []];
            if ($c['modo'] === 'todos') {
                continue;
            }
            $valores = $c['modo'] === 'entre' ? $c['valores'][0] . ' y ' . $c['valores'][1] : implode(', ', $c['valores']);
            $partes[] = $etiqueta . ' ' . mb_strtolower(self::MODOS[$c['modo']]) . ' ' . $valores;
        }
        foreach (['anulados', 'administradoras'] as $op) {
            if (!empty($f[$op])) {
                $partes[] = self::OPCIONES[$op];
            }
        }

        return $partes === [] ? 'Sin filtros' : implode(' · ', $partes);
    }

    /** @return list<string> */
    private static function partir(string $valores, string $hasta): array
    {
        $lista = preg_split('/[\s,;]+/', $valores) ?: [];
        if (trim($hasta) !== '') {
            $lista[] = $hasta;
        }

        return $lista;
    }

    private static function sonDigitos(array $valores): bool
    {
        foreach ($valores as $v) {
            if (!ctype_digit($v)) {
                return false;
            }
        }

        return true;
    }
}
