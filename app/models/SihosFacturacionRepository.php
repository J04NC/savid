<?php

/**
 * Consultas (solo lectura, sesión READ ONLY) del reporte SIHOS "Facturación":
 * facturas y notas que las afectan (notas contables de facturación y glosas
 * aceptadas) de un rango de FechDocu, para cruzar contra la DIAN.
 *
 * Fuente: EncaCont + DetaCont. FactElec entra solo para mostrar el estado de
 * envío electrónico (LEFT JOIN / mapa): lo causado que nunca se envió debe
 * aparecer igual. El valor por factura sale de las líneas de DetaCont con
 * cuenta de cartera (prefijo de TipoUsua.CodiCont: 13, 14... según la
 * institución), nunca de un '13%' fijo.
 *
 * Recibe el PDO ya conectado de SihosExternalRepository::connect(). */
class SihosFacturacionRepository
{
    public function __construct(private readonly PDO $pdo, private readonly string $codiInst)
    {
    }

    /**
     * Una fila por factura + tercero de la línea de cartera. FactElec se une
     * por su índice sin CodiAno (año de digitación, no del documento).
     *
     * @param string[] $codigosFactura
     * @param string[] $prefijosCartera
     * @param string[] $tipos CodiTipo de TipoUsua (vacío = todos)
     * @return list<array<string, mixed>>
     */
    public function fetchFacturas(array $codigosFactura, array $prefijosCartera, string $desde, string $hasta, array $tipos, string $nit): array
    {
        if ($codigosFactura === [] || $prefijosCartera === []) {
            return [];
        }

        [$likeCartera, $paramsCartera] = $this->condicionCartera($prefijosCartera);
        $params = [$this->codiInst, ...$codigosFactura, $desde, $hasta, ...$paramsCartera];
        $filtros = $this->filtrosTipoYTercero($tipos, $nit, 'e.TipoUsua', $params);

        $stmt = $this->pdo->prepare(
            "SELECT e.CodiDocu, e.NumeDocu, e.FechDocu, e.TipoUsua, t.NombTipo,
                    d.TiDoTerc, d.NuDoTerc, MAX(c.NombTerc) AS NombTerc,
                    SUM(d.Valor) AS ValorCartera, MAX(e.ValoTota) AS ValoTota, MAX(e.ValoEven) AS ValoEven,
                    MAX(f.EstaFactElec) AS EstaFactElec, MAX(f.FechEnvi) AS FechEnvi, MAX(f.CUFE) AS CUFE
             FROM EncaCont e
             INNER JOIN DetaCont d
                ON d.CodiInst = e.CodiInst AND d.CodiDocu = e.CodiDocu AND d.NumeDocu = e.NumeDocu
             LEFT JOIN FactElec f
                ON f.CodiInst = e.CodiInst AND f.CodiDocu = e.CodiDocu AND f.NumeDocu = e.NumeDocu
             LEFT JOIN TipoUsua t ON t.CodiInst = e.CodiInst AND t.CodiTipo = e.TipoUsua
             LEFT JOIN CodiTerc c ON c.TipoDocu = d.TiDoTerc AND c.NumeTerc = d.NuDoTerc
             WHERE e.CodiInst = ? AND e.CodiDocu IN (" . $this->ph($codigosFactura) . ")
               AND e.Causado = 1 AND e.Anulado <> 1
               AND e.FechDocu BETWEEN ? AND ?
               AND ({$likeCartera}){$filtros}
             GROUP BY e.CodiDocu, e.NumeDocu, d.TiDoTerc, d.NuDoTerc"
        );
        $stmt->execute($params);

        return $this->normalizar($stmt->fetchAll(), ['ValorCartera', 'ValoTota', 'ValoEven']);
    }

    /**
     * Una fila por nota + factura afectada + tercero de la línea. La factura
     * afectada sale de DetaCont.TiDoRefe/NuDoRefe (la cabecera de la nota no
     * la lleva: una nota puede afectar muchas facturas). Los filtros de tipo
     * de usuario y tercero se aplican a la FACTURA afectada / línea, y la
     * fecha a la nota. Valor = valor absoluto de la cartera movida en esa
     * factura.
     *
     * @param string[] $codigosNota
     * @param string[] $codigosFactura
     * @param string[] $prefijosCartera
     * @param string[] $tipos
     * @return list<array<string, mixed>>
     */
    public function fetchNotas(array $codigosNota, array $codigosFactura, array $prefijosCartera, string $desde, string $hasta, array $tipos, string $nit): array
    {
        if ($codigosNota === [] || $codigosFactura === [] || $prefijosCartera === []) {
            return [];
        }

        [$likeCartera, $paramsCartera] = $this->condicionCartera($prefijosCartera);
        $params = [$this->codiInst, ...$codigosNota, $desde, $hasta, ...$paramsCartera, ...$codigosFactura];
        $filtros = $this->filtrosTipoYTercero($tipos, $nit, 'fa.TipoUsua', $params);

        $stmt = $this->pdo->prepare(
            "SELECT n.CodiDocu, n.NumeDocu, n.FechDocu, d.TiDoRefe, d.NuDoRefe,
                    fa.FechDocu AS FechFactura, fa.TipoUsua, t.NombTipo,
                    d.TiDoTerc, d.NuDoTerc, MAX(c.NombTerc) AS NombTerc,
                    ABS(SUM(d.Valor)) AS ValorFactura
             FROM EncaCont n
             INNER JOIN DetaCont d
                ON d.CodiInst = n.CodiInst AND d.CodiDocu = n.CodiDocu AND d.NumeDocu = n.NumeDocu
             INNER JOIN EncaCont fa
                ON fa.CodiInst = d.CodiInst AND fa.CodiDocu = d.TiDoRefe AND fa.NumeDocu = d.NuDoRefe
             LEFT JOIN TipoUsua t ON t.CodiInst = fa.CodiInst AND t.CodiTipo = fa.TipoUsua
             LEFT JOIN CodiTerc c ON c.TipoDocu = d.TiDoTerc AND c.NumeTerc = d.NuDoTerc
             WHERE n.CodiInst = ? AND n.CodiDocu IN (" . $this->ph($codigosNota) . ")
               AND n.Causado = 1 AND n.Anulado <> 1
               AND n.FechDocu BETWEEN ? AND ?
               AND ({$likeCartera})
               AND fa.CodiDocu IN (" . $this->ph($codigosFactura) . "){$filtros}
             GROUP BY n.CodiDocu, n.NumeDocu, d.TiDoRefe, d.NuDoRefe, d.TiDoTerc, d.NuDoTerc"
        );
        $stmt->execute($params);

        return $this->normalizar($stmt->fetchAll(), ['ValorFactura']);
    }

    /**
     * Estado de envío electrónico de las notas, por "CodiDocu|NumeDocu"
     * interno (CoDoRefe|NuDoRefe). FactElec no tiene índice por CoDoRefe/
     * NuDoRefe: un LEFT JOIN nota a nota tarda minutos, así que se lee UNA
     * vez (acotado por CodiAno, que sí está indexado) y se cruza en PHP.
     * FactElec.CodiDocu/NumeDocu aquí es el consecutivo electrónico de la
     * nota, no el documento interno.
     *
     * @param string[] $codigosNota
     * @return array<string, array<string, mixed>>
     */
    public function fetchEnviosNotas(array $codigosNota, string $anoDesde): array
    {
        if ($codigosNota === []) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            "SELECT CoDoRefe, NuDoRefe, CodiDocu, NumeDocu, ConcDian, EstaFactElec, FechEnvi, CUFE
             FROM FactElec
             WHERE CodiInst = ? AND CodiAno >= ? AND CoDoRefe IN (" . $this->ph($codigosNota) . ")"
        );
        $stmt->execute([$this->codiInst, $anoDesde, ...$codigosNota]);

        $mapa = [];
        foreach ($stmt->fetchAll() as $fila) {
            $mapa[$fila['CoDoRefe'] . '|' . (int)$fila['NuDoRefe']] = $fila;
        }

        return $mapa;
    }
    /** @param list<mixed> $valores */
    private function ph(array $valores): string
    {
        return implode(',', array_fill(0, count($valores), '?'));
    }

    /**
     * @param string[] $prefijos
     * @return array{0:string,1:list<string>}
     */
    private function condicionCartera(array $prefijos): array
    {
        $prefijos = array_values(array_unique(array_filter(
            array_map(static fn (string $p): string => preg_replace('/\D/', '', $p) ?? '', $prefijos),
            static fn (string $p): bool => $p !== ''
        )));

        return [
            implode(' OR ', array_fill(0, count($prefijos), 'd.CodiCont LIKE ?')),
            array_map(static fn (string $p): string => $p . '%', $prefijos),
        ];
    }

    /**
     * @param string[] $tipos
     * @param list<mixed> $params se amplía por referencia
     */
    private function filtrosTipoYTercero(array $tipos, string $nit, string $columnaTipo, array &$params): string
    {
        $sql = '';
        if ($tipos !== []) {
            $sql .= " AND {$columnaTipo} IN (" . $this->ph($tipos) . ')';
            array_push($params, ...$tipos);
        }
        if ($nit !== '') {
            $sql .= ' AND d.NuDoTerc = ?';
            $params[] = $nit;
        }

        return $sql;
    }

    /**
     * @param list<array<string, mixed>> $filas
     * @param string[] $camposNumericos
     * @return list<array<string, mixed>>
     */
    private function normalizar(array $filas, array $camposNumericos): array
    {
        foreach ($filas as &$fila) {
            foreach ($camposNumericos as $campo) {
                $fila[$campo] = (float)$fila[$campo];
            }
        }
        unset($fila);

        return $filas;
    }
}
