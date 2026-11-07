<?php

/**
 * Consultas (solo lectura, sesión READ ONLY) del reporte SIHOS "Liquidación":
 * una fila por línea de DetaFact (actividades y cirugías) de las liquidaciones
 * CONFIRMADAS de un rango de fechas. A diferencia del reporte de liquidación
 * de SIHOS (imprfacturac.php), NO agrupa por liquidación+CUPS — eso fusiona
 * líneas de distinto profesional — y trae el tipo de servicio del CUPS
 * (CodiProc.TipoServ) además del de la línea (DetaFact.TipoServ), que en las
 * cirugías es el componente (honorarios, anestesia, ayudantía, sala, materiales).
 *
 * Recibe el PDO ya conectado de SihosExternalRepository::connect().
 */
class SihosLiquidacionRepository
{
    public function __construct(private readonly PDO $pdo, private readonly string $codiInst)
    {
    }

    /**
     * Catálogo de tipos de servicio de la institución.
     *
     * @return array<int, array{NombTipo:string,TipoConc:string,TipoHoja:string,EsCirugi:int}> CodiTipo => datos
     */
    public function fetchTiposServicio(): array
    {
        $tipos = [];
        foreach ($this->pdo->query('SELECT CodiTipo, NombTipo, TipoConc, TipoHoja, EsCirugi FROM TipoServ') as $fila) {
            $tipos[(int)$fila['CodiTipo']] = [
                'NombTipo' => trim((string)$fila['NombTipo']),
                'TipoConc' => (string)($fila['TipoConc'] ?? ''),
                'TipoHoja' => (string)($fila['TipoHoja'] ?? ''),
                'EsCirugi' => (int)($fila['EsCirugi'] ?? 0),
            ];
        }

        return $tipos;
    }

    /**
     * Líneas de liquidaciones confirmadas (Causado=1, no anuladas) con
     * FechFact entre $desde y $hasta (inclusive), de a una (lectura sin
     * buffer: un mes son decenas de miles de líneas). Mientras se recorre el
     * generador no se puede ejecutar otra consulta con este mismo PDO.
     *
     * TipoDeta: 1 actividad, 3 cirugía, 4 otras actividades (los mismos que el
     * filtro "Actividades/Cirugías" de SIHOS); el 2 son suministros y se excluye.
     *
     * @return Generator<int, array<string,mixed>>
     */
    public function fetchLineas(string $desde, string $hasta): Generator
    {
        $sql = "SELECT e.FechFact, e.CodiDocu, e.NumeFact,
                       e.CodiAdmi, a.NombAdmi, e.TipoCont, tc.NombTipo AS NombTipoCont, e.NumeCont, c.DescCont,
                       e.CodiServ AS ServOrig, so.NombServ AS NombServOrig, e.ServEgre, se.NombServ AS NombServEgre,
                       d.TipoDeta, d.TipoServ, cp.TipoServ AS TipoServCups, cp.CodiCups, cp.NombProc,
                       d.CodiMedi, u.TipoDocu AS TipoDocuProf, u.CC AS CedulaProf, u.Nombre AS NombreProf, u.RegiProf, u.Activo AS ActivoProf, ce.NombEspe,
                       d.CantServ, d.ValoEAPB, d.ValoUsua, d.ValoTota
                FROM EncaFact e
                INNER JOIN DetaFact d ON (e.CodiInst = d.CodiInst AND e.CodiAno = d.CodiAno AND e.CodiDocu = d.CodiDocu AND e.NumeFact = d.NumeFact)
                LEFT JOIN CodiProc cp ON cp.CodiProc = d.CodiServ
                LEFT JOIN Usuarios u ON u.Login = d.CodiMedi
                LEFT JOIN CodiEspe ce ON ce.CodiEspe = u.CodiEspe
                LEFT JOIN CodiAdmi a ON a.CodiAdmi = e.CodiAdmi
                LEFT JOIN TipoCont tc ON tc.CodiTipo = e.TipoCont
                LEFT JOIN Contrato c ON (c.CodiInst = e.CodiInst AND c.CodiAdmi = e.CodiAdmi AND c.NumeCont = e.NumeCont)
                LEFT JOIN CodiServ so ON so.CodiServ = e.CodiServ
                LEFT JOIN CodiServ se ON se.CodiServ = e.ServEgre
                WHERE e.CodiInst = ? AND e.Causado = 1 AND e.Anulado <> 1
                  AND e.FechFact BETWEEN ? AND ?
                  AND d.TipoDeta IN (1, 3, 4)
                ORDER BY e.FechFact, e.CodiDocu, e.NumeFact, d.ConsDeFa";

        $buffered = $this->pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
        $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$this->codiInst, $desde, $hasta]);
            while (($fila = $stmt->fetch()) !== false) {
                yield $fila;
            }
            $stmt->closeCursor();
        } finally {
            $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
        }
    }
}
