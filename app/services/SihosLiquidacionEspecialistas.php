<?php

/**
 * Acumula el reporte SIHOS "Liquidación" por especialista (profesional =
 * DetaFact.CodiMedi): identificación, producción del rango, desglose por
 * actividad, valor institucional (sala y materiales de sus cirugías) y
 * constancia de actividad (meses y días con producción). Incluye a TODOS los
 * profesionales con líneas en el rango; "Producción médica" distingue su
 * trabajo médico del resto (laboratorio, imágenes, estancia, etc.).
 */
class SihosLiquidacionEspecialistas
{
    /** @var array<string,array<string,mixed>> login => acumulado */
    private array $profesionales = [];

    /** @param list<string> $mesesRango meses (AAAA-MM) del rango del reporte, en orden */
    public function __construct(private readonly array $mesesRango = [])
    {
    }

    /**
     * @param array<string,mixed> $f línea del repository
     * @param array{0:int,1:float,2:float,3:float,4:float} $valores líneas, cantidad, EAPB, usuario, total
     */
    public function agregar(array $f, string $esp, string $actividad, string $componente, string $prod, array $valores): void
    {
        $login = (string)$f['CodiMedi'];
        $p = &$this->profesionales[$login];
        $p ??= [
            'tipoDoc' => (string)$f['TipoDocuProf'], 'cedula' => (string)$f['CedulaProf'],
            'nombre' => (string)($f['NombreProf'] ?? $login), 'registro' => (string)$f['RegiProf'],
            'especialidad' => $esp, 'activo' => (int)$f['ActivoProf'] === 1 ? 'Sí' : 'No',
            'tot' => [0, 0.0, 0.0, 0.0, 0.0], 'prodMedica' => 0.0, 'institucional' => 0.0,
            'actividades' => [], 'fechas' => [], 'meses' => [],
        ];
        foreach ($valores as $i => $v) {
            $p['tot'][$i] += $v;
        }
        $p['prodMedica'] += $prod === 'Sí' ? $valores[4] : 0.0;
        $p['institucional'] += in_array($componente, SihosLiquidacionClasificador::COMPONENTES_INSTITUCIONALES, true) ? $valores[4] : 0.0;
        $p['actividades'][$actividad][0] = ($p['actividades'][$actividad][0] ?? 0.0) + $valores[1];
        $p['actividades'][$actividad][1] = ($p['actividades'][$actividad][1] ?? 0.0) + $valores[4];
        $p['fechas'][(string)$f['FechFact']] = true;
        $mes = substr((string)$f['FechFact'], 0, 7);
        $p['meses'][$mes] ??= [0.0, 0.0];
        $p['meses'][$mes][0] += $prod === 'Sí' ? $valores[4] : 0.0;
        $p['meses'][$mes][1] += $valores[4];
    }

    /**
     * Filas ya calculadas, de mayor a menor valor total.
     *
     * @return list<array<string,mixed>>
     */
    public function filas(): array
    {
        $filas = [];
        foreach ($this->profesionales as $p) {
            $fechas = array_keys($p['fechas']);
            sort($fechas);
            $meses = count(array_unique(array_map(static fn (string $d): string => substr($d, 0, 7), $fechas)));
            $total = $p['tot'][4];
            $filas[] = [
                'tipoDoc' => $p['tipoDoc'], 'cedula' => $p['cedula'], 'nombre' => $p['nombre'], 'registro' => $p['registro'],
                'especialidad' => $p['especialidad'], 'activo' => $p['activo'],
                'lineas' => $p['tot'][0], 'cantidad' => $p['tot'][1], 'eapb' => $p['tot'][2], 'usuario' => $p['tot'][3], 'total' => $total,
                'prodMedica' => $p['prodMedica'], 'otras' => $total - $p['prodMedica'], 'institucional' => $p['institucional'],
                'actividades' => $p['actividades'], 'meses' => $p['meses'],
                'mesesActivos' => $meses, 'diasActivos' => count($fechas),
                'primera' => $fechas[0] ?? '', 'ultima' => $fechas[count($fechas) - 1] ?? '',
                'promMensual' => $meses > 0 ? $total / $meses : 0.0, 'valorPorDia' => $fechas !== [] ? $total / count($fechas) : 0.0,
            ];
        }
        usort($filas, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        return $filas;
    }

    /** @param resource $fh @param list<array<string,mixed>> $filas */
    public function escribirCsv($fh, array $filas): void
    {
        $cabecera = ['Tipo documento', 'Cédula', 'Profesional', 'Registro profesional', 'Especialidad', 'Activo en SIHOS',
            'Líneas', 'Cantidad', 'Valor EAPB', 'Valor usuario', 'Valor total', 'Valor producción médica', 'Valor otras actividades',
            'Valor institucional (sala y materiales)', 'Meses con actividad', 'Días con actividad', 'Primera fecha', 'Última fecha',
            'Promedio mensual (meses con actividad)', 'Valor por día con actividad'];
        foreach (SihosLiquidacionClasificador::ACTIVIDADES as $a) {
            array_push($cabecera, "Cantidad $a", "Valor $a");
        }
        foreach ($this->mesesRango as $m) {
            array_push($cabecera, "Producción médica $m", "Valor total $m");
        }
        SihosLiquidacionService::escribirFila($fh, $cabecera);

        $n = static fn (float $v): string => SihosLiquidacionService::num($v);
        foreach ($filas as $f) {
            $fila = [$f['tipoDoc'], $f['cedula'], $f['nombre'], $f['registro'], $f['especialidad'], $f['activo'],
                $f['lineas'], $n($f['cantidad']), $n($f['eapb']), $n($f['usuario']), $n($f['total']), $n($f['prodMedica']), $n($f['otras']),
                $n($f['institucional']), $f['mesesActivos'], $f['diasActivos'], $f['primera'], $f['ultima'],
                $n(round($f['promMensual'])), $n(round($f['valorPorDia']))];
            foreach (SihosLiquidacionClasificador::ACTIVIDADES as $a) {
                array_push($fila, $n($f['actividades'][$a][0] ?? 0.0), $n($f['actividades'][$a][1] ?? 0.0));
            }
            foreach ($this->mesesRango as $m) {
                array_push($fila, $n($f['meses'][$m][0] ?? 0.0), $n($f['meses'][$m][1] ?? 0.0));
            }
            SihosLiquidacionService::escribirFila($fh, $fila);
        }
    }
}
