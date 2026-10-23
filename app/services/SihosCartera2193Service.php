<?php

/**
 * Reporte SIHOS "2193 Cartera": cartera por edades por deudor (administradora)
 * y tipo de usuario, con facturación radicada por edad, pendiente de radicar,
 * glosa en curso y giro directo. Reemplaza al cartera.php de SIHOS
 * (imprinfo2193.php, CartEdad=1) calculando lo mismo por lotes — ver
 * SihosCartera2193Repository.
 *
 * Fidelidad a SIHOS: las reglas se copian del original, incluidas sus
 * rarezas, para que las cifras coincidan y cualquier diferencia con la
 * normativa se vea y se corrija en SIHOS, no aquí:
 *  - de las anotaciones de glosa solo cuenta la ÚLTIMA glosa de la factura
 *    (glosasencurso() sobrescribe en vez de acumular), aunque el valor de
 *    glosa sí suma todas;
 *  - el giro directo se suma por tercero en cada tipo de usuario 2/90/91
 *    en que aparece (cuenta doble en el gran total).
 */
class SihosCartera2193Service
{
    /** Documentos de factura: factura de venta (28), de contado (59) y recibo de responsabilidad de glosa (70). */
    private const DOCU_APLI_FACTURA = [28, 59, 70];
    private const DOCU_APLI_RGL = [70];
    /** Glosa en curso (GLO). */
    private const DOCU_APLI_GLOSA = [48];
    /** Recibos de caja/giro que usa la consulta de giro directo de SIHOS. */
    private const DOCU_APLI_RECIBO_GIRO = [1, 66, 60, 65, 67, 71, 79];
    /** Tipos de usuario con columna "Giro Directo" (subsidiado, SOAT, ADRES). */
    private const TIPOS_CON_GIRO = ['2', '90', '91'];
    /** Facturas que se resuelven juntas (saldos, radicación y glosas) antes de acumular. */
    private const FACTURAS_POR_LOTE = 20000;

    private SihosEmpresaConfigRepository $configRepository;

    public function __construct()
    {
        $this->configRepository = new SihosEmpresaConfigRepository();
    }

    /**
     * Tipos de usuario disponibles para el filtro (CodiTipo => NombTipo).
     *
     * @return array{ok:bool,error?:string,tipos?:array<string,string>}
     */
    public function listarTiposUsuario(int $empresaId): array
    {
        $conexion = $this->abrirRepositorio($empresaId);
        if (isset($conexion['error'])) {
            return ['ok' => false, 'error' => $conexion['error']];
        }

        try {
            $tipos = $conexion['repo']->fetchTiposUsuario();
        } catch (PDOException $e) {
            error_log('SihosCartera2193Service: ' . $e->getMessage());

            return ['ok' => false, 'error' => 'No se pudo consultar SIHOS. Verifique la conexión e intente de nuevo.'];
        }

        return ['ok' => true, 'tipos' => array_map(static fn (array $t): string => $t['NombTipo'], $tipos)];
    }

    /**
     * @param string[] $tiposUsuario CodiTipo elegidos; vacío = todos
     * @return array<string,mixed>
     */
    public function buildReporte(int $empresaId, string $fechaCorte, array $tiposUsuario, ?string $nit = null): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaCorte)) {
            return ['ok' => false, 'error' => 'La fecha de corte es inválida.'];
        }

        $conexion = $this->abrirRepositorio($empresaId);
        if (isset($conexion['error'])) {
            return ['ok' => false, 'error' => $conexion['error']];
        }

        try {
            return $this->calcular($conexion['repo'], $fechaCorte, $tiposUsuario, $nit);
        } catch (PDOException $e) {
            error_log('SihosCartera2193Service: ' . $e->getMessage());

            return ['ok' => false, 'error' => 'No se pudo consultar SIHOS. Verifique la conexión e intente de nuevo.'];
        }
    }

    /** @return array{repo?:SihosCartera2193Repository,error?:string} */
    private function abrirRepositorio(int $empresaId): array
    {
        $configFila = $this->configRepository->findByEmpresaId($empresaId);
        if ($configFila === null || $configFila['host'] === '' || $configFila['base_datos'] === '' || $configFila['usuario'] === '') {
            return ['error' => 'Esta empresa no tiene conexión a SIHOS configurada.'];
        }

        $codiInst = trim((string)($configFila['codi_inst'] ?? ''));
        if ($codiInst === '') {
            return ['error' => 'Falta configurar el CodiInst de esta empresa en Conexión SIHOS.'];
        }

        $externo = new SihosExternalRepository([
            'host' => $configFila['host'],
            'port' => (string)$configFila['puerto'],
            'database' => $configFila['base_datos'],
            'username' => $configFila['usuario'],
            'codiInst' => $codiInst,
            'password' => SihosCredentialCipher::decrypt($configFila['password_cifrado'] ?? null),
            'charset' => $configFila['charset'],
        ]);

        return ['repo' => new SihosCartera2193Repository($externo->connect(), $codiInst)];
    }

    /**
     * @param string[] $tiposUsuario
     * @return array<string,mixed>
     */
    private function calcular(SihosCartera2193Repository $repo, string $fechaCorte, array $tiposUsuario, ?string $nit): array
    {
        $catalogo = $repo->fetchTiposUsuario();
        $elegidos = $tiposUsuario === [] ? array_keys($catalogo) : array_values(array_intersect($tiposUsuario, array_keys($catalogo)));
        if ($elegidos === []) {
            return ['ok' => false, 'error' => 'Elija al menos un tipo de usuario válido.'];
        }

        $codigosFactura = $repo->resolveCodigosPorAplicacion(self::DOCU_APLI_FACTURA);
        if ($codigosFactura === []) {
            return ['ok' => false, 'error' => 'Esta institución no tiene tipos de documento de factura configurados en SIHOS.'];
        }
        $codigosRgl = $repo->resolveCodigosPorAplicacion(self::DOCU_APLI_RGL);

        $contexto = [
            'catalogo' => $catalogo,
            'codigosRgl' => $codigosRgl,
            'fechaCorte' => $fechaCorte,
            'excluidas' => $repo->fetchCuentasExcluidasSaldo(),
            'dificilCobro' => $repo->fetchCuentaDificilCobro(),
            'prefijosRadicacion' => $this->prefijosRadicacion($catalogo),
            'codigosGlosa' => $repo->resolveCodigosPorAplicacion(self::DOCU_APLI_GLOSA),
            'limites' => $this->limitesEdad($fechaCorte),
        ];

        // Las facturas llegan fila a fila y se procesan por lotes: en memoria solo queda el acumulado por deudor.
        $acumulados = [];
        $totalFacturas = 0;
        $lote = [];
        foreach ($repo->fetchFacturas($codigosFactura, $fechaCorte, $elegidos, $nit) as $factura) {
            $lote[] = $factura;
            if (count($lote) >= self::FACTURAS_POR_LOTE) {
                $this->procesarLote($repo, $lote, $contexto, $acumulados);
                $totalFacturas += count($lote);
                $lote = [];
            }
        }
        if ($lote !== []) {
            $this->procesarLote($repo, $lote, $contexto, $acumulados);
            $totalFacturas += count($lote);
        }

        $terceros = $tercerosConGiro = [];
        foreach (array_keys($acumulados) as $llave) {
            [$tiDoTerc, $nuDoTerc, $tipoUsua] = explode('|', $llave, 3);
            $terceros[] = [$tiDoTerc, $nuDoTerc];
            if (in_array($tipoUsua, self::TIPOS_CON_GIRO, true)) {
                $tercerosConGiro[] = [$tiDoTerc, $nuDoTerc];
            }
        }
        $giros = $repo->fetchGiroDirectoPorTercero($repo->resolveCodigosPorAplicacion(self::DOCU_APLI_RECIBO_GIRO), $tercerosConGiro, $fechaCorte);
        $nombres = $repo->fetchNombresTerceros($terceros);

        return ['ok' => true, 'facturas' => $totalFacturas] + $this->armarTipos($acumulados, $catalogo, $elegidos, $giros, $nombres);
    }

    /**
     * Una sección por tipo de usuario con sus deudores y totales, más el gran
     * total. Como el original: una fila solo se muestra si tiene saldo
     * radicado, pendiente, glosa o giro, pero los totales suman todas.
     *
     * @param array<string, array{rad:list<float>,pend:list<float>,glos:list<float>}> $acumulados
     * @param array<string, array{NombTipo:string}> $catalogo
     * @param string[] $elegidos
     * @param array<string,float> $giros
     * @param array<string,string> $nombres
     * @return array{tipos:list<array<string,mixed>>,granTotal:array<string,mixed>}
     */
    private function armarTipos(array $acumulados, array $catalogo, array $elegidos, array $giros, array $nombres): array
    {
        ksort($acumulados);
        $gran = $this->totalesVacios();
        $tipos = [];

        foreach ($elegidos as $codiTipo) {
            $conGiro = in_array((string)$codiTipo, self::TIPOS_CON_GIRO, true);
            $filas = [];
            $totales = $this->totalesVacios();

            foreach ($acumulados as $llave => $a) {
                [$tiDoTerc, $nuDoTerc, $tipoUsua] = explode('|', $llave, 3);
                if ($tipoUsua !== (string)$codiTipo) {
                    continue;
                }
                $giro = $conGiro ? ($giros[$tiDoTerc . '|' . $nuDoTerc] ?? 0.0) : 0.0;
                $fila = [
                    'TiDoTerc' => $tiDoTerc,
                    'NuDoTerc' => $nuDoTerc,
                    'NombTerc' => $nombres[$tiDoTerc . '|' . $nuDoTerc] ?? '',
                    'v' => $a['rad'],
                    'total' => array_sum($a['rad']),
                    'pend' => array_sum($a['pend']),
                    'glosa' => array_sum($a['glos']),
                    'giro' => $giro,
                ];
                $this->sumarFila($totales, $fila);
                $this->sumarFila($gran, $fila);
                if ($fila['total'] > 0 || $fila['pend'] > 0 || $fila['giro'] > 0 || $fila['glosa'] > 0) {
                    $filas[] = $fila;
                }
            }

            if ($filas !== []) {
                $tipos[] = ['CodiTipo' => (string)$codiTipo, 'NombTipo' => $catalogo[(string)$codiTipo]['NombTipo'], 'conGiro' => $conGiro, 'filas' => $filas, 'totales' => $totales];
            }
        }

        return ['tipos' => $tipos, 'granTotal' => $gran];
    }

    /** @return array{v:list<float>,total:float,pend:float,glosa:float,giro:float} */
    private function totalesVacios(): array
    {
        return ['v' => array_fill(0, 5, 0.0), 'total' => 0.0, 'pend' => 0.0, 'glosa' => 0.0, 'giro' => 0.0];
    }

    /**
     * @param array{v:list<float>,total:float,pend:float,glosa:float,giro:float} $totales
     * @param array<string,mixed> $fila
     */
    private function sumarFila(array &$totales, array $fila): void
    {
        foreach ($fila['v'] as $i => $valor) {
            $totales['v'][$i] += $valor;
        }
        foreach (['total', 'pend', 'glosa', 'giro'] as $campo) {
            $totales[$campo] += $fila[$campo];
        }
    }

    public static function formatoMoneda(float $valor): string
    {
        return '$' . number_format($valor, 0, ',', '.');
    }

    /**
     * Calcula saldo, radicación, glosa y edad de un lote de facturas y lo
     * suma al acumulado por deudor y tipo de usuario.
     *
     * @param list<array<string,mixed>> $facturas
     * @param array<string,mixed> $c contexto fijo del reporte (ver calcular())
     * @param array<string, array{rad:list<float>,pend:list<float>,glos:list<float>}> $acumulados
     */
    private function procesarLote(SihosCartera2193Repository $repo, array $facturas, array $c, array &$acumulados): void
    {
        $numerosPorDocumento = [];
        foreach ($facturas as $f) {
            $numerosPorDocumento[$f['CodiDocu']][] = (string)$f['NumeDocu'];
        }

        $saldos = $repo->fetchSaldosPorFactura($numerosPorDocumento, $c['fechaCorte'], $c['excluidas']);
        $cuentasDebito = $repo->fetchCuentasDebitoPorFactura($numerosPorDocumento, $c['fechaCorte'], $c['prefijosRadicacion'], $c['dificilCobro']);
        $glosas = $repo->fetchGlosasPorFactura($c['codigosGlosa'], $numerosPorDocumento, $c['fechaCorte']);
        $numerosPorGlosa = [];
        foreach ($glosas as $lista) {
            foreach ($lista as $g) {
                $numerosPorGlosa[$g['CodiDocu']][] = $g['NumeDocu'];
            }
        }
        $anotaciones = $repo->fetchAnotacionesGlosa($numerosPorGlosa, $c['fechaCorte']);

        foreach ($facturas as $f) {
            $tipo = $c['catalogo'][(string)$f['TipoUsua']];
            $clave = $f['CodiDocu'] . '|' . $f['NumeDocu'];
            $esRgl = in_array($f['CodiDocu'], $c['codigosRgl'], true);

            $saldo = $this->saldoFactura($saldos[$clave . '|' . $f['TiDoTerc'] . '|' . $f['NuDoTerc']] ?? [], $tipo['CodiCont'], $esRgl);
            $radicada = $this->estaRadicada($cuentasDebito[$clave] ?? [], $tipo, $c['dificilCobro']);
            $glosa = ($radicada && $saldo < 0) ? 0.0 : $this->glosaEnCurso($glosas[$clave] ?? [], $anotaciones);
            $edad = $this->edad((string)$f['FechEdad'], $c['limites']);

            $llave = $f['TiDoTerc'] . '|' . $f['NuDoTerc'] . '|' . $f['TipoUsua'];
            $acumulados[$llave] ??= ['rad' => array_fill(0, 5, 0.0), 'pend' => array_fill(0, 5, 0.0), 'glos' => array_fill(0, 5, 0.0)];
            $acumulados[$llave][$radicada ? 'rad' : 'pend'][$edad] += $saldo;
            $acumulados[$llave]['glos'][$edad] += $glosa;
        }
    }

    /**
     * Prefijos (hasta 5 dígitos) de las cuentas de radicación de todos los
     * tipos de usuario: CodiRadi, o la cuenta de cartera si no tiene.
     *
     * @param array<string, array{NombTipo:string,CodiCont:string,CodiRadi:string}> $catalogo
     * @return string[]
     */
    private function prefijosRadicacion(array $catalogo): array
    {
        $prefijos = [];
        foreach ($catalogo as $tipo) {
            $prefijos[] = $this->prefijoRadicacion($tipo);
        }

        return array_values(array_unique($prefijos));
    }

    /** @param array{CodiCont:string,CodiRadi:string} $tipo */
    private function prefijoRadicacion(array $tipo): string
    {
        $radi = substr($tipo['CodiRadi'], 0, 5);

        return $radi === '' ? $tipo['CodiCont'] : $radi;
    }

    /**
     * Saldo de la factura (ConsultaSaldosConta): cuentas que empiezan por los
     * 2 primeros dígitos de la cuenta del tipo de usuario; para el recibo de
     * responsabilidad de glosa (DocuApli 70) la clase 2, en valor absoluto.
     *
     * @param array<string, float> $porPrefijo prefijo de 2 dígitos => suma
     */
    private function saldoFactura(array $porPrefijo, string $cuentaTipo, bool $esRgl): float
    {
        $prefijo = $esRgl ? '2' : substr($cuentaTipo, 0, 2);
        $saldo = 0.0;
        foreach ($porPrefijo as $cuenta => $valor) {
            if (str_starts_with((string)$cuenta, $prefijo)) {
                $saldo += $valor;
            }
        }
        $saldo = round($saldo, 2);

        return $esRgl ? abs($saldo) : $saldo;
    }

    /**
     * FacturaRadicada: hay un débito de la factura en una cuenta de
     * radicación distinta a la de cartera del tipo, o directo en difícil cobro.
     *
     * @param string[] $cuentas
     * @param array{CodiCont:string,CodiRadi:string} $tipo
     */
    private function estaRadicada(array $cuentas, array $tipo, string $dificilCobro): bool
    {
        $radi = $this->prefijoRadicacion($tipo);
        foreach ($cuentas as $cuenta) {
            if ($cuenta !== $tipo['CodiCont'] && str_starts_with($cuenta, $radi)) {
                return true;
            }
            if ($dificilCobro !== '' && $cuenta === $dificilCobro) {
                return true;
            }
        }

        return false;
    }

    /**
     * glosasencurso()['CursMenosAcep']: suma de las glosas menos lo aceptado
     * (IPS + EAPB) de la ÚLTIMA glosa — ver la nota de fidelidad de la clase.
     *
     * @param list<array{CodiDocu:string,NumeDocu:string,ValoTota:float}> $glosas
     * @param array<string, array{AcepConc:float,RechConc:float,IpsCond:float,EapbCond:float}> $anotaciones
     */
    private function glosaEnCurso(array $glosas, array $anotaciones): float
    {
        if ($glosas === []) {
            return 0.0;
        }

        $total = 0.0;
        foreach ($glosas as $g) {
            $total += round($g['ValoTota'], 2);
        }
        $ultima = end($glosas);
        $a = $anotaciones[$ultima['CodiDocu'] . '|' . $ultima['NumeDocu']] ?? ['AcepConc' => 0.0, 'RechConc' => 0.0, 'IpsCond' => 0.0, 'EapbCond' => 0.0];

        return round($total - ($a['IpsCond'] + $a['AcepConc']) - ($a['EapbCond'] + $a['RechConc']), 2);
    }

    /**
     * Fechas (YYYYMMDD) que separan las 5 edades: 0-60, 61-90, 91-180,
     * 181-360 y más de 360 días, contadas hacia atrás desde el corte.
     *
     * @return array<string,int>
     */
    private function limitesEdad(string $fechaCorte): array
    {
        $corte = new DateTimeImmutable($fechaCorte);
        $atras = static fn (int $dias): int => (int)$corte->modify("-{$dias} day")->format('Ymd');

        return ['f59' => $atras(59), 'f60' => $atras(60), 'f89' => $atras(89), 'f90' => $atras(90), 'f179' => $atras(179), 'f180' => $atras(180), 'f359' => $atras(359)];
    }

    /**
     * Índice de la edad (0 a 4) de una fecha; una fecha vacía o 0000-00-00
     * cae en "más de 360", como en el original.
     *
     * @param array<string,int> $l
     */
    private function edad(string $fecha, array $l): int
    {
        $f = (int)str_replace('-', '', $fecha);
        if ($f >= $l['f59']) {
            return 0;
        }
        if ($f >= $l['f89'] && $f <= $l['f60']) {
            return 1;
        }
        if ($f >= $l['f179'] && $f <= $l['f90']) {
            return 2;
        }
        if ($f >= $l['f359'] && $f <= $l['f180']) {
            return 3;
        }

        return 4;
    }
}
