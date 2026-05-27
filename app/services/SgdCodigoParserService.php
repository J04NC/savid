<?php

/**
 * Interpreta códigos del listado maestro según tipos documentales de la empresa (sin prefijos fijos).
 */
class SgdCodigoParserService
{
    /**
     * @param list<string> $tipoCodigos Ordenados de mayor a menor longitud (PD antes que P)
     * @return array{proceso: string, tipo: ?string, numero: ?string, sufijo: ?string}
     */
    public function parse(string $codigo, array $tipoCodigos): array
    {
        $codigo = strtoupper(trim($codigo));
        if ($codigo === '') {
            return ['proceso' => '', 'tipo' => null, 'numero' => null, 'sufijo' => null];
        }

        $dash = strpos($codigo, '-');
        if ($dash === false) {
            return ['proceso' => $codigo, 'tipo' => null, 'numero' => null, 'sufijo' => null];
        }

        $proceso = substr($codigo, 0, $dash);
        $tail = substr($codigo, $dash + 1);

        usort($tipoCodigos, static fn($a, $b) => strlen($b) <=> strlen($a));

        foreach ($tipoCodigos as $tipo) {
            $tipo = strtoupper($tipo);
            $pattern = '/^' . preg_quote($tipo, '/') . '(\d+)(?:-([A-Z]{1,3})(\d+))?$/i';
            if (preg_match($pattern, $tail, $m)) {
                $sufijo = null;
                if (!empty($m[2]) && !empty($m[3])) {
                    $sufijo = strtoupper($m[2]) . $m[3];
                }

                return [
                    'proceso' => $proceso,
                    'tipo' => $tipo,
                    'numero' => $m[1],
                    'sufijo' => $sufijo,
                ];
            }
        }

        return ['proceso' => $proceso, 'tipo' => null, 'numero' => null, 'sufijo' => $tail];
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?string} dependencia, serie, subserie
     */
    public function parseCodigoCarpeta(string $codigoCarpeta): array
    {
        $codigoCarpeta = trim($codigoCarpeta);
        if (!preg_match('/^(\d+)(?:\.(\d+))?(?:\.(\d+))?/', $codigoCarpeta, $m)) {
            return [null, null, null];
        }

        return [
            $m[1] ?? null,
            $m[2] ?? null,
            $m[3] ?? null,
        ];
    }

    public function excelSerialToDate($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value) && preg_match('/^\d{1,2}\/\d{1,2}\/\d{2,4}$/', trim($value))) {
            $ts = strtotime(str_replace('/', '-', $value));

            return $ts ? date('Y-m-d', $ts) : null;
        }

        $num = is_numeric($value) ? (float)$value : null;
        if ($num === null || $num < 1000) {
            return null;
        }

        $ts = (int)(($num - 25569) * 86400);

        return $ts > 0 ? date('Y-m-d', $ts) : null;
    }
}
