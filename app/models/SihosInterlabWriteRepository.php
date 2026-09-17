<?php

/**
 * Conexión de ESCRITURA para SIHOS > Procesos > Interfaz Laboratorio, de
 * una empresa concreta. Excepción DELIBERADA y acotada a la regla general
 * de "SAVID nunca escribe historia clínica en SIHOS" (ver memoria
 * sihos-readonly): crea/actualiza HojaProc y DetaPrue (resultados de
 * laboratorio) y las tablas de interfaz Roche. Credencial separada tanto de
 * la de solo lectura como de la de escritura contable
 * (SihosExternalWriteRepository) — nunca deben compartir usuario/grant de
 * BD, para no mezclar el radio de impacto de una corrección contable con el
 * de una carga clínica.
 *
 * Puerto unificado (para ambos clientes, La Unión y Roldanillo) de la
 * lógica de docs/sihos/interlabunion/interlabu/interfaz_resultados.php y
 * docs/sihos/interlabunion/interlabu/interfaz_solicitudes.php — la versión
 * más completa de las dos (Unión), con las diferencias de negocio de
 * Roldanillo como comportamiento condicional según la data real, y los 2
 * bugs reales de Roldanillo corregidos (validar el resultado del INSERT en
 * vez del string SQL; guardar el ValoMaxi real en vez de '.' fijo).
 */
class SihosInterlabWriteRepository
{
    private ?PDO $pdo = null;

    /** Segundos que se espera por el bloqueo antes de rendirse. */
    private const ESPERA_BLOQUEO = 10;

    /**
     * @param array{host:string,port:string,database:string,username:string,password:string,charset:string,codiInst:string} $config
     */
    public function __construct(private readonly array $config)
    {
    }

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
        }

        return $this->pdo;
    }

    private function codiInst(): string
    {
        return (string)($this->config['codiInst'] ?? '');
    }

    /**
     * Serializa una operación con un bloqueo nombrado de MySQL — mismo
     * patrón que SihosExternalWriteRepository::conBloqueo(). El nombre se
     * acota al objetivo lógico (institución + identificador de fila), así
     * que dos filas distintas no se estorban entre sí.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     * @throws SihosOperacionEnCursoException si no se obtiene el bloqueo
     */
    public function conBloqueo(string $clave, callable $fn)
    {
        $pdo = $this->connect();
        $nombre = 'savid:interlab:' . substr(sha1($this->codiInst() . '|' . $clave), 0, 40);

        $stmt = $pdo->prepare('SELECT GET_LOCK(?, ?)');
        $stmt->execute([$nombre, self::ESPERA_BLOQUEO]);

        if ((int)$stmt->fetchColumn() !== 1) {
            throw new SihosOperacionEnCursoException(
                'Esta fila ya se está procesando en otra petición. Espere a que termine antes de repetirla.'
            );
        }

        try {
            return $fn();
        } finally {
            try {
                $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                $release->execute([$nombre]);
            } catch (PDOException $e) {
                // La conexión se cierra al final de la petición y el lock cae con ella.
            }
        }
    }

    // =====================================================================
    // RESULTADOS (interfaz_resultados.php)
    // =====================================================================

    public function fetchResultadoPendiente(int $id): ?array
    {
        $stmt = $this->connect()->prepare('SELECT * FROM Interfaz_resultados_Roche WHERE CodiInst = ? AND id = ?');
        $stmt->execute([$this->codiInst(), $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function resolverLoginUsuario(string $cc): string
    {
        $stmt = $this->connect()->prepare('SELECT Login FROM Usuarios WHERE CC = ?');
        $stmt->execute([$cc]);

        return (string)($stmt->fetchColumn() ?: '');
    }

    /**
     * Resuelve el ConsAdmi/CodiProc reales de SIHOS para una orden de la
     * interfaz, creando la Admisión si hace falta. Réplica unificada del
     * bloque "CONSULTO EL CODIPROC" + "SE CONSULTA LA LIQ" + "SE CREA LA
     * ADMISION SI NO EXISTE" del script original.
     *
     * Diferencia condicional con el original: la consulta de CodiDiag/
     * CodiRel1-4 (diagnósticos, presentes en Unión, ausentes en Roldanillo)
     * usa LEFT JOIN a EncaOrde en vez de INNER — si la orden no tiene fila
     * de EncaOrde con esos datos, simplemente vienen NULL, sin romper la
     * resolución del resto de campos. Así ambos clientes comparten el mismo
     * código y el dato aparece solo cuando la orden realmente lo trae.
     *
     * @return array{consAdmi:string,consOrde:string,item:string,codiProc:?string,codiFina:?string,codiDocu:?string,numeLiqu:?string,consDeFa:?string,cantFact:?string,cantSumi:?string,codiServ:?string,codiDiag:?string,codiRel1:?string,codiRel2:?string,codiRel3:?string,codiRel4:?string,liquidado:bool}
     */
    public function resolverConsAdmiYDatosOrden(string $codiInst, string $consAdmi, string $consOrde, string $item, string $codiPrue, string $historia): array
    {
        $pdo = $this->connect();

        $sqlC = "
            SELECT d.CodiProc, e.CodiDiag, e.CodiRel1, e.CodiRel2, e.CodiRel3, e.CodiRel4,
                   d.CantFact, d.CodiDocu, d.NumeLiqu, d.ConsDeFa, d.CantSumi, d.CodiServ, c.FinaCons
            FROM DetaOrde d
            INNER JOIN CodiProc c ON (d.CodiProc = c.CodiProc)
            LEFT JOIN EncaOrde e ON (e.CodiInst = d.CodiInst AND e.ConsAdmi = d.ConsAdmi AND e.ConsOrde = d.ConsOrde AND e.CodiModu = d.CodiModu)
            WHERE d.CodiInst = ? AND d.ConsAdmi = ? AND d.ConsOrde = ? AND d.Item = ?
        ";
        $stmt = $pdo->prepare($sqlC);
        $stmt->execute([$codiInst, $consAdmi, $consOrde, $item]);
        $filaC = $stmt->fetch();

        if ($filaC !== false) {
            return [
                'consAdmi' => $consAdmi,
                'consOrde' => $consOrde,
                'item' => $item,
                'codiProc' => $filaC['CodiProc'],
                'codiFina' => $filaC['FinaCons'],
                'codiDocu' => $filaC['CodiDocu'],
                'numeLiqu' => $filaC['NumeLiqu'],
                'consDeFa' => $filaC['ConsDeFa'],
                'cantFact' => $filaC['CantFact'],
                'cantSumi' => $filaC['CantSumi'],
                'codiServ' => $filaC['CodiServ'],
                'codiDiag' => $filaC['CodiDiag'],
                'codiRel1' => $filaC['CodiRel1'],
                'codiRel2' => $filaC['CodiRel2'],
                'codiRel3' => $filaC['CodiRel3'],
                'codiRel4' => $filaC['CodiRel4'],
                'liquidado' => false,
            ];
        }

        // No hay DetaOrde: se busca vía liquidación (facturación directa).
        $sqlL = "
            SELECT e.CodiInst, IF(e.ConsAdmi = '', d.ConsAdmi, e.ConsAdmi) AS ConsAdmi, e.CodiDocu, e.NumeFact,
                   d.ConsDeFa, c.CodiProc, c.FinaCons, d.CantServ AS CantFact, e.CodiServ,
                   d.TipoServ
            FROM EncaFact e
            INNER JOIN DetaFact d ON (e.CodiInst = d.CodiInst AND e.CodiDocu = d.CodiDocu AND e.NumeFact = d.NumeFact)
            INNER JOIN CodiProc c ON (d.CodiServ = c.CodiProc)
            WHERE e.CodiInst = ? AND e.NumeFact = ? AND d.ConsDeFa = ? AND c.CodiCups = ? AND e.NumeUsua = ?
            LIMIT 1
        ";
        $stmt = $pdo->prepare($sqlL);
        $stmt->execute([$codiInst, $consAdmi, $item, $codiPrue, $historia]);
        $filaL = $stmt->fetch();

        if ($filaL === false) {
            return [
                'consAdmi' => '', 'consOrde' => $consOrde, 'item' => $item, 'codiProc' => null, 'codiFina' => null,
                'codiDocu' => null, 'numeLiqu' => null, 'consDeFa' => null, 'cantFact' => null, 'cantSumi' => null,
                'codiServ' => null, 'codiDiag' => null, 'codiRel1' => null, 'codiRel2' => null, 'codiRel3' => null,
                'codiRel4' => null, 'liquidado' => false,
            ];
        }

        $consAdmiReal = (string)$filaL['ConsAdmi'];
        $causExte = $this->resolverCausExteRespaldo($codiInst, $consAdmi, $item, $codiPrue, $historia, (int)$filaL['TipoServ']);

        if ($consAdmiReal === '') {
            $consAdmiReal = $this->crearAdmisionYObtenerConsAdmi($codiInst, $filaL, $causExte);
        }

        return [
            'consAdmi' => $consAdmiReal,
            'consOrde' => (string)$filaL['NumeFact'],
            'item' => (string)$filaL['ConsDeFa'],
            'codiProc' => $filaL['CodiProc'],
            'codiFina' => $filaL['FinaCons'],
            'codiDocu' => $filaL['CodiDocu'],
            'numeLiqu' => (string)$filaL['NumeFact'],
            'consDeFa' => $filaL['ConsDeFa'],
            'cantFact' => $filaL['CantFact'],
            'cantSumi' => null,
            'codiServ' => $filaL['CodiServ'],
            'codiDiag' => null, 'codiRel1' => null, 'codiRel2' => null, 'codiRel3' => null, 'codiRel4' => null,
            'liquidado' => true,
        ];
    }

    /**
     * CausExte de respaldo (solo Unión lo calculaba): '13' si TipoServ=1,
     * '15' si TipoServ IN (5,34,36,43,44), vacío en otro caso. Condicional:
     * si la liquidación ya trae un CausExte propio no hace falta este
     * cálculo — el llamador decide si lo usa.
     */
    private function resolverCausExteRespaldo(string $codiInst, string $consAdmi, string $item, string $codiPrue, string $historia, int $tipoServ): string
    {
        if ($tipoServ === 1) {
            return '13';
        }
        if (in_array($tipoServ, [5, 34, 36, 43, 44], true)) {
            return '15';
        }

        return '';
    }

    /**
     * @param array<string, mixed> $filaL fila de resolverConsAdmiYDatosOrden() (rama liquidación)
     */
    private function crearAdmisionYObtenerConsAdmi(string $codiInst, array $filaL, string $causExte): string
    {
        $pdo = $this->connect();

        $stmt = $pdo->prepare('SELECT MAX(ConsAdmi) AS ConsAdmi FROM Admision WHERE CodiInst = ?');
        $stmt->execute([$codiInst]);
        $nuevoConsAdmi = (string)(((int)$stmt->fetchColumn()) + 1);

        $sqlIA = "
            INSERT INTO Admision (CodiInst, ConsAdmi, CodiServ, CausExte, FechIngr, HoraIngr, EstaIngr, ViaIngre, TipoAten,
                                   CodiDocu, NumeFact, ConsDeFa, Cerrado, FechCier, HoraCier, FechDigi, HoraDigi)
            VALUES (?, ?, ?, ?, CURDATE(), CURTIME(), 1, 2, 1, ?, ?, ?, 1, CURDATE(), CURTIME(), CURDATE(), CURTIME())
        ";
        $stmt = $pdo->prepare($sqlIA);
        $ok = $stmt->execute([
            $codiInst, $nuevoConsAdmi, $filaL['CodiServ'], $causExte,
            $filaL['CodiDocu'], $filaL['NumeFact'], $filaL['ConsDeFa'],
        ]);

        // FIX (bug real de interlabrolda): se valida el resultado real del
        // INSERT, no la variable que contiene el texto del SQL (que en el
        // script original de Roldanillo siempre era "truthy" y nunca
        // detectaba el fallo).
        if (!$ok) {
            throw new RuntimeException('No se pudo crear la admisión en SIHOS para ConsAdmi ' . $nuevoConsAdmi . '.');
        }

        $stmt = $pdo->prepare('UPDATE EncaFact SET ConsAdmi = ? WHERE CodiInst = ? AND CodiDocu = ? AND NumeFact = ?');
        $stmt->execute([$nuevoConsAdmi, $codiInst, $filaL['CodiDocu'], $filaL['NumeFact']]);

        return $nuevoConsAdmi;
    }

    public function actualizarEstadoResultado(int $id, int $enviada, int $correccion): void
    {
        $stmt = $this->connect()->prepare('UPDATE Interfaz_resultados_Roche SET Enviada = ?, Correcion = ? WHERE id = ?');
        $stmt->execute([$enviada, $correccion, $id]);
    }

    public function fetchNumeUsuaAdmision(string $codiInst, string $consAdmi): ?string
    {
        $stmt = $this->connect()->prepare('SELECT NumeUsua FROM Admision WHERE CodiInst = ? AND ConsAdmi = ?');
        $stmt->execute([$codiInst, $consAdmi]);
        $valor = $stmt->fetchColumn();

        return $valor === false ? null : (string)$valor;
    }

    /**
     * Texto agrupado de homologación virtual "9999" para IndiAdic (función
     * condicional: solo produce algo si esta empresa tiene filas 9999 en su
     * catálogo de homologación para este examen — hoy solo ocurre en
     * Unión, pero no está hardcodeado por cliente). IMPORTANTE (lección del
     * bug depurado en esta misma sesión): se filtra por el ConsAdmi/ConsOrde
     * CRUDOS tal como los envía Roche (`$consAdmiRoche`/`$consOrdeRoche`),
     * nunca por el ConsAdmi ya resuelto de SIHOS — las tablas de interfaz
     * nunca conocen ese último.
     */
    public function resolverDescripcionAgrupada9999(string $codiInst, string $codiPrueCups, string $consAdmiRoche, string $consOrdeRoche, string $item): ?string
    {
        $sql = "
            SELECT GROUP_CONCAT(r.Descripcion_Examen SEPARATOR '\r\n') AS descripcion
            FROM Interfaz_resultados_Roche r
            INNER JOIN Interfaz_homologacion_lab h ON (r.CodiPrue = h.CodiCups AND r.CodAnalito = h.Analito)
            WHERE h.CodiPrue = '9999' AND r.CodiInst = ? AND r.CodiPrue = ? AND r.ConsAdmi = ? AND r.ConsOrde = ? AND r.Item = ?
        ";
        $stmt = $this->connect()->prepare($sql);
        $stmt->execute([$codiInst, $codiPrueCups, $consAdmiRoche, $consOrdeRoche, $item]);
        $valor = $stmt->fetchColumn();

        return ($valor === false || $valor === null || $valor === '') ? null : (string)$valor;
    }

    /**
     * @return array{consHoPr:int,existia:bool}
     */
    public function resolverHojaProc(string $codiInst, string $consAdmi, string $consOrde, string $item, string $codiProc): array
    {
        $pdo = $this->connect();

        $stmt = $pdo->prepare("
            SELECT h.ConsHoPr
            FROM Admision a
            INNER JOIN HojaProc h ON (a.CodiInst = h.CodiInst AND a.ConsAdmi = h.ConsAdmi)
            WHERE h.CodiInst = ? AND h.ConsAdmi = ? AND h.NumeOrde = ? AND h.Item = ? AND h.CodiProc = ?
        ");
        $stmt->execute([$codiInst, $consAdmi, $consOrde, $item, $codiProc]);
        $consHoPr = $stmt->fetchColumn();

        if ($consHoPr !== false) {
            return ['consHoPr' => (int)$consHoPr, 'existia' => true];
        }

        $stmt = $pdo->prepare('SELECT MAX(ConsHoPr) AS ConsHoPr FROM HojaProc WHERE CodiInst = ? AND ConsAdmi = ?');
        $stmt->execute([$codiInst, $consAdmi]);

        return ['consHoPr' => ((int)$stmt->fetchColumn()) + 1, 'existia' => false];
    }

    public function existeHomologacionParaCups(string $codiCups): bool
    {
        $stmt = $this->connect()->prepare('SELECT 1 FROM Interfaz_homologacion_lab WHERE CodiCups = ? LIMIT 1');
        $stmt->execute([$codiCups]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * @param array{codiInst:string,consAdmi:string,consHoPr:int,codiModu:string,codiProc:?string,codiFina:?string,indiAdic:string,usuaDigi:string,cantProc:string,consOrde:string,item:string,codiDocu:?string,numeLiqu:?string,consDeFa:?string,cantFact:?string,codiServ:?string,codiDiag:?string,codiRel1:?string,codiRel2:?string,codiRel3:?string,codiRel4:?string} $datos
     */
    public function crearHojaProc(array $datos): void
    {
        $sql = "
            INSERT INTO HojaProc (CodiInst, ConsAdmi, ConsHoPr, CodiModu, CodiProc, CodiFina, FechProc, HoraProc,
                                   IndiAdic, UsuaAsis, CantProc, NumeOrde, Item, CodiDocu, NumeLiqu, ConsDeFa, CantFact,
                                   FechDigi, HoraDigi, UsuaDigi, FechModi, HoraModi, UsuaModi, CodiServ,
                                   DiagPrin, DiagRela, DiagRel1, DiagRel2, DiagRel3)
            VALUES (?, ?, ?, ?, ?, ?, CURDATE(), CURTIME(), ?, ?, ?, ?, ?, ?, ?, ?, ?,
                    CURDATE(), CURTIME(), ?, CURDATE(), CURTIME(), ?, ?, ?, ?, ?, ?, ?)
        ";
        $stmt = $this->connect()->prepare($sql);
        $ok = $stmt->execute([
            $datos['codiInst'], $datos['consAdmi'], $datos['consHoPr'], $datos['codiModu'], $datos['codiProc'], $datos['codiFina'],
            $datos['indiAdic'], $datos['usuaDigi'], $datos['cantProc'], $datos['consOrde'], $datos['item'],
            $datos['codiDocu'], $datos['numeLiqu'], $datos['consDeFa'], $datos['cantFact'],
            $datos['usuaDigi'], $datos['usuaDigi'], $datos['codiServ'],
            $datos['codiDiag'], $datos['codiRel1'], $datos['codiRel1'], $datos['codiRel2'], $datos['codiRel3'],
        ]);

        // FIX (mismo bug de interlabrolda que crearAdmisionYObtenerConsAdmi):
        // valida el resultado real, no el texto del SQL.
        if (!$ok) {
            throw new RuntimeException('No se pudo crear HojaProc en SIHOS (ConsAdmi ' . $datos['consAdmi'] . ').');
        }
    }

    public function actualizarHojaProc(string $codiInst, string $consAdmi, int $consHoPr, string $codiProc, string $codiModu, string $usuaDigi, ?string $indiAdic = null): void
    {
        if ($indiAdic !== null && $indiAdic !== '') {
            $sql = "UPDATE HojaProc SET CodiModu = ?, FechModi = CURDATE(), HoraModi = CURTIME(), UsuaModi = ?, IndiAdic = ?
                    WHERE CodiInst = ? AND ConsAdmi = ? AND ConsHoPr = ? AND CodiProc = ?";
            $stmt = $this->connect()->prepare($sql);
            $stmt->execute([$codiModu, $usuaDigi, $indiAdic, $codiInst, $consAdmi, $consHoPr, $codiProc]);

            return;
        }

        $sql = "UPDATE HojaProc SET CodiModu = ?, FechModi = CURDATE(), HoraModi = CURTIME(), UsuaModi = ?
                WHERE CodiInst = ? AND ConsAdmi = ? AND ConsHoPr = ? AND CodiProc = ?";
        $stmt = $this->connect()->prepare($sql);
        $stmt->execute([$codiModu, $usuaDigi, $codiInst, $consAdmi, $consHoPr, $codiProc]);
    }

    public function marcarLiquidacionRealizada(string $codiInst, string $numeLiqu, string $item, string $cantFact, string $consAdmi): void
    {
        $stmt = $this->connect()->prepare(
            'UPDATE DetaFact SET CantReal = ?, ConsAdmi = ? WHERE CodiInst = ? AND NumeFact = ? AND ConsDeFa = ?'
        );
        $stmt->execute([$cantFact, $consAdmi, $codiInst, $numeLiqu, $item]);
    }

    public function marcarOrdenRealizada(string $codiInst, string $consAdmi, string $consOrde, string $item, string $cantSumi): void
    {
        $stmt = $this->connect()->prepare(
            'UPDATE DetaOrde SET CantReal = ? WHERE CodiInst = ? AND ConsAdmi = ? AND ConsOrde = ? AND Item = ?'
        );
        $stmt->execute([$cantSumi, $codiInst, $consAdmi, $consOrde, $item]);
    }

    public function resolverCodiPrueHomologado(string $codiCups, string $codAnalito): ?string
    {
        $stmt = $this->connect()->prepare('SELECT CodiPrue FROM Interfaz_homologacion_lab WHERE CodiCups = ? AND Analito = ?');
        $stmt->execute([$codiCups, $codAnalito]);
        $valor = $stmt->fetchColumn();

        return $valor === false ? null : (string)$valor;
    }

    public function existeDetaPrue(string $codiInst, string $consAdmi, int $consHoPr, string $codiPrue, string $consPrue): bool
    {
        $stmt = $this->connect()->prepare(
            'SELECT 1 FROM DetaPrue WHERE CodiInst = ? AND ConsAdmi = ? AND ConsHoPr = ? AND CodiPrue = ? AND ConsPrue = ? LIMIT 1'
        );
        $stmt->execute([$codiInst, $consAdmi, $consHoPr, $codiPrue, $consPrue]);

        return $stmt->fetchColumn() !== false;
    }

    public function existeCodiPrueParametrizado(string $codiProc, string $codiPrue): bool
    {
        $stmt = $this->connect()->prepare('SELECT 1 FROM CodiPrue WHERE CodiProc = ? AND CodiPrue = ? LIMIT 1');
        $stmt->execute([$codiProc, $codiPrue]);

        return $stmt->fetchColumn() !== false;
    }

    public function crearDetaPrue(string $codiInst, string $consAdmi, int $consHoPr, string $consPrue, string $codiProc, string $codiPrue, string $valor, string $usuaDigi, string $valoMaxi): void
    {
        $sql = "
            INSERT INTO DetaPrue (CodiInst, ConsAdmi, ConsHoPr, ConsPrue, CodiProc, CodiPrue, Valor,
                                   FechDigi, HoraDigi, UsuaDigi, FechModi, HoraModi, UsuaModi, ValoMaxi)
            VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE(), CURTIME(), ?, CURDATE(), CURTIME(), ?, ?)
        ";
        // FIX (bug real de interlabrolda): ValoMaxi guarda el valor real
        // (OrdenLIS), no un '.' fijo que nunca aportaba información.
        $stmt = $this->connect()->prepare($sql);
        $stmt->execute([$codiInst, $consAdmi, $consHoPr, $consPrue, $codiProc, $codiPrue, $valor, $usuaDigi, $usuaDigi, $valoMaxi]);
    }

    public function actualizarDetaPrue(string $codiInst, string $consAdmi, int $consHoPr, string $consPrue, string $valor, string $usuaDigi): void
    {
        $sql = "UPDATE DetaPrue SET Valor = ?, FechModi = CURDATE(), HoraModi = CURTIME(), UsuaModi = ?
                WHERE CodiInst = ? AND ConsAdmi = ? AND ConsHoPr = ? AND ConsPrue = ?";
        $stmt = $this->connect()->prepare($sql);
        $stmt->execute([$valor, $usuaDigi, $codiInst, $consAdmi, $consHoPr, $consPrue]);
    }

    // =====================================================================
    // SOLICITUDES (interfaz_solicitudes.php)
    // =====================================================================

    public function existeSolicitudInterfaz(string $codiInst, string $consAdmi, string $consOrde, string $item): bool
    {
        $stmt = $this->connect()->prepare(
            'SELECT 1 FROM Interfaz_solicitudes_SIHOS WHERE CodiInst = ? AND ConsAdmi = ? AND ConsOrde = ? AND Item = ? LIMIT 1'
        );
        $stmt->execute([$codiInst, $consAdmi, $consOrde, $item]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Trae y enriquece TODOS los datos de una candidata para insertarla en
     * Interfaz_solicitudes_SIHOS: paciente, admisión/liquidación, barrio,
     * municipio, departamento, empresa, profesional, centro de producción,
     * sede, examen. Réplica del bloque "DATOS DEL PACIENTE" en adelante del
     * script original — es una sola operación lógica de enriquecimiento,
     * por eso vive en un único método aunque haga muchas subconsultas.
     *
     * @param array{CodiInst:string,ConsAdmi:string,ConsOrde:string,Item:string,CodiModu:string,CodiProc:string,ObseProc:?string,ConsDeFa:?string,FechDigi:string,HoraDigi:string,UsuaDigi:string,TipoDocu:?string,NumeUsua:string,NumeLiqu:?string,TipoOrde:?string,TipoInterfaz:string} $candidata
     * @return array<string, mixed>|null null si no aplica (no es tipo de servicio de laboratorio)
     */
    public function enriquecerCandidataSolicitud(array $candidata): ?array
    {
        $pdo = $this->connect();
        $codiInst = $candidata['CodiInst'];

        $stmt = $pdo->prepare('SELECT TipoServ, CodiCups FROM CodiProc WHERE CodiProc = ?');
        $stmt->execute([$candidata['CodiProc']]);
        $filaProc = $stmt->fetch();
        if ($filaProc === false || !in_array((string)$filaProc['TipoServ'], ['13', '34'], true)) {
            return null;
        }

        $esLiquidacion = $candidata['TipoInterfaz'] === '2';
        $numeFact = $candidata['NumeLiqu'];
        $consDeFa = $candidata['ConsDeFa'];

        if ($esLiquidacion) {
            $consAdmi = $numeFact;
            $consOrde = $numeFact;
            $item = $consDeFa;
        } else {
            $consAdmi = $candidata['ConsAdmi'];
            $consOrde = $candidata['ConsOrde'];
            $item = $candidata['Item'];
        }

        $stmt = $pdo->prepare("
            SELECT CONCAT(NombUsua, ' ', NombUsu1) AS Nombres, CONCAT(Ape1Usua, ' ', Ape2Usua) AS Apellidos,
                   SexoUsua, FechNaci, DireResi, TeleResi, TeleCelu, ResiBarr, ResiComu, ResiMuni, ResiDepa, Email
            FROM Paciente WHERE TipoDocu = ? AND NumeUsua = ?
        ");
        $stmt->execute([$candidata['TipoDocu'], $candidata['NumeUsua']]);
        $paciente = $stmt->fetch() ?: [];

        $camposEspeciales = [',', '.', '"', '*', '+', '/', 'º', '°', '(', ')', '{', '}', '[', ']', '?', '¿', '|', '$', '%', '&', '<', '>', ':', '_', 'Â', 'É', 'Í', 'Ó', 'Ú', 'á', 'é', 'í', 'ó', 'ú'];
        $direccion = str_replace($camposEspeciales, '', (string)($paciente['DireResi'] ?? ''));

        $departamento = (string)($paciente['ResiDepa'] ?? '');
        $municipio = (string)($paciente['ResiMuni'] ?? '');
        $comuna = (string)($paciente['ResiComu'] ?? '');
        $barrio = (string)($paciente['ResiBarr'] ?? '');

        $descripcionBarrio = $this->lookupUnaColumna($pdo, 'CodiBarr', 'NombBarr', ['CodiDepa' => $departamento, 'CodiMuni' => $municipio, 'CodiComu' => $comuna, 'CodiBarr' => $barrio]);
        $descripcionMunicipio = $this->lookupUnaColumna($pdo, 'CodiMuni', 'NombMuni', ['CodiDepa' => $departamento, 'CodiMuni' => $municipio]);
        $descripcionDepartamento = $this->lookupUnaColumna($pdo, 'CodiDepa', 'NombDepa', ['CodiDepa' => $departamento]);

        if ($esLiquidacion) {
            $stmt = $pdo->prepare("
                SELECT e.CodiAdmi, s.TipoAten, e.NumeCont, e.ServEgre, e.CamaActu, e.CondUsua, e.TipoAfil, e.TipoUsua
                FROM EncaFact e INNER JOIN CodiServ s ON (e.ServEgre = s.CodiServ)
                WHERE e.CodiInst = ? AND e.NumeFact = ?
            ");
            $stmt->execute([$codiInst, $numeFact]);
            $ctx = $stmt->fetch() ?: [];
            $factura = '1';
            $fechaFacturacion = date('Y-m-d H:i:s');
            $usuFactura = $candidata['UsuaDigi'];
        } else {
            $stmt = $pdo->prepare('SELECT CodiAdmi, TipoAten, NumeCont, ServEgre, CamaActu, CondUsua, TipoAfil, TipoUsua FROM Admision WHERE CodiInst = ? AND ConsAdmi = ?');
            $stmt->execute([$codiInst, $consAdmi]);
            $ctx = $stmt->fetch() ?: [];

            $stmt = $pdo->prepare('SELECT CodiServ FROM EncaOrde WHERE CodiInst = ? AND ConsAdmi = ? AND ConsOrde = ? LIMIT 1');
            $stmt->execute([$codiInst, $consAdmi, $consOrde]);
            $ctx['ServEgre'] = $stmt->fetchColumn() ?: ($ctx['ServEgre'] ?? '');

            $factura = '0';
            $fechaFacturacion = '';
            $usuFactura = '';
        }

        $tipoOrden = ((int)($ctx['TipoAten'] ?? 0) === 1) ? 1 : 2;
        $embarazada = in_array((string)($ctx['CondUsua'] ?? ''), ['1', '2', '3'], true) ? 1 : 0;

        $descripcionEmpresa = $this->lookupUnaColumna($pdo, 'CodiAdmi', 'NombAdmi', ['CodiAdmi' => (string)($ctx['CodiAdmi'] ?? '')]);
        $descripcionProfesional = $this->lookupUnaColumna($pdo, 'Usuarios', 'Nombre', ['Login' => $candidata['UsuaDigi']]);
        $descripcionCentro = $this->lookupUnaColumna($pdo, 'CodiServ', 'NombServ', ['CodiServ' => (string)($ctx['ServEgre'] ?? '')]);
        $descripcionSede = $this->lookupUnaColumna($pdo, 'CodiInst', 'NombInst', ['CodiInst' => $codiInst]);
        $descripcionExamen = $this->lookupUnaColumna($pdo, 'CodiProc', 'NombProc', ['CodiProc' => $candidata['CodiProc']]);

        return [
            'CodiInst' => $codiInst, 'ConsAdmi' => $consAdmi, 'ConsOrde' => $consOrde, 'Item' => $item,
            'Historia' => $candidata['NumeUsua'], 'Apellidos' => (string)($paciente['Apellidos'] ?? ''), 'Nombres' => (string)($paciente['Nombres'] ?? ''),
            'Genero' => (string)($paciente['SexoUsua'] ?? ''), 'FechaNacimiento' => (string)($paciente['FechNaci'] ?? ''),
            'TipoDocumento' => $candidata['TipoDocu'], 'Direccion' => $direccion,
            'Telefono' => trim(($paciente['TeleResi'] ?? '') . ' - ' . ($paciente['TeleCelu'] ?? '')),
            'Barrio' => $barrio, 'DescripcionBarrio' => $descripcionBarrio,
            'Municipio' => $municipio, 'DescripcionMunicipio' => $descripcionMunicipio,
            'Departamento' => $departamento, 'DescripcionDepartamento' => $descripcionDepartamento,
            'ComentarioOrden' => (string)($candidata['ObseProc'] ?? ''), 'TipoOrden' => $tipoOrden,
            'Regimen' => (string)($ctx['TipoUsua'] ?? ''), 'Empresa' => (string)($ctx['CodiAdmi'] ?? ''), 'DescripcionEmpresa' => $descripcionEmpresa,
            'Nivel' => (string)($ctx['TipoAfil'] ?? ''), 'Contrato' => (string)($ctx['NumeCont'] ?? ''),
            'ProfesionalOrdena' => $candidata['UsuaDigi'], 'DescripcionProfesionalOrdena' => $descripcionProfesional,
            'CentroProduccion' => (string)($ctx['ServEgre'] ?? ''), 'DescripcionCentroProduccion' => $descripcionCentro,
            'Cama' => (string)($ctx['CamaActu'] ?? ''), 'Factura' => $factura, 'FechaFacturacion' => $fechaFacturacion, 'UsuFactura' => $usuFactura,
            'Sede' => $codiInst, 'DescripcionSede' => $descripcionSede, 'Embarazada' => $embarazada,
            'CodigoExamen' => (string)$filaProc['CodiCups'], 'DescripcionExamen' => $descripcionExamen,
            'IdRegistro' => $consAdmi, 'OrdenMedica' => $consOrde,
            'FechaOrden' => trim($candidata['FechDigi'] . ' ' . $candidata['HoraDigi']),
            'CodiModu' => $candidata['CodiModu'], 'UsuaDigi' => $candidata['UsuaDigi'], 'Email' => (string)($paciente['Email'] ?? ''),
        ];
    }

    /** @param array<string,string> $where */
    private function lookupUnaColumna(PDO $pdo, string $tabla, string $columna, array $where): string
    {
        $condiciones = [];
        $valores = [];
        foreach ($where as $col => $valor) {
            $condiciones[] = "{$col} = ?";
            $valores[] = $valor;
        }
        $stmt = $pdo->prepare("SELECT {$columna} FROM {$tabla} WHERE " . implode(' AND ', $condiciones));
        $stmt->execute($valores);
        $valor = $stmt->fetchColumn();

        return $valor === false ? '' : (string)$valor;
    }

    /**
     * @param array<string, mixed> $datos de enriquecerCandidataSolicitud()
     */
    public function insertarSolicitud(array $datos): void
    {
        $sql = "
            INSERT INTO Interfaz_solicitudes_SIHOS (
                CodiInst, ConsAdmi, ConsOrde, Item, Historia, Apellidos, Nombres, Genero, Fecha_Nacimiento,
                Comentario_Historia, Tipo_Documento, Direccion, Telefono, Barrio, Descripcion_Barrio,
                Municipio, Descripcion_Municipio, Departamento, Descripcion_Departamento,
                Comentario_Orden, Tipo_Orden, Regimen, Empresa, Descripcion_Empresa, Nivel, Contrato,
                Profesional_Ordena, Descripcion_Profesional_Ordena, Centro_Produccion, Descripcion_Centro_Produccion, Cama,
                Factura, Fecha_Facturacion, usu_Factura, Sede, Descripcion_Sede, Embarazada, Codigo_Examen, Descripcion_Examen,
                Cargada, id_registro, orden_medica, Fecha_Orden, Fecha_Hora_Inserta, Importad,
                CodiModu, FechDigi, HoraDigi, UsuaDigi, FechModi, HoraModi, UsuaModi, Correo
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?,
                '', ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?, ?, ?,
                '0', ?, ?, ?, ?, '',
                ?, CURDATE(), CURTIME(), ?, CURDATE(), CURTIME(), ?, ?
            )
        ";
        $stmt = $this->connect()->prepare($sql);
        $stmt->execute([
            $datos['CodiInst'], $datos['ConsAdmi'], $datos['ConsOrde'], $datos['Item'], $datos['Historia'], $datos['Apellidos'], $datos['Nombres'], $datos['Genero'], $datos['FechaNacimiento'],
            $datos['TipoDocumento'], $datos['Direccion'], $datos['Telefono'], $datos['Barrio'], $datos['DescripcionBarrio'],
            $datos['Municipio'], $datos['DescripcionMunicipio'], $datos['Departamento'], $datos['DescripcionDepartamento'],
            $datos['ComentarioOrden'], $datos['TipoOrden'], $datos['Regimen'], $datos['Empresa'], $datos['DescripcionEmpresa'], $datos['Nivel'], $datos['Contrato'],
            $datos['ProfesionalOrdena'], $datos['DescripcionProfesionalOrdena'], $datos['CentroProduccion'], $datos['DescripcionCentroProduccion'], $datos['Cama'],
            $datos['Factura'], $datos['FechaFacturacion'], $datos['UsuFactura'], $datos['Sede'], $datos['DescripcionSede'], $datos['Embarazada'], $datos['CodigoExamen'], $datos['DescripcionExamen'],
            $datos['IdRegistro'], $datos['OrdenMedica'], $datos['FechaOrden'],
            $datos['CodiModu'], $datos['UsuaDigi'], $datos['UsuaDigi'], $datos['Email'],
        ]);
    }

    // =====================================================================
    // HOMOLOGACIÓN (catálogo — no es dato clínico de paciente)
    // =====================================================================

    public function crearHomologacion(string $codiCups, string $codiPrue, string $analito): void
    {
        $stmt = $this->connect()->prepare('INSERT INTO Interfaz_homologacion_lab (CodiCups, CodiPrue, Analito) VALUES (?, ?, ?)');
        $stmt->execute([$codiCups, $codiPrue, $analito]);
    }

    public function actualizarHomologacion(string $codiCupsAnterior, string $codiPrueAnterior, string $analitoAnterior, string $codiCups, string $codiPrue, string $analito): void
    {
        $stmt = $this->connect()->prepare(
            'UPDATE Interfaz_homologacion_lab SET CodiCups = ?, CodiPrue = ?, Analito = ? WHERE CodiCups = ? AND CodiPrue = ? AND Analito = ?'
        );
        $stmt->execute([$codiCups, $codiPrue, $analito, $codiCupsAnterior, $codiPrueAnterior, $analitoAnterior]);
    }

    public function eliminarHomologacion(string $codiCups, string $codiPrue, string $analito): void
    {
        $stmt = $this->connect()->prepare('DELETE FROM Interfaz_homologacion_lab WHERE CodiCups = ? AND CodiPrue = ? AND Analito = ?');
        $stmt->execute([$codiCups, $codiPrue, $analito]);
    }
}
