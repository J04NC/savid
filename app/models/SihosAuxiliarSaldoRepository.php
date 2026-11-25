<?php

/**
 * Saldos del reporte SIHOS "Auxiliar" (solo lectura): último cierre contable,
 * saldos base de SaldCont/SaldNIIF (por cuenta) y SaldTerc/SaTeNIIF (por cuenta
 * y tercero), y los movimientos para reconstruir el saldo hasta el día anterior
 * al inicio del informe. SIHOS hacía estas consultas una por cuenta y una por
 * tercero; aquí van en lote (cuentas en grupos de 500).
 *
 * Como en SIHOS, el saldo es el total de la cuenta: no aplica los filtros de
 * sede, centro de costo, documento, etc. (solo institución, causado, no anulado).
 */
class SihosAuxiliarSaldoRepository
{
    /** contabilidad => [saldo por cuenta, saldo por tercero, detalle, columna de año del detalle, columna de cuenta] */
    private const TABLAS = [
        'colgaap' => ['SaldCont', 'SaldTerc', 'DetaCont', 'Periodo', 'CodiCont'],
        'niif' => ['SaldNIIF', 'SaTeNIIF', 'DetaNIIF', 'CodiAno', 'idPartNIIF'],
    ];
    private const LOTE = 500;

    public function __construct(private readonly PDO $pdo, private readonly string $codiInst)
    {
    }

    /** @return array{ano:int,mes:int}|null último mes cerrado del módulo de contabilidad (26), sin el mes 13 */
    public function ultimoCierre(): ?array
    {
        $stmt = $this->pdo->prepare("SELECT CodiAno, CodiMes FROM Cierres
            WHERE CodiInst = ? AND Modulo = '26' AND TipoMovi = '2' AND CodiMes <> '13'
            ORDER BY CodiAno DESC, CodiMes DESC LIMIT 1");
        $stmt->execute([$this->codiInst]);
        $f = $stmt->fetch(PDO::FETCH_ASSOC);

        return $f ? ['ano' => (int)$f['CodiAno'], 'mes' => (int)$f['CodiMes']] : null;
    }

    /** Año del balance de apertura NIIF (CodiInst.AnnoNIIF). */
    public function annoNiif(): ?int
    {
        $stmt = $this->pdo->prepare('SELECT AnnoNIIF FROM CodiInst WHERE CodiInst = ?');
        $stmt->execute([$this->codiInst]);
        $v = $stmt->fetchColumn();

        return $v !== false && $v !== null && $v !== '' ? (int)$v : null;
    }

    /** Parámetro BuscPaci de SIHOS (8 = el tercero sale del encabezado del documento). */
    public function parametroBuscPaci(): string
    {
        $stmt = $this->pdo->prepare("SELECT ValoPara FROM Parametr WHERE NombPara = 'BuscPaci' AND CodiInst = ?");
        $stmt->execute([$this->codiInst]);

        return trim((string)$stmt->fetchColumn());
    }

    /**
     * Saldo base por cuenta: apertura (SaldDeAn - SaldCrAn) o cierre (SaldDebe - SaldCred) del mes.
     *
     * @param list<string> $cuentas
     * @return array<string,float>
     */
    public function saldoBaseCuentas(string $contab, int $ano, int $mes, bool $apertura, array $cuentas): array
    {
        [$tabla, , , , $col] = self::TABLAS[$contab];
        [$c1, $c2] = $apertura ? ['SaldDeAn', 'SaldCrAn'] : ['SaldDebe', 'SaldCred'];
        $out = [];
        foreach (array_chunk($cuentas, self::LOTE) as $lote) {
            $stmt = $this->pdo->prepare("SELECT CAST($col AS CHAR) AS Cuenta, SUM($c1) - SUM($c2) AS V FROM $tabla
                WHERE CodiInst = ? AND CodiAno = ? AND CodiMes = ? AND $col IN (" . $this->marcas($lote) . ') GROUP BY Cuenta');
            $stmt->execute([$this->codiInst, (string)$ano, $mes, ...$lote]);
            foreach ($stmt as $f) {
                $out[(string)$f['Cuenta']] = (float)$f['V'];
            }
        }

        return $out;
    }

    /**
     * Igual que saldoBaseCuentas() pero por cuenta y tercero.
     *
     * @param list<string> $cuentas
     * @return array<string,array<string,float>> cuenta => ["TiDo\tNuDo" => valor]
     */
    public function saldoBaseTerceros(string $contab, int $ano, int $mes, bool $apertura, array $cuentas): array
    {
        [, $tabla, , , $col] = self::TABLAS[$contab];
        [$c1, $c2] = $apertura ? ['SaldDeAn', 'SaldCrAn'] : ['SaldDebe', 'SaldCred'];
        $out = [];
        foreach (array_chunk($cuentas, self::LOTE) as $lote) {
            $stmt = $this->pdo->prepare("SELECT CAST($col AS CHAR) AS Cuenta, TipoDocu, NumeTerc, SUM($c1) - SUM($c2) AS V FROM $tabla
                WHERE CodiInst = ? AND CodiAno = ? AND CodiMes = ? AND $col IN (" . $this->marcas($lote) . ')
                GROUP BY Cuenta, TipoDocu, NumeTerc');
            $stmt->execute([$this->codiInst, (string)$ano, $mes, ...$lote]);
            foreach ($stmt as $f) {
                $out[(string)$f['Cuenta']][trim((string)$f['TipoDocu']) . "\t" . trim((string)$f['NumeTerc'])] = (float)$f['V'];
            }
        }

        return $out;
    }

    /**
     * Neto de movimientos (causados, no anulados) en [desde, hastaExclusiva) por cuenta y por tercero.
     *
     * @param list<string> $cuentas
     * @return array{cuentas:array<string,float>,terceros:array<string,array<string,float>>}
     */
    public function movimientos(string $contab, string $desde, string $hastaExclusiva, array $cuentas): array
    {
        [, , $tabla, $colAno, $col] = self::TABLAS[$contab];
        $out = ['cuentas' => [], 'terceros' => []];
        if ($desde >= $hastaExclusiva) {
            return $out;
        }
        foreach (array_chunk($cuentas, self::LOTE) as $lote) {
            $stmt = $this->pdo->prepare("SELECT CAST(d.$col AS CHAR) AS Cuenta, IFNULL(d.TiDoTerc, '') AS TiDo, IFNULL(d.NuDoTerc, '') AS NuDo, SUM(d.Valor) AS V
                FROM EncaCont e
                INNER JOIN $tabla d ON (d.CodiInst = e.CodiInst AND d.$colAno = e.CodiAno AND d.CodiDocu = e.CodiDocu AND d.NumeDocu = e.NumeDocu)
                WHERE e.CodiInst = ? AND e.Causado = '1' AND (e.Anulado IS NULL OR e.Anulado <> '1')
                  AND e.FechDocu >= ? AND e.FechDocu < ? AND d.$col IN (" . $this->marcas($lote) . ')
                GROUP BY Cuenta, TiDo, NuDo');
            $stmt->execute([$this->codiInst, $desde, $hastaExclusiva, ...$lote]);
            foreach ($stmt as $f) {
                $c = (string)$f['Cuenta'];
                $out['cuentas'][$c] = ($out['cuentas'][$c] ?? 0.0) + (float)$f['V'];
                $k = trim((string)$f['TiDo']) . "\t" . trim((string)$f['NuDo']);
                $out['terceros'][$c][$k] = ($out['terceros'][$c][$k] ?? 0.0) + (float)$f['V'];
            }
        }

        return $out;
    }

    private function marcas(array $lote): string
    {
        return implode(',', array_fill(0, count($lote), '?'));
    }
}
