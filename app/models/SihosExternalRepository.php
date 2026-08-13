<?php

/**
 * Conexión de SOLO LECTURA a la base de datos externa de SIHOS de UNA
 * empresa concreta (servidor MySQL propio, distinto al de SAVID; cada
 * empresa/tenant de SAVID puede apuntar a un SIHOS distinto — ver
 * SihosEmpresaConfigRepository). SIHOS es el sistema de producción del
 * hospital: esta conexión NUNCA debe escribir en su base de datos.
 * No usa AuditingPDO: no es la BD de SAVID, no hay escrituras que auditar
 * aquí porque no debe haber escrituras.
 *
 * La sesión se abre con `SET SESSION TRANSACTION READ ONLY` (ver connect())
 * para que cualquier INSERT/UPDATE/DELETE/DDL falle a nivel de motor, sin
 * depender de que el código que llama solo use SELECT. Además, el usuario
 * de conexión configurado por empresa debe tener permisos de solo lectura
 * (GRANT SELECT) en el servidor de SIHOS — esa es la protección real;
 * el READ ONLY de sesión es una segunda capa, no un sustituto.
 *
 * CodiInst: algunas instalaciones de SIHOS son multi-institución (un
 * hospital principal + puestos de salud satélite comparten la misma BD
 * física, cada uno con su propio CodiInst en EncaCont/DetaCont/DetaPlan/
 * MaesDocu/CodiInst). CodiDocu+NumeDocu NO es único globalmente, solo
 * dentro de un mismo CodiInst — por eso todas las consultas aquí filtran
 * por `codiInst` (config por empresa) en cada tabla que tiene esa columna.
 * CentCost es la única excepción: no tiene columna CodiInst en este esquema.
 */
class SihosExternalRepository
{
    private ?PDO $pdo = null;

    /**
     * @param array{host:string,port:string,database:string,username:string,password:string,charset:string,codiInst:string} $config
     */
    public function __construct(private readonly array $config)
    {
    }

    /**
     * @throws PDOException si no conecta (credenciales, host inalcanzable, etc.)
     */
    public function connect(): PDO
    {
        if ($this->pdo === null) {
            $host = $this->config['host'];
            $port = $this->config['port'];
            $dbname = $this->config['database'];
            $charset = $this->config['charset'];

            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";

            $this->pdo = new PDO($dsn, $this->config['username'], $this->config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 5,
            ]);

            $this->pdo->exec('SET SESSION TRANSACTION READ ONLY');
        }

        return $this->pdo;
    }

    /**
     * Prueba la conexión y devuelve la versión del servidor si responde.
     *
     * @throws PDOException si no conecta
     */
    public function fetchServerVersion(): string
    {
        return (string)$this->connect()->getAttribute(PDO::ATTR_SERVER_VERSION);
    }

    private function codiInst(): string
    {
        return (string)($this->config['codiInst'] ?? '');
    }

    /**
     * Códigos de documento (MaesDocu.CodiDocu) para uno o más DocuApli, de
     * la institución configurada. DocuApli es el catálogo fijo de "rol" del
     * documento en el software; CodiDocu es la etiqueta configurable por
     * cada instalación/institución — nunca se debe filtrar por CodiDocu
     * literal ('FE', 'GLA', ...). Exige ManeCont=1 y ManePres=1: descarta
     * tipos que comparten DocuApli pero no generan contabilidad/presupuesto
     * (p. ej. "RCE - Relación de cobro", DocuApli=28 igual que FE, pero
     * ManeCont=0 y ManePres=0).
     *
     * @param int[] $docuAplis
     * @return string[]
     */
    public function resolveCodigosDocumentoPorAplicacion(array $docuAplis): array
    {
        if ($docuAplis === []) {
            return [];
        }

        $ph = implode(',', array_fill(0, count($docuAplis), '?'));
        $stmt = $this->connect()->prepare(
            "SELECT DISTINCT CodiDocu FROM MaesDocu
             WHERE DocuApli IN ({$ph}) AND ManeCont = 1 AND ManePres = 1 AND CodiInst = ?"
        );
        $stmt->execute([...$docuAplis, $this->codiInst()]);

        return array_column($stmt->fetchAll(), 'CodiDocu');
    }

    /**
     * Igual que resolveCodigosDocumentoPorAplicacion() pero SIN exigir
     * ManeCont=1/ManePres=1. Necesario para "Reconocimiento" (DocuApli=40):
     * en al menos una institución real tiene ManePres=0 en MaesDocu pero SÍ
     * genera filas reales en DetaPlan (verificado con datos: 13 documentos,
     * ~$4.089.636.489 en un solo mes) — el flag no es una garantía dura,
     * solo un comportamiento por defecto.
     *
     * @param int[] $docuAplis
     * @return string[]
     */
    public function resolveCodigosDocumentoPorAplicacionSinFiltroPresupuesto(array $docuAplis): array
    {
        if ($docuAplis === []) {
            return [];
        }

        $ph = implode(',', array_fill(0, count($docuAplis), '?'));
        $stmt = $this->connect()->prepare(
            "SELECT DISTINCT CodiDocu FROM MaesDocu WHERE DocuApli IN ({$ph}) AND CodiInst = ?"
        );
        $stmt->execute([...$docuAplis, $this->codiInst()]);

        return array_column($stmt->fetchAll(), 'CodiDocu');
    }

    /**
     * Nombre del tipo de usuario/pagador (Contributivo, Subsidiado POS,
     * Particular...) de la institución conectada, para mostrar en vez del
     * código crudo de EncaCont.TipoUsua.
     *
     * @return array<int|string, string> CodiTipo => NombTipo
     */
    public function fetchTipoUsuarioNombres(): array
    {
        $stmt = $this->connect()->prepare('SELECT CodiTipo, NombTipo FROM TipoUsua WHERE CodiInst = ?');
        $stmt->execute([$this->codiInst()]);

        $nombres = [];
        foreach ($stmt->fetchAll() as $fila) {
            $nombres[$fila['CodiTipo']] = $fila['NombTipo'];
        }

        return $nombres;
    }

    /**
     * Presupuesto reconocido por documento: EncaCont+DetaPlan agregado por
     * CodiDocu+NumeDocu (un documento puede tener varias líneas DetaPlan
     * por rubro — se suman). El signo se invierte para los documentos de
     * $codigosSignoInvertido (glosas/notas que en DetaPlan quedan con el
     * signo contrario al de la ejecución presupuestal — regla de negocio
     * confirmada por el usuario contra su propio reporte de SIHOS).
     *
     * @param string[] $codigosPresupuesto
     * @param string[] $codigosSignoInvertido
     * @return array<string, array{CodiDocu:string,NumeDocu:string,FechDocu:string,CentCost:?string,TipoUsua:?string,Valor:float}> indexado por "CodiDocu-NumeDocu"
     */
    public function fetchPresupuestoReconocidoPorDocumento(
        array $codigosPresupuesto,
        array $codigosSignoInvertido,
        string $fechaIni,
        string $fechaFin
    ): array {
        if ($codigosPresupuesto === []) {
            return [];
        }

        $phPres = implode(',', array_fill(0, count($codigosPresupuesto), '?'));
        $condicionSigno = '0 = 1';
        $paramsSigno = [];

        if ($codigosSignoInvertido !== []) {
            $phSigno = implode(',', array_fill(0, count($codigosSignoInvertido), '?'));
            $condicionSigno = "EP.CodiDocu IN ({$phSigno})";
            $paramsSigno = $codigosSignoInvertido;
        }

        $sql = "
            SELECT EP.CodiDocu, EP.NumeDocu, MIN(EP.FechDocu) AS FechDocu, MIN(EP.CentCost) AS CentCost, MIN(EP.TipoUsua) AS TipoUsua,
                   SUM(IF(EP.TipoComp IN ('5', '0') AND ({$condicionSigno}), DP.Valor * -1, DP.Valor)) AS Valor
            FROM EncaCont EP
            INNER JOIN DetaPlan DP
                ON EP.CodiInst = DP.CodiInst AND EP.CodiAno = DP.CodiAno AND EP.CodiDocu = DP.CodiDocu AND EP.NumeDocu = DP.NumeDocu
            WHERE EP.CodiInst = ?
              AND EP.CodiDocu IN ({$phPres})
              AND EP.Causado = '1'
              AND EP.Anulado <> 1
              AND EP.FechDocu BETWEEN ? AND ?
            GROUP BY EP.CodiDocu, EP.NumeDocu
        ";
        $stmt = $this->connect()->prepare($sql);
        // Orden posicional: los "?" de $condicionSigno van en el SELECT,
        // ANTES que CodiInst/CodiDocu/fechas del WHERE en el texto SQL.
        $stmt->execute([...$paramsSigno, $this->codiInst(), ...$codigosPresupuesto, $fechaIni, $fechaFin]);

        $porDocumento = [];
        foreach ($stmt->fetchAll() as $fila) {
            $porDocumento[$fila['CodiDocu'] . '-' . $fila['NumeDocu']] = $fila;
        }

        return $porDocumento;
    }

    /**
     * Factura referenciada (vía DetaCont.TiDoRefe/NuDoRefe a nivel de línea)
     * de cada documento en $codigos, dentro del rango — para mostrar en el
     * detalle de diferencias qué factura afecta una nota/glosa que en sí
     * misma no es la factura directa.
     *
     * @param string[] $codigos
     * @return array<string, array{FacturaCodiDocu:string,FacturaNumeDocu:string,FacturaFecha:string}> indexado por "CodiDocu-NumeDocu" del documento (no de la factura)
     */
    public function fetchFacturaReferenciadaPorDocumento(array $codigos, string $fechaIni, string $fechaFin): array
    {
        if ($codigos === []) {
            return [];
        }

        $ph = implode(',', array_fill(0, count($codigos), '?'));
        $sql = "
            SELECT DISTINCT nc.CodiDocu, nc.NumeDocu,
                   fact.CodiDocu AS FacturaCodiDocu, fact.NumeDocu AS FacturaNumeDocu, fact.FechDocu AS FacturaFecha
            FROM EncaCont nc
            INNER JOIN DetaCont dcref
                ON dcref.CodiInst = nc.CodiInst AND dcref.CodiDocu = nc.CodiDocu AND dcref.NumeDocu = nc.NumeDocu
               AND dcref.TiDoRefe IS NOT NULL AND dcref.TiDoRefe <> ''
            INNER JOIN EncaCont fact
                ON fact.CodiInst = nc.CodiInst AND fact.CodiDocu = dcref.TiDoRefe AND fact.NumeDocu = dcref.NuDoRefe
            WHERE nc.CodiInst = ?
              AND nc.CodiDocu IN ({$ph})
              AND nc.FechDocu BETWEEN ? AND ?
        ";
        $stmt = $this->connect()->prepare($sql);
        $stmt->execute([$this->codiInst(), ...$codigos, $fechaIni, $fechaFin]);

        // Un documento SÍ puede referenciar varias facturas distintas (nota
        // consolidada de castigo/capita con una línea por factura —
        // verificado con un caso real: una sola nota redistribuyendo 12
        // facturas). Se guarda la primera para mostrar algo, pero se marca
        // 'ReferenciaAmbigua' para que quien sume valores (presupuesto, que
        // no tiene referencia por línea en DetaPlan) sepa que NO debe
        // atribuir el total a esa única factura — solo sirve para mostrar.
        $porDocumento = [];
        $conteos = [];
        foreach ($stmt->fetchAll() as $fila) {
            $clave = $fila['CodiDocu'] . '-' . $fila['NumeDocu'];
            $conteos[$clave] = ($conteos[$clave] ?? 0) + 1;
            if (!isset($porDocumento[$clave])) {
                $porDocumento[$clave] = $fila;
            }
        }
        foreach ($porDocumento as $clave => &$fila) {
            $fila['ReferenciaAmbigua'] = ($conteos[$clave] ?? 1) > 1;
        }
        unset($fila);

        return $porDocumento;
    }

    /**
     * Saldo contable de la familia 4312 (ingreso por servicios de salud)
     * por documento: EncaCont+DetaCont agregado por CodiDocu+NumeDocu, para
     * TODO documento que toque 4312xx en el rango (sin restringir tipo de
     * documento — así se detectan documentos "fuera de lista" que sí
     * afectan el ingreso contable pero no están en el universo de
     * presupuesto). El signo queda tal como se guarda en DetaCont (créditos
     * en negativo); se invierte al comparar contra presupuesto.
     *
     * @return array<string, array{CodiDocu:string,NumeDocu:string,FechDocu:string,TipoUsua:?string,Valor:float}> indexado por "CodiDocu-NumeDocu"
     */
    public function fetchContabilidad4312PorDocumento(string $fechaIni, string $fechaFin): array
    {
        $sql = "
            SELECT e.CodiDocu, e.NumeDocu, MIN(e.FechDocu) AS FechDocu, MIN(e.TipoUsua) AS TipoUsua, SUM(d.Valor) AS Valor
            FROM EncaCont e
            INNER JOIN DetaCont d ON e.CodiInst = d.CodiInst AND e.CodiDocu = d.CodiDocu AND e.NumeDocu = d.NumeDocu
            WHERE e.CodiInst = ?
              AND e.Causado = 1
              AND e.Anulado <> 1
              AND d.CodiCont LIKE '4312%'
              AND e.FechDocu BETWEEN ? AND ?
            GROUP BY e.CodiDocu, e.NumeDocu
        ";
        $stmt = $this->connect()->prepare($sql);
        $stmt->execute([$this->codiInst(), $fechaIni, $fechaFin]);

        $porDocumento = [];
        foreach ($stmt->fetchAll() as $fila) {
            $porDocumento[$fila['CodiDocu'] . '-' . $fila['NumeDocu']] = $fila;
        }

        return $porDocumento;
    }

    /**
     * Cuentas configuradas para la institución conectada: reversión de
     * glosa vigencia actual (CuenGlosAct), gasto/castigo vigencia anterior
     * (CuenGlos, CuenCast) y capita por defecto (AntCapita).
     */
    public function fetchCuentasInstitucion(): ?array
    {
        $stmt = $this->connect()->prepare('SELECT CuenGlosAct, CuenGlos, CuenCast, AntCapita FROM CodiInst WHERE CodiInst = ?');
        $stmt->execute([$this->codiInst()]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Cuentas de pasivo usadas como AntiCapi por centros de costo agregadores
     * de capita (p. ej. "FACTURACION CAPITADA"): las que NO son subcuenta
     * 4312xx (las 4312xx por centro de costo ya quedan cubiertas por el
     * chequeo genérico "empieza por 4"). CentCost no tiene columna CodiInst
     * en este esquema (no se filtra por institución).
     *
     * @return string[]
     */
    public function fetchCuentasCapitaPasivo(): array
    {
        $stmt = $this->connect()->query(
            "SELECT DISTINCT AntiCapi FROM CentCost WHERE AntiCapi IS NOT NULL AND AntiCapi <> '' AND AntiCapi NOT LIKE '4%'"
        );

        return array_column($stmt->fetchAll(), 'AntiCapi');
    }

    /**
     * Chequeo 1: facturas sin ninguna línea en DetaPlan.
     *
     * @param string[] $codigosFactura
     */
    public function fetchFacturasSinPresupuesto(array $codigosFactura, string $fechaIni, string $fechaFin): array
    {
        if ($codigosFactura === []) {
            return [];
        }

        $ph = implode(',', array_fill(0, count($codigosFactura), '?'));
        $sql = "
            SELECT ec.CodiDocu, ec.NumeDocu, ec.FechDocu, ec.ValoTota, ec.TiDoTerc, ec.NuDoTerc, ec.CentCost
            FROM EncaCont ec
            WHERE ec.CodiInst = ?
              AND ec.CodiDocu IN ({$ph})
              AND ec.FechDocu BETWEEN ? AND ?
              AND ec.Anulado = 0
              AND NOT EXISTS (
                  SELECT 1 FROM DetaPlan dp
                  WHERE dp.CodiInst = ec.CodiInst AND dp.CodiDocu = ec.CodiDocu AND dp.NumeDocu = ec.NumeDocu
              )
            ORDER BY ec.FechDocu, ec.NumeDocu
        ";
        $stmt = $this->connect()->prepare($sql);
        $stmt->execute([$this->codiInst(), ...$codigosFactura, $fechaIni, $fechaFin]);

        return $stmt->fetchAll();
    }

    /**
     * Chequeo 2: facturas sin ninguna línea de cuenta de ingreso (empieza
     * por 4) ni de capita pasivo (centro de costo agregador).
     *
     * @param string[] $codigosFactura
     * @param string[] $cuentasCapitaPasivo
     */
    public function fetchFacturasSinCuentaIngreso(
        array $codigosFactura,
        string $fechaIni,
        string $fechaFin,
        array $cuentasCapitaPasivo
    ): array {
        if ($codigosFactura === []) {
            return [];
        }

        $phFact = implode(',', array_fill(0, count($codigosFactura), '?'));
        $condicionIngreso = "dc.CodiCont LIKE '4%'";
        $paramsCapita = [];

        if ($cuentasCapitaPasivo !== []) {
            $phCapita = implode(',', array_fill(0, count($cuentasCapitaPasivo), '?'));
            $condicionIngreso .= " OR dc.CodiCont IN ({$phCapita})";
            $paramsCapita = $cuentasCapitaPasivo;
        }

        $sql = "
            SELECT ec.CodiDocu, ec.NumeDocu, ec.FechDocu, ec.ValoTota, ec.TiDoTerc, ec.NuDoTerc, ec.CentCost
            FROM EncaCont ec
            WHERE ec.CodiInst = ?
              AND ec.CodiDocu IN ({$phFact})
              AND ec.FechDocu BETWEEN ? AND ?
              AND ec.Anulado = 0
              AND NOT EXISTS (
                  SELECT 1 FROM DetaCont dc
                  WHERE dc.CodiInst = ec.CodiInst AND dc.CodiDocu = ec.CodiDocu AND dc.NumeDocu = ec.NumeDocu
                    AND ({$condicionIngreso})
              )
            ORDER BY ec.FechDocu, ec.NumeDocu
        ";
        $stmt = $this->connect()->prepare($sql);
        $stmt->execute([$this->codiInst(), ...$codigosFactura, $fechaIni, $fechaFin, ...$paramsCapita]);

        return $stmt->fetchAll();
    }

    /**
     * Chequeos 3 y 4: notas/glosas (NCC o GLA, según $codigosNota) que
     * referencian una factura de la MISMA vigencia (año de FechDocu) y no
     * tienen DetaPlan o no tienen una cuenta "esperada". Esperada = empieza
     * por 4 (reversión de ingreso normal), O es una cuenta que la factura
     * referenciada también usó en su propia causación — verificado con
     * datos reales: una nota que anula una factura CAPITA aún no
     * distribuida (sin DAC) revierte exactamente cartera/pasivo de esa
     * factura (ninguna 4312, porque la factura capita tampoco la tocó
     * directamente), y eso es una anulación válida, no una nota incompleta.
     * La factura referenciada se resuelve por DetaCont.TiDoRefe/NuDoRefe
     * (a nivel de línea, no de cabecera), dentro de la misma institución.
     *
     * @param string[] $codigosNota
     */
    public function fetchNotasVigenciaActualIncompletas(array $codigosNota, string $fechaIni, string $fechaFin): array
    {
        if ($codigosNota === []) {
            return [];
        }

        $ph = implode(',', array_fill(0, count($codigosNota), '?'));
        $codiInst = $this->codiInst();
        $sql = "
            SELECT DISTINCT
                nc.CodiDocu, nc.NumeDocu, nc.FechDocu, nc.ValoTota,
                fact.CodiDocu AS FacturaCodiDocu, fact.NumeDocu AS FacturaNumeDocu, fact.FechDocu AS FacturaFecha,
                (SELECT COUNT(*) FROM DetaPlan dp WHERE dp.CodiInst = nc.CodiInst AND dp.CodiDocu = nc.CodiDocu AND dp.NumeDocu = nc.NumeDocu) AS TieneDetaPlan,
                (SELECT COUNT(*) FROM DetaCont dc2
                  WHERE dc2.CodiInst = nc.CodiInst AND dc2.CodiDocu = nc.CodiDocu AND dc2.NumeDocu = nc.NumeDocu
                    AND (
                        dc2.CodiCont LIKE '4%'
                        OR EXISTS (
                            SELECT 1 FROM DetaCont dcf
                            WHERE dcf.CodiInst = fact.CodiInst AND dcf.CodiDocu = fact.CodiDocu AND dcf.NumeDocu = fact.NumeDocu
                              AND dcf.CodiCont = dc2.CodiCont
                        )
                    )
                ) AS TieneCuenta4
            FROM EncaCont nc
            INNER JOIN DetaCont dcref
                ON dcref.CodiInst = nc.CodiInst AND dcref.CodiDocu = nc.CodiDocu AND dcref.NumeDocu = nc.NumeDocu
               AND dcref.TiDoRefe IS NOT NULL AND dcref.TiDoRefe <> ''
            INNER JOIN EncaCont fact
                ON fact.CodiInst = nc.CodiInst AND fact.CodiDocu = dcref.TiDoRefe AND fact.NumeDocu = dcref.NuDoRefe
            WHERE nc.CodiInst = ?
              AND nc.CodiDocu IN ({$ph})
              AND nc.FechDocu BETWEEN ? AND ?
              AND nc.Anulado = 0
              AND YEAR(nc.FechDocu) = YEAR(fact.FechDocu)
            HAVING TieneDetaPlan = 0 OR TieneCuenta4 = 0
            ORDER BY nc.FechDocu, nc.NumeDocu
        ";
        $stmt = $this->connect()->prepare($sql);
        $stmt->execute([$codiInst, ...$codigosNota, $fechaIni, $fechaFin]);

        return $stmt->fetchAll();
    }

    /**
     * Chequeo 5 (facturas): líneas DetaCont con cuenta fuera de lo esperado
     * (no cartera 13/14, no ingreso 4xxx, no capita pasivo, no cuenta de
     * orden 8xxx — estas últimas se excluyen por ser memo/contingencia).
     *
     * @param string[] $codigosFactura
     * @param string[] $cuentasCapitaPasivo
     */
    public function fetchCuentasInesperadasFacturas(
        array $codigosFactura,
        string $fechaIni,
        string $fechaFin,
        array $cuentasCapitaPasivo
    ): array {
        if ($codigosFactura === []) {
            return [];
        }

        $phFact = implode(',', array_fill(0, count($codigosFactura), '?'));
        $exclCapita = '';
        $paramsCapita = [];

        if ($cuentasCapitaPasivo !== []) {
            $phCapita = implode(',', array_fill(0, count($cuentasCapitaPasivo), '?'));
            $exclCapita = " AND dc.CodiCont NOT IN ({$phCapita})";
            $paramsCapita = $cuentasCapitaPasivo;
        }

        $sql = "
            SELECT ec.CodiDocu, ec.NumeDocu, ec.FechDocu, dc.CodiCont, dc.Valor, dc.CentCost, dc.ConsDeta
            FROM EncaCont ec
            INNER JOIN DetaCont dc ON dc.CodiInst = ec.CodiInst AND dc.CodiDocu = ec.CodiDocu AND dc.NumeDocu = ec.NumeDocu
            WHERE ec.CodiInst = ?
              AND ec.CodiDocu IN ({$phFact})
              AND ec.FechDocu BETWEEN ? AND ?
              AND ec.Anulado = 0
              AND dc.CodiCont NOT LIKE '13%'
              AND dc.CodiCont NOT LIKE '14%'
              AND dc.CodiCont NOT LIKE '4%'
              AND dc.CodiCont NOT LIKE '8%'
              {$exclCapita}
            ORDER BY ec.FechDocu, ec.NumeDocu
        ";
        $stmt = $this->connect()->prepare($sql);
        $stmt->execute([$this->codiInst(), ...$codigosFactura, $fechaIni, $fechaFin, ...$paramsCapita]);

        return $stmt->fetchAll();
    }

    /**
     * Chequeo 5 (notas/glosas de vigencia actual): líneas DetaCont con
     * cuenta fuera de lo esperado. "Esperado" = cartera (13/14); la FAMILIA
     * de cuentas de reversión (prefijo de CodiInst.CuenGlosAct — es una
     * familia con una subcuenta por tipo de pagador, p. ej. 43951201 POS,
     * 43951203 subsidiado, 43951207 particulares... no un único valor); la
     * clase de cuentas de gasto/castigo (prefijo de CodiInst.CuenGlos, p.
     * ej. clase 58); cuenta de orden 8xxx (memo, excluida siempre); o
     * CUALQUIER cuenta que la factura referenciada también haya usado en su
     * propia causación (algunas instalaciones cancelan la nota contra la
     * misma subcuenta 4312xx de la factura, no contra una familia de
     * devoluciones dedicada — verificado con un caso real). Esta última
     * exclusión NO aplica si la nota se referencia a sí misma (TiDoRefe
     * apunta a su propio CodiDocu/NumeDocu): eso es en sí mismo un
     * problema de trazabilidad que no debe quedar oculto.
     * Mismo criterio de vigencia actual que los chequeos 3/4.
     *
     * @param string[] $codigosNota
     */
    public function fetchCuentasInesperadasNotasVigenciaActual(
        array $codigosNota,
        string $fechaIni,
        string $fechaFin,
        string $prefijoReversion,
        string $prefijoGasto
    ): array {
        if ($codigosNota === []) {
            return [];
        }

        $ph = implode(',', array_fill(0, count($codigosNota), '?'));

        $sql = "
            SELECT DISTINCT nc.CodiDocu, nc.NumeDocu, nc.FechDocu, dc.CodiCont, dc.Valor, dc.ConsDeta,
                   fact.CodiDocu AS FacturaCodiDocu, fact.NumeDocu AS FacturaNumeDocu
            FROM EncaCont nc
            INNER JOIN DetaCont dcref
                ON dcref.CodiInst = nc.CodiInst AND dcref.CodiDocu = nc.CodiDocu AND dcref.NumeDocu = nc.NumeDocu
               AND dcref.TiDoRefe IS NOT NULL AND dcref.TiDoRefe <> ''
            INNER JOIN EncaCont fact
                ON fact.CodiInst = nc.CodiInst AND fact.CodiDocu = dcref.TiDoRefe AND fact.NumeDocu = dcref.NuDoRefe
            INNER JOIN DetaCont dc
                ON dc.CodiInst = nc.CodiInst AND dc.CodiDocu = nc.CodiDocu AND dc.NumeDocu = nc.NumeDocu
            WHERE nc.CodiInst = ?
              AND nc.CodiDocu IN ({$ph})
              AND nc.FechDocu BETWEEN ? AND ?
              AND nc.Anulado = 0
              AND YEAR(nc.FechDocu) = YEAR(fact.FechDocu)
              AND dc.CodiCont NOT LIKE '13%'
              AND dc.CodiCont NOT LIKE '14%'
              AND dc.CodiCont NOT LIKE '8%'
              AND NOT (
                  (fact.CodiDocu <> nc.CodiDocu OR fact.NumeDocu <> nc.NumeDocu)
                  AND EXISTS (
                      SELECT 1 FROM DetaCont dcf
                      WHERE dcf.CodiInst = fact.CodiInst AND dcf.CodiDocu = fact.CodiDocu AND dcf.NumeDocu = fact.NumeDocu
                        AND dcf.CodiCont = dc.CodiCont
                  )
              )
        ";

        $params = [$this->codiInst(), ...$codigosNota, $fechaIni, $fechaFin];

        if ($prefijoReversion !== '') {
            $sql .= ' AND dc.CodiCont NOT LIKE ?';
            $params[] = $prefijoReversion . '%';
        }

        if ($prefijoGasto !== '') {
            $sql .= ' AND dc.CodiCont NOT LIKE ?';
            $params[] = $prefijoGasto . '%';
        }

        $sql .= ' ORDER BY nc.FechDocu, nc.NumeDocu';

        $stmt = $this->connect()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * ¿Está cerrado el módulo Presupuesto (Modulo=30 — catálogo fijo del
     * software SIHOS, no configurable por institución) para ese año/mes?
     * Mismo criterio que usa el propio SIHOS antes de permitir tocar datos
     * de un período (sihos/modulos/procesos/cierramesdia.php): fila en
     * `Cierres` con TipoMovi=2 (cerrado), CodiDia=0 (cierre mensual, no
     * diario — solo Caja cierra por día). Se usa como chequeo previo,
     * de solo lectura, antes de cualquier escritura en SIHOS.
     */
    public function isPresupuestoCerrado(string $codiAno, string $codiMes): bool
    {
        $stmt = $this->connect()->prepare(
            "SELECT COUNT(*) FROM Cierres
             WHERE CodiInst = ? AND Modulo = 30 AND CodiDia = 0 AND TipoMovi = 2
               AND CodiAno = ? AND CodiMes = ?"
        );
        $stmt->execute([$this->codiInst(), $codiAno, $codiMes]);

        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Estado actual (en el momento en que se llama, no un snapshot previo)
     * de un documento puntual: su fecha, si está anulado, la suma de sus
     * líneas DetaPlan y la factura que referencia (si alguna). Se usa para
     * re-verificar en fresco, justo antes de borrar, que la condición de
     * "nota sobre factura de vigencia anterior con presupuesto huérfano"
     * sigue siendo cierta — no basta con confiar en lo que ya mostraba el
     * reporte, pudo haber cambiado entre que se generó y que se actuó.
     */
    public function fetchEstadoParaEliminacionDetaPlan(string $codiDocu, string $numeDocu): ?array
    {
        $sql = "
            SELECT nc.FechDocu, nc.Anulado,
                   (SELECT COALESCE(SUM(dp.Valor), 0) FROM DetaPlan dp
                     WHERE dp.CodiInst = nc.CodiInst AND dp.CodiDocu = nc.CodiDocu AND dp.NumeDocu = nc.NumeDocu) AS SumaPresupuesto,
                   fact.CodiDocu AS FacturaCodiDocu, fact.NumeDocu AS FacturaNumeDocu, fact.FechDocu AS FacturaFecha
            FROM EncaCont nc
            LEFT JOIN DetaCont dcref
                ON dcref.CodiInst = nc.CodiInst AND dcref.CodiDocu = nc.CodiDocu AND dcref.NumeDocu = nc.NumeDocu
               AND dcref.TiDoRefe IS NOT NULL AND dcref.TiDoRefe <> ''
            LEFT JOIN EncaCont fact
                ON fact.CodiInst = nc.CodiInst AND fact.CodiDocu = dcref.TiDoRefe AND fact.NumeDocu = dcref.NuDoRefe
            WHERE nc.CodiInst = ? AND nc.CodiDocu = ? AND nc.NumeDocu = ?
            LIMIT 1
        ";
        $stmt = $this->connect()->prepare($sql);
        $stmt->execute([$this->codiInst(), $codiDocu, $numeDocu]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Para facturas dentro del rango, busca CUALQUIER documento de rol
     * "vinculado" (nota, glosa, nota genérica, DAC — lo que traiga
     * $codigosVinculados) que la referencie vía TiDoRefe/NuDoRefe, SIN
     * restringir su cuenta contable ni su fecha (pero sí excluye anulados —
     * uno anulado no cuenta como vínculo real, mostrarlo como "relacionado"
     * confundiría). No se restringe a facturas capita: una factura normal
     * cancelada por una nota (que puede tocar cartera/pasivo, no
     * necesariamente 4312) también necesita esta anotación — verificado con
     * un caso real (NCF que anula una factura capita tocando 140903/291027,
     * ninguna de las dos 4312).
     *
     * Se usa solo para anotar "el documento real está en tal fecha" cuando
     * ese documento quedó fuera del rango filtrado — nunca para sumarlo a
     * ningún total, solo para mostrar dónde está y por qué no se concilió.
     *
     * @param string[] $codigosFactura
     * @param string[] $codigosVinculados
     * @return array<string, array{VinculadoCodiDocu:string,VinculadoNumeDocu:string,VinculadoFecha:string}> indexado por "CodiDocu-NumeDocu" de la factura
     */
    public function fetchDocumentoVinculadoPorFactura(
        array $codigosFactura,
        array $codigosVinculados,
        string $fechaIni,
        string $fechaFin
    ): array {
        if ($codigosFactura === [] || $codigosVinculados === []) {
            return [];
        }

        $phFact = implode(',', array_fill(0, count($codigosFactura), '?'));
        $phVinc = implode(',', array_fill(0, count($codigosVinculados), '?'));

        $sql = "
            SELECT fe.CodiDocu, fe.NumeDocu,
                   MIN(vinc.CodiDocu) AS VinculadoCodiDocu, MIN(vinc.NumeDocu) AS VinculadoNumeDocu, MIN(vinc.FechDocu) AS VinculadoFecha
            FROM EncaCont fe
            INNER JOIN DetaCont d
                ON d.CodiInst = fe.CodiInst AND d.TiDoRefe = fe.CodiDocu AND d.NuDoRefe = fe.NumeDocu
            INNER JOIN EncaCont vinc
                ON vinc.CodiInst = d.CodiInst AND vinc.CodiDocu = d.CodiDocu AND vinc.NumeDocu = d.NumeDocu
               AND vinc.Anulado <> 1 AND vinc.CodiDocu IN ({$phVinc})
            WHERE fe.CodiInst = ?
              AND fe.CodiDocu IN ({$phFact})
              AND fe.FechDocu BETWEEN ? AND ?
            GROUP BY fe.CodiDocu, fe.NumeDocu
        ";
        $stmt = $this->connect()->prepare($sql);
        $stmt->execute([...$codigosVinculados, $this->codiInst(), ...$codigosFactura, $fechaIni, $fechaFin]);

        $porFactura = [];
        foreach ($stmt->fetchAll() as $fila) {
            $porFactura[$fila['CodiDocu'] . '-' . $fila['NumeDocu']] = $fila;
        }

        return $porFactura;
    }

    /**
     * Atribución de contabilidad (4312xx) de documentos vinculados a las
     * facturas que referencian, LÍNEA POR LÍNEA (no por documento completo)
     * — un documento vinculado puede referenciar VARIAS facturas distintas,
     * una por línea (verificado con un caso real: una sola nota con 12
     * líneas, cada una redistribuyendo 4312 a una factura diferente; sumar
     * el total del documento a una sola factura estaba mal). Solo se
     * atribuye cuando AMBOS documentos (el vinculado y la factura) caen
     * dentro del rango filtrado — mismo criterio de conciliación silenciosa
     * que el resto del módulo.
     *
     * @param string[] $codigosVinculados
     * @param string[] $codigosFactura
     * @return list<array{VinculadoCodiDocu:string,VinculadoNumeDocu:string,FacturaCodiDocu:string,FacturaNumeDocu:string,FacturaFecha:string,FacturaTipoUsua:?string,Valor:float}>
     */
    public function fetchAtribucionContabilidadVinculada(
        array $codigosVinculados,
        array $codigosFactura,
        string $fechaIni,
        string $fechaFin
    ): array {
        if ($codigosVinculados === [] || $codigosFactura === []) {
            return [];
        }

        $phVinc = implode(',', array_fill(0, count($codigosVinculados), '?'));
        $phFact = implode(',', array_fill(0, count($codigosFactura), '?'));

        $sql = "
            SELECT vinc.CodiDocu AS VinculadoCodiDocu, vinc.NumeDocu AS VinculadoNumeDocu,
                   fact.CodiDocu AS FacturaCodiDocu, fact.NumeDocu AS FacturaNumeDocu,
                   fact.FechDocu AS FacturaFecha, fact.TipoUsua AS FacturaTipoUsua,
                   SUM(dcref.Valor) AS Valor
            FROM EncaCont vinc
            INNER JOIN DetaCont dcref
                ON dcref.CodiInst = vinc.CodiInst AND dcref.CodiDocu = vinc.CodiDocu AND dcref.NumeDocu = vinc.NumeDocu
               AND dcref.CodiCont LIKE '4312%'
            INNER JOIN EncaCont fact
                ON fact.CodiInst = vinc.CodiInst AND fact.CodiDocu = dcref.TiDoRefe AND fact.NumeDocu = dcref.NuDoRefe
            WHERE vinc.CodiInst = ?
              AND vinc.CodiDocu IN ({$phVinc})
              AND vinc.FechDocu BETWEEN ? AND ?
              AND vinc.Anulado <> 1
              AND fact.CodiDocu IN ({$phFact})
              AND fact.FechDocu BETWEEN ? AND ?
            GROUP BY vinc.CodiDocu, vinc.NumeDocu, fact.CodiDocu, fact.NumeDocu, fact.FechDocu, fact.TipoUsua
        ";
        $stmt = $this->connect()->prepare($sql);
        $stmt->execute([$this->codiInst(), ...$codigosVinculados, $fechaIni, $fechaFin, ...$codigosFactura, $fechaIni, $fechaFin]);

        return $stmt->fetchAll();
    }

    /**
     * Para facturas en rango: qué otras cuentas (fuera de cartera 13/14,
     * ingreso 4312xx, capita pasivo, y cuenta de orden 8xxx) tocó la
     * factura en su propia causación — NO para conciliar nada, solo para
     * mostrar en la sección de diferencias qué cuenta real se usó cuando
     * no es 4312 (verificado con un caso real: una factura "Otros
     * deudores" acredita 48xx "OTROS INGRESOS" en vez de 4312, así que su
     * contabilidad(4312) da $0 aunque sí tiene un asiento real). La
     * diferencia se sigue mostrando igual — esto es solo trazabilidad.
     *
     * @param string[] $codigosFactura
     * @param string[] $cuentasCapitaPasivo
     * @return array<string, list<array{CodiCont:string,Valor:float}>> indexado por "CodiDocu-NumeDocu"
     */
    public function fetchCuentasNoIdentificadasFacturas(
        array $codigosFactura,
        array $cuentasCapitaPasivo,
        string $fechaIni,
        string $fechaFin
    ): array {
        if ($codigosFactura === []) {
            return [];
        }

        $phFact = implode(',', array_fill(0, count($codigosFactura), '?'));
        $exclCapita = '';
        $paramsCapita = [];

        if ($cuentasCapitaPasivo !== []) {
            $phCapita = implode(',', array_fill(0, count($cuentasCapitaPasivo), '?'));
            $exclCapita = " AND dc.CodiCont NOT IN ({$phCapita})";
            $paramsCapita = $cuentasCapitaPasivo;
        }

        $sql = "
            SELECT ec.CodiDocu, ec.NumeDocu, dc.CodiCont, SUM(dc.Valor) AS Valor
            FROM EncaCont ec
            INNER JOIN DetaCont dc ON dc.CodiInst = ec.CodiInst AND dc.CodiDocu = ec.CodiDocu AND dc.NumeDocu = ec.NumeDocu
            WHERE ec.CodiInst = ?
              AND ec.CodiDocu IN ({$phFact})
              AND ec.FechDocu BETWEEN ? AND ?
              AND ec.Anulado = 0
              AND dc.CodiCont NOT LIKE '13%'
              AND dc.CodiCont NOT LIKE '14%'
              AND dc.CodiCont NOT LIKE '4312%'
              AND dc.CodiCont NOT LIKE '8%'
              {$exclCapita}
            GROUP BY ec.CodiDocu, ec.NumeDocu, dc.CodiCont
        ";
        $stmt = $this->connect()->prepare($sql);
        $stmt->execute([$this->codiInst(), ...$codigosFactura, $fechaIni, $fechaFin, ...$paramsCapita]);

        $porFactura = [];
        foreach ($stmt->fetchAll() as $fila) {
            $clave = $fila['CodiDocu'] . '-' . $fila['NumeDocu'];
            $porFactura[$clave][] = ['CodiCont' => $fila['CodiCont'], 'Valor' => (float)$fila['Valor']];
        }

        return $porFactura;
    }

    /**
     * Rubros presupuestales (DetaPlan.CodiPlan) afectados por cada documento
     * de $codigosDocumento, sumados por rubro (un documento puede repetir el
     * mismo CodiPlan en varias líneas — p. ej. subdivisión por Pac01..Pac12/
     * vigencia). Solo para anotación/trazabilidad (no cambia ningún total).
     * El nombre legible viene de PlanPres (catálogo real por CodiInst+
     * CodiAno+CodiPlan) — NO de la tabla `CodiPlan`, que pese al nombre no es
     * el catálogo de rubros de esta instalación (solo 2 filas fijas "POS"/
     * "NO POS", verificado). PlanPres no tiene el mismo CodiPlan en todos los
     * años necesariamente, así que el join usa el CodiAno propio de la línea
     * de DetaPlan, no el de hoy.
     *
     * @param string[] $codigosDocumento
     * @return array<string, list<array{CodiPlan:string,NombPlan:?string,Valor:float}>> indexado por "CodiDocu-NumeDocu", cada lista ordenada por |Valor| descendente
     */
    public function fetchRubrosPresupuestoPorDocumento(array $codigosDocumento, string $fechaIni, string $fechaFin): array
    {
        if ($codigosDocumento === []) {
            return [];
        }

        $ph = implode(',', array_fill(0, count($codigosDocumento), '?'));
        $sql = "
            SELECT ec.CodiDocu, ec.NumeDocu, dp.CodiPlan, pp.NombPlan, SUM(dp.Valor) AS Valor
            FROM EncaCont ec
            INNER JOIN DetaPlan dp
                ON dp.CodiInst = ec.CodiInst AND dp.CodiAno = ec.CodiAno AND dp.CodiDocu = ec.CodiDocu AND dp.NumeDocu = ec.NumeDocu
            LEFT JOIN PlanPres pp
                ON pp.CodiInst = dp.CodiInst AND pp.CodiAno = dp.CodiAno AND pp.CodiPlan = dp.CodiPlan
            WHERE ec.CodiInst = ?
              AND ec.CodiDocu IN ({$ph})
              AND ec.FechDocu BETWEEN ? AND ?
            GROUP BY ec.CodiDocu, ec.NumeDocu, dp.CodiPlan, pp.NombPlan
        ";
        $stmt = $this->connect()->prepare($sql);
        $stmt->execute([$this->codiInst(), ...$codigosDocumento, $fechaIni, $fechaFin]);

        $porDocumento = [];
        foreach ($stmt->fetchAll() as $fila) {
            $clave = $fila['CodiDocu'] . '-' . $fila['NumeDocu'];
            $porDocumento[$clave][] = [
                'CodiPlan' => $fila['CodiPlan'],
                'NombPlan' => $fila['NombPlan'],
                'Valor' => (float)$fila['Valor'],
            ];
        }

        foreach ($porDocumento as &$lista) {
            usort($lista, static fn (array $a, array $b): int => abs($b['Valor']) <=> abs($a['Valor']));
        }
        unset($lista);

        return $porDocumento;
    }
}
