<?php

/**
 * Consultas (solo lectura, sesión READ ONLY) del reporte SIHOS "Documentos
 * contables": todas las líneas contables causadas y no anuladas de un rango,
 * sumadas por documento y cuenta, desde DetaCont (ColGAAP) o DetaNIIF (NIIF).
 *
 * Tabla y columnas salen de la lista blanca CONTABILIDADES (nunca del usuario).
 * DetaCont no tiene CodiAno: su año es Periodo (así lo une el propio SIHOS).
 *
 * El resultado puede tener cientos de miles de filas: se lee SIN buffer (fila
 * a fila) para no cargarlo entero en memoria. El agregado va en una tabla
 * derivada sin el texto de Concepto (un TEXT dentro del GROUP BY obliga a
 * MySQL 5.6 a usar tabla temporal en disco); el Concepto se trae después por
 * la PK de EncaCont. Medido contra datos reales: mismas filas y suma, ~2x
 * más rápido. Recibe el PDO ya conectado de SihosExternalRepository::connect().
 */
class SihosDocumentosContablesRepository
{
    /** contabilidad => [tabla de detalle, columna de año, columna de cuenta] */
    public const CONTABILIDADES = [
        'colgaap' => ['DetaCont', 'Periodo', 'CodiCont'],
        'niif' => ['DetaNIIF', 'CodiAno', 'idPartNIIF'],
    ];

    public function __construct(private readonly PDO $pdo, private readonly string $codiInst)
    {
    }

    /** @return array<string,string> CodiDocu => NombDocu de esta institución */
    public function fetchNombresDocumento(): array
    {
        $stmt = $this->pdo->prepare('SELECT CodiDocu, NombDocu FROM MaesDocu WHERE CodiInst = ?');
        $stmt->execute([$this->codiInst]);
        $out = [];
        foreach ($stmt as $f) {
            $out[trim((string)$f['CodiDocu'])] = (string)$f['NombDocu'];
        }

        return $out;
    }

    /** @return array<string,string> Login => Nombre */
    public function fetchNombresUsuario(): array
    {
        $out = [];
        foreach ($this->pdo->query('SELECT Login, Nombre FROM Usuarios') as $f) {
            $out[trim((string)$f['Login'])] = (string)$f['Nombre'];
        }

        return $out;
    }

    /**
     * Recorre las líneas (documento + cuenta, valor sumado) en orden de fecha y
     * llama $porFila con cada una: FechDocu, CodiDocu, NumeDocu, Concepto, UsuaCaus, Cuenta, Valor.
     *
     * @param string $hastaExclusiva primer día que ya NO entra (rango semiabierto)
     * @param array{modo:string,desde?:string,hasta?:string,valor?:string}|null $cuenta filtro de cuenta ya validado (solo dígitos)
     * @param callable(array<string,mixed>):void $porFila
     * @return int filas recorridas
     */
    public function recorrerLineas(string $contabilidad, string $desde, string $hastaExclusiva, ?array $cuenta, callable $porFila): int
    {
        if (!isset(self::CONTABILIDADES[$contabilidad])) {
            throw new InvalidArgumentException('Contabilidad no válida.');
        }
        [$tabla, $colAno, $colCuenta] = self::CONTABILIDADES[$contabilidad];

        $params = [$this->codiInst, $desde, $hastaExclusiva];
        $filtroCuenta = '';
        // CAST: idPartNIIF es BIGINT; como texto, "inicia por" y "entre" se comportan igual en ambas contabilidades.
        $exprCuenta = "CAST(d.$colCuenta AS CHAR)";
        if ($cuenta !== null) {
            switch ($cuenta['modo']) {
                case 'inicia':
                    $filtroCuenta = " AND $exprCuenta LIKE ?";
                    $params[] = $cuenta['valor'] . '%';
                    break;
                case 'igual':
                    $filtroCuenta = " AND $exprCuenta = ?";
                    $params[] = $cuenta['valor'];
                    break;
                case 'entre':
                    // Como texto '41990101' > '4199': el tope se rellena con 9 para incluir las subcuentas de "hasta".
                    $filtroCuenta = " AND $exprCuenta >= ? AND $exprCuenta <= ?";
                    $params[] = $cuenta['desde'];
                    $params[] = $cuenta['hasta'] . str_repeat('9', 20);
                    break;
            }
        }
        $params[] = $this->codiInst;

        $sql = "
            SELECT g.FechDocu, g.CodiDocu, g.NumeDocu, e2.Concepto, g.UsuaCaus, g.Cuenta, g.Valor
            FROM (
                SELECT ec.CodiAno, ec.CodiDocu, ec.NumeDocu, MIN(ec.FechDocu) AS FechDocu, MAX(ec.UsuaCaus) AS UsuaCaus,
                       d.$colCuenta AS Cuenta, SUM(d.Valor) AS Valor
                FROM EncaCont ec
                INNER JOIN $tabla d ON (d.CodiInst = ec.CodiInst AND d.$colAno = ec.CodiAno AND d.CodiDocu = ec.CodiDocu AND d.NumeDocu = ec.NumeDocu)
                WHERE ec.CodiInst = ?
                  AND ec.Causado = '1' AND (ec.Anulado IS NULL OR ec.Anulado <> '1')
                  AND ec.FechDocu >= ? AND ec.FechDocu < ?
                  AND d.Valor <> 0$filtroCuenta
                GROUP BY ec.CodiAno, ec.CodiDocu, ec.NumeDocu, d.$colCuenta
            ) g
            INNER JOIN EncaCont e2 ON (e2.CodiInst = ? AND e2.CodiAno = g.CodiAno AND e2.CodiDocu = g.CodiDocu AND e2.NumeDocu = g.NumeDocu)
            ORDER BY g.FechDocu, g.CodiDocu, g.NumeDocu, g.Cuenta
        ";

        $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        $filas = 0;
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            while ($f = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $porFila($f);
                $filas++;
            }
            $stmt->closeCursor();
        } finally {
            $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        }

        return $filas;
    }
}
