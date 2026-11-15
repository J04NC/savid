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
    /** EncaFact.TipoCont de las liquidaciones de facturación no individual (la entidad se factura después, en un documento consolidado). */
    public const TIPO_CONT_NO_INDIVIDUAL = 2;

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
        $sql = "SELECT e.FechFact, e.CodiAno, e.CodiDocu, e.NumeFact, d.ConsDeFa,
                       e.CodiAdmi, a.NombAdmi, e.TipoCont, tc.NombTipo AS NombTipoCont, e.NumeCont, c.DescCont,
                       e.CodiServ AS ServOrig, so.NombServ AS NombServOrig, e.ServEgre, se.NombServ AS NombServEgre,
                       d.TipoDeta, d.TipoServ, d.ConsAdmi, d.CodiServ AS CodiProcLinea, cp.TipoServ AS TipoServCups, cp.CodiCups, cp.NombProc,
                       d.CodiMedi, u.TipoDocu AS TipoDocuProf, u.CC AS CedulaProf, u.Nombre AS NombreProf, u.RegiProf, u.Activo AS ActivoProf, u.CodiEspe AS CodiEspeProf, ce.NombEspe,
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
     * @param string $codiEspeAdministrativo código de la especialidad ADMINISTRATIVO (CodiEspe)
     * @return list<int>
     */
    public function fetchAdmisionesAdministrativas(string $desde, string $hasta, string $codiEspeAdministrativo): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT d.ConsAdmi
             FROM EncaFact e
             INNER JOIN DetaFact d ON (e.CodiInst = d.CodiInst AND e.CodiAno = d.CodiAno AND e.CodiDocu = d.CodiDocu AND e.NumeFact = d.NumeFact)
             LEFT JOIN Usuarios u ON u.Login = d.CodiMedi
             WHERE e.CodiInst = ? AND e.Causado = 1 AND e.Anulado <> 1 AND e.FechFact BETWEEN ? AND ?
               AND d.TipoDeta IN (1, 3, 4) AND d.ConsAdmi > 0
               AND (d.CodiMedi IS NULL OR TRIM(d.CodiMedi) = '' OR u.CodiEspe = ?)"
        );
        $stmt->execute([$this->codiInst, $desde, $hasta, $codiEspeAdministrativo]);

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
                "SELECT r.ConsAdmi, r.UsuaAsis AS Login, DATE(r.FechCons) AS Fecha, u.CC AS Cedula, u.Nombre, u.CodiEspe
                 FROM RipsCons r LEFT JOIN Usuarios u ON u.Login = r.UsuaAsis
                 WHERE r.CodiInst = ? AND r.ConsAdmi IN ($in)"
            );
            $consultas->execute([$this->codiInst, ...$lote]);
            $hojas = $this->pdo->prepare(
                "SELECT h.ConsAdmi, h.UsuaAsis AS Login, DATE(h.FechProc) AS Fecha, h.CodiProc, u.CC AS Cedula, u.Nombre, u.CodiEspe
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

    /**
     * Liquidaciones vivas y confirmadas del rango con al menos una factura anulada por una nota crédito
     * (nota que la reversa por completo: diferencia 0 en 1 o 2 líneas de cuentas 14%; los documentos se
     * resuelven por DocuApli). Clases:
     *  - 'completa': todas sus facturas están anuladas.
     *  - 'entidad': anulada la factura de la entidad (con valor EAPB); lo que queda vivo es solo la factura
     *    del paciente (copago/cuota), sin valor EAPB.
     *  - 'revisar': cualquier otro caso (p. ej. anulada la del paciente y viva la de la entidad) o contrato
     *    con facturación no individual (la parte de la entidad se cobra después en un documento consolidado).
     * 'otra' indica si existe otra liquidación viva y confirmada del mismo paciente y admisión que no esté
     * en la misma situación ('Sí'/'No'): es la que reemplazaría a la que se anule.
     *
     * @param list<int> $periodos años contables de las notas
     * @return array<string,array{clase:string,otra:string}> 'CodiDocu|NumeFact' => datos
     */
    public function fetchLiquidacionesFacturaAnulada(string $desde, string $hasta, array $periodos): array
    {
        $codNota = SihosDocuApli::codigos($this->pdo, $this->codiInst, SihosDocuApli::NOTA);
        $codFact = SihosDocuApli::codigos($this->pdo, $this->codiInst, SihosDocuApli::FACTURA);
        if ($codNota === [] || $codFact === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($periodos), '?'));
        $inNota = implode(',', array_fill(0, count($codNota), '?'));
        $inFact = implode(',', array_fill(0, count($codFact), '?'));
        $nota = "SELECT DISTINCT x.TiDoRefe, x.NuDoRefe FROM (
                     SELECT d.TiDoRefe, d.NuDoRefe, SUM(d.Valor) + f.ValoTota AS DIF, COUNT(*) AS cont
                     FROM EncaCont e
                     INNER JOIN DetaCont d ON (e.CodiInst = d.CodiInst AND e.CodiDocu = d.CodiDocu AND e.NumeDocu = d.NumeDocu)
                     INNER JOIN EncaCont f ON (d.CodiInst = f.CodiInst AND d.TiDoRefe = f.CodiDocu AND d.NuDoRefe = f.NumeDocu)
                     WHERE e.CodiInst = ? AND e.Causado = '1' AND e.Anulado <> '1' AND e.CodiDocu IN ($inNota)
                       AND d.TiDoRefe IN ($inFact) AND d.CodiCont LIKE '14%' AND d.Periodo IN ($in)
                       AND (f.ValoEAPB > 0 OR d.CodiCont = '140907')
                     GROUP BY d.TiDoRefe, d.NuDoRefe, d.Periodo HAVING DIF = 0 AND cont IN (1, 2)
                 ) x";
        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT l.CodiDocu, l.NumeFact
             FROM EncaFact l
             INNER JOIN DetaLiqu dl ON (dl.CodiInst = l.CodiInst AND dl.CodiLiqu = l.CodiDocu AND dl.NumeLiqu = l.NumeFact)
             INNER JOIN ($nota) n ON (n.TiDoRefe = dl.CodiDocu AND n.NuDoRefe = dl.NumeDocu)
             WHERE l.CodiInst = ? AND l.Causado = 1 AND l.Anulado <> 1 AND l.FechFact BETWEEN ? AND ?"
        );
        $stmt->execute([$this->codiInst, ...$codNota, ...$codFact, ...$periodos, $this->codiInst, $desde, $hasta]);
        $liqs = $stmt->fetchAll();
        if ($liqs === []) {
            return [];
        }

        $anuladas = $this->pdo->prepare($nota);
        $anuladas->execute([$this->codiInst, ...$codNota, ...$codFact, ...$periodos]);
        $facturasAnuladas = [];
        foreach ($anuladas->fetchAll() as $f) {
            $facturasAnuladas[$f['TiDoRefe'] . '|' . $f['NuDoRefe']] = true;
        }

        $out = [];
        $info = [];
        foreach (array_chunk(array_column($liqs, 'NumeFact'), 300) as $lote) {
            $ph = implode(',', array_fill(0, count($lote), '?'));
            $det = $this->pdo->prepare(
                "SELECT l.CodiDocu, l.NumeFact, l.TipoDocu, l.NumeUsua, l.ConsAdmi, l.TipoCont, dl.CodiDocu AS FacDocu, dl.NumeDocu AS FacNume, dl.ValoEAPB, c.TiDoTerc, c.NuDoTerc
                 FROM EncaFact l
                 INNER JOIN DetaLiqu dl ON (dl.CodiInst = l.CodiInst AND dl.CodiLiqu = l.CodiDocu AND dl.NumeLiqu = l.NumeFact)
                 LEFT JOIN EncaCont c ON (c.CodiInst = dl.CodiInst AND c.CodiDocu = dl.CodiDocu AND c.NumeDocu = dl.NumeDocu)
                 WHERE l.CodiInst = ? AND l.Causado = 1 AND l.Anulado <> 1 AND l.NumeFact IN ($ph)"
            );
            $det->execute([$this->codiInst, ...$lote]);
            foreach ($det->fetchAll() as $r) {
                $k = $r['CodiDocu'] . '|' . $r['NumeFact'];
                $i = &$info[$k];
                $i ??= ['TipoDocu' => $r['TipoDocu'], 'NumeUsua' => $r['NumeUsua'], 'ConsAdmi' => (int)$r['ConsAdmi'], 'tipoCont' => (int)$r['TipoCont'], 'vivas' => 0, 'vivasPaciente' => 0, 'anuladasEapb' => 0];
                if (isset($facturasAnuladas[$r['FacDocu'] . '|' . $r['FacNume']])) {
                    $i['anuladasEapb'] += (float)$r['ValoEAPB'] > 0 ? 1 : 0;
                } else {
                    $i['vivas']++;
                    $i['vivasPaciente'] += ((float)$r['ValoEAPB'] === 0.0 && $r['TiDoTerc'] === $r['TipoDocu'] && $r['NuDoTerc'] === $r['NumeUsua']) ? 1 : 0;
                }
                unset($i);
            }
        }
        foreach ($liqs as $l) {
            $k = $l['CodiDocu'] . '|' . $l['NumeFact'];
            $i = $info[$k] ?? null;
            if ($i === null) {
                continue;
            }
            $clase = $i['tipoCont'] === self::TIPO_CONT_NO_INDIVIDUAL ? 'revisar' : ($i['vivas'] === 0 ? 'completa' : ($i['vivasPaciente'] === $i['vivas'] && $i['anuladasEapb'] > 0 ? 'entidad' : 'revisar'));
            $out[$k] = ['clase' => $clase, 'otra' => 'No'];
        }

        // ¿Hay otra liquidación viva del mismo paciente y admisión que no sea de las que se anularían?
        $pacientes = [];
        foreach ($out as $k => $_) {
            $pacientes[$info[$k]['TipoDocu'] . '|' . $info[$k]['NumeUsua']][] = $k;
        }
        $vivas = [];
        foreach (array_chunk(array_keys($pacientes), 100) as $lote) {
            $cond = implode(' OR ', array_fill(0, count($lote), '(TipoDocu = ? AND NumeUsua = ?)'));
            $params = [];
            foreach ($lote as $p) {
                array_push($params, ...explode('|', $p, 2));
            }
            $stmt = $this->pdo->prepare("SELECT CodiDocu, NumeFact, TipoDocu, NumeUsua, ConsAdmi FROM EncaFact WHERE CodiInst = ? AND Causado = 1 AND Anulado <> 1 AND ($cond)");
            $stmt->execute([$this->codiInst, ...$params]);
            foreach ($stmt->fetchAll() as $o) {
                $vivas[$o['TipoDocu'] . '|' . $o['NumeUsua']][] = [$o['CodiDocu'] . '|' . $o['NumeFact'], (int)$o['ConsAdmi']];
            }
        }
        foreach ($out as $k => &$o) {
            foreach ($vivas[$info[$k]['TipoDocu'] . '|' . $info[$k]['NumeUsua']] ?? [] as [$otra, $consAdmi]) {
                if ($otra !== $k && $consAdmi === $info[$k]['ConsAdmi'] && !in_array($out[$otra]['clase'] ?? '', ['completa', 'entidad'], true)) {
                    $o['otra'] = 'Sí';
                    break;
                }
            }
        }
        unset($o);

        return $out;
    }
}
