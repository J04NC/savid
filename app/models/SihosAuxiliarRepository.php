<?php

/**
 * Movimientos del reporte SIHOS "Auxiliar" (solo lectura, sesión READ ONLY):
 * EncaCont + DetaCont (ColGAAP, año = Periodo) o DetaNIIF (NIIF), con los
 * filtros del informe auxiliar de SIHOS (modulos/contabilidad/imprauxiliar.php).
 *
 * Diferencias deliberadas con SIHOS (errores del original):
 *  - La cuenta se compara SIEMPRE como texto: SIHOS comparaba idPartNIIF (BIGINT)
 *    como número y "entre 5 y 8" traía también las clases 1 a 4.
 *  - "Entre" de tercero usa la variable correcta y compara numéricamente
 *    cuando ambos extremos son números de documento.
 *  - El filtro de Recursos (DC.CodiRecu) sí se aplica (SIHOS lo ignoraba).
 *  - "Diferente de" conserva los NULL (NOT IN los descartaba).
 * Todos los valores van como parámetros; tablas/columnas salen de listas blancas.
 */
class SihosAuxiliarRepository
{
    /** contabilidad => [tabla de detalle, columna de año, columna de cuenta] */
    public const CONTABILIDADES = [
        'colgaap' => ['DetaCont', 'Periodo', 'CodiCont'],
        'niif' => ['DetaNIIF', 'CodiAno', 'idPartNIIF'],
    ];

    public function __construct(private readonly PDO $pdo, private readonly string $codiInst)
    {
    }

    /**
     * Débitos y créditos por cuenta y tercero, ordenados por cuenta y tercero.
     *
     * @param array<string,mixed> $f filtros validados (SihosAuxiliarFiltros::validar)
     * @return list<array{Cuenta:string,TiDo:string,NuDo:string,DB:string,CR:string}>
     */
    public function agregarMovimientos(array $f, bool $terceroEncabezado): array
    {
        [$from, $params, $cuenta, $tiDo, $nuDo] = $this->base($f, $terceroEncabezado);
        $sql = "SELECT $cuenta AS Cuenta, IFNULL($tiDo, '') AS TiDo, IFNULL($nuDo, '') AS NuDo,
                       SUM(CASE WHEN d.Valor > 0 THEN d.Valor ELSE 0 END) AS DB,
                       SUM(CASE WHEN d.Valor < 0 THEN -d.Valor ELSE 0 END) AS CR
                $from
                GROUP BY Cuenta, TiDo, NuDo
                ORDER BY Cuenta, TiDo, NuDo";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Recorre cada línea contable (sin agrupar, como SIHOS) en el orden del
     * informe: cuenta, tercero, fecha, documento. Lectura sin buffer.
     *
     * @param array<string,mixed> $f
     * @param callable(array<string,mixed>):void $porFila
     */
    public function recorrerDetalle(array $f, bool $terceroEncabezado, callable $porFila): int
    {
        [$from, $params, $cuenta, $tiDo, $nuDo] = $this->base($f, $terceroEncabezado);
        $sql = "SELECT $cuenta AS Cuenta, IFNULL($tiDo, '') AS TiDo, IFNULL($nuDo, '') AS NuDo,
                       ec.FechDocu, d.CodiDocu, d.NumeDocu, d.Valor, ec.Concepto, d.CentCost,
                       d.TiDoRefe, d.NuDoRefe, ec.TiDoExte, ec.NumeExte, ec.NumeCheq, ec.Anulado,
                       d.TipoDocu, d.NumePers
                $from
                ORDER BY Cuenta, TiDo, NuDo, ec.FechDocu, d.CodiDocu, d.NumeDocu, d.ConsDeta";

        $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        $filas = 0;
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            while ($fila = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $porFila($fila);
                $filas++;
            }
            $stmt->closeCursor();
        } finally {
            $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        }

        return $filas;
    }

    /**
     * FROM/WHERE común con todos los filtros.
     *
     * @return array{0:string,1:list<string>,2:string,3:string,4:string} [sql, params, expr cuenta, expr tipo doc tercero, expr número tercero]
     */
    private function base(array $f, bool $terceroEncabezado): array
    {
        if (!isset(self::CONTABILIDADES[$f['contabilidad']])) {
            throw new InvalidArgumentException('Contabilidad no válida.');
        }
        [$tabla, $colAno, $colCuenta] = self::CONTABILIDADES[$f['contabilidad']];
        $cuenta = "CAST(d.$colCuenta AS CHAR)";
        // Parámetro BuscPaci=8 de SIHOS: el tercero se toma del encabezado del documento.
        $alias = $terceroEncabezado ? 'ec' : 'd';
        $tiDo = "$alias.TiDoTerc";
        $nuDo = "$alias.NuDoTerc";

        $hastaExclusiva = (new DateTimeImmutable($f['hasta']))->modify('+1 day')->format('Y-m-d');
        $params = [$this->codiInst, $f['desde'], $hastaExclusiva];
        $where = "ec.CodiInst = ? AND ec.Causado = '1' AND ec.FechDocu >= ? AND ec.FechDocu < ?";
        if (!$f['anulados']) {
            $where .= " AND (ec.Anulado IS NULL OR ec.Anulado <> '1')";
        }
        if ($f['administradoras']) {
            $where .= " AND ec.CodiAdmi <> ''";
        }
        $where .= $this->criterio('ec.CodiCent', $f['sede'], $params);
        $where .= $this->criterio($cuenta, $f['cuenta'], $params, true);
        $where .= $this->criterio($nuDo, $f['tercero'], $params, false, true);
        $where .= $this->criterio('d.CentCost', $f['centro'], $params);
        $where .= $this->criterio('d.CodiRecu', $f['recurso'], $params);
        $where .= $this->criterio('d.CodiDocu', $f['documento'], $params);

        $from = "FROM EncaCont ec
                 INNER JOIN $tabla d ON (d.CodiInst = ec.CodiInst AND d.$colAno = ec.CodiAno AND d.CodiDocu = ec.CodiDocu AND d.NumeDocu = ec.NumeDocu)
                 WHERE $where";

        return [$from, $params, $cuenta, $tiDo, $nuDo];
    }

    /**
     * @param array{modo:string,valores:list<string>} $c
     * @param list<string> $params
     */
    private function criterio(string $col, array $c, array &$params, bool $esCuenta = false, bool $esDocumentoTercero = false): string
    {
        $v = $c['valores'];
        switch ($c['modo']) {
            case 'igual':
                array_push($params, ...$v);

                return " AND $col IN (" . implode(',', array_fill(0, count($v), '?')) . ')';
            case 'diferente':
                array_push($params, ...$v);

                return " AND ($col IS NULL OR $col NOT IN (" . implode(',', array_fill(0, count($v), '?')) . '))';
            case 'inicia':
                $params[] = $v[0] . '%';

                return " AND $col LIKE ?";
            case 'entre':
                [$desde, $hasta] = $v;
                if ($esDocumentoTercero && ctype_digit($desde) && ctype_digit($hasta)) {
                    // Números de documento de distinto largo: como texto '9' > '10'.
                    array_push($params, $desde, $hasta);

                    return " AND $col REGEXP '^[0-9]+$' AND CAST($col AS UNSIGNED) BETWEEN ? AND ?";
                }
                if ($esCuenta) {
                    // Como texto '41990101' > '4199': se rellena el tope para incluir las subcuentas.
                    $hasta .= str_repeat('9', 16);
                }
                array_push($params, $desde, $hasta);

                return " AND $col >= ? AND $col <= ?";
            default:
                return '';
        }
    }
}
