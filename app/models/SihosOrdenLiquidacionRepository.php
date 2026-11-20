<?php

/**
 * Consultas (solo lectura, sesión READ ONLY) del reporte SIHOS "Órdenes vs
 * liquidación": ítems de orden médica (EncaOrde/DetaOrde) y líneas liquidadas
 * (EncaFact/DetaFact, confirmadas y no anuladas) de una misma admisión.
 *
 * Las líneas liquidadas se buscan por admisión (DetaFact tiene índice
 * CodiInst+ConsAdmi) y no por fecha: una orden de un mes puede liquidarse en
 * otro. Recibe el PDO ya conectado de SihosExternalRepository::connect().
 */
class SihosOrdenLiquidacionRepository
{
    /** DetaFact.TipoDeta de actividades/procedimientos; el 2 son suministros y el 3 cirugía por componente. */
    private const TIPOS_DETA = '1, 4';

    public function __construct(private readonly PDO $pdo, private readonly string $codiInst)
    {
    }

    /** @return array<string, array{NombProc:string,CodiCups:string,TipoServ:?int}> CodiProc => datos */
    public function fetchProcedimientos(): array
    {
        $out = [];
        foreach ($this->pdo->query('SELECT CodiProc, NombProc, CodiCups, TipoServ FROM CodiProc') as $f) {
            $out[trim((string)$f['CodiProc'])] = [
                'NombProc' => trim((string)$f['NombProc']), 'CodiCups' => trim((string)$f['CodiCups']),
                'TipoServ' => $f['TipoServ'] !== null ? (int)$f['TipoServ'] : null,
            ];
        }

        return $out;
    }

    /** @return array<string,string> CodiAdmi => NombAdmi */
    public function fetchAdministradoras(): array
    {
        $out = [];
        foreach ($this->pdo->query('SELECT CodiAdmi, NombAdmi FROM CodiAdmi') as $f) {
            $out[trim((string)$f['CodiAdmi'])] = trim((string)$f['NombAdmi']);
        }

        return $out;
    }

    /** @return list<array<string,mixed>> ítems de orden con EncaOrde.Fecha en el rango (inclusive) */
    public function fetchOrdenesRango(string $desde, string $hasta): array
    {
        return $this->ordenes('e.Fecha BETWEEN ? AND ?', [$desde, $hasta]);
    }

    /**
     * @param list<int|string> $consAdmis
     * @return list<array<string,mixed>> ítems de orden de esas admisiones (de cualquier fecha)
     */
    public function fetchOrdenesAdmisiones(array $consAdmis): array
    {
        $out = [];
        foreach (array_chunk($consAdmis, 500) as $lote) {
            array_push($out, ...$this->ordenes('e.ConsAdmi IN (' . implode(',', array_fill(0, count($lote), '?')) . ')', $lote));
        }

        return $out;
    }

    /** @return list<array<string,mixed>> líneas liquidadas con EncaFact.FechFact en el rango (inclusive) */
    public function fetchLineasRango(string $desde, string $hasta): array
    {
        return $this->lineas('e.FechFact BETWEEN ? AND ?', [$desde, $hasta]);
    }

    /**
     * @param list<int|string> $consAdmis
     * @return list<array<string,mixed>> líneas liquidadas de esas admisiones (de cualquier fecha)
     */
    public function fetchLineasAdmisiones(array $consAdmis): array
    {
        $out = [];
        foreach (array_chunk($consAdmis, 500) as $lote) {
            array_push($out, ...$this->lineas('d.ConsAdmi IN (' . implode(',', array_fill(0, count($lote), '?')) . ')', $lote));
        }

        return $out;
    }

    /** @param list<mixed> $params */
    private function ordenes(string $where, array $params): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT e.ConsAdmi, e.ConsOrde, e.Fecha, e.OrdeAmbu, d.Item, TRIM(d.CodiProc) AS CodiProc,
                    d.CantSumi, d.CantReal, d.CantFact, d.FechSusp, d.CodiDocu, d.NumeLiqu, d.ConsDeFa, a.CodiAdmi
             FROM EncaOrde e
             INNER JOIN DetaOrde d ON (d.CodiInst = e.CodiInst AND d.ConsAdmi = e.ConsAdmi AND d.ConsOrde = e.ConsOrde)
             LEFT JOIN Admision a ON (a.CodiInst = e.CodiInst AND a.ConsAdmi = e.ConsAdmi)
             WHERE e.CodiInst = ? AND {$where}
             ORDER BY e.ConsAdmi, e.ConsOrde, d.Item"
        );
        $stmt->execute([$this->codiInst, ...$params]);

        return $stmt->fetchAll();
    }

    /** @param list<mixed> $params */
    private function lineas(string $where, array $params): array
    {
        $tipos = self::TIPOS_DETA;
        $stmt = $this->pdo->prepare(
            "SELECT e.FechFact, e.CodiAno, e.CodiDocu, e.NumeFact, e.CodiAdmi, d.ConsDeFa, d.ConsAdmi, d.TipoDeta,
                    TRIM(d.CodiServ) AS CodiProc, d.TipoServ, d.TipoOrde, d.NumeOrde, d.Item, d.CantServ, d.ValoEAPB, d.ValoUsua, d.ValoTota
             FROM EncaFact e
             INNER JOIN DetaFact d ON (d.CodiInst = e.CodiInst AND d.CodiAno = e.CodiAno AND d.CodiDocu = e.CodiDocu AND d.NumeFact = e.NumeFact)
             WHERE e.CodiInst = ? AND e.Causado = 1 AND e.Anulado <> 1 AND d.TipoDeta IN ({$tipos}) AND {$where}
             ORDER BY d.ConsAdmi, e.FechFact, e.CodiDocu, e.NumeFact, d.ConsDeFa"
        );
        $stmt->execute([$this->codiInst, ...$params]);

        return $stmt->fetchAll();
    }
}
