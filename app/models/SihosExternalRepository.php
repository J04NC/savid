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
        while ($fila = $stmt->fetch()) {
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
        while ($fila = $stmt->fetch()) {
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
        while ($fila = $stmt->fetch()) {
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
        while ($fila = $stmt->fetch()) {
            $porDocumento[$fila['CodiDocu'] . '-' . $fila['NumeDocu']] = $fila;
        }

        return $porDocumento;
    }

    /**
     * Cuentas configuradas para la institución conectada. Confirmado contra
     * el código fuente de SIHOS (sihos/modulos/administracion/codiinst.php):
     * CuenGlosAct = "Aceptación de Glosas Vigencia Actual"; CuenGlos =
     * "Aceptación de Glosas Vigencia Anterior" (el nombre de columna es
     * engañoso — pese a no decir "Act"/"Ant" en el nombre, es la de
     * vigencia ANTERIOR); CuenCast = "Conciliación" (vigencia actual);
     * CuenDeAn = "Devolución de Facturas Vigencias Anteriores"; CuenCaAn =
     * "Conciliación Vigencias Anteriores"; AntCapita = capita por defecto.
     */
    public function fetchCuentasInstitucion(): ?array
    {
        $stmt = $this->connect()->prepare('SELECT CuenGlosAct, CuenGlos, CuenCast, CuenDeAn, CuenCaAn, AntCapita FROM CodiInst WHERE CodiInst = ?');
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
     * Exclusión propia de glosas (no aplica a notas, que nunca tienen fila en
     * AnotGlos): en SIHOS (modulos/glosas/anotglos.php), una glosa aceptada
     * solo genera la reversión real (cuenta 4xx + DetaPlan) cuando la
     * anotación que la originó (AnotGlos.CoDoCont/NuDoCont = este documento)
     * tiene TipoCond=1 (Conciliado a favor) o TipoCond=2 (Aceptado) CON
     * TipoDeta=1 ("Enviada"). Si es TipoCond=2 con TipoDeta=2 ("Recibida" —
     * la EPS notificó que acepta, pero el prestador aún no lo tramitó
     * formalmente), SIHOS únicamente crea el par de cuentas de orden (memo) y
     * actualiza GlosAcEA, nunca Saldo/DetaPlan/cuenta 4xx — a propósito, no
     * por un proceso incompleto. Verificado con datos reales (empresa 18):
     * las 37 filas que antes salían aquí para glosas tenían TODAS
     * exactamente TipoCond=2/TipoDeta=2 — ninguna era un caso genuino de
     * proceso pendiente. Confirmado con el usuario: cuando la anotación sí
     * contabiliza, ya es definitiva, no queda "intermedio".
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
                ) AS TieneCuenta4,
                ag.TipoCond AS AnotGlosTipoCond,
                ag.TipoDeta AS AnotGlosTipoDeta
            FROM EncaCont nc
            INNER JOIN DetaCont dcref
                ON dcref.CodiInst = nc.CodiInst AND dcref.CodiDocu = nc.CodiDocu AND dcref.NumeDocu = nc.NumeDocu
               AND dcref.TiDoRefe IS NOT NULL AND dcref.TiDoRefe <> ''
            INNER JOIN EncaCont fact
                ON fact.CodiInst = nc.CodiInst AND fact.CodiDocu = dcref.TiDoRefe AND fact.NumeDocu = dcref.NuDoRefe
            LEFT JOIN AnotGlos ag
                ON ag.CodiInst = nc.CodiInst AND ag.CodiDocu = nc.TiDoRefe AND ag.NumeGlos = nc.NuDoRefe
               AND ag.CoDoCont = nc.CodiDocu AND ag.NuDoCont = nc.NumeDocu
            WHERE nc.CodiInst = ?
              AND nc.CodiDocu IN ({$ph})
              AND nc.FechDocu BETWEEN ? AND ?
              AND nc.Anulado = 0
              AND YEAR(nc.FechDocu) = YEAR(fact.FechDocu)
            HAVING (TieneDetaPlan = 0 OR TieneCuenta4 = 0)
               AND (AnotGlosTipoCond IS NULL OR AnotGlosTipoCond = 1 OR (AnotGlosTipoCond = 2 AND AnotGlosTipoDeta = 1))
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
            SELECT ec.CodiDocu, ec.NumeDocu, ec.FechDocu, dc.CodiCont, dc.Valor, dc.CentCost, dc.ConsDeta,
                   (SELECT COUNT(*) FROM DetaCont dc4
                     WHERE dc4.CodiInst = ec.CodiInst AND dc4.CodiDocu = ec.CodiDocu AND dc4.NumeDocu = ec.NumeDocu
                       AND dc4.CodiCont LIKE '4312%'
                   ) AS Tiene4312
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
     * Notas (NCF) que referencian una factura de VIGENCIA ANTERIOR (año
     * distinto, más viejo) y tocan una cuenta fuera de lo esperado — cuenta
     * hermana de fetchCuentasInesperadasNotasVigenciaActual() pero con dos
     * diferencias deliberadas, verificadas contra datos reales:
     *
     * 1) `YEAR(fact.FechDocu) < YEAR(nc.FechDocu)` en vez de `=` — la
     *    factura es de un año anterior al de la nota.
     * 2) SIN la excepción "espejo" (cuenta que la factura también usó en su
     *    propia causación): esa excepción se diseñó para notas de la MISMA
     *    vigencia (p. ej. anulación de capita sin distribuir) y aquí tapa
     *    justo el bug que se busca — la factura vieja casi siempre usó esa
     *    misma cuenta 4312 al facturar, por eso la nota la vuelve a tocar en
     *    vez de ir contra la cuenta de vigencia anterior configurada
     *    (CodiInst.CuenGlosAct/CuenGlos/CuenCast). Verificado: sin quitar
     *    la excepción, 0 de 8 casos reales se detectaban; quitándola,
     *    8 de 8, sin falsos positivos sobre notas ya bien clasificadas.
     *
     * A diferencia del chequeo de vigencia actual, esto SÍ tiene una acción
     * asociada (reclasificar la cuenta) — ver SihosCancelacionCuentaService.
     *
     * @param string[] $codigosNota
     * @return list<array{CodiDocu:string,NumeDocu:string,FechDocu:string,CodiCont:string,Valor:float,ConsDeta:int,FacturaCodiDocu:string,FacturaNumeDocu:string,FacturaFecha:string}>
     */
    public function fetchCuentasInesperadasNotasVigenciaAnterior(
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
                   fact.CodiDocu AS FacturaCodiDocu, fact.NumeDocu AS FacturaNumeDocu, fact.FechDocu AS FacturaFecha
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
              AND YEAR(fact.FechDocu) < YEAR(nc.FechDocu)
              AND dc.CodiCont NOT LIKE '13%'
              AND dc.CodiCont NOT LIKE '14%'
              AND dc.CodiCont NOT LIKE '8%'
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
     * Resuelve el rubro presupuestal (`TipoUsua.CodiPlan`) configurado para
     * un tipo de usuario, por el año configurado más cercano ≤ el año
     * objetivo — `TipoUsua` no siempre tiene fila para el año exacto
     * (verificado: para varios CodiTipo reales solo existe configurado
     * 2024, reutilizado también para notas de 2026). Nunca coincidencia
     * exacta de año.
     */
    public function resolveCodiPlanTipoUsua(string $codiTipo, string $codiAno): ?string
    {
        $stmt = $this->connect()->prepare(
            'SELECT CodiPlan FROM TipoUsua
             WHERE CodiInst = ? AND CodiTipo = ? AND CodiAno <= ?
             ORDER BY CodiAno DESC LIMIT 1'
        );
        $stmt->execute([$this->codiInst(), $codiTipo, $codiAno]);
        $codiPlan = $stmt->fetchColumn();

        return $codiPlan === false || $codiPlan === null || $codiPlan === '' ? null : (string)$codiPlan;
    }

    /**
     * Login de un usuario activo de SIHOS (`Usuarios.Login`, varchar(8) —
     * cabe sin truncar en el `UsuaDigi`/`UsuaModi` varchar(12) de las tablas
     * que SAVID escribe) que tenga el mismo tipo+número de documento que un
     * usuario de SAVID. `Usuarios.CC` guarda el número pese al nombre de la
     * columna; se filtra `Activo = 1` — un usuario desactivado en SIHOS no
     * debe recibir la atribución aunque el documento coincida. `null` si no
     * hay match (uso normal: la mayoría de usuarios de SAVID no tienen
     * cuenta en SIHOS, cae a un literal fijo en el llamador).
     */
    public function resolveLoginUsuarioPorDocumento(string $tipoDocu, string $numero): ?string
    {
        $stmt = $this->connect()->prepare(
            'SELECT Login FROM Usuarios WHERE TipoDocu = ? AND CC = ? AND Activo = 1 LIMIT 1'
        );
        $stmt->execute([$tipoDocu, $numero]);
        $login = $stmt->fetchColumn();

        return $login === false || $login === null || $login === '' ? null : (string)$login;
    }

    /**
     * Estado actual (en el momento en que se llama, no un snapshot previo)
     * de una nota de vigencia actual para la acción "construir DetaPlan
     * faltante" (sección 3): su fecha, si está anulada, su ValoTota, y la
     * factura que referencia (con su TipoUsua) — se usa para re-verificar
     * en fresco que la nota sigue sin DetaPlan justo antes de escribir.
     * `null` si el documento no existe o no referencia ninguna factura.
     */
    public function fetchEstadoParaConstruirDetaPlan(string $codiDocuNota, string $numeDocuNota): ?array
    {
        $sql = "
            SELECT nc.FechDocu, nc.Anulado, nc.ValoTota,
                   (SELECT COUNT(*) FROM DetaPlan dp
                     WHERE dp.CodiInst = nc.CodiInst AND dp.CodiDocu = nc.CodiDocu AND dp.NumeDocu = nc.NumeDocu) AS TieneDetaPlan,
                   fact.CodiDocu AS FacturaCodiDocu, fact.NumeDocu AS FacturaNumeDocu,
                   fact.FechDocu AS FacturaFecha, fact.TipoUsua AS FacturaTipoUsua
            FROM EncaCont nc
            INNER JOIN DetaCont dcref
                ON dcref.CodiInst = nc.CodiInst AND dcref.CodiDocu = nc.CodiDocu AND dcref.NumeDocu = nc.NumeDocu
               AND dcref.TiDoRefe IS NOT NULL AND dcref.TiDoRefe <> ''
            INNER JOIN EncaCont fact
                ON fact.CodiInst = nc.CodiInst AND fact.CodiDocu = dcref.TiDoRefe AND fact.NumeDocu = dcref.NuDoRefe
            WHERE nc.CodiInst = ? AND nc.CodiDocu = ? AND nc.NumeDocu = ?
            LIMIT 1
        ";
        $stmt = $this->connect()->prepare($sql);
        $stmt->execute([$this->codiInst(), $codiDocuNota, $numeDocuNota]);
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
        while ($fila = $stmt->fetch()) {
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
        while ($fila = $stmt->fetch()) {
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
        while ($fila = $stmt->fetch()) {
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

    /**
     * Código de documento (MaesDocu.CodiDocu) para la "Nota Contabilidad"
     * genérica de esta institución: DocuApli=3 (ajustes contables/notas
     * genéricas), ManeCont=1, resuelto por NOMBRE ("NOTA CONTAB%") — nunca
     * por código literal 'NC', porque CodiDocu es configurable por
     * instalación (mismo principio que el resto del repositorio). Verificado
     * con datos reales: existe literalmente CodiDocu='NC',
     * NombDocu='NOTA CONTABILIDAD' para empresa 18.
     */
    public function resolveCodigoNotaContableGenerica(): ?string
    {
        $stmt = $this->connect()->prepare(
            "SELECT CodiDocu FROM MaesDocu
             WHERE CodiInst = ? AND DocuApli = 3 AND ManeCont = 1 AND NombDocu LIKE 'NOTA CONTAB%'
             LIMIT 1"
        );
        $stmt->execute([$this->codiInst()]);
        $codiDocu = $stmt->fetchColumn();

        return $codiDocu === false ? null : (string)$codiDocu;
    }

    /**
     * ¿La institución maneja NIIF (libros paralelos)? Si es así, cada línea
     * contable que se inserte debe reflejarse también en DetaNIIF (ver
     * SihosExternalWriteRepository::crearNotaCancelacionCuentaInesperada).
     */
    public function fetchManeNIIF(): bool
    {
        $stmt = $this->connect()->prepare('SELECT ManeNIIF FROM CodiInst WHERE CodiInst = ?');
        $stmt->execute([$this->codiInst()]);

        return (int)$stmt->fetchColumn() === 1;
    }

    /**
     * ¿Está cerrado el módulo de Contabilidad (Modulo=22 — catálogo fijo del
     * software, confirmado con los Cierres reales de 2026 de empresa 18)
     * para ese año/mes? Mismo criterio que isPresupuestoCerrado() pero para
     * comprobantes contables genéricos (Nota Contabilidad), no presupuesto.
     */
    public function isContabilidadCerrada(string $codiAno, string $codiMes): bool
    {
        $stmt = $this->connect()->prepare(
            "SELECT COUNT(*) FROM Cierres
             WHERE CodiInst = ? AND Modulo = 22 AND CodiDia = 0 AND TipoMovi = 2
               AND CodiAno = ? AND CodiMes = ?"
        );
        $stmt->execute([$this->codiInst(), $codiAno, $codiMes]);

        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Homologación NIIF (tabla global HomoNIIF, sin CodiInst) para un
     * conjunto de cuentas contables. Si una cuenta no aparece en el
     * resultado, no tiene homologación — SIHOS bloquearía la contabilización
     * NIIF de esa cuenta, y esta acción debe bloquear igual.
     *
     * @param string[] $codigosCuenta
     * @return array<string, string> CodiCont => idPartNIIF
     */
    public function fetchHomologacionesNIIF(array $codigosCuenta): array
    {
        if ($codigosCuenta === []) {
            return [];
        }

        $ph = implode(',', array_fill(0, count($codigosCuenta), '?'));
        $stmt = $this->connect()->prepare("SELECT CodiCont, idPartNIIF FROM HomoNIIF WHERE CodiCont IN ({$ph})");
        $stmt->execute($codigosCuenta);

        $porCuenta = [];
        while ($fila = $stmt->fetch()) {
            $porCuenta[$fila['CodiCont']] = $fila['idPartNIIF'];
        }

        return $porCuenta;
    }

    /**
     * ¿Ya existe un ajuste contable previo para esta cuenta puntual de esta
     * factura? Busca cualquier OTRO documento (no la factura misma) cuya
     * línea DetaCont referencie esta factura (TiDoRefe/NuDoRefe) sobre esta
     * misma cuenta — la huella que deja `nota_ajuste` (o cualquier
     * corrección equivalente hecha directamente en SIHOS). Evita duplicar
     * la corrección si la acción se reintenta (p. ej. tras un timeout de
     * red donde la primera escritura sí se completó en SIHOS pero la
     * respuesta nunca llegó al navegador).
     *
     * @return array{CodiDocu:string,NumeDocu:string}|null
     */
    public function fetchAjustePrevio(string $codiDocuFactura, string $numeDocuFactura, string $cuentaInesperada): ?array
    {
        $stmt = $this->connect()->prepare(
            'SELECT dc.CodiDocu, dc.NumeDocu
             FROM DetaCont dc
             INNER JOIN EncaCont ec ON ec.CodiInst = dc.CodiInst AND ec.CodiDocu = dc.CodiDocu AND ec.NumeDocu = dc.NumeDocu
             WHERE dc.CodiInst = ? AND dc.TiDoRefe = ? AND dc.NuDoRefe = ? AND dc.CodiCont = ?
               AND dc.CodiDocu <> ? AND ec.Anulado = 0
             ORDER BY ec.FechDigi DESC, ec.HoraDigi DESC
             LIMIT 1'
        );
        $stmt->execute([$this->codiInst(), $codiDocuFactura, $numeDocuFactura, $cuentaInesperada, $codiDocuFactura]);
        $fila = $stmt->fetch();

        return $fila === false ? null : $fila;
    }

    /**
     * Versión en lote de fetchAjustePrevio(): para un conjunto puntual de
     * facturas (las ya encontradas con cuenta fuera de lo esperado, no todo
     * el universo de facturas) evita N consultas al pintar la sección 5a.
     * Mismo criterio — sin filtro de fecha, porque la pregunta es si la
     * corrección YA EXISTE en SIHOS, no si cae dentro del rango filtrado.
     *
     * Filtra por tripletas (CodiDocu,NumeDocu,CodiCont) exactas — no basta
     * con el par factura, porque TiDoRefe/NuDoRefe son usados por
     * prácticamente toda la contabilidad de esa factura (cartera, glosas,
     * pagos...) para referenciarla; sin acotar también por cuenta, la
     * consulta escanea muchas más filas de las necesarias y devuelve
     * coincidencias en cuentas que no son la "fuera de lo esperado" que nos
     * interesa (verificado con datos reales: sin este filtro tardaba >12s y
     * traía cuentas de cartera/reversión ajenas al caso).
     *
     * @param list<array{CodiDocu:string,NumeDocu:string,CodiCont:string}> $filas
     * @return array<string, true> claves "CodiDocuFactura-NumeDocuFactura-CodiCont" ya ajustadas
     */
    public function fetchClavesConAjustePrevio(array $filas): array
    {
        if ($filas === []) {
            return [];
        }

        $triplasUnicas = [];
        foreach ($filas as $f) {
            $triplasUnicas[$f['CodiDocu'] . '|' . $f['NumeDocu'] . '|' . $f['CodiCont']] = [$f['CodiDocu'], $f['NumeDocu'], $f['CodiCont']];
        }
        $triplasUnicas = array_values($triplasUnicas);

        // OR-es explícitos, no "(a,b,c) IN ((..),(..))": en MySQL 5.6 (la
        // versión real de SIHOS, verificado con EXPLAIN) el IN de tuplas no
        // usa ninguno de los índices por TiDoRefe/NuDoRefe/CodiCont y termina
        // escaneando ~1.4M filas (>12s, agotó memoria en un caso). Con OR
        // explícitos, MySQL sí usa el índice (rows≈10, <30ms).
        $condiciones = implode(' OR ', array_fill(0, count($triplasUnicas), '(dc.TiDoRefe = ? AND dc.NuDoRefe = ? AND dc.CodiCont = ?)'));
        $params = [$this->codiInst()];
        foreach ($triplasUnicas as [$cd, $nd, $cc]) {
            $params[] = $cd;
            $params[] = $nd;
            $params[] = $cc;
        }

        $stmt = $this->connect()->prepare(
            "SELECT DISTINCT dc.TiDoRefe AS FacturaCodiDocu, dc.NuDoRefe AS FacturaNumeDocu, dc.CodiCont
             FROM DetaCont dc
             INNER JOIN EncaCont ec ON ec.CodiInst = dc.CodiInst AND ec.CodiDocu = dc.CodiDocu AND ec.NumeDocu = dc.NumeDocu
             WHERE dc.CodiInst = ?
               AND ({$condiciones})
               AND NOT (dc.CodiDocu = dc.TiDoRefe AND dc.NumeDocu = dc.NuDoRefe)
               AND ec.Anulado = 0"
        );
        $stmt->execute($params);

        $claves = [];
        while ($fila = $stmt->fetch()) {
            $claves[$fila['FacturaCodiDocu'] . '-' . $fila['FacturaNumeDocu'] . '-' . $fila['CodiCont']] = true;
        }

        return $claves;
    }

    /**
     * Re-verificación en fresco (justo antes de escribir, no confiar en el
     * snapshot del reporte) del estado de una factura y de la línea contable
     * "fuera de lo esperado" puntual (por ConsDeta) que se quiere cancelar
     * contra las líneas 4312 que la factura tenga en este momento. Devuelve
     * null si la factura ya no existe o esa línea puntual ya no está (pudo
     * haberse corregido o borrado entre que se generó el reporte y que se
     * hizo clic).
     *
     * @return array{Anulado:int,FechDocu:string,TiDoTerc:?string,NuDoTerc:?string,CodiCent:?string,LineaInesperadaCodiCont:string,LineaInesperadaValor:float,Lineas4312:list<array{CodiCont:string,CentCost:?string,Valor:float}>}|null
     */
    public function fetchEstadoParaReversionCuentaInesperada(
        string $codiDocuFactura,
        string $numeDocuFactura,
        int $consDeta
    ): ?array {
        $codiInst = $this->codiInst();

        $stmt = $this->connect()->prepare(
            'SELECT Anulado, FechDocu, TiDoTerc, NuDoTerc, CodiCent FROM EncaCont
             WHERE CodiInst = ? AND CodiDocu = ? AND NumeDocu = ?'
        );
        $stmt->execute([$codiInst, $codiDocuFactura, $numeDocuFactura]);
        $factura = $stmt->fetch();
        if ($factura === false) {
            return null;
        }

        $stmt = $this->connect()->prepare(
            'SELECT CodiCont, Valor FROM DetaCont
             WHERE CodiInst = ? AND CodiDocu = ? AND NumeDocu = ? AND ConsDeta = ?'
        );
        $stmt->execute([$codiInst, $codiDocuFactura, $numeDocuFactura, $consDeta]);
        $lineaInesperada = $stmt->fetch();
        if ($lineaInesperada === false) {
            return null;
        }

        $stmt = $this->connect()->prepare(
            "SELECT CodiCont, CentCost, Valor FROM DetaCont
             WHERE CodiInst = ? AND CodiDocu = ? AND NumeDocu = ? AND CodiCont LIKE '4312%'
             ORDER BY ConsDeta"
        );
        $stmt->execute([$codiInst, $codiDocuFactura, $numeDocuFactura]);
        $lineas4312 = array_map(
            static fn (array $fila): array => [
                'CodiCont' => $fila['CodiCont'],
                'CentCost' => $fila['CentCost'],
                'Valor' => (float)$fila['Valor'],
            ],
            $stmt->fetchAll()
        );

        return [
            'Anulado' => (int)$factura['Anulado'],
            'FechDocu' => (string)$factura['FechDocu'],
            'TiDoTerc' => $factura['TiDoTerc'],
            'NuDoTerc' => $factura['NuDoTerc'],
            'CodiCent' => $factura['CodiCent'],
            'LineaInesperadaCodiCont' => $lineaInesperada['CodiCont'],
            'LineaInesperadaValor' => (float)$lineaInesperada['Valor'],
            'Lineas4312' => $lineas4312,
        ];
    }

    /**
     * Análogo a fetchAjustePrevio() pero para la reclasificación de cuenta
     * de notas de vigencia anterior (sección 5b): la clave de búsqueda es
     * la propia NOTA que se corrige (no la factura), porque la nota de
     * ajuste en la rama de mes cerrado referencia la nota origen, no la
     * factura. Solo aplica a la rama de mes cerrado — la rama de mes
     * abierto edita en sitio, no deja rastro de "documento referenciando",
     * por eso esa rama re-verifica directamente el CodiCont de la línea en
     * vez de buscar un ajuste previo.
     *
     * @return array{CodiDocu:string,NumeDocu:string}|null
     */
    public function fetchAjustePrevioNota(string $codiDocuNota, string $numeDocuNota, string $cuentaCorregida): ?array
    {
        $stmt = $this->connect()->prepare(
            'SELECT dc.CodiDocu, dc.NumeDocu
             FROM DetaCont dc
             INNER JOIN EncaCont ec ON ec.CodiInst = dc.CodiInst AND ec.CodiDocu = dc.CodiDocu AND ec.NumeDocu = dc.NumeDocu
             WHERE dc.CodiInst = ? AND dc.TiDoRefe = ? AND dc.NuDoRefe = ? AND dc.CodiCont = ?
               AND dc.CodiDocu <> ? AND ec.Anulado = 0
             ORDER BY ec.FechDigi DESC, ec.HoraDigi DESC
             LIMIT 1'
        );
        $stmt->execute([$this->codiInst(), $codiDocuNota, $numeDocuNota, $cuentaCorregida, $codiDocuNota]);
        $fila = $stmt->fetch();

        return $fila === false ? null : $fila;
    }

    /**
     * Re-verificación en fresco (justo antes de escribir) de una nota de
     * vigencia anterior: su propio estado (Anulado, FechDocu, tercero,
     * sede) y todas sus líneas 4312 ACTUALES sobre facturas de vigencia
     * anterior — mismo criterio que fetchCuentasInesperadasNotasVigenciaAnterior()
     * pero para un documento puntual, sin depender del snapshot del
     * reporte. Devuelve null si la nota ya no existe.
     *
     * @return array{Anulado:int,FechDocu:string,TiDoTerc:?string,NuDoTerc:?string,CodiCent:?string,Lineas4312:list<array{ConsDeta:int,CodiCont:string,CentCost:?string,TiDoTerc:?string,NuDoTerc:?string,Valor:float}>}|null
     */
    public function fetchEstadoParaReclasificacionVigenciaAnterior(string $codiDocuNota, string $numeDocuNota): ?array
    {
        $codiInst = $this->codiInst();

        $stmt = $this->connect()->prepare(
            'SELECT Anulado, FechDocu, TiDoTerc, NuDoTerc, CodiCent FROM EncaCont
             WHERE CodiInst = ? AND CodiDocu = ? AND NumeDocu = ?'
        );
        $stmt->execute([$codiInst, $codiDocuNota, $numeDocuNota]);
        $nota = $stmt->fetch();

        if ($nota === false) {
            return null;
        }

        // dc YA trae su propio TiDoRefe/NuDoRefe (referencia a nivel de
        // línea) — no hace falta un join independiente contra otra fila de
        // DetaCont del mismo documento para resolverlo. Verificado: hacerlo
        // así (como sí hace, sin problema, fetchCuentasInesperadasNotasVigenciaAnterior()
        // porque ahí un SELECT DISTINCT lo absorbe) multiplicaba cada línea
        // 4312 por la cantidad de líneas con referencia del documento — acá
        // habría duplicado la reclasificación.
        $stmt = $this->connect()->prepare(
            "SELECT dc.ConsDeta, dc.CodiCont, dc.CentCost, dc.TiDoTerc, dc.NuDoTerc, dc.Valor
             FROM DetaCont dc
             INNER JOIN EncaCont fact
                 ON fact.CodiInst = dc.CodiInst AND fact.CodiDocu = dc.TiDoRefe AND fact.NumeDocu = dc.NuDoRefe
             WHERE dc.CodiInst = ? AND dc.CodiDocu = ? AND dc.NumeDocu = ?
               AND dc.CodiCont LIKE '4312%'
               AND dc.TiDoRefe IS NOT NULL AND dc.TiDoRefe <> ''
               AND YEAR(fact.FechDocu) < YEAR(?)
             ORDER BY dc.ConsDeta"
        );
        $stmt->execute([$codiInst, $codiDocuNota, $numeDocuNota, $nota['FechDocu']]);
        $lineas4312 = array_map(
            static fn (array $fila): array => [
                'ConsDeta' => (int)$fila['ConsDeta'],
                'CodiCont' => $fila['CodiCont'],
                'CentCost' => $fila['CentCost'],
                'TiDoTerc' => $fila['TiDoTerc'],
                'NuDoTerc' => $fila['NuDoTerc'],
                'Valor' => (float)$fila['Valor'],
            ],
            $stmt->fetchAll()
        );

        return [
            'Anulado' => (int)$nota['Anulado'],
            'FechDocu' => (string)$nota['FechDocu'],
            'TiDoTerc' => $nota['TiDoTerc'],
            'NuDoTerc' => $nota['NuDoTerc'],
            'CodiCent' => $nota['CodiCent'],
            'Lineas4312' => $lineas4312,
        ];
    }

    /**
     * Reporte de nómina para "Aportes en línea" (PILA): una fila por
     * empleado con sus datos y valores de cotización (pensión, salud, ARL,
     * parafiscales) del período dado — más filas adicionales del mismo
     * empleado cuando aplica VACACIONES, incapacidad (EG/RP), licencia no
     * remunerada, licencia de maternidad, o retroactivo de vacaciones "solo"
     * (ver más abajo) en el período (cada novedad se reporta como su propia
     * fila). El documento base de salario ya NO es 'NE' fijo — ver "Nómina
     * de Empleados con más de un documento" abajo.
     *
     * `DetaNomi.CodiMes` se guarda de forma inconsistente ('7' sin cero a
     * la izquierda o '07' con él, según cómo se causó cada documento) — se
     * filtra por ambas formas en todo el reporte, incluida la de VACACIONES.
     *
     * El chequeo de retiro (columnas RET/FECH_RET, contra
     * `Empleado.FechFinV`) usa el MISMO año/mes del período reportado — no
     * es un mes fijo aparte (decisión confirmada por el usuario: la
     * consulta original traía un mes distinto al del resto del reporte,
     * era un resto de una ejecución anterior, no una regla de negocio).
     *
     * `Ciu` (municipio) ya NO viene hardcodeado ('LA UNION' fijo, como en la
     * consulta original) — lo resuelve el llamador (empresa → tercero →
     * municipio del catálogo territorial de SAVID, vía
     * `SihosEmpresaConfigRepository::municipioEmpresa()`) y se pasa como
     * parámetro `$municipio` (ligado a `:municipio` en las 6 ramas), para
     * que el reporte muestre el municipio real de la empresa que se está
     * generando, no siempre el de Unión. `Depa` ('VALLE') y el resto de
     * literales fijos (Tipo de Cotizante, Actividad Económica '3861001',
     * ARL 'COLMENA', etc.) siguen tal cual de la consulta original — son
     * constantes de esta empresa/sede, no catálogo de la instalación de
     * SIHOS; nadie ha pedido aún generalizarlos.
     *
     * A diferencia del resto de este repositorio, `Empleado`/`DetaNomi` no
     * se filtran por `CodiInst` en la consulta original (nómina es única
     * por instalación, sin puestos satélite con nómina propia en los casos
     * reales) — aun así se agrega `e.CodiInst = :codiInst` en cada
     * subconsulta para mantener el mismo aislamiento por institución que el
     * resto del módulo.
     *
     * NÓMINA DE EMPLEADOS CON MÁS DE UN DOCUMENTO (2026-08-31, verificado
     * contra SIHOS real de Hospital de Roldanillo antes de aplicarse):
     *
     * El bloque principal ("normal") ya NO restringe `CodiDocu` al valor
     * fijo 'NE' — lo resuelve dinámicamente vía `codiDocuPorDocuApli(41)`
     * (Nómina de Empleados, catálogo `DocuApli`). Esto importa por dos
     * razones distintas, ambas confirmadas con datos reales de Roldanillo:
     *
     *   1) Una institución puede tener VARIOS documentos de Nómina de
     *      Empleados a la vez, uno por tipo de vinculación (Roldanillo:
     *      'NE' administrativa, 'NEO' oficial, 'NOP' operativa, además de
     *      'NEA'/'NEX'/'TPN' sin movimiento real de CodiConc=1 hoy). Con el
     *      filtro fijo a 'NE', los ~70 empleados de Roldanillo vinculados
     *      bajo 'NEO'/'NOP' NO aparecían en absoluto en la fila "normal" del
     *      reporte (sí en sus novedades — vacaciones/incapacidad/licencias
     *      — porque esos bloques nunca filtraron por documento).
     *   2) Un empleado con cambio de cargo A MITAD DE MES puede tener su
     *      sueldo partido entre DOS documentos de Nómina de Empleados en el
     *      MISMO período (caso real verificado: 25 días en 'NE' + 1 día en
     *      'NOP' = 26 días trabajados). Antes, el filtro fijo a 'NE' + el
     *      JOIN de ARL/CCF/SENA/ICBF por (CodiDocu,NumeDocu) EXACTO del
     *      documento anclado significaba que la porción del OTRO documento
     *      se perdía por completo — no solo los días, también su parte de
     *      ARL/CCF/SENA/ICBF (verificado: para ese empleado, la cotización
     *      ARL real reportada subía de $264.900 a $344.600 al sumar ambos
     *      documentos).
     *
     * Por eso el ancla ya NO es una fila cruda de `DetaNomi` (`d`): es una
     * subconsulta que SUMA Cantidad/ValoBase/ValoEmpe/ValoPatr de
     * `CodiConc='1'` agrupando por empleado+período, restringida a los
     * `CodiDocu` resueltos de Nómina de Empleados — así un empleado con su
     * sueldo partido entre documentos produce UNA sola fila con los totales
     * correctos, en vez de una fila con un valor arbitrario de uno de los
     * dos (comportamiento no determinista de MySQL con `ONLY_FULL_GROUP_BY`
     * desactivado, que es como ya corría esta consulta).
     *
     * Como consecuencia, las subconsultas ARL/Para(CCF)/sena/icbf/fond(FSP)
     * — que antes empataban por (CodiDocu,NumeDocu) EXACTO del ancla, porque
     * asumían un solo documento — pasan al mismo patrón que AFP/EPS/BPara: se
     * agregan (SUM) por empleado+período, restringidas también a los
     * `CodiDocu` de Nómina de Empleados (antes esa restricción venía gratis
     * del empate exacto por documento; al aflojar el empate hay que
     * declararla explícita para no recoger, por ejemplo, una línea de ARL
     * que por error viviera en el documento de Retroactivos). `inca`/`LM`/
     * `LNR`/`hyr` (incapacidad, licencia maternidad, licencia no remunerada,
     * horas-extra/bonificaciones — esta última solo alimenta el indicador
     * VST) tenían el MISMO problema estructural (empate por documento
     * exacto) y se corrigieron igual, aunque no estén ligadas a Nómina de
     * Empleados específicamente — un valor real puede vivir bajo cualquier
     * documento del período. Efecto colateral verificado (no un bug nuevo):
     * el indicador VST ahora se detecta correctamente incluso cuando la
     * bonificación/hora-extra vive en un documento distinto al ancla — antes
     * podía quedar en 'NO' por error para esos casos.
     *
     * `hyr`/VST — criterio ampliado (2026-09): además de recargos, horas
     * extra, gastos de representación y bonificación por servicios
     * (RDF/RDO/RNF/RNO/HEDF/HEDO/HENF/HENO/EsGasRep/EsBoniSe), cualquier
     * concepto de RETROACTIVO que sí compute para IBC (`c.BaseIBC='1'` junto
     * con `EsRetroa` o cualquiera de las banderas `Retr*` del catálogo
     * `Concepto`) también marca VST='SI'. Un retroactivo pagado en el
     * período (sueldo, recargos, horas extra, gastos de representación,
     * bonificación de servicios, vacaciones, etc.) es, por definición, un
     * ingreso adicional no permanente que sube el IBC ese mes — coincide con
     * la definición oficial de "Variación de Salario Transitoria" del
     * operador de aportes en línea. Los retroactivos con `BaseIBC='2'`
     * (p. ej. RETROACTIVO PRIMA VACACIONES, RETROACTIVO AUX TRANSPORTE en
     * los catálogos reales de Roldanillo/La Unión) quedan fuera a propósito:
     * no afectan el IBC, así que no constituyen VST. Esta bandera es
     * puramente informativa (columna VST del reporte) — no toca ningún
     * cálculo de base ni de cotización, así que no interfiere con la lógica
     * ya verificada de retroactivo de vacaciones (`retroSoloSubquery`/
     * `retroTotalSubquery`) ni con `fusionarNormalConUnicaNovedad()`.
     *
     * De paso se corrigió un error real de precedencia de operadores en el
     * WHERE de `inca` (`c.EsIncapa='1' or c.EsIncEmp='1' OR c.EsIncaRP='1'
     * AND d.CodiAno=...` sin paréntesis alrededor del OR se evalúa como
     * `EsIncapa='1' or EsIncEmp='1' or (EsIncaRP='1' AND periodo=...)` — los
     * dos primeros casos NUNCA se filtraban por período). Se agregaron los
     * paréntesis correctos.
     *
     * También se detectó y corrigió, al normalizar estas subconsultas, un
     * riesgo de fila duplicada por la inconsistencia de padding de
     * `CodiMes` explicada arriba: IBC/AFP/EPS agrupaban por el `CodiMes`
     * crudo (sin normalizar '7' vs '07' a un mismo grupo) — inofensivo
     * mientras el ancla también fuera una fila cruda con un CodiMes crudo
     * (comparación texto=texto, sin coerción), pero el nuevo ancla expone
     * `CodiMes` como entero (`CAST(...AS SIGNED)`, necesario para fusionar
     * '7' y '07' del propio ancla) — comparar ese entero contra el CodiMes
     * crudo de AFP/EPS fuerza a MySQL a coaccionar AMBAS variantes de
     * padding al mismo número, haciendo que un empleado con datos en ambas
     * variantes calzara con dos filas de AFP/EPS a la vez (fila duplicada).
     * Se corrigió agrupando también por `CAST(CodiMes AS SIGNED)` en
     * IBC/AFP/EPS (mismo patrón que BPara ya usaba) — verificado contra el
     * caso real que lo disparaba (Unión, un empleado con una bonificación
     * repartida entre 'NE' con CodiMes='6' y otro documento con CodiMes='06'
     * en el mismo período) antes y después del cambio.
     *
     * RETROACTIVO DE VACACIONES (2026-08-31, a pedido del usuario,
     * verificado contra SIHOS real de Roldanillo — único cliente con este
     * mecanismo activo hoy):
     *
     * Algunas instituciones liquidan un pago retroactivo relacionado con
     * vacaciones (p. ej. un ajuste de prima/sueldo de vacaciones calculado
     * y pagado meses después) en un documento aparte, "Nómina de
     * Retroactivos" (`DocuApli` global 86 — Roldanillo: `CodiDocu='NR'`),
     * bajo un concepto con el flag `Concepto.RetrVaca='1'` (distinto de
     * `EsVacaci='1'`, la vacación real). Igual que el resto de conceptos
     * `RETROACTIVO*`, ese pago SÍ debe contar como base de pensión/salud/
     * parafiscales (`Concepto.BaseIBC='1'`, `BasePara='1'` — verificado en
     * el catálogo real), pero NO como base de ARL (`Concepto.BaseArl='2'`
     * — false — a diferencia de "RETROACTIVO SUELDO", que sí tiene
     * `BaseArl='1'`): no hay exposición a riesgo laboral en un ajuste de
     * vacaciones, exactamente la misma regla que ya aplica a una vacación
     * real (ver bloque VACACIONES más abajo, que tampoco cotiza ARL).
     *
     * El manejo depende de si el empleado YA tiene una fila real de
     * VACACIONES (`EsVacaci='1'`) en el mismo período — decisión explícita
     * del usuario:
     *
     *   - SI la tiene: el valor del retroactivo se SUMA a esa fila (a la
     *     base de pensión/EPS/CCF/SENA/ICBF, nunca a ARL) — no se crea una
     *     fila aparte. Ver el JOIN a `retro` dentro del bloque VACACIONES.
     *   - SI NO la tiene (el empleado no tomó vacaciones reales este
     *     período, solo tiene el ajuste retroactivo): se genera una fila
     *     SINTÉTICA con la misma forma que una fila de VACACIONES real (VAC
     *     = 'VACACIONES', para que el operador de aportes en línea le dé el
     *     mismo tratamiento: sí pensión/salud/parafiscales, nunca ARL —
     *     `COTIZACION ARL` queda en blanco igual que en una vacación real),
     *     con 1 día (`D_AFP`/`D_EPS`/`D_ARL`/`D_PARA`) y fecha de inicio/fin
     *     el primer día del período reportado (no hay un rango real de
     *     `ConcEmpl` que citar, al no ser una vacación tomada de verdad). Es
     *     el último bloque `UNION ALL` — solo se agrega si la institución
     *     tiene documentos de Retroactivos (`codiDocuPorDocuApli(86)` no
     *     vacío); si no, ese bloque se omite por completo (Unión no lo usa
     *     hoy, la consulta queda idéntica a como estaba).
     *
     * El día "prestado" (fila sintética) se RESTA de los días trabajados de
     * la fila normal (`D_AFP`/`D_EPS`/`D_ARL`/`D_PARA` del ancla), para que
     * el total de días del período (trabajados + vacaciones) siga cuadrando
     * — vía el criterio "tiene retroactivo Y NO tiene vacación real este
     * período" (subconsulta `retroSolo`, con `NOT EXISTS` sobre
     * `EsVacaci='1'`), el mismo que decide si se crea la fila sintética.
     * Cuando el empleado SÍ tiene vacación real, NO se resta ningún día de
     * la fila normal — el retroactivo no le agrega un día de ausencia
     * distinto al que ya tiene la vacación real.
     *
     * SIEMPRE (tenga o no vacación real, decisión explícita del usuario) se
     * resta de la fila normal el VALOR del retroactivo — base (`I.B.C.
     * PENSION`/`EPS`/`CCF`/`IBC Otros Parafiscales`, nunca ARL) y su
     * cotización calculada (base × tarifa de cada administradora), vía una
     * segunda subconsulta `retro` (sin el `NOT EXISTS`, `retroTotalSubquery`
     * — la misma que ya se usa para sumarlo en la fila de vacaciones real),
     * unida por empleado+período sin condición sobre vacación real. Antes de
     * esto, el valor del retroactivo quedaba SOLO sumado en la fila de
     * vacaciones (real o sintética) pero seguía también dentro de la base y
     * la cotización real de la fila normal (`IBC`/`AFP`/`EPS`/`BPara`/`Para`/
     * `sena`/`icbf` no lo excluían) — contado dos veces. Verificado con dos
     * casos reales (uno con vacación real fusionada, otro solo con fila
     * sintética): tras la resta, tanto el I.B.C. como la cotización de la
     * fila normal bajan exactamente en el valor del retroactivo, en las 5
     * administradoras (AFP/EPS/CCF/SENA/ICBF).
     *
     * PISO DE 1 DÍA DE SALARIO MÍNIMO en la fila SINTÉTICA de retroactivo de
     * vacaciones (2026-09-01, a pedido del usuario, caso real confirmado:
     * empleado con retroactivo de vacaciones de solo $19.406 — muy por
     * debajo de 1 día de salario mínimo — el operador exige base $58.750
     * (16% → $9.400) en vez del valor real exportado ($3.105). El operador
     * de aportes en línea no acepta un IBC diario menor a 1 SMLDV (salario
     * mínimo legal diario vigente = salario mínimo mensual / 30) en ninguna
     * fila reportada. `$salarioMinimoMensual` (parámetro nuevo, resuelto por
     * `SihosNominaPilaService::buildFilas()` desde la configuración de la
     * empresa — 0.0 si no está configurado, NUNCA null: en MySQL
     * `GREATEST(x,NULL)` da `NULL`, no `x`, así que pasar null rompería la
     * columna en silencio) se divide entre 30 (`:smlvDiario`) y se usa así:
     *   - Fila SINTÉTICA (rama 6, `retro.ValoEmpe` = `retroSoloSubquery`):
     *     las 5 bases (`I.B.C. PENSION`/`EPS`/`ARL`/`CCF`/`IBC Otros
     *     Parafiscales`) y sus cotizaciones/`Total_AFP` usan
     *     `GREATEST(retro.ValoEmpe,:smlvDiario)` en vez del valor crudo.
     *   - Fila NORMAL (rama 1): en vez de restar el valor crudo del
     *     retroactivo, resta el MISMO valor con piso aplicado
     *     (`GREATEST(retroSolo.ValoEmpe,:smlvDiario)`) — así el total
     *     combinado (fila sintética + fila normal) NO cambia, solo se
     *     reacomoda para que la fila sintética cumpla el piso. Se usa
     *     `retroSolo` (no `retro`/`retroTotalSubquery`) a propósito: cuando
     *     el empleado SÍ tiene vacación real este período (branch 2,
     *     `retroSolo` es NULL), la resta de la fila normal sigue exactamente
     *     como antes — el piso NO se aplica ahí todavía (alcance de esta
     *     primera versión, a pedido del usuario: solo el caso de retroactivo
     *     de vacaciones SIN vacación real).
     *
     * `I.B.C. ARL` de la fila de VACACIONES (real fusionada; en la
     * sintética ya era el caso) SÍ suma el retroactivo (`d.ValoEmpe+retro.ValoEmpe`,
     * igual que PENSION/EPS/CCF/Otros Parafiscales de esa misma fila) — a
     * pedido explícito del usuario, para que las 5 columnas de IBC de una
     * misma fila muestren siempre el mismo valor (consistencia visual del
     * reporte). `COTIZACION ARL` de esa fila sigue en blanco: el aumento es
     * solo de la base mostrada, nunca genera una cotización de riesgos
     * nueva — `RETROACTIVO VACACIONES` sigue sin ser base real de ARL
     * (`BaseArl='2'`, ver más abajo).
     *
     * `I.B.C. ARL` de la fila NORMAL (2026-09-01, a pedido del usuario):
     * hasta acá venía de `ARL.ValoBase` — el valor que el propio SIHOS ya
     * calculó en su línea interna "RIESGOS PROFESIONALES A.R.L." (concepto
     * `EsRiePro='1'`), restringida a los documentos de Nómina de Empleados
     * (`$inEmp`). Esa línea NUNCA mira el documento de Retroactivos, así
     * que cualquier retroactivo o VST (horas extra, recargos, gastos de
     * representación) que subiera el I.B.C. de PENSION/EPS (vía `IBC.ValoEmpe`,
     * sin restricción de documento) se quedaba fuera de I.B.C. ARL — caso
     * real detectado: empleado con RETROACTIVO SUELDO en el documento de
     * Retroactivos, I.B.C. PENSION/EPS=5.486.077 pero I.B.C. ARL=3.762.886
     * (solo el sueldo del documento normal).
     *
     * Se evaluó usar `Concepto.BaseArl='1'` para completar la diferencia
     * (mismo enfoque intentado antes para retroactivo de vacaciones con el
     * empleado 16553468, revertido) pero **`BaseArl` es inconsistente entre
     * empresas**: `RETROACTIVO SUELDO` (CodiConc=49) tiene `BaseArl='2'` en
     * Roldanillo pero `BaseArl='1'` en La Unión — el mismo concepto, dos
     * valores distintos según cómo lo configuró cada hospital en su propio
     * catálogo. No es una fuente confiable.
     *
     * En vez de eso, `I.B.C. ARL` de la fila normal ahora usa **la misma
     * fórmula exacta que `I.B.C. PENSION`/`I.B.C. EPS`**: `IBC.ValoEmpe`
     * (pool de `BaseIBC='1'`, sin restricción de documento) menos las
     * mismas restas de vacaciones/incapacidad/licencia de maternidad/
     * retroactivo-vacaciones. Por construcción, ARL siempre queda igual a
     * AFP/EPS en la fila normal — sin depender de `BaseArl`. El retroactivo
     * de vacaciones sigue excluido de ARL en esta fila (se resta `retro.ValoEmpe`,
     * igual que en PENSION/EPS), consistente con que vacaciones no genera
     * riesgo ARL. `COTIZACION ARL` (`ARL.ValoPatr`) no se tocó — sigue
     * siendo el valor que ya calculó SIHOS, mismo criterio que en la fila
     * de vacaciones fusionada: solo se completa la base, nunca se inventa
     * una cotización nueva.
     *
     * `COTIZACION AFP`/`EPS`/`CCF`/`SENA`/`ICBF` de la fila NORMAL
     * (2026-09-01, investigado a pedido del usuario, caso real: empleado con
     * RETROACTIVO SUELDO, "Valor Cotización" de pensión salió $602.100 en
     * vez de $877.800): MISMO problema estructural que `I.B.C. ARL` arriba,
     * pero en la cotización en vez de la base — `(AFP.ValoEmpe+AFP.ValoPatr)`
     * (y su equivalente para EPS/CCF/SENA/ICBF) es la línea que el propio
     * SIHOS ya calculó, que vive SOLO en el documento de Nómina de
     * Empleados y no se actualiza automáticamente cuando se digita un
     * RETROACTIVO SUELDO/RECARGOS/HORAS EXTRAS/GASTOS REPRESENTACIÓN u otro
     * concepto VST en el documento de Retroactivos.
     *
     * SE INTENTÓ un arreglo ADITIVO (sumar a la cotización de SIHOS el
     * `ROUND(extra × tarifa,-2)` de la porción de VST/retroactivo, con
     * `extra` = base ya calculada arriba menos `d.ValoEmpe` del ancla) y se
     * REVIRTIÓ: SIHOS recalcula esa línea de forma ASÍNCRONA (a veces sí, a
     * veces no, sin patrón fijo desde este código) — confirmado con el mismo
     * empleado real, cuya línea de SIHOS pasó de reflejar solo el sueldo a
     * reflejar sueldo+retroactivo completo entre dos consultas de esta misma
     * sesión, sin ningún cambio de código de por medio. El arreglo aditivo
     * asumía que la línea de SIHOS SIEMPRE está incompleta (le falta el
     * VST/retroactivo) — cuando SIHOS ya la había recalculado por su cuenta,
     * el mismo ajuste duplicaba el valor ($1.153.500 en vez de $877.800).
     * No hay forma confiable de saber, solo mirando `AFP.ValoEmpe+ValoPatr`,
     * si esa línea YA incluye el VST/retroactivo o no.
     *
     * PENDIENTE: la fórmula sigue siendo `(AFP.ValoEmpe+AFP.ValoPatr)` menos
     * las restas de vacaciones/incapacidad/licencia no remunerada (12%)/
     * licencia de maternidad/retroactivo-vacaciones, sin cambios — igual que
     * antes de esta investigación. Sigue existiendo la ventana de riesgo
     * documentada arriba (cotización incompleta si se genera el archivo
     * antes de que SIHOS recalcule su propia línea) hasta definir una
     * fórmula robusta que no dependa de si SIHOS ya recalculó o no.
     *
     * INCAPACIDAD PRÓRROGA (2026-09-01, a pedido del usuario): existe un
     * cuarto flag de incapacidad en `Concepto`, `EsIncaPr='1'` (incapacidad
     * prorrogada — cuando la incapacidad inicial se extiende), además de
     * `EsIncapa`/`EsIncEmp`/`EsIncaRP`, que ya se usaban. La subconsulta
     * `inca` (resta de la fila normal) y el bloque de incapacidad (genera su
     * propia fila) SOLO revisaban esos tres — un empleado con TODO el mes en
     * incapacidad prórroga (`EsIncaPr='1'`, `BaseIBC='1'`) no obtenía fila de
     * incapacidad propia (0 líneas con esos tres flags) y sus días (30)
     * nunca se reportaban en ninguna fila, mientras su valor sí se colaba en
     * el I.B.C. de la fila normal (por el flag `BaseIBC='1'`, ajeno al tipo
     * de incapacidad). Se agregó `OR c.EsIncaPr='1'` a ambos WHERE — mismo
     * tratamiento que ya tenían los otros tres tipos de incapacidad, sin
     * necesidad de una rama nueva.
     *
     * `fetchNominaPila()` no reconstruye la "suma esperada real" multi-
     * porción que sí hace `SihosNominaPilaCorreccionService` (no hay
     * columna "COTIZACION RETRO VACACIONES" en el formato de cargue que
     * comparar) — el valor tomado es directamente el retroactivo tal cual
     * está en SIHOS para el período, sin reconstrucción.
     *
     * Además de las 90 columnas de la plantilla, se agregan 4 columnas AL
     * FINAL — `AFP_NIT`, `EPS_NIT`, `ARL_NIT`, `CCF_NIT` — con el NIT
     * (`CodiTerc.NumeTerc`, tal cual, con guión y DV) de la administradora
     * que ya se resuelve en el JOIN para el nombre; solo sirven para que
     * SihosNominaPilaService cruce contra `tercero_nomina` de SAVID por NIT
     * (más confiable que cruzar por texto) y las descarta antes de armar la
     * grilla/Excel — nunca deben llegar a `ENCABEZADOS` ni al archivo final.
     *
     * @param float $salarioMinimoMensual salario mínimo mensual vigente del
     *     año reportado, para el piso de 1 SMLDV del retroactivo de
     *     vacaciones (ver docblock de esa sección más arriba). 0.0 (valor
     *     por defecto) si no hay configuración — el piso simplemente no
     *     aplica, nunca pasar null (rompe `GREATEST` en MySQL).
     * @return list<array<string, mixed>> una fila por empleado/novedad, con
     *     las 90 columnas de "Tipo ID" (TipoDocu) a "Valor Cotización ICBF"
     *     (COTIZACION ICBF) del formato de cargue de aportes en línea —
     *     columnas B..CM de la plantilla de liquidación (la columna "No."
     *     y las columnas de ESAP/MEN/Exonerado/UPC adicional al final de la
     *     plantilla no las produce esta consulta) — más las 4 columnas de
     *     NIT descritas arriba, solo para cruce interno.
     */
    public function fetchNominaPila(string $codiAno, string $codiMes, string $municipio, float $salarioMinimoMensual = 0.0): array
    {
        $codiMesCorto = ltrim($codiMes, '0');
        if ($codiMesCorto === '') {
            $codiMesCorto = '0';
        }
        $codiMesPadded = str_pad($codiMesCorto, 2, '0', STR_PAD_LEFT);

        // Piso de 1 día de salario mínimo (SMLDV) para el retroactivo de
        // vacaciones — ver docblock de :smlvDiario más abajo. 0.0 (sin
        // configurar) es un no-op: GREATEST(x,0) siempre da x para una base
        // positiva, y en MySQL GREATEST(x,NULL) da NULL (rompería la
        // columna) — por eso nunca se pasa null aquí, siempre un float.
        // round() a peso entero (2026-09-02, a pedido del usuario): el
        // salario mínimo mensual no siempre es múltiplo exacto de 30, así
        // que la división sola arrastra decimales (verificado real:
        // $1.300.000/30 = $43.333,333...) a las columnas de BASE (I.B.C.
        // PENSION/EPS/ARL/CCF/Otros Parafiscales), que a diferencia de las
        // de cotización no llevan ROUND() propio. SIHOS jamás guarda
        // centavos (todo `ValoEmpe`/`ValoBase` es peso entero) — redondear
        // aquí es justamente lo fiel a ese formato, no un valor inventado.
        $smlvDiario = round($salarioMinimoMensual / 30);

        $codiDocuEmpleados = $this->codiDocuPorDocuApli(self::DOCUAPLI_NOMINA_EMPLEADOS);
        if ($codiDocuEmpleados === []) {
            return [];
        }
        $paramsEmp = [];
        foreach ($codiDocuEmpleados as $i => $codiDocu) {
            $paramsEmp["docuEmp{$i}"] = $codiDocu;
        }
        $inEmp = implode(',', array_map(static fn (string $k): string => ":{$k}", array_keys($paramsEmp)));

        $codiDocuRetro = $this->codiDocuPorDocuApli(self::DOCUAPLI_NOMINA_RETROACTIVOS);
        $tieneRetro = $codiDocuRetro !== [];
        $paramsRetro = [];
        foreach ($codiDocuRetro as $i => $codiDocu) {
            $paramsRetro["docuRetro{$i}"] = $codiDocu;
        }
        // Placeholder inocuo (nunca calza ningún CodiDocu real) cuando la
        // institución no tiene documentos de Retroactivos — así el bloque
        // `retroSolo` de más abajo sigue siendo SQL válido (LEFT JOIN que
        // entonces nunca calza nada) sin necesitar una segunda variante de
        // la consulta.
        $inRetro = $tieneRetro
            ? implode(',', array_map(static fn (string $k): string => ":{$k}", array_keys($paramsRetro)))
            : "''";

        // Retroactivo de vacaciones (Concepto.RetrVaca='1') SOLO para
        // empleados que este período NO tengan una línea real de vacaciones
        // (EsVacaci='1') — ver docblock. Reutilizada para restar 1 día de la
        // fila normal y como origen de la fila sintética de VACACIONES.
        $retroSoloSubquery = "
            select r.TipoDocu, r.NumePers, r.CodiAno, CAST(r.CodiMes AS SIGNED) as CodiMes, SUM(r.ValoEmpe) as ValoEmpe
            from DetaNomi r
            inner join Concepto cr on (r.CodiConc=cr.CodiConc and r.CodiInst=cr.CodiInst)
            where cr.RetrVaca='1' and r.CodiInst=:codiInst and r.CodiDocu in ({$inRetro})
              and r.CodiAno=:codiAno and (r.CodiMes=:codiMesCorto or r.CodiMes=:codiMesPadded) and r.ValoEmpe<>0
              and not exists (
                  select 1 from DetaNomi v inner join Concepto cv on (v.CodiConc=cv.CodiConc and v.CodiInst=cv.CodiInst)
                  where cv.EsVacaci='1' and v.CodiInst=r.CodiInst and v.TipoDocu=r.TipoDocu and v.NumePers=r.NumePers
                    and v.CodiAno=r.CodiAno and CAST(v.CodiMes AS SIGNED)=CAST(r.CodiMes AS SIGNED) and v.ValoEmpe<>0
              )
            group by r.TipoDocu, r.NumePers, r.CodiAno, CAST(r.CodiMes AS SIGNED)
        ";

        // Mismo retroactivo, SIN el filtro NOT EXISTS — usada solo dentro
        // del bloque VACACIONES real, que por su propio WHERE ya garantiza
        // que el empleado SÍ tiene línea de vacaciones ese período.
        $retroTotalSubquery = "
            select r.TipoDocu, r.NumePers, r.CodiAno, CAST(r.CodiMes AS SIGNED) as CodiMes, SUM(r.ValoEmpe) as ValoEmpe
            from DetaNomi r
            inner join Concepto cr on (r.CodiConc=cr.CodiConc and r.CodiInst=cr.CodiInst)
            where cr.RetrVaca='1' and r.CodiInst=:codiInst and r.CodiDocu in ({$inRetro})
              and r.CodiAno=:codiAno and (r.CodiMes=:codiMesCorto or r.CodiMes=:codiMesPadded) and r.ValoEmpe<>0
            group by r.TipoDocu, r.NumePers, r.CodiAno, CAST(r.CodiMes AS SIGNED)
        ";

        $sql = "
select e.TipoDocu,e.NumePers,e.Ape1Pers,e.Ape2Pers,e.Nom1Pers,e.Nom2Pers,'VALLE' AS 'Depa',:municipio AS 'Ciu','1. DEPENDIENTE' AS 'T_C','NINGUNO' AS 'ST_C',
'190' AS HorasLabo,'NO' AS 'Extranjero','NO' AS 'RES_EXT','' AS 'FECH_RAD_EXT','NO' AS 'ING','' AS 'FECH_ING',if(year(e.FechFinV)=:codiAno AND month(e.FechFinV)=:codiMesCorto,'Todos los sistemas (ARL, AFP, CCF, EPS)','NO') AS 'RET',if(year(e.FechFinV)=:codiAno AND month(e.FechFinV)=:codiMesCorto,e.FechFinV,'') AS 'FECH_RET',
'NO' AS TDE,'NO' AS TAE,'NO' AS TDP,'NO' AS TAP,'' AS VSP,'' AS 'Fecha VSP',if(hyr.ValoEmpe<>'','SI','NO') AS VST,'NO' as 'SLN','' AS 'Inicio SLN','' AS 'Fin  SLN',
'NO' as 'IGE','' AS 'Inicio IGE','' AS 'Fin IGE','NO' as 'LMA','' AS 'Inicio LMA','' AS 'Fin LMA',
'NO' as 'VAC', '' as 'INICIO VAC-LR','' as 'FIN VAC-LR','NO' AS AVP,'NO' AS VCT,'' AS 'Inicio VCT','' AS 'Fin VCT','' AS IRL,'' AS 'Inicio IRL','' AS 'Fin IRL','NO' AS 'Correcciones',
e.Salario as Salario,'NO' AS 'Salario Integral','NO' AS 'Salario Variable',ctafp.NombTerc AS AFP,(d.Cantidad - if(retroSolo.ValoEmpe is null,0,1)) AS 'D_AFP',
IBC.ValoEmpe-if(vac.ValoEmpe IS NULL,0,vac.ValoEmpe)-if(inca.ValoEmpe IS NULL,0,inca.ValoEmpe)-if(LM.ValoEmpe IS NULL,0,LM.ValoEmpe)-if(retroSolo.ValoEmpe is null,if(retro.ValoEmpe IS NULL,0,retro.ValoEmpe),GREATEST(retroSolo.ValoEmpe,:smlvDiario)) as 'I.B.C. PENSION',if(arp.CodiClas in ('3','5'),'26%','16%') AS Tarifa_AFP,(AFP.ValoEmpe+AFP.ValoPatr)-round((if(vac.ValoEmpe IS NULL,0,vac.ValoEmpe)*if(arp.CodiClas in ('3','5'),26,16))/100,-2)-round((if(inca.ValoEmpe IS NULL,0,inca.ValoEmpe)*if(arp.CodiClas in ('3','5'),26,16))/100,-2)-round((if(LNR.ValoEmpe IS NULL,0,LNR.ValoEmpe)*12)/100,-2)-round((if(LM.ValoEmpe IS NULL,0,LM.ValoEmpe)*if(arp.CodiClas in ('3','5'),26,16))/100,-2)-round((if(retroSolo.ValoEmpe is null,if(retro.ValoEmpe IS NULL,0,retro.ValoEmpe),GREATEST(retroSolo.ValoEmpe,:smlvDiario))*if(arp.CodiClas in ('3','5'),26,16))/100,-2) as 'COTIZACION AFP',
if(arp.CodiClas in ('3','5'),'1. Actividades de alto riesgo','Sin Riesgo') as 'In_altoR','' AS 'Cotización Voluntaria Afiliado','' AS 'Cotización Voluntaria Empleador',
fond.ValoEmpe as 'FSolidaridad','' AS 'Fondo Subsistencia','' AS 'Valor no Retenido',(AFP.ValoEmpe+AFP.ValoPatr)+if(fond.ValoEmpe is null,'',fond.ValoEmpe) as 'Total_AFP','NINGUNA' AS 'AFP Destino',
cteps.NombTerc AS EPS,(d.Cantidad - if(retroSolo.ValoEmpe is null,0,1)) AS 'D_EPS',IBC.ValoEmpe-if(vac.ValoEmpe IS NULL,0,vac.ValoEmpe)-if(inca.ValoEmpe IS NULL,0,inca.ValoEmpe)-if(LM.ValoEmpe IS NULL,0,LM.ValoEmpe)-if(retroSolo.ValoEmpe is null,if(retro.ValoEmpe IS NULL,0,retro.ValoEmpe),GREATEST(retroSolo.ValoEmpe,:smlvDiario)) as 'I.B.C. EPS','12.50%' AS Tarifa,(EPS.ValoEmpe+EPS.ValoPatr)-round((if(vac.ValoEmpe IS NULL,0,vac.ValoEmpe)*12.5)/100,-2)-round((if(inca.ValoEmpe IS NULL,0,inca.ValoEmpe)*12.5)/100,-2)-round((if(LNR.ValoEmpe IS NULL,0,LNR.ValoEmpe)*8.5)/100,-2)-round((if(LM.ValoEmpe IS NULL,0,LM.ValoEmpe)*12.5)/100,-2)-round((if(retroSolo.ValoEmpe is null,if(retro.ValoEmpe IS NULL,0,retro.ValoEmpe),GREATEST(retroSolo.ValoEmpe,:smlvDiario))*12.5)/100,-2) as 'COTIZACION EPS',
'0' AS 'Valor UPC','' AS 'No Autorización Incapacidad EG',inca.ValoEmpe as VInca,'' AS 'No Autorización LMA',LM.ValoEmpe as VLMA,'NINGUNA' AS 'EPS Destino',
ctarl.NombTerc AS ARL,(d.Cantidad - if(retroSolo.ValoEmpe is null,0,1)) AS 'D_ARL',IBC.ValoEmpe-if(vac.ValoEmpe IS NULL,0,vac.ValoEmpe)-if(inca.ValoEmpe IS NULL,0,inca.ValoEmpe)-if(LM.ValoEmpe IS NULL,0,LM.ValoEmpe)-if(retroSolo.ValoEmpe is null,if(retro.ValoEmpe IS NULL,0,retro.ValoEmpe),GREATEST(retroSolo.ValoEmpe,:smlvDiario)) as 'I.B.C. ARL',ROUND(arp.PorcClAr,3) as 'TARIFA ARL','NINGUNA' AS Clase,'RIESGO 3' AS 'Centro de Trabajo','3861001' AS 'Actividad Económica',
(ARL.ValoPatr) as 'COTIZACION ARL',(d.Cantidad - if(retroSolo.ValoEmpe is null,0,1)) AS 'D_PARA',caja.NombTerc AS CCF,BPara.ValoEmpe-if(vac.ValoEmpe IS NULL,0,vac.ValoEmpe)-if(LM.ValoEmpe IS NULL,0,LM.ValoEmpe)-if(retroSolo.ValoEmpe is null,if(retro.ValoEmpe IS NULL,0,retro.ValoEmpe),GREATEST(retroSolo.ValoEmpe,:smlvDiario)) as 'I.B.C. CCF','4.00%' AS 'Tarifa CCF',
Para.ValoPatr-round((if(vac.ValoEmpe IS NULL,0,vac.ValoEmpe)*4)/100,-2)-round((if(LM.ValoEmpe IS NULL,0,LM.ValoEmpe)*4)/100,-2)-round((if(retroSolo.ValoEmpe is null,if(retro.ValoEmpe IS NULL,0,retro.ValoEmpe),GREATEST(retroSolo.ValoEmpe,:smlvDiario))*4)/100,-2) as 'COTIZACION CCF',BPara.ValoEmpe-if(vac.ValoEmpe IS NULL,0,vac.ValoEmpe)-if(LM.ValoEmpe IS NULL,0,LM.ValoEmpe)-if(retroSolo.ValoEmpe is null,if(retro.ValoEmpe IS NULL,0,retro.ValoEmpe),GREATEST(retroSolo.ValoEmpe,:smlvDiario)) AS 'IBC Otros Parafiscales','2.00%' AS 'Tarifa SENA',sena.ValoPatr-round((if(vac.ValoEmpe IS NULL,0,vac.ValoEmpe)*2)/100,-2)-round((if(LM.ValoEmpe IS NULL,0,LM.ValoEmpe)*2)/100,-2)-round((if(retroSolo.ValoEmpe is null,if(retro.ValoEmpe IS NULL,0,retro.ValoEmpe),GREATEST(retroSolo.ValoEmpe,:smlvDiario))*2)/100,-2) as 'COTIZACION SENA','3.00%' AS 'Tarifa ICBF',icbf.ValoPatr-round((if(vac.ValoEmpe IS NULL,0,vac.ValoEmpe)*3)/100,-2)-round((if(LM.ValoEmpe IS NULL,0,LM.ValoEmpe)*3)/100,-2)-round((if(retroSolo.ValoEmpe is null,if(retro.ValoEmpe IS NULL,0,retro.ValoEmpe),GREATEST(retroSolo.ValoEmpe,:smlvDiario))*3)/100,-2) as 'COTIZACION ICBF',ctafp.NumeTerc AS AFP_NIT,cteps.NumeTerc AS EPS_NIT,ctarl.NumeTerc AS ARL_NIT,caja.NumeTerc AS CCF_NIT
from Empleado e
inner join (
    select TipoDocu, NumePers, CodiAno, CAST(CodiMes AS SIGNED) as CodiMes,
           SUM(Cantidad) as Cantidad, SUM(ValoBase) as ValoBase, SUM(ValoEmpe) as ValoEmpe, SUM(ValoPatr) as ValoPatr
    from DetaNomi
    where CodiInst=:codiInst and CodiConc='1' and CodiDocu in ({$inEmp})
      and CodiAno=:codiAno and (CodiMes=:codiMesCorto or CodiMes=:codiMesPadded)
    group by TipoDocu, NumePers, CodiAno, CAST(CodiMes AS SIGNED)
) d on (e.CodiInst=:codiInst and e.TipoDocu=d.TipoDocu and e.NumePers=d.NumePers)
inner join ClasiARP arp on (e.ClasiARP=arp.CodiClas)
left join CodiTerc ctafp on (e.TiDoAFP=ctafp.TipoDocu and e.NuDoAFP=ctafp.NumeTerc)
left join CodiTerc cteps on (e.TiDoEPS=cteps.TipoDocu and e.NuDoEPS=cteps.NumeTerc)
left join CodiTerc ctarl on (e.TiDoARP=ctarl.TipoDocu and e.NuDoARP=ctarl.NumeTerc)
left join CodiTerc caja on (e.TiDoCaja=caja.TipoDocu and e.NuDoCaja=caja.NumeTerc)
left JOIN (
select e.TipoDocu,e.NumePers,sum(d.ValoBase) as ValoBase,d.CodiDocu,d.NumeDocu,sum(d.ValoEmpe) as ValoEmpe,sum(d.ValoPatr) as ValoPatr,d.CodiAno,d.CodiMes
from Empleado e
inner join DetaNomi d on (e.CodiInst=d.CodiInst and e.TipoDocu=d.TipoDocu and e.NumePers=d.NumePers and e.CodiInst=:codiInst)
inner join Concepto c on (d.CodiConc=c.CodiConc)
where c.BaseIBC='1'
AND d.CodiAno=:codiAno AND (d.CodiMes=:codiMesCorto OR d.CodiMes=:codiMesPadded)
group by e.TipoDocu,e.NumePers,d.CodiAno,CAST(d.CodiMes AS SIGNED)
) as IBC
on (e.TipoDocu=IBC.TipoDocu and e.NumePers=IBC.NumePers and d.CodiAno=IBC.CodiAno and CAST(d.CodiMes AS SIGNED)=IBC.CodiMes)
left JOIN (
select e.TipoDocu,e.NumePers,sum(d.ValoBase) as ValoBase,d.CodiDocu,d.NumeDocu,sum(d.ValoEmpe) as ValoEmpe,sum(d.ValoPatr) as ValoPatr,d.CodiAno,d.CodiMes
from Empleado e
inner join DetaNomi d on (e.CodiInst=d.CodiInst and e.TipoDocu=d.TipoDocu and e.NumePers=d.NumePers and e.CodiInst=:codiInst)
inner join Concepto c on (d.CodiConc=c.CodiConc)
where c.EsPensio='1'
AND d.CodiAno=:codiAno AND (d.CodiMes=:codiMesCorto OR d.CodiMes=:codiMesPadded)
group by e.TipoDocu,e.NumePers,d.CodiAno,CAST(d.CodiMes AS SIGNED)
) as AFP
on (e.TipoDocu=AFP.TipoDocu and e.NumePers=AFP.NumePers and d.CodiAno=AFP.CodiAno and CAST(d.CodiMes AS SIGNED)=CAST(AFP.CodiMes AS SIGNED))
left JOIN (
select e.TipoDocu,e.NumePers,sum(d.ValoBase) as ValoBase,d.CodiDocu,d.NumeDocu,sum(d.ValoEmpe) as ValoEmpe,sum(d.ValoPatr) as ValoPatr,d.CodiAno,d.CodiMes
from Empleado e
inner join DetaNomi d on (e.CodiInst=d.CodiInst and e.TipoDocu=d.TipoDocu and e.NumePers=d.NumePers and e.CodiInst=:codiInst)
inner join Concepto c on (d.CodiConc=c.CodiConc)
where c.EsSalud='1'
AND d.CodiAno=:codiAno AND (d.CodiMes=:codiMesCorto OR d.CodiMes=:codiMesPadded)
group by e.TipoDocu,e.NumePers,d.CodiAno,CAST(d.CodiMes AS SIGNED)
) as EPS
on (e.TipoDocu=EPS.TipoDocu and e.NumePers=EPS.NumePers and d.CodiAno=EPS.CodiAno and CAST(d.CodiMes AS SIGNED)=CAST(EPS.CodiMes AS SIGNED))
left JOIN (
select e.TipoDocu,e.NumePers,sum(d.ValoBase) as ValoBase,d.CodiAno,CAST(d.CodiMes AS SIGNED) as CodiMes,sum(d.ValoEmpe) as ValoEmpe,sum(d.ValoPatr) as ValoPatr
from Empleado e
inner join DetaNomi d on (e.CodiInst=d.CodiInst and e.TipoDocu=d.TipoDocu and e.NumePers=d.NumePers and e.CodiInst=:codiInst)
inner join Concepto c on (d.CodiConc=c.CodiConc) where c.EsRiePro='1' and d.CodiDocu in ({$inEmp})
AND d.CodiAno=:codiAno AND (d.CodiMes=:codiMesCorto OR d.CodiMes=:codiMesPadded)
group by e.TipoDocu,e.NumePers,d.CodiAno,CAST(d.CodiMes AS SIGNED)
) as ARL
on (e.TipoDocu=ARL.TipoDocu and e.NumePers=ARL.NumePers and d.CodiAno=ARL.CodiAno and CAST(d.CodiMes AS SIGNED)=ARL.CodiMes)
left JOIN (
select e.TipoDocu,e.NumePers,sum(d.ValoBase) as ValoBase,d.CodiDocu,d.NumeDocu,sum(d.ValoEmpe) as ValoEmpe,sum(d.ValoPatr) as ValoPatr,d.CodiAno,d.CodiMes
from Empleado e
inner join DetaNomi d on (e.CodiInst=d.CodiInst and e.TipoDocu=d.TipoDocu and e.NumePers=d.NumePers and e.CodiInst=:codiInst)
inner join Concepto c on (d.CodiConc=c.CodiConc)
where c.BasePara='1'
AND d.CodiAno=:codiAno AND (d.CodiMes=:codiMesCorto OR d.CodiMes=:codiMesPadded)
group by e.TipoDocu,e.NumePers,d.CodiAno,CAST(d.CodiMes AS SIGNED)
) as BPara
on (e.TipoDocu=BPara.TipoDocu and e.NumePers=BPara.NumePers and d.CodiAno=BPara.CodiAno and CAST(d.CodiMes AS SIGNED)=BPara.CodiMes)
left JOIN (
select e.TipoDocu,e.NumePers,sum(d.ValoBase) as ValoBase,d.CodiAno,CAST(d.CodiMes AS SIGNED) as CodiMes,sum(d.ValoEmpe) as ValoEmpe,sum(d.ValoPatr) as ValoPatr
from Empleado e
inner join DetaNomi d on (e.CodiInst=d.CodiInst and e.TipoDocu=d.TipoDocu and e.NumePers=d.NumePers and e.CodiInst=:codiInst)
inner join Concepto c on (d.CodiConc=c.CodiConc) where c.EsCaja='1' and d.CodiDocu in ({$inEmp})
AND d.CodiAno=:codiAno AND (d.CodiMes=:codiMesCorto OR d.CodiMes=:codiMesPadded)
group by e.TipoDocu,e.NumePers,d.CodiAno,CAST(d.CodiMes AS SIGNED)
) as Para
on (e.TipoDocu=Para.TipoDocu and e.NumePers=Para.NumePers and d.CodiAno=Para.CodiAno and CAST(d.CodiMes AS SIGNED)=Para.CodiMes)
left JOIN (
select e.TipoDocu,e.NumePers,sum(d.ValoBase) as ValoBase,d.CodiAno,CAST(d.CodiMes AS SIGNED) as CodiMes,sum(d.ValoEmpe) as ValoEmpe,sum(d.ValoPatr) as ValoPatr
from Empleado e
inner join DetaNomi d on (e.CodiInst=d.CodiInst and e.TipoDocu=d.TipoDocu and e.NumePers=d.NumePers and e.CodiInst=:codiInst)
inner join Concepto c on (d.CodiConc=c.CodiConc) where c.EsSena='1' and d.CodiDocu in ({$inEmp})
AND d.CodiAno=:codiAno AND (d.CodiMes=:codiMesCorto OR d.CodiMes=:codiMesPadded)
group by e.TipoDocu,e.NumePers,d.CodiAno,CAST(d.CodiMes AS SIGNED)
) as sena
on (e.TipoDocu=sena.TipoDocu and e.NumePers=sena.NumePers and d.CodiAno=sena.CodiAno and CAST(d.CodiMes AS SIGNED)=sena.CodiMes)
left JOIN (
select e.TipoDocu,e.NumePers,sum(d.ValoBase) as ValoBase,d.CodiAno,CAST(d.CodiMes AS SIGNED) as CodiMes,sum(d.ValoEmpe) as ValoEmpe,sum(d.ValoPatr) as ValoPatr
from Empleado e
inner join DetaNomi d on (e.CodiInst=d.CodiInst and e.TipoDocu=d.TipoDocu and e.NumePers=d.NumePers and e.CodiInst=:codiInst)
inner join Concepto c on (d.CodiConc=c.CodiConc) where c.EsIcbf='1' and d.CodiDocu in ({$inEmp})
AND d.CodiAno=:codiAno AND (d.CodiMes=:codiMesCorto OR d.CodiMes=:codiMesPadded)
group by e.TipoDocu,e.NumePers,d.CodiAno,CAST(d.CodiMes AS SIGNED)
) as icbf
on (e.TipoDocu=icbf.TipoDocu and e.NumePers=icbf.NumePers and d.CodiAno=icbf.CodiAno and CAST(d.CodiMes AS SIGNED)=icbf.CodiMes)
left JOIN (
select e.TipoDocu,e.NumePers,sum(d.ValoBase) as ValoBase,d.CodiAno,CAST(d.CodiMes AS SIGNED) as CodiMes,sum(d.ValoEmpe) as ValoEmpe,sum(d.ValoPatr) as ValoPatr
from Empleado e
inner join DetaNomi d on (e.CodiInst=d.CodiInst and e.TipoDocu=d.TipoDocu and e.NumePers=d.NumePers and e.CodiInst=:codiInst)
inner join Concepto c on (d.CodiConc=c.CodiConc)
where c.EsSolPen='1' and d.CodiDocu in ({$inEmp})
AND d.CodiAno=:codiAno AND (d.CodiMes=:codiMesCorto OR d.CodiMes=:codiMesPadded)
group by e.TipoDocu,e.NumePers,d.CodiAno,CAST(d.CodiMes AS SIGNED)
) as fond
on (e.TipoDocu=fond.TipoDocu and e.NumePers=fond.NumePers and d.CodiAno=fond.CodiAno and CAST(d.CodiMes AS SIGNED)=fond.CodiMes)
left JOIN (
select e.TipoDocu,e.NumePers,sum(d.ValoBase) as ValoBase,d.CodiAno,CAST(d.CodiMes AS SIGNED) as CodiMes,sum(d.ValoEmpe) as ValoEmpe
from Empleado e
inner join DetaNomi d on (e.CodiInst=d.CodiInst and e.TipoDocu=d.TipoDocu and e.NumePers=d.NumePers and e.CodiInst=:codiInst)
inner join Concepto c on (d.CodiConc=c.CodiConc)
where (c.EsIncapa='1' or c.EsIncEmp='1' OR c.EsIncaRP='1' OR c.EsIncaPr='1')
AND d.CodiAno=:codiAno AND (d.CodiMes=:codiMesCorto OR d.CodiMes=:codiMesPadded)
group by e.TipoDocu,e.NumePers,d.CodiAno,CAST(d.CodiMes AS SIGNED)
) as inca
on (e.TipoDocu=inca.TipoDocu and e.NumePers=inca.NumePers and d.CodiAno=inca.CodiAno and CAST(d.CodiMes AS SIGNED)=inca.CodiMes)
left JOIN (
select e.TipoDocu,e.NumePers,sum(d.ValoBase) as ValoBase,d.CodiAno,CAST(d.CodiMes AS SIGNED) as CodiMes,sum(d.ValoEmpe) as ValoEmpe
from Empleado e
inner join DetaNomi d on (e.CodiInst=d.CodiInst and e.TipoDocu=d.TipoDocu and e.NumePers=d.NumePers and e.CodiInst=:codiInst)
inner join Concepto c on (d.CodiConc=c.CodiConc)
where (c.RDF='1' or c.RDO='1' or c.RNF='1' or c.RNO='1' or c.HEDF='1' or c.HEDO='1' or c.HENF='1' or c.HENO='1' or c.EsGasRep='1' or c.EsBoniSe='1'
    or (c.BaseIBC='1' and (c.EsRetroa='1' or c.RetrSuel='1' or c.RetrAuTr='1' or c.RetrSuAl='1' or c.RetrInca='1' or c.RetrVaca='1' or c.RetrPrVa='1' or c.RetrPrSe='1' or c.RetrPrNa='1' or c.RetrCesa='1' or c.RetrInCe='1' or c.RetrReca='1' or c.RetrHoEx='1' or c.RetrGaRe='1' or c.RetrQuin='1' or c.RetrBoSe='1' or c.RetrPrAn='1' or c.RetrSoSu='1' or c.RetrBoRe='1' or c.RetrSind='1' or c.RetrPrTe='1' or c.RetrViat='1' or c.RetrOtro='1')))
AND d.CodiAno=:codiAno AND (d.CodiMes=:codiMesCorto OR d.CodiMes=:codiMesPadded)
group by e.TipoDocu,e.NumePers,d.CodiAno,CAST(d.CodiMes AS SIGNED)
) as hyr
on (e.TipoDocu=hyr.TipoDocu and e.NumePers=hyr.NumePers AND d.CodiAno=hyr.CodiAno AND CAST(d.CodiMes AS SIGNED)=hyr.CodiMes)
left JOIN (
select e.TipoDocu,e.NumePers,sum(d.ValoBase) as ValoBase,d.CodiAno,CAST(d.CodiMes AS SIGNED) as CodiMes,sum(d.ValoEmpe) as ValoEmpe,sum(d.ValoPatr) as ValoPatr,sum(d.Cantidad) as Cantidad
from Empleado e
inner join DetaNomi d on (e.CodiInst=d.CodiInst and e.TipoDocu=d.TipoDocu and e.NumePers=d.NumePers and e.CodiInst=:codiInst)
inner join Concepto c on (d.CodiConc=c.CodiConc)
where c.EsLiMate='1' and d.CodiDocu in ({$inEmp})
AND d.CodiAno=:codiAno AND (d.CodiMes=:codiMesCorto OR d.CodiMes=:codiMesPadded)
group by e.TipoDocu,e.NumePers,d.CodiAno,CAST(d.CodiMes AS SIGNED)
) as LM
on (e.TipoDocu=LM.TipoDocu and e.NumePers=LM.NumePers and d.CodiAno=LM.CodiAno and CAST(d.CodiMes AS SIGNED)=LM.CodiMes)
left JOIN (
select e.TipoDocu,e.NumePers,sum(d.ValoBase) as ValoBase,d.CodiAno,CAST(d.CodiMes AS SIGNED) as CodiMes,sum(d.ValoEmpe) as ValoEmpe,sum(d.ValoPatr) as ValoPatr
from Empleado e
inner join DetaNomi d on (e.CodiInst=d.CodiInst and e.TipoDocu=d.TipoDocu and e.NumePers=d.NumePers and e.CodiInst=:codiInst)
inner join Concepto c on (d.CodiConc=c.CodiConc)
where c.EsLiNoRe='1' and d.CodiDocu in ({$inEmp})
AND d.CodiAno=:codiAno AND (d.CodiMes=:codiMesCorto OR d.CodiMes=:codiMesPadded)
group by e.TipoDocu,e.NumePers,d.CodiAno,CAST(d.CodiMes AS SIGNED)
) as LNR
on (e.TipoDocu=LNR.TipoDocu and e.NumePers=LNR.NumePers and d.CodiAno=LNR.CodiAno and CAST(d.CodiMes AS SIGNED)=LNR.CodiMes)
left JOIN(
select e.TipoDocu,e.NumePers,d.ValoBase,d.CodiDocu,d.NumeDocu,d.ValoEmpe,d.ValoPatr,ce.FechInic,ce.FechFina,'SI' as sino
from Empleado e
inner join DetaNomi d on (e.CodiInst=d.CodiInst and e.TipoDocu=d.TipoDocu and e.NumePers=d.NumePers and e.CodiInst=:codiInst)
inner join Concepto c on (d.CodiConc=c.CodiConc)
inner join ConcEmpl ce on (e.TipoDocu=ce.TipoDocu and e.NumePers=ce.NumePers and d.CodiConc=ce.CodiConc)
where c.EsVacaci='1' and d.CodiAno=:codiAno and (d.CodiMes=:codiMesCorto or d.CodiMes=:codiMesPadded) AND d.ValoEmpe<>0
)as vac
on (e.TipoDocu=vac.TipoDocu and e.NumePers=vac.NumePers)
left JOIN (
    {$retroSoloSubquery}
) as retroSolo
on (e.TipoDocu=retroSolo.TipoDocu and e.NumePers=retroSolo.NumePers and d.CodiAno=retroSolo.CodiAno and d.CodiMes=retroSolo.CodiMes)
left JOIN (
    {$retroTotalSubquery}
) as retro
on (e.TipoDocu=retro.TipoDocu and e.NumePers=retro.NumePers and d.CodiAno=retro.CodiAno and d.CodiMes=retro.CodiMes)
UNION ALL
select e.TipoDocu,e.NumePers,e.Ape1Pers,e.Ape2Pers,e.Nom1Pers,e.Nom2Pers,'VALLE' AS 'Depa',:municipio AS 'Ciu','1. DEPENDIENTE' AS 'T_C','NINGUNO' AS 'ST_C',
'190' AS HorasLabo,'NO' AS 'Extranjero','NO' AS 'RES_EXT','' AS 'FECH_RAD_EXT','NO' AS 'ING','' AS 'FECH_ING','NO' AS 'RET','' AS 'FECH_RET',
'NO' AS TDE,'NO' AS TAE,'NO' AS TDP,'NO' AS TAP,'' AS VSP,'' AS 'Fecha VSP','NO' AS VST,'NO' as 'SLN','' AS 'Inicio SLN','' AS 'Fin  SLN',
'NO' as 'IGE','' AS 'Inicio IGE','' AS 'Fin IGE','NO' as 'LMA','' AS 'Inicio LMA','' AS 'Fin LMA',
'VACACIONES' as 'VAC', ce.FechInic as 'INICIO VAC-LR',ce.FechFina as 'FIN VAC-LR','NO' AS AVP,'NO' AS VCT,'' AS 'Inicio VCT','' AS 'Fin VCT','' AS IRL,'' AS 'Inicio IRL','' AS 'Fin IRL','NO' AS 'Correcciones',
e.Salario as Salario,'NO' AS 'Salario Integral','NO' AS 'Salario Variable',ctafp.NombTerc AS AFP,sum(d.Cantidad) AS 'D_AFP',
(d.ValoEmpe+IFNULL(retro.ValoEmpe,0)) as 'I.B.C. PENSION','16%' AS Tarifa,ROUND(((d.ValoEmpe+IFNULL(retro.ValoEmpe,0))*16)/100,0) as 'COTIZACION AFP',
if(arp.CodiClas in ('3','5'),'1. Actividades de alto riesgo','Sin Riesgo') as 'In_altoR','' AS 'Cotización Voluntaria Afiliado','' AS 'Cotización Voluntaria Empleador',
'' as 'FSolidaridad','' AS 'Fondo Subsistencia','' AS 'Valor no Retenido',ROUND(((d.ValoEmpe+IFNULL(retro.ValoEmpe,0))*16)/100,0) as 'Total_AFP','NINGUNA' AS 'AFP Destino',
cteps.NombTerc AS EPS,sum(d.Cantidad) AS 'D_EPS',(d.ValoEmpe+IFNULL(retro.ValoEmpe,0)) as 'I.B.C. EPS','12.50%' AS Tarifa,ROUND(((d.ValoEmpe+IFNULL(retro.ValoEmpe,0))*12.5)/100,0) as 'COTIZACION EPS',
'0' AS 'Valor UPC','' AS 'No Autorización Incapacidad EG','' as VInca,'' AS 'No Autorización LMA','' as VLMA,'NINGUNA' AS 'EPS Destino',
ctarl.NombTerc AS ARL,d.Cantidad AS 'D_ARL',(d.ValoEmpe+IFNULL(retro.ValoEmpe,0)) as 'I.B.C. ARL',ROUND(arp.PorcClAr,3) AS 'TARIFA ARL','NINGUNA' AS Clase,'RIESGO 3' AS 'Centro de Trabajo','3861001' AS 'Actividad Económica',
'' as 'COTIZACION ARL',sum(d.Cantidad) AS 'D_PARA',caja.NombTerc AS CCF,(d.ValoEmpe+IFNULL(retro.ValoEmpe,0)) as 'I.B.C. CCF','4.00%' AS 'Tarifa CCF',
ROUND(((sum(d.ValoEmpe)+IFNULL(MAX(retro.ValoEmpe),0))*4)/100,-2) as 'COTIZACION CCF',(d.ValoEmpe+IFNULL(retro.ValoEmpe,0)) AS 'IBC Otros Parafiscales','2.00%' AS 'Tarifa SENA',ROUND(((sum(d.ValoEmpe)+IFNULL(MAX(retro.ValoEmpe),0))*2)/100,-2) as 'COTIZACION SENA','3.00%' AS 'Tarifa ICBF',ROUND(((sum(d.ValoEmpe)+IFNULL(MAX(retro.ValoEmpe),0))*3)/100,-2) as 'COTIZACION ICBF',ctafp.NumeTerc AS AFP_NIT,cteps.NumeTerc AS EPS_NIT,ctarl.NumeTerc AS ARL_NIT,caja.NumeTerc AS CCF_NIT
from Empleado e
inner join DetaNomi d on (e.CodiInst=d.CodiInst and e.TipoDocu=d.TipoDocu and e.NumePers=d.NumePers and e.CodiInst=:codiInst)
inner join Concepto c on (d.CodiConc=c.CodiConc)
inner join ConcEmpl ce on (e.TipoDocu=ce.TipoDocu and e.NumePers=ce.NumePers and d.CodiConc=ce.CodiConc)
inner join ClasiARP arp on (e.ClasiARP=arp.CodiClas)
left join CodiTerc ctafp on (e.TiDoAFP=ctafp.TipoDocu and e.NuDoAFP=ctafp.NumeTerc)
left join CodiTerc cteps on (e.TiDoEPS=cteps.TipoDocu and e.NuDoEPS=cteps.NumeTerc)
left join CodiTerc ctarl on (e.TiDoARP=ctarl.TipoDocu and e.NuDoARP=ctarl.NumeTerc)
left join CodiTerc caja on (e.TiDoCaja=caja.TipoDocu and e.NuDoCaja=caja.NumeTerc)
left JOIN (
    {$retroTotalSubquery}
) as retro
on (e.TipoDocu=retro.TipoDocu and e.NumePers=retro.NumePers and d.CodiAno=retro.CodiAno and CAST(d.CodiMes AS SIGNED)=retro.CodiMes)
where c.EsVacaci='1' and d.CodiAno=:codiAno AND (d.CodiMes=:codiMesCorto or d.CodiMes=:codiMesPadded) AND d.ValoEmpe<>0
group by e.NumePers
union ALL
select e.TipoDocu,e.NumePers,e.Ape1Pers,e.Ape2Pers,e.Nom1Pers,e.Nom2Pers,'VALLE' AS 'Depa',:municipio AS 'Ciu','1. DEPENDIENTE' AS 'T_C','NINGUNO' AS 'ST_C',
'190' AS HorasLabo,'NO' AS 'Extranjero','NO' AS 'RES_EXT','' AS 'FECH_RAD_EXT','NO' AS 'ING','' AS 'FECH_ING','NO' AS 'RET','' AS 'FECH_RET',
'NO' AS TDE,'NO' AS TAE,'NO' AS TDP,'NO' AS TAP,'' AS VSP,'' AS 'Fecha VSP','NO' AS VST,'NO' as 'SLN','' AS 'Inicio SLN','' AS 'Fin  SLN',
'SI' as 'IGE','' AS 'Inicio IGE','' AS 'Fin IGE','NO' as 'LMA','' AS 'Inicio LMA','' AS 'Fin LMA',
'NO' as 'VAC', '' as 'INICIO VAC-LR','' as 'FIN VAC-LR','NO' AS AVP,'NO' AS VCT,'' AS 'Inicio VCT','' AS 'Fin VCT','' AS IRL,'' AS 'Inicio IRL','' AS 'Fin IRL','NO' AS 'Correcciones',
e.Salario as Salario,'NO' AS 'Salario Integral','NO' AS 'Salario Variable',ctafp.NombTerc AS AFP,sum(d.Cantidad) AS 'D_AFP',
sum(d.ValoEmpe) as 'I.B.C. PENSION','16%' AS Tarifa,ROUND((sum(d.ValoEmpe)*16)/100,-2) as 'COTIZACION AFP',
if(arp.CodiClas in ('3','5'),'1. Actividades de alto riesgo','Sin Riesgo') as 'In_altoR','' AS 'Cotización Voluntaria Afiliado','' AS 'Cotización Voluntaria Empleador',
'' as 'FSolidaridad','' AS 'Fondo Subsistencia','' AS 'Valor no Retenido',ROUND((sum(d.ValoEmpe)*16)/100,-2) as 'Total_AFP','NINGUNA' AS 'AFP Destino',
cteps.NombTerc AS EPS,sum(d.Cantidad) AS 'D_EPS',sum(d.ValoEmpe) as 'I.B.C. EPS','12.50%' AS Tarifa,ROUND((sum(d.ValoEmpe)*12.5)/100,-2) as 'COTIZACION EPS',
'0' AS 'Valor UPC','' AS 'No Autorización Incapacidad EG','' as VInca,'' AS 'No Autorización LMA','' as VLMA,'NINGUNA' AS 'EPS Destino',
ctarl.NombTerc AS ARL,sum(d.Cantidad) AS 'D_ARL',sum(d.ValoEmpe) as 'I.B.C. ARL',ROUND(arp.PorcClAr,3) AS 'TARIFA ARL','NINGUNA' AS Clase,'RIESGO 3' AS 'Centro de Trabajo','3861001' AS 'Actividad Económica',
'' as 'COTIZACION ARL',sum(d.Cantidad) AS 'D_PARA',caja.NombTerc AS CCF,'' as 'I.B.C. CCF','4.00%' AS 'Tarifa CCF',
'' as 'COTIZACION CCF','' AS 'IBC Otros Parafiscales','2.00%' AS 'Tarifa SENA','' as 'COTIZACION SENA','3.00%' AS 'Tarifa ICBF','' as 'COTIZACION ICBF',ctafp.NumeTerc AS AFP_NIT,cteps.NumeTerc AS EPS_NIT,ctarl.NumeTerc AS ARL_NIT,caja.NumeTerc AS CCF_NIT
from Empleado e
inner join DetaNomi d on (e.CodiInst=d.CodiInst and e.TipoDocu=d.TipoDocu and e.NumePers=d.NumePers and e.CodiInst=:codiInst)
inner join Concepto c on (d.CodiConc=c.CodiConc)
inner join ClasiARP arp on (e.ClasiARP=arp.CodiClas)
left join CodiTerc ctafp on (e.TiDoAFP=ctafp.TipoDocu and e.NuDoAFP=ctafp.NumeTerc)
left join CodiTerc cteps on (e.TiDoEPS=cteps.TipoDocu and e.NuDoEPS=cteps.NumeTerc)
left join CodiTerc ctarl on (e.TiDoARP=ctarl.TipoDocu and e.NuDoARP=ctarl.NumeTerc)
left join CodiTerc caja on (e.TiDoCaja=caja.TipoDocu and e.NuDoCaja=caja.NumeTerc)
WHERE (c.EsIncapa='1' or c.EsIncEmp='1' or c.EsIncaRP='1' or c.EsIncaPr='1') and d.CodiAno=:codiAno and (d.CodiMes=:codiMesCorto or d.CodiMes=:codiMesPadded) AND ValoEmpe<>0
group by e.NumePers
UNION ALL
select e.TipoDocu,e.NumePers,e.Ape1Pers,e.Ape2Pers,e.Nom1Pers,e.Nom2Pers,'VALLE' AS 'Depa',:municipio AS 'Ciu','1. DEPENDIENTE' AS 'T_C','NINGUNO' AS 'ST_C',
'190' AS HorasLabo,'NO' AS 'Extranjero','NO' AS 'RES_EXT','' AS 'FECH_RAD_EXT','NO' AS 'ING','' AS 'FECH_ING','NO' AS 'RET','' AS 'FECH_RET',
'NO' AS TDE,'NO' AS TAE,'NO' AS TDP,'NO' AS TAP,'' AS VSP,'' AS 'Fecha VSP','NO' AS VST,if(d.ValoEmpe<>'','LICENCIA NO REMUNERADA','NO') as 'SLN','' AS 'Inicio SLN','' AS 'Fin  SLN',
'NO' as 'IGE','' AS 'Inicio IGE','' AS 'Fin IGE','NO' as 'LMA','' AS 'Inicio LMA','' AS 'Fin LMA',
'NO' as 'VAC', '' as 'INICIO VAC-LR','' as 'FIN VAC-LR','NO' AS AVP,'NO' AS VCT,'' AS 'Inicio VCT','' AS 'Fin VCT','' AS IRL,'' AS 'Inicio IRL','' AS 'Fin IRL','NO' AS 'Correcciones',
e.Salario as Salario,'NO' AS 'Salario Integral','NO' AS 'Salario Variable',ctafp.NombTerc AS AFP,sum(d.Cantidad) AS 'D_AFP',
sum(d.ValoEmpe) as 'I.B.C. PENSION','12%' AS Tarifa,ROUND((sum(d.ValoEmpe)*12)/100,-2) as 'COTIZACION AFP',
if(arp.CodiClas in ('3','5'),'1. Actividades de alto riesgo','Sin Riesgo') as 'In_altoR','' AS 'Cotización Voluntaria Afiliado','' AS 'Cotización Voluntaria Empleador',
'' as 'FSolidaridad','' AS 'Fondo Subsistencia','' AS 'Valor no Retenido',ROUND((sum(d.ValoEmpe)*12)/100,-2) as 'Total_AFP','NINGUNA' AS 'AFP Destino',
cteps.NombTerc AS EPS,sum(d.Cantidad) AS 'D_EPS',sum(d.ValoEmpe) as 'I.B.C. EPS','8.50%' AS Tarifa,ROUND((sum(d.ValoEmpe)*8.5)/100,-2) as 'COTIZACION EPS',
'0' AS 'Valor UPC','' AS 'No Autorización Incapacidad EG','' as VInca,'' AS 'No Autorización LMA','' as VLMA,'NINGUNA' AS 'EPS Destino',
ctarl.NombTerc AS ARL,sum(d.Cantidad) AS 'D_ARL',d.ValoEmpe as 'I.B.C. ARL',ROUND(arp.PorcClAr,3) AS 'TARIFA ARL','NINGUNA' AS Clase,'RIESGO 3' AS 'Centro de Trabajo','3861001' AS 'Actividad Económica',
'' as 'COTIZACION ARL',sum(d.Cantidad) AS 'D_PARA',caja.NombTerc AS CCF,'' as 'I.B.C. CCF','4.00%' AS 'Tarifa CCF',
'' as 'COTIZACION CCF','' AS 'IBC Otros Parafiscales','2.00%' AS 'Tarifa SENA','' as 'COTIZACION SENA','3.00%' AS 'Tarifa ICBF','' as 'COTIZACION ICBF',ctafp.NumeTerc AS AFP_NIT,cteps.NumeTerc AS EPS_NIT,ctarl.NumeTerc AS ARL_NIT,caja.NumeTerc AS CCF_NIT
from Empleado e
inner join DetaNomi d on (e.CodiInst=d.CodiInst and e.TipoDocu=d.TipoDocu and e.NumePers=d.NumePers and e.CodiInst=:codiInst)
inner join Concepto c on (d.CodiConc=c.CodiConc)
inner join ClasiARP arp on (e.ClasiARP=arp.CodiClas)
left join CodiTerc ctafp on (e.TiDoAFP=ctafp.TipoDocu and e.NuDoAFP=ctafp.NumeTerc)
left join CodiTerc cteps on (e.TiDoEPS=cteps.TipoDocu and e.NuDoEPS=cteps.NumeTerc)
left join CodiTerc ctarl on (e.TiDoARP=ctarl.TipoDocu and e.NuDoARP=ctarl.NumeTerc)
left join CodiTerc caja on (e.TiDoCaja=caja.TipoDocu and e.NuDoCaja=caja.NumeTerc)
WHERE (c.EsLiNoRe='1') and d.CodiAno=:codiAno and (d.CodiMes=:codiMesCorto or d.CodiMes=:codiMesPadded) AND ValoEmpe<>0
group by e.NumePers
UNION ALL
select e.TipoDocu,e.NumePers,e.Ape1Pers,e.Ape2Pers,e.Nom1Pers,e.Nom2Pers,'VALLE' AS 'Depa',:municipio AS 'Ciu','1. DEPENDIENTE' AS 'T_C','NINGUNO' AS 'ST_C',
'190' AS HorasLabo,'NO' AS 'Extranjero','NO' AS 'RES_EXT','' AS 'FECH_RAD_EXT','NO' AS 'ING','' AS 'FECH_ING','NO' AS 'RET','' AS 'FECH_RET',
'NO' AS TDE,'NO' AS TAE,'NO' AS TDP,'NO' AS TAP,'' AS VSP,'' AS 'Fecha VSP','NO' AS VST,'NO' as 'SLN','' AS 'Inicio SLN','' AS 'Fin  SLN',
'NO' as 'IGE','' AS 'Inicio IGE','' AS 'Fin IGE','SI' as 'LMA','' AS 'Inicio LMA','' AS 'Fin LMA',
'NO' as 'VAC', '' as 'INICIO VAC-LR','' as 'FIN VAC-LR','NO' AS AVP,'NO' AS VCT,'' AS 'Inicio VCT','' AS 'Fin VCT','' AS IRL,'' AS 'Inicio IRL','' AS 'Fin IRL','NO' AS 'Correcciones',
e.Salario as Salario,'NO' AS 'Salario Integral','NO' AS 'Salario Variable',ctafp.NombTerc AS AFP,sum(d.Cantidad) AS 'D_AFP',
sum(d.ValoEmpe) as 'I.B.C. PENSION','16%' AS Tarifa,ROUND((sum(d.ValoEmpe)*16)/100,-2) as 'COTIZACION AFP',
if(arp.CodiClas in ('3','5'),'1. Actividades de alto riesgo','Sin Riesgo') as 'In_altoR','' AS 'Cotización Voluntaria Afiliado','' AS 'Cotización Voluntaria Empleador',
'' as 'FSolidaridad','' AS 'Fondo Subsistencia','' AS 'Valor no Retenido',ROUND((sum(d.ValoEmpe)*16)/100,-2) as 'Total_AFP','NINGUNA' AS 'AFP Destino',
cteps.NombTerc AS EPS,sum(d.Cantidad) AS 'D_EPS',sum(d.ValoEmpe) as 'I.B.C. EPS','12.50%' AS Tarifa,ROUND((sum(d.ValoEmpe)*12.5)/100,-2) as 'COTIZACION EPS',
'0' AS 'Valor UPC','' AS 'No Autorización Incapacidad EG','' as VInca,'' AS 'No Autorización LMA','' as VLMA,'NINGUNA' AS 'EPS Destino',
ctarl.NombTerc AS ARL,sum(d.Cantidad) AS 'D_ARL',d.ValoEmpe as 'I.B.C. ARL',ROUND(arp.PorcClAr,3) AS 'TARIFA ARL','NINGUNA' AS Clase,'RIESGO 3' AS 'Centro de Trabajo','3861001' AS 'Actividad Económica',
'' as 'COTIZACION ARL',sum(d.Cantidad) AS 'D_PARA',caja.NombTerc AS CCF,d.ValoEmpe as 'I.B.C. CCF','4.00%' AS 'Tarifa CCF',
ROUND((sum(d.ValoEmpe)*4)/100,-2) as 'COTIZACION CCF',d.ValoEmpe AS 'IBC Otros Parafiscales','2.00%' AS 'Tarifa SENA',ROUND((sum(d.ValoEmpe)*2)/100,-2) as 'COTIZACION SENA','3.00%' AS 'Tarifa ICBF',ROUND((sum(d.ValoEmpe)*3)/100,-2) as 'COTIZACION ICBF',ctafp.NumeTerc AS AFP_NIT,cteps.NumeTerc AS EPS_NIT,ctarl.NumeTerc AS ARL_NIT,caja.NumeTerc AS CCF_NIT
from Empleado e
inner join DetaNomi d on (e.CodiInst=d.CodiInst and e.TipoDocu=d.TipoDocu and e.NumePers=d.NumePers and e.CodiInst=:codiInst)
inner join Concepto c on (d.CodiConc=c.CodiConc)
inner join ClasiARP arp on (e.ClasiARP=arp.CodiClas)
left join CodiTerc ctafp on (e.TiDoAFP=ctafp.TipoDocu and e.NuDoAFP=ctafp.NumeTerc)
left join CodiTerc cteps on (e.TiDoEPS=cteps.TipoDocu and e.NuDoEPS=cteps.NumeTerc)
left join CodiTerc ctarl on (e.TiDoARP=ctarl.TipoDocu and e.NuDoARP=ctarl.NumeTerc)
left join CodiTerc caja on (e.TiDoCaja=caja.TipoDocu and e.NuDoCaja=caja.NumeTerc)
WHERE (c.EsLiMate='1') and d.CodiAno=:codiAno and (d.CodiMes=:codiMesCorto or d.CodiMes=:codiMesPadded) AND ValoEmpe<>0
group by e.NumePers";

        if ($tieneRetro) {
            $sql .= "
UNION ALL
select e.TipoDocu,e.NumePers,e.Ape1Pers,e.Ape2Pers,e.Nom1Pers,e.Nom2Pers,'VALLE' AS 'Depa',:municipio AS 'Ciu','1. DEPENDIENTE' AS 'T_C','NINGUNO' AS 'ST_C',
'190' AS HorasLabo,'NO' AS 'Extranjero','NO' AS 'RES_EXT','' AS 'FECH_RAD_EXT','NO' AS 'ING','' AS 'FECH_ING','NO' AS 'RET','' AS 'FECH_RET',
'NO' AS TDE,'NO' AS TAE,'NO' AS TDP,'NO' AS TAP,'' AS VSP,'' AS 'Fecha VSP','NO' AS VST,'NO' as 'SLN','' AS 'Inicio SLN','' AS 'Fin  SLN',
'NO' as 'IGE','' AS 'Inicio IGE','' AS 'Fin IGE','NO' as 'LMA','' AS 'Inicio LMA','' AS 'Fin LMA',
'VACACIONES' as 'VAC', :fechaRetroVac as 'INICIO VAC-LR',:fechaRetroVac as 'FIN VAC-LR','NO' AS AVP,'NO' AS VCT,'' AS 'Inicio VCT','' AS 'Fin VCT','' AS IRL,'' AS 'Inicio IRL','' AS 'Fin IRL','NO' AS 'Correcciones',
e.Salario as Salario,'NO' AS 'Salario Integral','NO' AS 'Salario Variable',ctafp.NombTerc AS AFP,1 AS 'D_AFP',
GREATEST(retro.ValoEmpe,:smlvDiario) as 'I.B.C. PENSION','16%' AS Tarifa,ROUND((GREATEST(retro.ValoEmpe,:smlvDiario)*16)/100,0) as 'COTIZACION AFP',
if(arp.CodiClas in ('3','5'),'1. Actividades de alto riesgo','Sin Riesgo') as 'In_altoR','' AS 'Cotización Voluntaria Afiliado','' AS 'Cotización Voluntaria Empleador',
'' as 'FSolidaridad','' AS 'Fondo Subsistencia','' AS 'Valor no Retenido',ROUND((GREATEST(retro.ValoEmpe,:smlvDiario)*16)/100,0) as 'Total_AFP','NINGUNA' AS 'AFP Destino',
cteps.NombTerc AS EPS,1 AS 'D_EPS',GREATEST(retro.ValoEmpe,:smlvDiario) as 'I.B.C. EPS','12.50%' AS Tarifa,ROUND((GREATEST(retro.ValoEmpe,:smlvDiario)*12.5)/100,0) as 'COTIZACION EPS',
'0' AS 'Valor UPC','' AS 'No Autorización Incapacidad EG','' as VInca,'' AS 'No Autorización LMA','' as VLMA,'NINGUNA' AS 'EPS Destino',
ctarl.NombTerc AS ARL,1 AS 'D_ARL',GREATEST(retro.ValoEmpe,:smlvDiario) as 'I.B.C. ARL',ROUND(arp.PorcClAr,3) AS 'TARIFA ARL','NINGUNA' AS Clase,'RIESGO 3' AS 'Centro de Trabajo','3861001' AS 'Actividad Económica',
'' as 'COTIZACION ARL',1 AS 'D_PARA',caja.NombTerc AS CCF,GREATEST(retro.ValoEmpe,:smlvDiario) as 'I.B.C. CCF','4.00%' AS 'Tarifa CCF',
ROUND((GREATEST(retro.ValoEmpe,:smlvDiario)*4)/100,-2) as 'COTIZACION CCF',GREATEST(retro.ValoEmpe,:smlvDiario) AS 'IBC Otros Parafiscales','2.00%' AS 'Tarifa SENA',ROUND((GREATEST(retro.ValoEmpe,:smlvDiario)*2)/100,-2) as 'COTIZACION SENA','3.00%' AS 'Tarifa ICBF',ROUND((GREATEST(retro.ValoEmpe,:smlvDiario)*3)/100,-2) as 'COTIZACION ICBF',ctafp.NumeTerc AS AFP_NIT,cteps.NumeTerc AS EPS_NIT,ctarl.NumeTerc AS ARL_NIT,caja.NumeTerc AS CCF_NIT
from Empleado e
inner join (
    {$retroSoloSubquery}
) as retro
on (e.TipoDocu=retro.TipoDocu and e.NumePers=retro.NumePers)
inner join ClasiARP arp on (e.ClasiARP=arp.CodiClas)
left join CodiTerc ctafp on (e.TiDoAFP=ctafp.TipoDocu and e.NuDoAFP=ctafp.NumeTerc)
left join CodiTerc cteps on (e.TiDoEPS=cteps.TipoDocu and e.NuDoEPS=cteps.NumeTerc)
left join CodiTerc ctarl on (e.TiDoARP=ctarl.TipoDocu and e.NuDoARP=ctarl.NumeTerc)
left join CodiTerc caja on (e.TiDoCaja=caja.TipoDocu and e.NuDoCaja=caja.NumeTerc)
where e.CodiInst=:codiInst";
        }

        $sql .= "\nORDER BY Ape1Pers, Ape2Pers, Nom1Pers, NumePers";

        $params = array_merge([
            'codiInst' => $this->codiInst(),
            'codiAno' => $codiAno,
            'codiMesCorto' => $codiMesCorto,
            'codiMesPadded' => $codiMesPadded,
            'municipio' => $municipio,
            'smlvDiario' => $smlvDiario,
        ], $paramsEmp, $paramsRetro);

        if ($tieneRetro) {
            $params['fechaRetroVac'] = sprintf('%04d-%02d-01', (int)$codiAno, (int)$codiMesPadded);
        }

        $stmt = $this->connect()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Columnas booleanas de `Concepto` que identifican, sin depender de un
     * número de concepto fijo (varía por institución), cuál es "el" concepto
     * de cotización obligatoria de cada rubro — usadas por
     * `buscarConceptoCorreccionNomina()` (SihosNominaPilaCorreccionService,
     * que corrige DetaNomi, y SihosPlanillaIntegradaService, que solo
     * compara y muestra diferencias). Lista blanca cerrada: nunca se
     * interpola directamente un valor de entrada externa en SQL.
     *
     * 'arl' (riesgos laborales) solo lo usa SihosPlanillaIntegradaService —
     * SihosNominaPilaCorreccionService lo deja fuera de alcance a propósito
     * (ver su docblock): un POST forjado con concepto=arl hacia esa acción
     * de escritura no debe colarse solo porque este flag exista aquí (esa
     * clase valida contra su propia lista MAPA_CODIGO_ERROR, más estricta).
     *
     * 'fondo_solidaridad' (Fondo de Solidaridad Pensional) SÍ lo usan ambas
     * clases: SihosPlanillaIntegradaService para comparar/mostrar, y
     * SihosNominaPilaCorreccionService para corregir (códigos de error
     * 836/837 del CSV del operador). Es un `CodiConc` DISTINTO de 'pension'
     * en SIHOS (verificado: "PENSION A.F.P" y "FONDO DE SOLIDARIDAD
     * PENSIONAL" son dos líneas separadas de `DetaNomi`, `EsPensio`/`EsSolPen`
     * nunca están ambos en '1' a la vez) y su `ValoPatr` es SIEMPRE $0 (100%
     * a cargo del empleado, sin aporte patronal — verificado con datos
     * reales) — por eso SihosNominaPilaCorreccionService lo corrige
     * ajustando `ValoEmpe`, la única excepción a su regla general de nunca
     * tocar ese campo. Además, el dinero de FSP se remite A TRAVÉS de la AFP
     * del empleado, así que SihosPlanillaIntegradaService lo suma junto con
     * 'pension' al comparar el total liquidado por administradora.
     */
    public const FLAGS_CONCEPTO_CORRECCION = [
        'pension' => 'EsPensio',
        'salud' => 'EsSalud',
        'ccf' => 'EsCaja',
        'sena' => 'EsSENA',
        'icbf' => 'EsICBF',
        'arl' => 'EsRiePro',
        'fondo_solidaridad' => 'EsSolPen',
    ];

    /**
     * Códigos del catálogo GLOBAL `DocuApli` (tabla sin `CodiInst` — es la
     * misma numeración en cualquier institución de SIHOS) para "Nómina de
     * Empleados" y "Nómina de Vacaciones" — confirmado consultando
     * `DocuApli` real (CodiTipo 41 → NombTipo "Nomina de Empleados",
     * CodiTipo 45 → "Nomina de Vacaciones"). NUNCA se hardcodea la letra de
     * `CodiDocu` (p. ej. 'NE'/'NV'): esa letra es configurable por
     * institución en `MaesDocu.CodiDocu` — lo estable entre instalaciones es
     * el código numérico de `DocuApli`. `codiDocuNominaCotizacion()` resuelve
     * en tiempo de ejecución, contra `MaesDocu` de la institución conectada,
     * qué `CodiDocu` real corresponde a cada uno de estos dos tipos.
     */
    private const DOCUAPLI_NOMINA_EMPLEADOS = 41;
    private const DOCUAPLI_NOMINA_VACACIONES = 45;

    /**
     * Documento(s) de "Nómina de Retroactivos" (`DocuApli` global 86,
     * confirmado contra `DocuApli` real) — algunas instituciones (p. ej.
     * Hospital de Roldanillo, `CodiDocu='NR'`) liquidan los conceptos
     * `RETROACTIVO*` en un documento aparte de la nómina normal, en vez de
     * dentro del/los documento(s) de Nómina de Empleados. `fetchNominaPila()`
     * lo usa SOLO para localizar el retroactivo de vacaciones (`Concepto.RetrVaca='1'`
     * — ver su docblock); el resto de conceptos `RETROACTIVO*` (sueldo, horas
     * extra, prima, etc.) no los toca este reporte, quedan fuera de alcance.
     * Una institución sin documentos de este `DocuApli` (p. ej. Unión antes
     * de empezar a usar este mecanismo) resuelve a `[]` y el mecanismo
     * simplemente no aplica — no es un requisito, es oportunista.
     */
    private const DOCUAPLI_NOMINA_RETROACTIVOS = 86;

    /** @var array<int,list<string>> caché en memoria por DocuApli — ver codiDocuPorDocuApli() */
    private array $codiDocuPorDocuApliCache = [];

    /** @var list<string>|null caché en memoria de codiDocuNominaCotizacion(), ver su docblock */
    private ?array $codiDocuNominaCotizacionCache = null;

    /**
     * `CodiDocu`(s) de esta institución para un `DocuApli` del catálogo
     * GLOBAL `DocuApli` (tabla sin `CodiInst` — misma numeración en
     * cualquier instalación de SIHOS). NUNCA se hardcodea la letra de
     * `CodiDocu` (p. ej. 'NE'/'NV'/'NR'): esa letra, y CUÁNTOS documentos
     * hay de un mismo tipo, son configurables por institución en
     * `MaesDocu.CodiDocu` — verificado que varía: Unión tiene un solo
     * documento de Nómina de Empleados ('NE'), mientras que Hospital de
     * Roldanillo tiene seis bajo el mismo `DocuApli` 41 (NE administrativa,
     * NEA aprendiz, NEO oficial, NEX nómina extra, NOP operativa, TPN
     * traslado provisión nómina) — todos se incluyen aquí, no solo los que
     * en la práctica tienen movimiento hoy, para no tener que tocar este
     * código si la institución empieza a usar uno que hoy está inactivo.
     *
     * Cacheado en memoria por instancia y por `DocuApli` — el mismo
     * repositorio se reutiliza dentro de una misma ejecución (reporte PILA
     * completo, o bucle de corrección) y esta consulta no cambia mientras
     * tanto.
     *
     * @return list<string> vacío si la institución no tiene documentos de ese DocuApli
     */
    private function codiDocuPorDocuApli(int $docuApli): array
    {
        if (isset($this->codiDocuPorDocuApliCache[$docuApli])) {
            return $this->codiDocuPorDocuApliCache[$docuApli];
        }

        $stmt = $this->connect()->prepare('SELECT CodiDocu FROM MaesDocu WHERE CodiInst = ? AND DocuApli = ?');
        $stmt->execute([$this->codiInst(), $docuApli]);

        return $this->codiDocuPorDocuApliCache[$docuApli] = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Resuelve los `CodiDocu` reales de esta institución (`CodiInst`) para
     * "Nómina de Empleados" y "Nómina de Vacaciones" — los documentos donde
     * puede vivir una cotización obligatoria de un empleado en un período:
     * el salario normal en el primero, el período en vacaciones en el
     * segundo. Un empleado con vacaciones parciales en el mes puede tener,
     * por ejemplo, Pensión/Salud completamente en el documento de
     * vacaciones y CCF/SENA/ICBF en el de empleados — verificado con un
     * caso real en Unión (empleado con el período entero en vacaciones:
     * Pensión y Salud solo existían en el documento de vacaciones, el resto
     * de conceptos solo en el de empleados). Por eso
     * `buscarConceptoCorreccionNomina()` busca en los dos.
     *
     * Delega en codiDocuPorDocuApli() (una llamada cacheada por tipo) y
     * combina ambos tipos — mismo resultado neto que la única consulta
     * `DocuApli IN (41,45)` que este método hacía antes, solo que ahora
     * reutiliza la misma caché por tipo que fetchNominaPila() usa para
     * "solo Nómina de Empleados" (41) sin repetir la consulta a MaesDocu.
     *
     * @return list<string>
     */
    private function codiDocuNominaCotizacion(): array
    {
        if ($this->codiDocuNominaCotizacionCache !== null) {
            return $this->codiDocuNominaCotizacionCache;
        }

        return $this->codiDocuNominaCotizacionCache = array_values(array_unique(array_merge(
            $this->codiDocuPorDocuApli(self::DOCUAPLI_NOMINA_EMPLEADOS),
            $this->codiDocuPorDocuApli(self::DOCUAPLI_NOMINA_VACACIONES)
        )));
    }

    /**
     * Busca en `DetaNomi` (documentos de Nómina de Empleados / Nómina de
     * Vacaciones de esta institución — ver codiDocuNominaCotizacion()) las
     * líneas del concepto pedido para un empleado y período — para el cruce
     * de correcciones de SihosNominaPilaCorreccionService, NUNCA para el
     * reporte normal (que sigue calculando desde IBC×tarifa).
     *
     * Se identifica el concepto por el flag de `Concepto` (Es<Rubro>='1'),
     * no por un `CodiConc` fijo — dos instalaciones de SIHOS pueden numerar
     * sus conceptos distinto. `$flagConcepto` DEBE venir de
     * FLAGS_CONCEPTO_CORRECCION (lista blanca) — nunca de entrada externa
     * directa, para no interpolar un nombre de columna arbitrario en SQL.
     *
     * Devuelve UN GRUPO por cada documento (`CodiDocu`+`NumeDocu`) donde
     * aparece el concepto — típicamente uno (Nómina de Empleados) o, si el
     * empleado tuvo vacaciones/incapacidad en el período, dos (también el de
     * Vacaciones). El llamador decide que solo es "corregible sin ambigüedad"
     * cuando hay EXACTAMENTE un grupo con EXACTAMENTE una línea (`ConsConc`)
     * — más de un grupo, o un grupo con más de una línea, es una situación
     * que requiere revisión humana (ver docblock de
     * SihosNominaPilaCorreccionService::evaluarGrupo()): no hay una forma
     * segura de adivinar en cuál aplicar el ajuste, ni de repartir el total
     * esperado entre documentos o líneas.
     *
     * @return list<array{codiDocu:string,numeDocu:string,nombreDocu:string,codiConc:string,lineas:list<array{consConc:string,valoEmpe:float,valoPatr:float}>}>
     */
    public function buscarConceptoCorreccionNomina(
        string $tipoDocu,
        string $numePers,
        string $codiAno,
        string $codiMes,
        string $flagConcepto
    ): array {
        if (!in_array($flagConcepto, self::FLAGS_CONCEPTO_CORRECCION, true)) {
            throw new \InvalidArgumentException("Flag de concepto no permitido: {$flagConcepto}");
        }

        $codiMesCorto = ltrim($codiMes, '0');
        $codiMesCorto = $codiMesCorto === '' ? '0' : $codiMesCorto;
        $codiMesPadded = str_pad($codiMesCorto, 2, '0', STR_PAD_LEFT);

        $codigosDocu = $this->codiDocuNominaCotizacion();
        if ($codigosDocu === []) {
            return [];
        }

        $paramsDocu = [];
        $nombresDocu = [];
        foreach ($codigosDocu as $i => $codigoDocu) {
            $nombre = "docu{$i}";
            $nombresDocu[] = ":{$nombre}";
            $paramsDocu[$nombre] = $codigoDocu;
        }
        $placeholdersDocu = implode(',', $nombresDocu);

        $stmt = $this->connect()->prepare(
            "SELECT d.CodiDocu, d.NumeDocu, m.NombDocu, d.CodiConc, d.ConsConc, d.ValoEmpe, d.ValoPatr
             FROM DetaNomi d
             INNER JOIN Concepto c ON c.CodiInst = d.CodiInst AND c.CodiConc = d.CodiConc
             INNER JOIN MaesDocu m ON m.CodiInst = d.CodiInst AND m.CodiDocu = d.CodiDocu
             WHERE d.CodiInst = :codiInst AND d.TipoDocu = :tipoDocu AND d.NumePers = :numePers
               AND d.CodiAno = :codiAno AND (d.CodiMes = :codiMesCorto OR d.CodiMes = :codiMesPadded)
               AND d.CodiDocu IN ({$placeholdersDocu}) AND c.{$flagConcepto} = '1'
             ORDER BY d.CodiDocu, d.NumeDocu, d.ConsConc"
        );
        $stmt->execute([
            'codiInst' => $this->codiInst(),
            'tipoDocu' => $tipoDocu,
            'numePers' => $numePers,
            'codiAno' => $codiAno,
            'codiMesCorto' => $codiMesCorto,
            'codiMesPadded' => $codiMesPadded,
            ...$paramsDocu,
        ]);
        $filas = $stmt->fetchAll();

        $grupos = [];
        foreach ($filas as $f) {
            $clave = $f['CodiDocu'] . '|' . $f['NumeDocu'];
            if (!isset($grupos[$clave])) {
                $grupos[$clave] = [
                    'codiDocu' => (string)$f['CodiDocu'],
                    'numeDocu' => (string)$f['NumeDocu'],
                    'nombreDocu' => (string)$f['NombDocu'],
                    'codiConc' => (string)$f['CodiConc'],
                    'lineas' => [],
                ];
            }
            $grupos[$clave]['lineas'][] = [
                'consConc' => (string)$f['ConsConc'],
                'valoEmpe' => (float)$f['ValoEmpe'],
                'valoPatr' => (float)$f['ValoPatr'],
            ];
        }

        return array_values($grupos);
    }

    /** ¿Ya está confirmada ("causada") la nómina de este documento en SIHOS? true si no se encuentra el documento (más seguro rechazar que asumir editable). */
    public function nominaEstaCausada(string $codiDocu, string $numeDocu): bool
    {
        $stmt = $this->connect()->prepare(
            'SELECT Causado FROM EncaCont WHERE CodiInst = ? AND CodiDocu = ? AND NumeDocu = ?'
        );
        $stmt->execute([$this->codiInst(), $codiDocu, $numeDocu]);
        $causado = $stmt->fetchColumn();

        return $causado === false || (int)$causado === 1;
    }

    /** @var array<string,?string> caché en memoria de nombreEmpleado(), clave "tipoDocu|numePers" */
    private array $nombreEmpleadoCache = [];

    /**
     * Nombre completo del empleado (Ape1 Ape2 Nom1 Nom2, tal cual está en
     * `Empleado`, sin normalizar) — solo para etiquetar de forma legible la
     * vista previa de corrección de nómina (SihosNominaPilaCorreccionService),
     * nunca para el reporte PILA en sí (ese usa las columnas por separado,
     * ver fetchNominaPila()). `null` si no se encuentra el empleado.
     */
    public function nombreEmpleado(string $tipoDocu, string $numePers): ?string
    {
        $clave = "{$tipoDocu}|{$numePers}";
        if (array_key_exists($clave, $this->nombreEmpleadoCache)) {
            return $this->nombreEmpleadoCache[$clave];
        }

        $stmt = $this->connect()->prepare(
            'SELECT Ape1Pers, Ape2Pers, Nom1Pers, Nom2Pers FROM Empleado WHERE CodiInst = ? AND TipoDocu = ? AND NumePers = ?'
        );
        $stmt->execute([$this->codiInst(), $tipoDocu, $numePers]);
        $fila = $stmt->fetch();

        if ($fila === false) {
            $this->nombreEmpleadoCache[$clave] = null;

            return null;
        }

        $nombre = trim(implode(' ', array_filter([
            trim((string)$fila['Ape1Pers']),
            trim((string)$fila['Ape2Pers']),
            trim((string)$fila['Nom1Pers']),
            trim((string)$fila['Nom2Pers']),
        ], static fn (string $s): bool => $s !== '')));

        $this->nombreEmpleadoCache[$clave] = $nombre !== '' ? $nombre : null;

        return $this->nombreEmpleadoCache[$clave];
    }

    /**
     * Columna de `Empleado` con el NIT (`NuDoXxx`) de la administradora
     * asignada a cada empleado, por concepto — usada por
     * sumaCotizacionPorAdministradora() para agrupar. SENA/ICBF no
     * aparecen aquí a propósito: son administradoras únicas y fijas para
     * toda la institución (no se elige por empleado), así que no hay nada
     * por lo cual agrupar — sumaCotizacionPorAdministradora() devuelve para
     * esos dos conceptos un total único.
     */
    private const CAMPO_ADMINISTRADORA_POR_CONCEPTO = [
        'pension' => 'NuDoAFP',
        'salud' => 'NuDoEPS',
        'arl' => 'NuDoARP',
        'ccf' => 'NuDoCaja',
        // El Fondo de Solidaridad Pensional se paga a través de la MISMA
        // AFP del empleado (no tiene administradora propia) — se agrupa
        // por el mismo campo que 'pension'.
        'fondo_solidaridad' => 'NuDoAFP',
    ];

    /**
     * Suma REAL en `DetaNomi` (documentos de Nómina de Empleados / Nómina
     * de Vacaciones — igual que buscarConceptoCorreccionNomina()) de un
     * concepto de cotización para TODO el período, agrupada por la
     * administradora asignada a cada empleado (`Empleado.NuDoXxx`) —
     * para el chequeo agregado de SihosPlanillaIntegradaService contra la
     * tabla "totales por administradora" del archivo de PILA.
     *
     * Para 'sena'/'icbf' (sin administradora por empleado, ver
     * CAMPO_ADMINISTRADORA_POR_CONCEPTO) se agrupa por una clave fija
     * `''` — un único total para todo el concepto.
     *
     * Deliberadamente consulta `DetaNomi` de nuevo aquí (no reutiliza los
     * totales ya calculados por fetchNominaPila()): esta pantalla existe
     * para detectar diferencias REALES entre lo liquidado y SIHOS, así que
     * el lado "SIHOS" de la comparación tiene que salir de la base de
     * datos real, nunca de nuestro propio cálculo del reporte.
     *
     * @return array<string, float> NIT de la administradora sin DV (o `''` si no aplica) => suma
     */
    public function sumaCotizacionPorAdministradora(string $codiAno, string $codiMes, string $flagConcepto): array
    {
        if (!in_array($flagConcepto, self::FLAGS_CONCEPTO_CORRECCION, true)) {
            throw new \InvalidArgumentException("Flag de concepto no permitido: {$flagConcepto}");
        }

        $codiMesCorto = ltrim($codiMes, '0');
        $codiMesCorto = $codiMesCorto === '' ? '0' : $codiMesCorto;
        $codiMesPadded = str_pad($codiMesCorto, 2, '0', STR_PAD_LEFT);

        $codigosDocu = $this->codiDocuNominaCotizacion();
        if ($codigosDocu === []) {
            return [];
        }

        $paramsDocu = [];
        $nombresDocu = [];
        foreach ($codigosDocu as $i => $codigoDocu) {
            $nombre = "docu{$i}";
            $nombresDocu[] = ":{$nombre}";
            $paramsDocu[$nombre] = $codigoDocu;
        }
        $placeholdersDocu = implode(',', $nombresDocu);

        $concepto = array_search($flagConcepto, self::FLAGS_CONCEPTO_CORRECCION, true);
        $campoAdministradora = self::CAMPO_ADMINISTRADORA_POR_CONCEPTO[$concepto] ?? null;

        if ($campoAdministradora === null) {
            // Sin administradora por empleado (SENA/ICBF): un único total.
            $stmt = $this->connect()->prepare(
                "SELECT SUM(d.ValoEmpe + d.ValoPatr) AS Suma
                 FROM DetaNomi d
                 INNER JOIN Concepto c ON c.CodiInst = d.CodiInst AND c.CodiConc = d.CodiConc
                 WHERE d.CodiInst = :codiInst AND d.CodiAno = :codiAno
                   AND (d.CodiMes = :codiMesCorto OR d.CodiMes = :codiMesPadded)
                   AND d.CodiDocu IN ({$placeholdersDocu}) AND c.{$flagConcepto} = '1'"
            );
            $stmt->execute([
                'codiInst' => $this->codiInst(),
                'codiAno' => $codiAno,
                'codiMesCorto' => $codiMesCorto,
                'codiMesPadded' => $codiMesPadded,
                ...$paramsDocu,
            ]);
            $suma = $stmt->fetchColumn();

            return $suma === null ? [] : ['' => round((float)$suma, 2)];
        }

        $stmt = $this->connect()->prepare(
            "SELECT e.{$campoAdministradora} AS Nit, SUM(d.ValoEmpe + d.ValoPatr) AS Suma
             FROM DetaNomi d
             INNER JOIN Concepto c ON c.CodiInst = d.CodiInst AND c.CodiConc = d.CodiConc
             INNER JOIN Empleado e ON e.CodiInst = d.CodiInst AND e.TipoDocu = d.TipoDocu AND e.NumePers = d.NumePers
             WHERE d.CodiInst = :codiInst AND d.CodiAno = :codiAno
               AND (d.CodiMes = :codiMesCorto OR d.CodiMes = :codiMesPadded)
               AND d.CodiDocu IN ({$placeholdersDocu}) AND c.{$flagConcepto} = '1'
             GROUP BY e.{$campoAdministradora}"
        );
        $stmt->execute([
            'codiInst' => $this->codiInst(),
            'codiAno' => $codiAno,
            'codiMesCorto' => $codiMesCorto,
            'codiMesPadded' => $codiMesPadded,
            ...$paramsDocu,
        ]);

        $resultado = [];
        while ($fila = $stmt->fetch()) {
            // `Empleado.NuDoXxx` guarda el NIT con el guión-DV (p. ej.
            // "800224808-8") — se quita para que la clave quede en el mismo
            // formato sin DV que usa el resto de la app (terceroidentificacion.numero)
            // y que trae el archivo de la planilla integrada.
            $nit = preg_replace('/-\d$/', '', trim((string)($fila['Nit'] ?? ''))) ?? '';
            $resultado[$nit] = round((float)$fila['Suma'], 2);
        }

        return $resultado;
    }
}
