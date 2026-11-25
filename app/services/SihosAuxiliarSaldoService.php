<?php

/**
 * Saldo anterior del reporte SIHOS "Auxiliar", con la misma regla de SIHOS
 * (función SaldoAnterior de imprauxiliar.php) y una corrección:
 *
 *  - Si el mes de inicio ya está cerrado: saldo de cierre (SaldDebe - SaldCred)
 *    del mes anterior. SIHOS no sumaba los movimientos del 1 del mes al día
 *    anterior al inicio, así que un informe desde el 15 quedaba sin los
 *    movimientos del 1 al 14. Aquí sí se suman (corregido a pedido del usuario).
 *  - Si no está cerrado: saldo de apertura (SaldDeAn - SaldCrAn) del último mes
 *    cerrado + los movimientos desde el 1 de ese mes hasta el día anterior al inicio.
 *  - NIIF en enero del año de apertura (CodiInst.AnnoNIIF): apertura de enero.
 *  - Sin ningún cierre contable: saldo 0 (igual que SIHOS).
 *
 * El saldo es el total de la cuenta (o cuenta+tercero), sin los filtros del
 * informe, como en SIHOS. Por tercero solo se calcula si tiene tipo y número.
 */
class SihosAuxiliarSaldoService
{
    /**
     * @param array{ano:int,mes:int}|null $cierre último mes cerrado
     * @return array{ano:int,mes:int,apertura:bool,movDesde:string,movHasta:string}|null
     */
    public static function plan(string $contab, string $desde, ?array $cierre, ?int $annoNiif): ?array
    {
        if ($cierre === null) {
            return null;
        }
        $inicio = new DateTimeImmutable($desde);
        $ano = (int)$inicio->format('Y');
        $mes = (int)$inicio->format('n');

        if ($contab === 'niif' && $annoNiif === $ano && $mes === 1) {
            return ['ano' => $ano, 'mes' => 1, 'apertura' => true, 'movDesde' => $ano . '-01-01', 'movHasta' => $desde];
        }
        if ($cierre['ano'] > $ano || ($cierre['ano'] === $ano && $cierre['mes'] >= $mes)) {
            [$baseAno, $baseMes] = $mes === 1 ? [$ano - 1, 12] : [$ano, $mes - 1];

            return ['ano' => $baseAno, 'mes' => $baseMes, 'apertura' => false, 'movDesde' => $inicio->format('Y-m-01'), 'movHasta' => $desde];
        }

        return [
            'ano' => $cierre['ano'], 'mes' => $cierre['mes'], 'apertura' => true,
            'movDesde' => sprintf('%04d-%02d-01', $cierre['ano'], $cierre['mes']), 'movHasta' => $desde,
        ];
    }

    /**
     * @param list<string> $cuentas cuentas con movimiento en el informe
     * @return array{cuentas:array<string,float>,terceros:array<string,array<string,float>>}
     */
    public function calcular(SihosAuxiliarSaldoRepository $repo, string $contab, string $desde, array $cuentas, bool $porTercero): array
    {
        $out = ['cuentas' => [], 'terceros' => []];
        $plan = self::plan($contab, $desde, $repo->ultimoCierre(), $contab === 'niif' ? $repo->annoNiif() : null);
        if ($plan === null || $cuentas === []) {
            return $out;
        }

        $out['cuentas'] = $repo->saldoBaseCuentas($contab, $plan['ano'], $plan['mes'], $plan['apertura'], $cuentas);
        $movs = $repo->movimientos($contab, $plan['movDesde'], $plan['movHasta'], $cuentas);
        foreach ($movs['cuentas'] as $c => $v) {
            $out['cuentas'][$c] = ($out['cuentas'][$c] ?? 0.0) + $v;
        }

        if ($porTercero) {
            $out['terceros'] = $repo->saldoBaseTerceros($contab, $plan['ano'], $plan['mes'], $plan['apertura'], $cuentas);
            foreach ($movs['terceros'] as $c => $porTerc) {
                foreach ($porTerc as $k => $v) {
                    $out['terceros'][$c][$k] = ($out['terceros'][$c][$k] ?? 0.0) + $v;
                }
            }
        }

        return $out;
    }

    /** Saldo anterior de un tercero en una cuenta; 0 si el tercero no tiene tipo y número (como SIHOS). */
    public static function deTercero(array $saldos, string $cuenta, string $tiDo, string $nuDo): float
    {
        if ($tiDo === '' || $nuDo === '') {
            return 0.0;
        }

        return round($saldos['terceros'][$cuenta][$tiDo . "\t" . $nuDo] ?? 0.0, 2);
    }
}
