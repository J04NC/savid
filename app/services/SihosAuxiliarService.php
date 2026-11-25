<?php

/**
 * Reporte SIHOS "Auxiliar" (réplica de modulos/contabilidad/imprauxiliar.php):
 * débitos, créditos y saldos por cuenta y tercero, con el detalle de cada
 * movimiento, de ColGAAP (DetaCont) o NIIF (DetaNIIF). Solo lectura.
 *
 * Sin las consultas por línea/cuenta/tercero de SIHOS: 1) totales por cuenta y
 * tercero en una consulta, 2) saldos en lote, 3) nombres precargados, 4) detalle
 * sin buffer escrito en streaming. Devuelve el resumen por cuenta para la pantalla.
 */
class SihosAuxiliarService
{
    private SihosEmpresaConfigRepository $configRepository;

    public function __construct()
    {
        $this->configRepository = new SihosEmpresaConfigRepository();
    }

    /**
     * @param array<string,mixed> $entrada filtros (ver SihosAuxiliarFiltros::validar)
     * @param callable(string,int):void|null $progreso fase y filas escritas
     * @return array<string,mixed>
     */
    public function generar(int $empresaId, array $entrada, string $rutaXlsx, ?callable $progreso = null): array
    {
        $v = SihosAuxiliarFiltros::validar($entrada);
        if (!$v['ok']) {
            return ['ok' => false, 'error' => $v['error']];
        }
        $f = $v['filtros'];
        $pdo = $this->conectar($empresaId, $codiInst);
        if ($pdo === null) {
            return ['ok' => false, 'error' => 'Esta empresa no tiene conexión a SIHOS configurada (o falta el CodiInst).'];
        }
        $avisar = static function (string $fase, int $filas = 0) use ($progreso): void {
            if ($progreso !== null) {
                $progreso($fase, $filas);
            }
        };

        $movs = new SihosAuxiliarRepository($pdo, $codiInst);
        $saldoRepo = new SihosAuxiliarSaldoRepository($pdo, $codiInst);
        $cat = new SihosAuxiliarCatalogoRepository($pdo, $codiInst);
        $terceroEncabezado = $saldoRepo->parametroBuscPaci() === '8';

        $avisar('Calculando débitos y créditos');
        $cuentas = $this->agrupar($movs->agregarMovimientos($f, $terceroEncabezado));
        $nivel = ($f['info'] === 'resumen' || $f['saldo_cuenta']) ? 'cuenta' : ($f['solo_terceros'] ? 'tercero' : 'detalle');
        $conTerceros = $f['diseno'] === 'vertical' && $nivel !== 'cuenta';

        $saldos = ['cuentas' => [], 'terceros' => []];
        if (!$f['solo_movimientos']) {
            $avisar('Calculando saldos anteriores');
            $saldos = (new SihosAuxiliarSaldoService())->calcular($saldoRepo, $f['contabilidad'], $f['desde'], array_map('strval', array_keys($cuentas)), $conTerceros);
        }

        $avisar('Cargando nombres');
        $ano = (int)substr($f['desde'], 0, 4);
        $nombresCuenta = $cat->nombresCuentas($f['contabilidad'], $ano);
        $terceros = $nivel === 'cuenta' ? ['actual' => [], 'historial' => []] : $cat->terceros($this->numerosTercero($cuentas));
        $ctx = $nivel === 'detalle' ? [
            'documentos' => $cat->documentos(), 'centros' => $cat->centrosCosto(), 'empleados' => $cat->empleados(), 'terceros' => $terceros,
        ] : [];

        $nombreContab = SihosAuxiliarFiltros::CONTABILIDADES[$f['contabilidad']];
        $fmt = static fn (string $d): string => (new DateTimeImmutable($d))->format('d/m/Y');
        $excel = new SihosAuxiliarExcel(
            $rutaXlsx, $f, 'INFORME AUXILIAR — ' . $nombreContab,
            'Desde ' . $fmt($f['desde']) . ' hasta ' . $fmt($f['hasta']) . ' · ' . SihosAuxiliarFiltros::describir($f)
        );

        $resumen = [];
        $tot = [0.0, 0.0, 0.0, 0.0];
        $escribirCuenta = function (string $c) use ($cuentas, $saldos, $nombresCuenta, $excel, &$resumen, &$tot): void {
            $salAnt = round($saldos['cuentas'][$c] ?? 0.0, 2);
            $db = $cuentas[$c]['db'];
            $cr = $cuentas[$c]['cr'];
            $salNue = $salAnt + $db - $cr;
            $excel->cuenta($c, $nombresCuenta[$c] ?? '', $salAnt, $db, $cr, $salNue);
            $resumen[] = [$c, $nombresCuenta[$c] ?? '', $salAnt, round($db, 2), round($cr, 2), round($salNue, 2)];
            $tot = [$tot[0] + $salAnt, $tot[1] + $db, $tot[2] + $cr, $tot[3] + $salNue];
        };
        $escribirTercero = function (string $c, string $tiDo, string $nuDo) use ($cuentas, $saldos, $terceros, $f, $excel): void {
            $t = $cuentas[$c]['terceros'][$tiDo . "\t" . $nuDo] ?? ['db' => 0.0, 'cr' => 0.0];
            $salAnt = SihosAuxiliarSaldoService::deTercero($saldos, $c, $tiDo, $nuDo);
            $doc = $nuDo !== '' ? $tiDo . ' - ' . $nuDo : '';
            $excel->tercero($doc, $nuDo !== '' ? SihosAuxiliarDetalle::nombreTercero($terceros, $tiDo, $nuDo, $f['hasta']) : '', $salAnt, $t['db'], $t['cr'], $salAnt + $t['db'] - $t['cr']);
        };

        $lineas = 0;
        if ($nivel !== 'detalle') {
            foreach ($cuentas as $c => $datos) {
                $escribirCuenta((string)$c);
                if ($conTerceros) {
                    foreach ($datos['terceros'] as $t) {
                        $escribirTercero((string)$c, $t['tiDo'], $t['nuDo']);
                    }
                }
            }
        } else {
            $avisar('Escribiendo el Excel');
            $actual = null;
            $terActual = null;
            $vertical = $f['diseno'] === 'vertical';
            $lineas = $movs->recorrerDetalle($f, $terceroEncabezado, function (array $d) use (
                &$actual, &$terActual, &$lineas, $vertical, $escribirCuenta, $escribirTercero, $excel, $ctx, $nombresCuenta, $avisar
            ): void {
                $c = (string)$d['Cuenta'];
                $tiDo = trim((string)$d['TiDo']);
                $nuDo = trim((string)$d['NuDo']);
                if ($c !== $actual) {
                    $actual = $c;
                    $terActual = null;
                    $escribirCuenta($c);
                }
                if ($vertical && $tiDo . "\t" . $nuDo !== $terActual) {
                    $terActual = $tiDo . "\t" . $nuDo;
                    $escribirTercero($c, $tiDo, $nuDo);
                }
                $datos = $vertical ? SihosAuxiliarDetalle::vertical($d, $ctx) : SihosAuxiliarDetalle::horizontal($d, $c, $nombresCuenta[$c] ?? '', $tiDo, $nuDo, $ctx);
                $excel->detalle($datos, (float)$d['Valor'], (string)$d['Anulado'] === '1');
                if ((++$lineas % 2000) === 0) {
                    $avisar('Escribiendo el Excel', $lineas);
                }
            });
        }
        $excel->total($tot[0], $tot[1], $tot[2], $tot[3]);
        $hojas = $excel->cerrar();

        return [
            'ok' => true, 'contabilidad' => $nombreContab, 'desde' => $f['desde'], 'hasta' => $f['hasta'],
            'filtros' => SihosAuxiliarFiltros::describir($f), 'conSaldos' => !$f['solo_movimientos'],
            'cuentas' => $resumen, 'totales' => array_map(static fn (float $x): float => round($x, 2), $tot), 'lineas' => $lineas, 'hojas' => $hojas,
        ];
    }

    /** @return array<string,string> catálogos para las listas de ayuda del formulario (vacío si no hay conexión) */
    public function catalogos(int $empresaId): array
    {
        $pdo = $this->conectar($empresaId, $codiInst);
        if ($pdo === null) {
            return [];
        }
        $cat = new SihosAuxiliarCatalogoRepository($pdo, $codiInst);
        $docs = array_map(static fn (array $d): string => $d['nombre'], $cat->documentos());
        ksort($docs);

        return ['sede' => $cat->sedes(), 'centro' => $cat->centrosCosto(), 'recurso' => $cat->recursos(), 'documento' => $docs];
    }

    /**
     * @param list<array<string,mixed>> $filas salida de agregarMovimientos (ordenada)
     * @return array<string,array{db:float,cr:float,terceros:array<string,array{tiDo:string,nuDo:string,db:float,cr:float}>}>
     */
    private function agrupar(array $filas): array
    {
        $out = [];
        foreach ($filas as $r) {
            $c = (string)$r['Cuenta'];
            $k = trim((string)$r['TiDo']) . "\t" . trim((string)$r['NuDo']);
            $out[$c] ??= ['db' => 0.0, 'cr' => 0.0, 'terceros' => []];
            $out[$c]['db'] += (float)$r['DB'];
            $out[$c]['cr'] += (float)$r['CR'];
            $out[$c]['terceros'][$k] = ['tiDo' => trim((string)$r['TiDo']), 'nuDo' => trim((string)$r['NuDo']), 'db' => (float)$r['DB'], 'cr' => (float)$r['CR']];
        }

        return $out;
    }

    /** @return list<string> */
    private function numerosTercero(array $cuentas): array
    {
        $n = [];
        foreach ($cuentas as $datos) {
            foreach ($datos['terceros'] as $t) {
                if ($t['nuDo'] !== '') {
                    $n[$t['nuDo']] = true;
                }
            }
        }

        return array_map('strval', array_keys($n));
    }

    private function conectar(int $empresaId, ?string &$codiInst): ?PDO
    {
        $cfg = $this->configRepository->findByEmpresaId($empresaId);
        $codiInst = trim((string)($cfg['codi_inst'] ?? ''));
        if ($cfg === null || $codiInst === '' || $cfg['host'] === '' || $cfg['base_datos'] === '' || $cfg['usuario'] === '') {
            return null;
        }

        return SihosRepositoryFactory::buildRead($cfg, $codiInst)->connect();
    }
}
