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
                       d.TipoDeta, d.TipoServ, d.ConsAdmi, d.CodiServ AS CodiProcLinea, cp.TipoServ AS TipoServCups, cp.CodiCups, cp.NombProc,
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

    /**
     * Admisiones de las líneas liquidadas con un usuario administrativo (o sin
     * profesional) en el rango, para precargar sus atenciones.
     *
     * @param list<string> $loginsAdministrativos en minúsculas
     * @return list<int>
     */
    public function fetchAdmisionesAdministrativas(string $desde, string $hasta, array $loginsAdministrativos): array
    {
        $in = implode(',', array_fill(0, count($loginsAdministrativos), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT d.ConsAdmi
             FROM EncaFact e
             INNER JOIN DetaFact d ON (e.CodiInst = d.CodiInst AND e.CodiAno = d.CodiAno AND e.CodiDocu = d.CodiDocu AND e.NumeFact = d.NumeFact)
             WHERE e.CodiInst = ? AND e.Causado = 1 AND e.Anulado <> 1 AND e.FechFact BETWEEN ? AND ?
               AND d.TipoDeta IN (1, 3, 4) AND d.ConsAdmi > 0
               AND (d.CodiMedi IS NULL OR TRIM(d.CodiMedi) = '' OR LOWER(TRIM(d.CodiMedi)) IN ($in))"
        );
        $stmt->execute([$this->codiInst, $desde, $hasta, ...$loginsAdministrativos]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Atenciones de varias admisiones, para inferir al profesional real de una
     * línea liquidada con un usuario administrativo: consultas (RipsCons) y
     * hojas de procedimiento (HojaProc), cada una con el profesional que atendió.
     * Se consultan en lotes (una consulta por admisión es lenta por la latencia
     * de red). Usar una conexión distinta a la del generador de fetchLineas().
     *
     * @param list<int> $consAdmis
     * @return array<int,array{consultas:list<array<string,mixed>>,hojas:list<array<string,mixed>>}> ConsAdmi => atenciones
     */
    public function fetchAtencionesAdmisiones(array $consAdmis): array
    {
        $out = [];
        foreach (array_chunk($consAdmis, 500) as $lote) {
            $in = implode(',', array_fill(0, count($lote), '?'));
            $consultas = $this->pdo->prepare(
                "SELECT r.ConsAdmi, r.UsuaAsis AS Login, DATE(r.FechCons) AS Fecha, u.CC AS Cedula, u.Nombre
                 FROM RipsCons r LEFT JOIN Usuarios u ON u.Login = r.UsuaAsis
                 WHERE r.CodiInst = ? AND r.ConsAdmi IN ($in)"
            );
            $consultas->execute([$this->codiInst, ...$lote]);
            $hojas = $this->pdo->prepare(
                "SELECT h.ConsAdmi, h.UsuaAsis AS Login, DATE(h.FechProc) AS Fecha, h.CodiProc, u.CC AS Cedula, u.Nombre
                 FROM HojaProc h LEFT JOIN Usuarios u ON u.Login = h.UsuaAsis
                 WHERE h.CodiInst = ? AND h.ConsAdmi IN ($in)"
            );
            $hojas->execute([$this->codiInst, ...$lote]);
            foreach ($lote as $id) {
                $out[$id] = ['consultas' => [], 'hojas' => []];
            }
            foreach ($consultas->fetchAll() as $fila) {
                $out[(int)$fila['ConsAdmi']]['consultas'][] = $fila;
            }
            foreach ($hojas->fetchAll() as $fila) {
                $out[(int)$fila['ConsAdmi']]['hojas'][] = $fila;
            }
        }

        return $out;
    }
}
