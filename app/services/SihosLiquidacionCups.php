<?php

/**
 * Acumula el reporte SIHOS "Liquidación" por CUPS y nombre de actividad (procedimiento): cuántas veces se
 * liquidó y por cuánto. La cantidad se separa en producción médica, valor institucional (sala y materiales
 * de cirugía) y total, para que un mismo acto con varios componentes no infle la cantidad. Además calcula el
 * valor unitario por entidad (las N administradoras con más cantidad del rango, más "Otras"). Sin datos de paciente.
 */
class SihosLiquidacionCups
{
    /** @var array<string,array{0:string,1:string,2:string,3:int,4:array<int,float>,5:array<int,float>,6:array<int,float>}> */
    private array $filas = [];
    /** @var array<string,array<string,array<string,array{0:float,1:float,2:float,3:float}>>> fila => administradora => componente => [cantidad, valor, mín, máx] */
    private array $porEntidad = [];
    /** @var array<string,array{0:string,1:float}> código => [nombre, cantidad] */
    private array $entidades = [];

    public function __construct(private readonly int $topEntidades = 3)
    {
    }

    /** @param array{0:int,1:float,2:float,3:float,4:float} $valores líneas, cantidad, EAPB, usuario, total */
    public function agregar(string $cups, string $procedimiento, string $actividad, string $componente, string $prod, array $valores, string $codiAdmi = '', string $nombAdmi = ''): void
    {
        $clave = $cups . "\x1f" . $procedimiento . "\x1f" . $actividad;
        $f = &$this->filas[$clave];
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
        $this->entidades[$codiAdmi] ??= [$nombAdmi, 0.0];
        $this->entidades[$codiAdmi][1] += $valores[1];
        $u = $valores[1] > 0 ? $valores[4] / $valores[1] : null;
        $c = &$this->porEntidad[$clave][$codiAdmi][$componente];
        $c ??= [0.0, 0.0, INF, -INF];
        $c[0] += $valores[1];
        $c[1] += $valores[4];
        if ($u !== null) {
            $c[2] = min($c[2], $u);
            $c[3] = max($c[3], $u);
        }
    }

    /** @return list<array{0:string,1:string}> [código, nombre] de las entidades con valor unitario, de mayor a menor cantidad */
    public function entidadesTop(): array
    {
        uasort($this->entidades, static fn (array $a, array $b): int => $b[1] <=> $a[1]);
        $top = [];
        foreach (array_slice($this->entidades, 0, $this->topEntidades, true) as $codigo => [$nombre]) {
            $top[] = [(string)$codigo, $nombre];
        }

        return $top;
    }

    /**
     * Valor unitario de un acto: suma, por componente, de valor ÷ cantidad (así los componentes de una cirugía no se
     * dividen entre el número de líneas). El rango mínimo–máximo solo existe cuando hay un único componente.
     *
     * @param list<array{0:float,1:float,2:float,3:float}> $componentes
     * @return array{cantidad:float,unitario:float,min:?float,max:?float}
     */
    private static function unitario(array $componentes): array
    {
        $unitario = 0.0;
        $cantidad = 0.0;
        foreach ($componentes as [$cant, $valor]) {
            $unitario += $cant > 0 ? $valor / $cant : 0.0;
            $cantidad = max($cantidad, $cant);
        }
        $unico = count($componentes) === 1 && is_finite($componentes[0][2]);

        return ['cantidad' => $cantidad, 'unitario' => $unitario, 'min' => $unico ? $componentes[0][2] : null, 'max' => $unico ? $componentes[0][3] : null];
    }

    /**
     * Filas ya calculadas, de mayor a menor valor total. 'entidades' trae, en el orden de entidadesTop() y con "Otras" al final, el valor unitario.
     *
     * @return list<array<string,mixed>>
     */
    public function filas(): array
    {
        $codigos = array_column($this->entidadesTop(), 0);
        $salida = [];
        foreach ($this->filas as $clave => [$cups, $proc, $act, $lineas, $total, $med, $inst]) {
            $grupos = array_fill(0, count($codigos) + 1, []);
            foreach ($this->porEntidad[$clave] ?? [] as $codi => $comps) {
                $g = array_search((string)$codi, $codigos, true);
                $g = $g === false ? count($codigos) : $g;
                foreach ($comps as $comp => $c) {
                    $a = &$grupos[$g][$comp];
                    $a ??= [0.0, 0.0, INF, -INF];
                    $a[0] += $c[0];
                    $a[1] += $c[1];
                    $a[2] = min($a[2], $c[2]);
                    $a[3] = max($a[3], $c[3]);
                    unset($a);
                }
            }
            $salida[] = ['cups' => $cups, 'procedimiento' => $proc, 'actividad' => $act, 'lineas' => $lineas,
                'cantMedica' => $med[0], 'valMedica' => $med[1], 'cantInst' => $inst[0], 'valInst' => $inst[1],
                'cantidad' => $total[0], 'total' => $total[1],
                'entidades' => array_map(static fn (array $comps): array => self::unitario(array_values($comps)), $grupos)];
        }
        usort($salida, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        return $salida;
    }

    /** @param resource $fh @param list<array<string,mixed>> $filas */
    public function escribirCsv($fh, array $filas): void
    {
        $cabecera = ['CUPS', 'Procedimiento', 'Actividad', 'Líneas', 'Cantidad producción médica', 'Valor producción médica',
            'Cantidad institucional', 'Valor institucional', 'Cantidad total', 'Valor total'];
        $nombres = [...array_column($this->entidadesTop(), 1), 'Otras entidades'];
        foreach ($nombres as $n) {
            array_push($cabecera, "$n - cantidad", "$n - valor unitario", "$n - mínimo", "$n - máximo");
        }
        SihosLiquidacionService::escribirFila($fh, $cabecera);
        $n = static fn (float $v): string => SihosLiquidacionService::num($v);
        foreach ($filas as $f) {
            $fila = [$f['cups'], $f['procedimiento'], $f['actividad'], $f['lineas'],
                $n($f['cantMedica']), $n($f['valMedica']), $n($f['cantInst']), $n($f['valInst']), $n($f['cantidad']), $n($f['total'])];
            foreach ($f['entidades'] as $e) {
                array_push($fila, $n($e['cantidad']), $n(round($e['unitario'])), $e['min'] === null ? '' : $n(round($e['min'])), $e['max'] === null ? '' : $n(round($e['max'])));
            }
            SihosLiquidacionService::escribirFila($fh, $fila);
        }
    }
}
