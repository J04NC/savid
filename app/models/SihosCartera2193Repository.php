<?php

/**
 * Consultas (solo lectura, sesión READ ONLY) del reporte SIHOS "2193 Cartera"
 * — cartera por edades por deudor y tipo de usuario, equivalente al
 * modulos/contabilidad/cartera.php de SIHOS pero resuelto por lotes: el
 * original hace entre 10 y 15 consultas POR FACTURA (cientos de miles de
 * consultas para una EPS grande); aquí son unas pocas por lote de
 * facturas, agrupadas por documento y con IN(...) de NumeDocu.
 *
 * Las reglas de cada consulta replican la función de SIHOS que
 * reemplazan (se cita en cada método): el reporte debe dar las mismas
 * cifras que SIHOS, no "corregirlas". Recibe el PDO ya conectado de
 * SihosExternalRepository::connect().
 */
class SihosCartera2193Repository
{
    /** Máximo de NumeDocu por IN(...) — mantiene cada consulta liviana. */
    private const LOTE = 800;

    public function __construct(private readonly PDO $pdo, private readonly string $codiInst)
    {
    }

    /**
     * Tipos de usuario de la institución (TipoUsua), con la cuenta de
     * cartera y la de radicación que usan FacturaRadicada/ConsultaSaldosConta.
     *
     * @return array<string, array{NombTipo:string,CodiCont:string,CodiRadi:string}> CodiTipo => datos
     */
    public function fetchTiposUsuario(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT CodiTipo, NombTipo, CodiCont, CodiRadi FROM TipoUsua WHERE CodiInst = ? ORDER BY CodiTipo'
        );
        $stmt->execute([$this->codiInst]);

        $tipos = [];
        foreach ($stmt->fetchAll() as $fila) {
            $tipos[(string)$fila['CodiTipo']] = [
                'NombTipo' => (string)$fila['NombTipo'],
                'CodiCont' => (string)($fila['CodiCont'] ?? ''),
                'CodiRadi' => (string)($fila['CodiRadi'] ?? ''),
            ];
        }

        return $tipos;
    }

    /**
     * CodiDocu por DocuApli — igual que consultarCodiDocu() de SIHOS: sin
     * exigir ManeCont (a diferencia de SihosExternalRepository), porque
     * el reporte original no lo exige.
     *
     * @param int[] $docuAplis
     * @return string[]
     */
    public function resolveCodigosPorAplicacion(array $docuAplis): array
    {
        $ph = implode(',', array_fill(0, count($docuAplis), '?'));
        $stmt = $this->pdo->prepare("SELECT DISTINCT CodiDocu FROM MaesDocu WHERE DocuApli IN ({$ph}) AND CodiInst = ?");
        $stmt->execute([...$docuAplis, $this->codiInst]);

        return array_column($stmt->fetchAll(), 'CodiDocu');
    }

    /**
     * Facturas con cartera al corte (sqlC de cartera.php): causadas, no
     * anuladas, de una administradora (JOIN CodiAdmi). Las radicadas hasta
     * el corte se edadizan por FechRadi; las demás por FechDocu.
     *
     * @param string[] $codigosFactura
     * @param string[] $tiposUsuario CodiTipo elegidos
     * @return list<array{CodiDocu:string,NumeDocu:string,TiDoTerc:string,NuDoTerc:string,TipoUsua:string,FechEdad:string}>
     */
    public function fetchFacturas(array $codigosFactura, string $fechaCorte, array $tiposUsuario, ?string $nit): array
    {
        $phDocu = implode(',', array_fill(0, count($codigosFactura), '?'));
        $phTipo = implode(',', array_fill(0, count($tiposUsuario), '?'));
        $params = [$fechaCorte, $this->codiInst, ...$codigosFactura, ...$tiposUsuario];
        $critNit = '';
        if ($nit !== null) {
            $critNit = ' AND EC.NuDoTerc = ?';
            $params[] = $nit;
        }
        array_push($params, $fechaCorte, $fechaCorte, $fechaCorte);

        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT EC.CodiDocu, EC.NumeDocu, EC.TiDoTerc, EC.NuDoTerc, EC.TipoUsua,
                    IF(EC.FechRadi <= ?, EC.FechRadi, EC.FechDocu) AS FechEdad
             FROM EncaCont EC
             INNER JOIN CodiAdmi A ON EC.TiDoTerc = A.TiDoTerc AND EC.NuDoTerc = A.NitAdmin
             WHERE EC.CodiInst = ? AND EC.CodiDocu IN ({$phDocu})
               AND EC.Causado = '1' AND EC.Anulado <> '1' AND EC.TipoComp <> '9' AND EC.CodiAdmi <> ''
               AND EC.TipoUsua IN ({$phTipo}){$critNit}
               AND (EC.FechRadi <= ? OR (EC.FechDocu <= ? AND (EC.FechRadi IS NULL OR EC.FechRadi > ?)))"
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Cuentas que ConsultaSaldosConta() excluye del saldo: las de impuestos
     * (TablImpu, de la institución) y las de ReteCREE.
     *
     * @return string[]
     */
    public function fetchCuentasExcluidasSaldo(): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT CodiCont FROM TablImpu WHERE CodiInst = ? AND CodiCont <> ''
             UNION SELECT CodiCont FROM ReteCREE WHERE CodiCont <> ''"
        );
        $stmt->execute([$this->codiInst]);

        return array_column($stmt->fetchAll(), 'CodiCont');
    }

    /**
     * Saldo por factura (ConsultaSaldosConta): suma de DetaCont que
     * referencia la factura, de documentos causados no anulados hasta el
     * corte, sin las cuentas excluidas. Se agrupa por tercero y por los 2
     * primeros dígitos de la cuenta; el service elige el prefijo que
     * corresponde al tipo de usuario de cada factura (o '2' para RGl).
     *
     * @param array<string, list<string>> $numerosPorDocumento CodiDocu => NumeDocu[]
     * @param string[] $cuentasExcluidas
     * @return array<string, array<string, float>> "doc|num|tiTerc|nuTerc" => [prefijo2 => saldo]
     */
    public function fetchSaldosPorFactura(array $numerosPorDocumento, string $fechaCorte, array $cuentasExcluidas): array
    {
        $critExcluidas = '';
        if ($cuentasExcluidas !== []) {
            $critExcluidas = ' AND d.CodiCont NOT IN (' . implode(',', array_fill(0, count($cuentasExcluidas), '?')) . ')';
        }

        $saldos = [];
        foreach ($this->lotes($numerosPorDocumento) as [$codiDocu, $numeros]) {
            $ph = implode(',', array_fill(0, count($numeros), '?'));
            $stmt = $this->pdo->prepare(
                "SELECT d.TiDoRefe, d.NuDoRefe, d.TiDoTerc, d.NuDoTerc, LEFT(d.CodiCont, 2) AS Prefijo, SUM(d.Valor) AS Saldo
                 FROM EncaCont e
                 INNER JOIN DetaCont d ON d.CodiInst = e.CodiInst AND d.Periodo = e.CodiAno
                    AND d.CodiDocu = e.CodiDocu AND d.NumeDocu = e.NumeDocu
                 WHERE d.CodiInst = ? AND d.TiDoRefe = ? AND d.NuDoRefe IN ({$ph}){$critExcluidas}
                   AND e.Causado = 1 AND e.Anulado <> 1 AND e.FechDocu <= ?
                 GROUP BY d.TiDoRefe, d.NuDoRefe, d.TiDoTerc, d.NuDoTerc, LEFT(d.CodiCont, 2)"
            );
            $stmt->execute([$this->codiInst, $codiDocu, ...$numeros, ...$cuentasExcluidas, $fechaCorte]);

            foreach ($stmt->fetchAll() as $fila) {
                $clave = $fila['TiDoRefe'] . '|' . $fila['NuDoRefe'] . '|' . $fila['TiDoTerc'] . '|' . $fila['NuDoTerc'];
                $saldos[$clave][(string)$fila['Prefijo']] = (float)$fila['Saldo'];
            }
        }

        return $saldos;
    }

    /** Cuenta de difícil cobro de la institución (CodiInst.CuenDiCo), '' si no tiene. */
    public function fetchCuentaDificilCobro(): string
    {
        $stmt = $this->pdo->prepare('SELECT CuenDiCo FROM CodiInst WHERE CodiInst = ?');
        $stmt->execute([$this->codiInst]);

        return (string)($stmt->fetchColumn() ?: '');
    }

    /**
     * Cuentas en las que una factura tiene un débito referenciado hasta el
     * corte (FacturaRadicada): el service decide si alguna es de
     * radicación o de difícil cobro. Solo trae las cuentas que importan
     * (las que empiezan por un prefijo de radicación o igual a la de
     * difícil cobro) para no devolver toda la contabilidad de la factura.
     *
     * @param array<string, list<string>> $numerosPorDocumento CodiDocu => NumeDocu[]
     * @param string[] $prefijosRadicacion prefijos LIKE (hasta 5 dígitos)
     * @return array<string, list<string>> "doc|num" => cuentas
     */
    public function fetchCuentasDebitoPorFactura(array $numerosPorDocumento, string $fechaCorte, array $prefijosRadicacion, string $cuentaDificilCobro): array
    {
        $criterios = array_fill(0, count($prefijosRadicacion), 'd.CodiCont LIKE ?');
        $params = array_map(static fn (string $p): string => $p . '%', $prefijosRadicacion);
        if ($cuentaDificilCobro !== '') {
            $criterios[] = 'd.CodiCont = ?';
            $params[] = $cuentaDificilCobro;
        }
        if ($criterios === []) {
            return [];
        }
        $critCuentas = implode(' OR ', $criterios);

        $cuentas = [];
        foreach ($this->lotes($numerosPorDocumento) as [$codiDocu, $numeros]) {
            $ph = implode(',', array_fill(0, count($numeros), '?'));
            $stmt = $this->pdo->prepare(
                "SELECT DISTINCT d.TiDoRefe, d.NuDoRefe, d.CodiCont
                 FROM EncaCont e
                 INNER JOIN DetaCont d ON e.CodiInst = d.CodiInst AND e.CodiAno = d.Periodo
                    AND e.CodiDocu = d.CodiDocu AND e.NumeDocu = d.NumeDocu
                 INNER JOIN MaesDocu m ON e.CodiInst = m.CodiInst AND e.CodiDocu = m.CodiDocu
                 WHERE e.CodiInst = ? AND e.Causado = 1 AND e.Anulado <> 1 AND d.TiDoRefe = ?
                   AND d.NuDoRefe IN ({$ph}) AND d.Valor > 0 AND e.FechDocu <= ? AND ({$critCuentas})"
            );
            $stmt->execute([$this->codiInst, $codiDocu, ...$numeros, $fechaCorte, ...$params]);

            foreach ($stmt->fetchAll() as $fila) {
                $cuentas[$fila['TiDoRefe'] . '|' . $fila['NuDoRefe']][] = (string)$fila['CodiCont'];
            }
        }

        return $cuentas;
    }

    /**
     * Glosas en curso de cada factura (glosasencurso): documentos de glosa
     * causados no anulados hasta el corte que referencian la factura,
     * ordenados por CodiDocu/NumeDocu (SIHOS no ordena; el service usa la
     * última, como el original).
     *
     * @param string[] $codigosGlosa
     * @param array<string, list<string>> $numerosPorDocumento CodiDocu => NumeDocu[]
     * @return array<string, list<array{CodiDocu:string,NumeDocu:string,ValoTota:float}>> "doc|num" => glosas
     */
    public function fetchGlosasPorFactura(array $codigosGlosa, array $numerosPorDocumento, string $fechaCorte): array
    {
        if ($codigosGlosa === []) {
            return [];
        }
        $phGlosa = implode(',', array_fill(0, count($codigosGlosa), '?'));

        $glosas = [];
        foreach ($this->lotes($numerosPorDocumento) as [$codiDocu, $numeros]) {
            $ph = implode(',', array_fill(0, count($numeros), '?'));
            $stmt = $this->pdo->prepare(
                "SELECT e.TiDoRefe, e.NuDoRefe, e.CodiDocu, e.NumeDocu, e.ValoTota
                 FROM EncaCont e
                 WHERE e.CodiInst = ? AND e.CodiDocu IN ({$phGlosa}) AND e.TiDoRefe = ? AND e.NuDoRefe IN ({$ph})
                   AND e.Causado = 1 AND e.Anulado <> 1 AND e.FechDocu <= ?
                 ORDER BY e.CodiDocu, e.NumeDocu"
            );
            $stmt->execute([$this->codiInst, ...$codigosGlosa, $codiDocu, ...$numeros, $fechaCorte]);

            foreach ($stmt->fetchAll() as $fila) {
                $glosas[$fila['TiDoRefe'] . '|' . $fila['NuDoRefe']][] = [
                    'CodiDocu' => (string)$fila['CodiDocu'],
                    'NumeDocu' => (string)$fila['NumeDocu'],
                    'ValoTota' => (float)$fila['ValoTota'],
                ];
            }
        }

        return $glosas;
    }

    /**
     * Anotaciones de cada glosa (AnotGlos hasta el corte), ya sumadas por
     * condición como las 3 consultas de glosasencurso.
     *
     * @param array<string, list<string>> $numerosPorGlosa CodiDocu de glosa => NumeGlos[]
     * @return array<string, array{AcepConc:float,RechConc:float,IpsCond:float,EapbCond:float}> "doc|num" => sumas
     */
    public function fetchAnotacionesGlosa(array $numerosPorGlosa, string $fechaCorte): array
    {
        $anotaciones = [];
        foreach ($this->lotes($numerosPorGlosa) as [$codiDocu, $numeros]) {
            $ph = implode(',', array_fill(0, count($numeros), '?'));
            $stmt = $this->pdo->prepare(
                "SELECT CodiDocu, NumeGlos,
                        SUM(IF(TipoCond = 1, ValoAcep, 0)) AS AcepConc,
                        SUM(IF(TipoCond = 1, ValoRech, 0)) AS RechConc,
                        SUM(IF(TipoDeta = 1 AND TipoCond = 2, ValoAcep, 0)) AS IpsCond,
                        SUM(IF(TipoDeta = 2 AND TipoCond = 2, ValoAcep, 0)) AS EapbCond
                 FROM AnotGlos
                 WHERE CodiInst = ? AND CodiDocu = ? AND NumeGlos IN ({$ph})
                   AND Causado = 1 AND Anulado != 1 AND FechAnot <= ?
                 GROUP BY CodiDocu, NumeGlos"
            );
            $stmt->execute([$this->codiInst, $codiDocu, ...$numeros, $fechaCorte]);

            foreach ($stmt->fetchAll() as $fila) {
                $anotaciones[$fila['CodiDocu'] . '|' . $fila['NumeGlos']] = [
                    'AcepConc' => (float)$fila['AcepConc'],
                    'RechConc' => (float)$fila['RechConc'],
                    'IpsCond' => (float)$fila['IpsCond'],
                    'EapbCond' => (float)$fila['EapbCond'],
                ];
            }
        }

        return $anotaciones;
    }

    /**
     * Parte {CodiDocu => NumeDocu[]} en lotes de LOTE números.
     *
     * @param array<string, list<string>> $numerosPorDocumento
     * @return iterable<array{0:string,1:list<string>}>
     */
    private function lotes(array $numerosPorDocumento): iterable
    {
        foreach ($numerosPorDocumento as $codiDocu => $numeros) {
            foreach (array_chunk($numeros, self::LOTE) as $lote) {
                yield [(string)$codiDocu, $lote];
            }
        }
    }
}
