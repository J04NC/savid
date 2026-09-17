<?php

/**
 * Conexión de SOLO LECTURA a las tablas de interfaz Roche <-> SIHOS
 * (`Interfaz_resultados_Roche`, `Interfaz_solicitudes_SIHOS`,
 * `Interfaz_homologacion_lab`) de una empresa concreta. Reutiliza la MISMA
 * base de datos externa que `SihosExternalRepository` (son tablas del mismo
 * esquema `sihos` de cada institución) pero es una clase separada: esa otra
 * clase es exclusivamente para el reporte contable/presupuestal de Cruce, y
 * mezclar aquí sus métodos la volvería confusa. Ver
 * SihosInterlabWriteRepository para la contraparte de escritura.
 *
 * Fuerza `SET SESSION TRANSACTION READ ONLY` igual que SihosExternalRepository:
 * cualquier escritura debe fallar a nivel de motor, no solo por convención.
 *
 * Todas las consultas filtran por `CodiInst` (config por empresa) — ver el
 * docblock de SihosExternalRepository sobre instalaciones multi-institución.
 */
class SihosInterlabRepository
{
    private ?PDO $pdo = null;

    /**
     * @param array{host:string,port:string,database:string,username:string,password:string,charset:string,codiInst:string} $config
     */
    public function __construct(private readonly array $config)
    {
    }

    /**
     * @throws PDOException si no conecta
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

    private function codiInst(): string
    {
        return (string)($this->config['codiInst'] ?? '');
    }

    /**
     * Filas de `Interfaz_resultados_Roche` (cola de resultados enviados por
     * Roche), filtradas por rango de fecha e, opcionalmente, por estado
     * `Enviada`. Nunca sin rango de fecha: la tabla puede tener años de
     * historial.
     *
     * @param int[] $estados vacío = todos los estados
     * @return list<array<string, mixed>>
     */
    public function fetchResultados(string $fechaIni, string $fechaFin, array $estados = [], int $limite = 500): array
    {
        $condicionEstado = '';
        $params = [$this->codiInst(), $fechaIni, $fechaFin];

        if ($estados !== []) {
            $ph = implode(',', array_fill(0, count($estados), '?'));
            $condicionEstado = "AND Enviada IN ({$ph})";
            $params = [...$params, ...$estados];
        }

        $sql = "
            SELECT id, CodiInst, ConsAdmi, ConsOrde, Item, CodiPrue, CodAnalito, Descripcion_Examen,
                   Resultado, Unidades, Valor_Ref_Minimo, Valor_Ref_Maximo, Patologico, Comentario,
                   Antibiotico, CMI, Sensibilidad, MicroOrganismo, ComentarioMicroorg,
                   Historia, OrdenLIS, Enviada, Correcion, motivo_correcion,
                   Fecha_Resultado, Fecha_Validacion, Fecha_Envio_Resultado, FechDigi, HoraDigi
            FROM Interfaz_resultados_Roche
            WHERE CodiInst = ?
              AND Fecha_Resultado BETWEEN ? AND ?
              {$condicionEstado}
            ORDER BY Fecha_Resultado DESC, ConsAdmi DESC, ConsOrde DESC, Item
            LIMIT {$limite}
        ";
        $stmt = $this->connect()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Una sola fila de `Interfaz_resultados_Roche` por `id` — usada por el
     * procesamiento (una fila a la vez) para releer el estado justo antes de
     * escribir, no para el datatable.
     */
    public function fetchResultadoPorId(int $id): ?array
    {
        $stmt = $this->connect()->prepare(
            'SELECT * FROM Interfaz_resultados_Roche WHERE CodiInst = ? AND id = ?'
        );
        $stmt->execute([$this->codiInst(), $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * IDs pendientes de procesar (`Enviada=0 OR Correcion=1`) — mismo
     * criterio que el `WHERE` del script original, SIN rango de fecha (el
     * cron y el botón "Procesar seleccionados" trabajan sobre esto, no
     * sobre `fetchResultados()`, que sí exige rango para la vista web).
     *
     * @return int[]
     */
    public function fetchIdsResultadosPendientes(int $limite = 1000): array
    {
        $stmt = $this->connect()->prepare("
            SELECT id FROM Interfaz_resultados_Roche
            WHERE CodiInst = ? AND (Enviada = 0 OR Correcion = 1)
            ORDER BY ConsAdmi, ConsOrde, Item
            LIMIT {$limite}
        ");
        $stmt->execute([$this->codiInst()]);

        return array_map('intval', array_column($stmt->fetchAll(), 'id'));
    }

    /**
     * Filas ya registradas en `Interfaz_solicitudes_SIHOS` (histórico de lo
     * ya exportado a Roche), filtradas por rango de fecha de la orden.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchSolicitudesRegistradas(string $fechaIni, string $fechaFin, int $limite = 500): array
    {
        $sql = "
            SELECT CodiInst, ConsAdmi, ConsOrde, Item, Historia, Apellidos, Nombres,
                   Comentario_Orden, Regimen, Empresa, Descripcion_Empresa,
                   Profesional_Ordena, Descripcion_Profesional_Ordena,
                   Centro_Produccion, Descripcion_Centro_Produccion,
                   Codigo_Examen, Descripcion_Examen, Cargada,
                   Fecha_Orden, Fecha_Hora_Inserta
            FROM Interfaz_solicitudes_SIHOS
            WHERE CodiInst = ?
              AND Fecha_Orden BETWEEN ? AND ?
            ORDER BY Fecha_Hora_Inserta DESC
            LIMIT {$limite}
        ";
        $stmt = $this->connect()->prepare($sql);
        $stmt->execute([$this->codiInst(), $fechaIni, $fechaFin]);

        return $stmt->fetchAll();
    }

    /**
     * Órdenes/liquidaciones de laboratorio (últimos `$diasAtras` días) que
     * TODAVÍA no están en `Interfaz_solicitudes_SIHOS` — candidatas a
     * "Procesar". Réplica de `$sqlCP` en interfaz_solicitudes.php, con la
     * comprobación de duplicado empujada al SQL (`NOT EXISTS`) en vez de
     * hacerse fila por fila en PHP como el script original: mismo resultado,
     * una sola consulta.
     *
     * `STRAIGHT_JOIN` (forzando DetaOrde/DetaFact como tabla líder en vez de
     * dejar que el optimizador empiece por EncaOrde/DetaFact vía su PRIMARY)
     * es obligatorio aquí: verificado con EXPLAIN + medición real contra la
     * BD de producción de La Unión — sin forzar el orden, el optimizador
     * arrastra ~270.000 filas de EncaOrde (todas las de la institución, sin
     * filtro de fecha útil) antes de aplicar el resto de condiciones, y la
     * consulta no termina en un tiempo razonable (más de 2 minutos, se
     * abortó). Con STRAIGHT_JOIN baja a ~2.6s / ~14s por rama. Causa raíz:
     * ni DetaOrde ni DetaFact tienen índice sobre FechDigi en el esquema de
     * SIHOS (verificado con SHOW INDEX) — no es algo corregible desde SAVID
     * sin alterar el esquema externo del cliente.
     *
     * Se ejecutan las 2 ramas como consultas INDEPENDIENTES (sin `UNION` en
     * un solo SQL) y se fusionan aquí en PHP: verificado con medición real
     * que un `UNION` (con la deduplicación/orden que exige) sobre estas dos
     * ramas no termina en un tiempo razonable (más de 90s, abortado), aunque
     * cada rama por separado sí es rápida (~3s / ~15s). Deduplicar 2 arrays
     * pequeños en PHP es trivial; forzar a MySQL a materializar y ordenar el
     * combinado no lo es, dado el volumen de columnas de texto.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchSolicitudesCandidatas(int $diasAtras = 1): array
    {
        // $diasAtras se interpola directo (entero validado por el type hint del
        // parámetro, nunca input crudo de usuario): un placeholder "?" aquí
        // hace que PDO (emulated prepares) lo pase como string citado en vez
        // de entero literal, y MySQL entonces descarta el plan optimizado por
        // STRAIGHT_JOIN — verificado con medición real, la consulta pasa de
        // ~3s/~15s a no terminar en más de 100s con el placeholder.
        $dias = $diasAtras;

        // Sin NOT EXISTS aquí: verificado con medición real que la subconsulta
        // correlacionada contra Interfaz_solicitudes_SIHOS es rápida en la rama
        // de órdenes (pocas filas base) pero NO TERMINA en la de liquidaciones
        // (más de 100s, abortada) — MySQL no usa el índice ahí bajo STRAIGHT_JOIN.
        // La deduplicación contra lo ya registrado se hace más abajo, en PHP,
        // contra una tercera consulta simple (rápida) de solo claves.
        $sqlOrdenes = "
            SELECT STRAIGHT_JOIN d.CodiInst, d.ConsAdmi, d.ConsOrde, d.Item, d.CodiModu, d.CodiProc, d.ObseProc,
                   d.ConsDeFa, d.FechDigi, d.HoraDigi, d.UsuaDigi, d.FechModi, d.HoraModi, d.UsuaModi,
                   a.TipoDocu, a.NumeUsua, d.NumeLiqu, '' AS TipoOrde, '1' AS TipoInterfaz
            FROM DetaOrde d
            INNER JOIN EncaOrde e ON (e.CodiInst = d.CodiInst AND e.ConsAdmi = d.ConsAdmi AND e.ConsOrde = d.ConsOrde AND e.CodiModu = d.CodiModu)
            INNER JOIN Admision a ON (a.CodiInst = e.CodiInst AND a.ConsAdmi = e.ConsAdmi)
            INNER JOIN CodiProc c ON (d.CodiProc = c.CodiProc AND c.TipoServ IN (13, 34, 49))
            WHERE d.CodiInst = ? AND d.CantReal <> '1'
              AND d.FechDigi >= DATE_SUB(CURDATE(), INTERVAL {$dias} DAY) AND e.OrdeAmbu <> '1'
              AND a.ServEgre IN (SELECT CodiServ FROM CodiServ WHERE TipoAten IN (2, 3))
        ";
        $sqlLiquidaciones = "
            SELECT STRAIGHT_JOIN d.CodiInst, d.ConsAdmi, d.NumeOrde AS ConsOrde, d.Item, '' AS CodiModu, d.CodiServ AS CodiProc, '' AS ObseProc,
                   d.ConsDeFa, d.FechDigi, d.HoraDigi, d.CodiMedi AS UsuaDigi, d.FechModi, d.HoraModi, d.CodiMedi AS UsuaModi,
                   e.TipoDocu, e.NumeUsua, d.NumeFact AS NumeLiqu, d.TipoOrde, '2' AS TipoInterfaz
            FROM DetaFact d
            INNER JOIN EncaFact e ON (e.CodiInst = d.CodiInst AND e.CodiAno = d.CodiAno AND e.CodiDocu = d.CodiDocu AND e.NumeFact = d.NumeFact)
            WHERE d.CodiInst = ? AND d.TipoDeta = 1 AND d.TipoHoja <> 'HojaProc' AND d.TipoServ IN (13, 34, 49)
              AND d.FechDigi >= DATE_SUB(CURDATE(), INTERVAL {$dias} DAY)
              AND e.Causado = '1' AND e.Anulado <> '1'
              AND e.ServEgre IN (SELECT CodiServ FROM CodiServ WHERE TipoAten NOT IN (2, 3))
        ";
        // Margen de +2 días sobre $dias: una candidata de "ayer" pudo quedar
        // registrada hoy (Fecha_Orden/FechDigi de origen vs. Fecha_Hora_Inserta
        // de cuándo se insertó en la interfaz no son la misma fecha).
        $sqlYaRegistradas = "
            SELECT CodiInst, ConsAdmi, ConsOrde, Item
            FROM Interfaz_solicitudes_SIHOS
            WHERE CodiInst = ? AND Fecha_Hora_Inserta >= DATE_SUB(CURDATE(), INTERVAL " . ($dias + 2) . " DAY)
        ";

        $pdo = $this->connect();

        $stmt1 = $pdo->prepare($sqlOrdenes);
        $stmt1->execute([$this->codiInst()]);
        $filas = $stmt1->fetchAll();

        $stmt2 = $pdo->prepare($sqlLiquidaciones);
        $stmt2->execute([$this->codiInst()]);

        $stmt3 = $pdo->prepare($sqlYaRegistradas);
        $stmt3->execute([$this->codiInst()]);

        $yaRegistradas = [];
        foreach ($stmt3->fetchAll() as $fila) {
            $yaRegistradas[$fila['CodiInst'] . '|' . $fila['ConsAdmi'] . '|' . $fila['ConsOrde'] . '|' . $fila['Item']] = true;
        }

        $vistas = [];
        foreach ($filas as $fila) {
            $vistas[$fila['CodiInst'] . '|' . $fila['ConsAdmi'] . '|' . $fila['ConsOrde'] . '|' . $fila['Item']] = true;
        }
        foreach ($stmt2->fetchAll() as $fila) {
            $clave = $fila['CodiInst'] . '|' . $fila['ConsAdmi'] . '|' . $fila['ConsOrde'] . '|' . $fila['Item'];
            if (isset($vistas[$clave])) {
                continue;
            }
            $vistas[$clave] = true;
            $filas[] = $fila;
        }

        $filas = array_values(array_filter(
            $filas,
            static fn (array $fila): bool => !isset($yaRegistradas[
                $fila['CodiInst'] . '|' . $fila['ConsAdmi'] . '|' . $fila['ConsOrde'] . '|' . $fila['Item']
            ])
        ));

        usort($filas, static fn (array $a, array $b): int => strcmp((string)$b['FechDigi'], (string)$a['FechDigi']));

        return $filas;
    }

    /**
     * Catálogo de homologación (mapeo CodiCups+Analito -> CodiPrue interno),
     * con el nombre de cada lado resuelto contra su propio catálogo de
     * SIHOS: `NombreCups` viene de `CodiProc.NombProc` (por `CodiCups`, el
     * procedimiento externo) y `NombrePrue` de `CodiPrue.NombPrue` (por
     * `CodiProc`+`CodiPrue`, la prueba interna parametrizada dentro de ese
     * procedimiento).
     *
     * Se resuelve en PHP con 2 mapas (CodiProc y CodiPrue completos, ~13.700
     * y ~4.800 filas respectivamente) en vez de JOIN/subconsulta correlacionada
     * en SQL: verificado con medición real que `CodiProc.CodiCups` NO tiene
     * índice, así que un JOIN ahí escanea toda la tabla por cada fila del
     * catálogo de homologación (2047 filas -> más de 8s). Cargar ambos
     * catálogos completos una sola vez es más rápido que 2047 lookups sin
     * índice. Además, muchas filas de homologación tienen `CodiCups` vacío
     * (verificado con datos reales) — un JOIN directo por ese valor las
     * empareja con CUALQUIER fila de CodiProc que también tenga CodiCups=''
     * (nombre arbitrario y filas duplicadas: 2047 -> 5227 en la medición
     * real) — el mapa en PHP simplemente no resuelve nombre para esas filas.
     *
     * Clave primaria de la fila es compuesta (CodiCups, CodiPrue, Analito)
     * — sin `id`.
     *
     * @return list<array{CodiCups:string,CodiPrue:string,Analito:string,NombreCups:?string,NombrePrue:?string}>
     */
    public function fetchHomologacion(string $codiCups = ''): array
    {
        $pdo = $this->connect();

        $sql = 'SELECT CodiCups, Analito, CodiPrue FROM Interfaz_homologacion_lab';
        $params = [];
        if ($codiCups !== '') {
            $sql .= ' WHERE CodiCups = ?';
            $params[] = $codiCups;
        }
        $sql .= ' ORDER BY CodiCups, Analito';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $filas = $stmt->fetchAll();

        if ($filas === []) {
            return [];
        }

        $mapaCups = []; // CodiCups -> [CodiProc, NombProc]
        foreach ($pdo->query("SELECT CodiCups, CodiProc, NombProc FROM CodiProc WHERE CodiCups <> ''") as $fila) {
            if (!isset($mapaCups[$fila['CodiCups']])) {
                $mapaCups[$fila['CodiCups']] = [$fila['CodiProc'], $fila['NombProc']];
            }
        }

        $mapaPrue = []; // "CodiProc|CodiPrue" -> NombPrue
        foreach ($pdo->query('SELECT CodiProc, CodiPrue, NombPrue FROM CodiPrue') as $fila) {
            $mapaPrue[$fila['CodiProc'] . '|' . $fila['CodiPrue']] = $fila['NombPrue'];
        }

        foreach ($filas as &$fila) {
            $fila['NombreCups'] = null;
            $fila['NombrePrue'] = null;

            if ($fila['CodiCups'] !== '' && isset($mapaCups[$fila['CodiCups']])) {
                [$codiProc, $nombreCups] = $mapaCups[$fila['CodiCups']];
                $fila['NombreCups'] = $nombreCups;
                $fila['NombrePrue'] = $mapaPrue[$codiProc . '|' . $fila['CodiPrue']] ?? null;
            }
        }
        unset($fila);

        return $filas;
    }
}
