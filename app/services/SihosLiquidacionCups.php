<?php

/**
 * Acumula el reporte SIHOS "Liquidación" por CUPS y nombre de actividad (procedimiento): cuántas veces se
 * liquidó y por cuánto. La cantidad se separa en producción médica, valor institucional (sala y materiales
 * de cirugía) y total, para que un mismo acto con varios componentes no infle la cantidad. Sin datos de paciente.
 */
class SihosLiquidacionCups
{
    /** @var array<string,array{0:string,1:string,2:string,3:int,4:array<int,float>,5:array<int,float>,6:array<int,float>}> */
    private array $filas = [];

    /** @param array{0:int,1:float,2:float,3:float,4:float} $valores líneas, cantidad, EAPB, usuario, total */
    public function agregar(string $cups, string $procedimiento, string $actividad, string $componente, string $prod, array $valores): void
    {
        $f = &$this->filas[$cups . "\x1f" . $procedimiento . "\x1f" . $actividad];
        $f ??= [$cups, $procedimiento, $actividad, 0, [0.0, 0.0], [0.0, 0.0], [0.0, 0.0]];
        $f[3]++;
        $f[4][0] += $valores[1];
        $f[4][1] += $valores[4];
        if ($prod === 'Sí') {
            $f[5][0] += $valores[1];
            $f[5][1] += $valores[4];
        }
        if (in_array($componente, SihosLiquidacionClasificador::COMPONENTES_INSTITUCIONALES, true)) {
            $f[6][0] += $valores[1];
            $f[6][1] += $valores[4];
        }
    }

    /**
     * Filas ya calculadas, de mayor a menor valor total.
     *
     * @return list<array<string,mixed>>
     */
    public function filas(): array
    {
        $salida = [];
        foreach ($this->filas as [$cups, $proc, $act, $lineas, $total, $med, $inst]) {
            $salida[] = ['cups' => $cups, 'procedimiento' => $proc, 'actividad' => $act, 'lineas' => $lineas,
                'cantMedica' => $med[0], 'valMedica' => $med[1], 'cantInst' => $inst[0], 'valInst' => $inst[1],
                'cantidad' => $total[0], 'total' => $total[1]];
        }
        usort($salida, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        return $salida;
    }

    /** @param resource $fh @param list<array<string,mixed>> $filas */
    public function escribirCsv($fh, array $filas): void
    {
        SihosLiquidacionService::escribirFila($fh, ['CUPS', 'Procedimiento', 'Actividad', 'Líneas', 'Cantidad producción médica', 'Valor producción médica',
            'Cantidad institucional', 'Valor institucional', 'Cantidad total', 'Valor total']);
        $n = static fn (float $v): string => SihosLiquidacionService::num($v);
        foreach ($filas as $f) {
            SihosLiquidacionService::escribirFila($fh, [$f['cups'], $f['procedimiento'], $f['actividad'], $f['lineas'],
                $n($f['cantMedica']), $n($f['valMedica']), $n($f['cantInst']), $n($f['valInst']), $n($f['cantidad']), $n($f['total'])]);
        }
    }
}
