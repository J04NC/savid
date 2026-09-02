<?php

/**
 * Conexión de ESCRITURA a la base de datos externa de SIHOS de una
 * empresa concreta. Deliberadamente separada de SihosExternalRepository
 * (que fuerza `SET SESSION TRANSACTION READ ONLY`): esta clase existe
 * SOLO para las escrituras explícitas y auditadas contra SIHOS —
 * borrado de DetaPlan huérfano (ver SihosPresupuestoEliminacionService) y
 * creación de la Nota Contabilidad que cancela una cuenta contable fuera
 * de lo esperado contra 4312 (ver SihosCancelacionCuentaService). No se
 * usa para los reportes, y los reportes nunca deben instanciar esta clase.
 *
 * La credencial de conexión (usuario_escritura/password_escritura_cifrado
 * en sihos_empresa_config) es distinta de la de solo lectura y opcional
 * por diseño: sin ella configurada, ninguna de estas acciones aparece.
 */
class SihosExternalWriteRepository
{
    private ?PDO $pdo = null;

    /** @var array<string, array{idPartNIIF:string,Nivel:int,OpciTerc:int}>|null caché en memoria, ver partNIIFMap() */
    private ?array $partNIIFMap = null;

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
        }

        return $this->pdo;
    }

    private function codiInst(): string
    {
        return (string)($this->config['codiInst'] ?? '');
    }

    /** Segundos que se espera por el bloqueo antes de rendirse. */
    private const ESPERA_BLOQUEO = 10;

    /**
     * Serializa una operación de escritura con un bloqueo nombrado de MySQL.
     *
     * Por qué un lock nombrado y no `FOR UPDATE` en estas tablas: la
     * comprobación de "ya existe un ajuste" cruza DetaCont/EncaCont por un
     * índice que empieza en TiDoRefe, de baja cardinalidad. Un bloqueo de hueco
     * ahí frenaría inserciones ajenas y podría entorpecer a los propios
     * usuarios de SIHOS. GET_LOCK serializa solo esta operación lógica sin
     * tocar ninguna fila. (En DetaPlan sí se usa FOR UPDATE: allí el filtro es
     * el prefijo de la PRIMARY KEY y el bloqueo queda en un documento.)
     *
     * El nombre se acota al objetivo lógico (institución + documento + cuenta),
     * de modo que dos correcciones distintas no se estorban. El bloqueo es de
     * sesión, no transaccional: se toma antes de abrir la transacción y se
     * libera al terminar, pase lo que pase.
     *
     * MySQL 5.6 (el de SIHOS) mantiene un único lock nombrado por sesión; aquí
     * se toma exactamente uno por operación, así que encaja.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     * @throws SihosOperacionEnCursoException si no se obtiene el bloqueo
     */
    private function conBloqueo(string $clave, callable $fn)
    {
        $pdo = $this->connect();
        $nombre = 'savid:' . substr(sha1($this->codiInst() . '|' . $clave), 0, 40);

        $stmt = $pdo->prepare('SELECT GET_LOCK(?, ?)');
        $stmt->execute([$nombre, self::ESPERA_BLOQUEO]);

        if ((int)$stmt->fetchColumn() !== 1) {
            throw new SihosOperacionEnCursoException(
                'La misma operación ya se está ejecutando sobre SIHOS. Espere a que termine y verifique el resultado antes de repetirla.'
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

    /**
     * Borra todas las líneas de DetaPlan de un documento puntual, dentro de
     * una transacción, y devuelve el snapshot de lo borrado (para el
     * registro de auditoría en SAVID). Si algo falla, revierte y relanza.
     *
     * @return array<int, array<string, mixed>> filas borradas
     * @throws PDOException
     */
    public function eliminarDetaPlan(string $codiDocu, string $numeDocu): array
    {
        return $this->conBloqueo('detaplan:' . $codiDocu . ':' . $numeDocu, function () use ($codiDocu, $numeDocu): array {
            return $this->eliminarDetaPlanBloqueado($codiDocu, $numeDocu);
        });
    }

    /** @see eliminarDetaPlan (se ejecuta ya con el bloqueo tomado) */
    private function eliminarDetaPlanBloqueado(string $codiDocu, string $numeDocu): array
    {
        $pdo = $this->connect();
        $codiInst = (string)($this->config['codiInst'] ?? '');

        $pdo->beginTransaction();

        try {
            /*
             * FOR UPDATE: sin él, la lectura dentro de la transacción es
             * consistente pero NO bloquea, así que dos peticiones simultáneas
             * ven las mismas filas, ambas pasan la validación y ambas borran.
             * El WHERE usa el prefijo de la PRIMARY KEY
             * (CodiInst, CodiDocu, NumeDocu, …), verificado con EXPLAIN:
             * key=PRIMARY, rows=1 — el bloqueo queda acotado al documento y no
             * compromete una tabla de 1,4 millones de filas.
             */
            $stmtSelect = $pdo->prepare(
                'SELECT * FROM DetaPlan WHERE CodiInst = ? AND CodiDocu = ? AND NumeDocu = ? FOR UPDATE'
            );
            $stmtSelect->execute([$codiInst, $codiDocu, $numeDocu]);
            $filas = $stmtSelect->fetchAll();

            if ($filas === []) {
                $pdo->rollBack();

                return [];
            }

            $stmtDelete = $pdo->prepare(
                'DELETE FROM DetaPlan WHERE CodiInst = ? AND CodiDocu = ? AND NumeDocu = ?'
            );
            $stmtDelete->execute([$codiInst, $codiDocu, $numeDocu]);

            $pdo->commit();

            return $filas;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Construye la única línea DetaPlan que le falta a una nota (NCF) sobre
     * una factura de vigencia actual (sección 3 del reporte), dentro de una
     * transacción. Réplica de la fórmula validada contra casos sanos reales:
     * ConsDeta=1, CodiPlan/Valor recibidos ya resueltos por el llamador,
     * CentCost='0', Vigencia=1, sin TipoDoRe/NumeDoRe. Deliberadamente NO
     * toca SaldPlan/ValoUsad/SaldDisp (quedan en su default 0.00 — columnas
     * de saldo acumulado real que SIHOS recalcula con su propio proceso de
     * reconstrucción presupuestal, el mismo que el usuario ya corre a mano
     * después de tocar DetaPlan; ver SihosPresupuestoEliminacionService).
     *
     * Re-chequea, ya dentro de la transacción, que el documento siga sin
     * ninguna fila DetaPlan (idempotencia — si ya existe, hace rollback y
     * devuelve un array vacío en vez de duplicar la línea).
     *
     * @return array<string, mixed> fila insertada (vacío si ya existía)
     * @throws PDOException
     */
    public function construirDetaPlanNotaVigenciaActual(
        string $codiDocuNota,
        string $numeDocuNota,
        string $codiAno,
        string $codiPlan,
        float $valor,
        string $usuaDigi
    ): array {
        return $this->conBloqueo('detaplan:' . $codiDocuNota . ':' . $numeDocuNota, function () use (
            $codiDocuNota, $numeDocuNota, $codiAno, $codiPlan, $valor, $usuaDigi
        ): array {
            return $this->construirDetaPlanNotaVigenciaActualBloqueado(
                $codiDocuNota, $numeDocuNota, $codiAno, $codiPlan, $valor, $usuaDigi
            );
        });
    }

    /** @see construirDetaPlanNotaVigenciaActual (ya con el bloqueo tomado) */
    private function construirDetaPlanNotaVigenciaActualBloqueado(
        string $codiDocuNota,
        string $numeDocuNota,
        string $codiAno,
        string $codiPlan,
        float $valor,
        string $usuaDigi
    ): array {
        $pdo = $this->connect();
        $codiInst = $this->codiInst();

        $pdo->beginTransaction();

        try {
            /*
             * FOR UPDATE sobre el rango del documento. Aquí importa incluso
             * cuando no hay filas: el bloqueo de hueco impide que otra
             * transacción inserte la misma línea entre este chequeo y el
             * INSERT de abajo (doble clic = línea duplicada). Se usa SELECT
             * de la clave en vez de COUNT(*) porque un agregado no bloquea
             * el rango de la misma forma.
             */
            $stmtCheck = $pdo->prepare(
                'SELECT ConsDeta FROM DetaPlan WHERE CodiInst = ? AND CodiDocu = ? AND NumeDocu = ? FOR UPDATE'
            );
            $stmtCheck->execute([$codiInst, $codiDocuNota, $numeDocuNota]);

            if ($stmtCheck->fetch() !== false) {
                $pdo->rollBack();

                return [];
            }

            $stmtInsert = $pdo->prepare(
                'INSERT INTO DetaPlan
                    (CodiInst, CodiAno, CodiDocu, NumeDocu, ConsDeta, CodiPlan, CentCost, Valor, Vigencia,
                     FechDigi, HoraDigi, UsuaDigi, FechModi, HoraModi, UsuaModi)
                 VALUES (?, ?, ?, ?, 1, ?, \'0\', ?, 1, CURDATE(), CURTIME(), ?, CURDATE(), CURTIME(), ?)'
            );
            $stmtInsert->execute([
                $codiInst, $codiAno, $codiDocuNota, $numeDocuNota, $codiPlan, $valor, $usuaDigi, $usuaDigi,
            ]);

            $stmtSelect = $pdo->prepare(
                'SELECT * FROM DetaPlan WHERE CodiInst = ? AND CodiDocu = ? AND NumeDocu = ? AND ConsDeta = 1'
            );
            $stmtSelect->execute([$codiInst, $codiDocuNota, $numeDocuNota]);
            $filaInsertada = $stmtSelect->fetch();

            $pdo->commit();

            return $filaInsertada === false ? [] : $filaInsertada;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Crea una Nota Contabilidad (NC) que cancela (débito) una línea
     * contable "fuera de lo esperado" de una factura contra (crédito) la(s)
     * línea(s) 4312 que esa misma factura ya tiene — repartido
     * proporcionalmente si hay más de una. Solo contabilidad: nunca inserta
     * en DetaPlan. Réplica fiel, dentro de una sola transacción, de lo que
     * SIHOS hace en vivo en cada transacción normal (no de la reconstrucción
     * contable completa por mes): inserta EncaCont/DetaCont/DetaNIIF (si
     * aplica) y actualiza los saldos agregados que SIHOS mantiene en
     * paralelo (SaldCont, CodiCont.AcumDebe/AcumHabe/SaldActu, SaldTerc, SaldCent,
     * SaldNIIF, SaTeNIIF) — ver `funciones.php`: saldcont(), saldterc(),
     * saldcent(), saldNIIF(), saldtercNIIF().
     *
     * Vuelve a leer todo en fresco (dentro de esta misma transacción, en
     * esta conexión) en vez de confiar en lo que ya haya verificado el
     * servicio que llama — si algo cambió entre la verificación y la
     * escritura, se aborta con un motivo claro en vez de escribir sobre
     * datos obsoletos.
     *
     * @return array{ok:bool,motivo?:string,codiDocuNota?:string,numeDocuNota?:string,fecha?:string,valorTotal?:float,lineas?:list<array{CodiCont:string,Valor:float}>}
     * @throws PDOException en errores reales de BD (no en rechazos de negocio, esos se devuelven como ok=false)
     */
    public function crearNotaCancelacionCuentaInesperada(
        string $codiDocuFactura,
        string $numeDocuFactura,
        int $consDetaInesperada,
        string $codiDocuNota,
        string $fecha,
        string $codiAno,
        string $codiMes,
        bool $maneNIIF,
        string $usuaDigi
    ): array {
        return $this->conBloqueo(
            'cancelacion:' . $codiDocuFactura . ':' . $numeDocuFactura . ':' . $consDetaInesperada,
            function () use ($codiDocuFactura, $numeDocuFactura, $consDetaInesperada, $codiDocuNota,
                            $fecha, $codiAno, $codiMes, $maneNIIF, $usuaDigi): array {
                return $this->crearNotaCancelacionCuentaInesperadaBloqueado(
                    $codiDocuFactura, $numeDocuFactura, $consDetaInesperada, $codiDocuNota,
                    $fecha, $codiAno, $codiMes, $maneNIIF, $usuaDigi
                );
            }
        );
    }

    /** @see crearNotaCancelacionCuentaInesperada (ya con el bloqueo tomado) */
    private function crearNotaCancelacionCuentaInesperadaBloqueado(
        string $codiDocuFactura,
        string $numeDocuFactura,
        int $consDetaInesperada,
        string $codiDocuNota,
        string $fecha,
        string $codiAno,
        string $codiMes,
        bool $maneNIIF,
        string $usuaDigi
    ): array {
        // Esta acción hace decenas de idas y vueltas a la BD remota de SIHOS
        // (jerarquía de SaldCont/CodiCont/SaldNIIF por cada línea) — el
        // límite global de PHP (30s) puede alcanzarse justo después de que
        // SIHOS ya comprometió la transacción, cortando la respuesta al
        // navegador sin que el usuario se entere de que sí se escribió
        // (verificado con un caso real). Se amplía solo para esta acción.
        set_time_limit(180);

        $pdo = $this->connect();
        $codiInst = $this->codiInst();

        $pdo->beginTransaction();

        try {
            // 1) Re-verificación en fresco de la factura y la línea puntual.
            $stmt = $pdo->prepare(
                'SELECT Anulado, TiDoTerc, NuDoTerc, CodiCent FROM EncaCont
                 WHERE CodiInst = ? AND CodiDocu = ? AND NumeDocu = ?'
            );
            $stmt->execute([$codiInst, $codiDocuFactura, $numeDocuFactura]);
            $factura = $stmt->fetch();

            if ($factura === false) {
                $pdo->rollBack();

                return ['ok' => false, 'motivo' => "La factura {$codiDocuFactura}-{$numeDocuFactura} ya no existe."];
            }

            if ((int)$factura['Anulado'] === 1) {
                $pdo->rollBack();

                return ['ok' => false, 'motivo' => 'La factura está anulada, no se modifica.'];
            }

            $stmt = $pdo->prepare(
                'SELECT CodiCont, Valor FROM DetaCont
                 WHERE CodiInst = ? AND CodiDocu = ? AND NumeDocu = ? AND ConsDeta = ?'
            );
            $stmt->execute([$codiInst, $codiDocuFactura, $numeDocuFactura, $consDetaInesperada]);
            $lineaInesperada = $stmt->fetch();

            if ($lineaInesperada === false) {
                $pdo->rollBack();

                return ['ok' => false, 'motivo' => 'La línea contable a cancelar ya no existe — puede que ya se haya corregido.'];
            }

            $stmt = $pdo->prepare(
                "SELECT CodiCont, CentCost, Valor FROM DetaCont
                 WHERE CodiInst = ? AND CodiDocu = ? AND NumeDocu = ? AND CodiCont LIKE '4312%'
                 ORDER BY ConsDeta"
            );
            $stmt->execute([$codiInst, $codiDocuFactura, $numeDocuFactura]);
            $lineas4312 = $stmt->fetchAll();

            if ($lineas4312 === []) {
                $pdo->rollBack();

                return ['ok' => false, 'motivo' => 'La factura ya no tiene ninguna línea 4312 contra la cual repartir.'];
            }

            // 1b) Guardia de idempotencia, re-verificada DENTRO de esta misma
            // transacción (no solo en el pre-chequeo del servicio): si entre
            // el pre-chequeo y este punto ya se creó un ajuste para esta
            // cuenta+factura (p. ej. un reintento tras un timeout de red
            // donde la escritura anterior sí se completó en SIHOS), no se
            // duplica. Verificado con un caso real: dos clics terminaron
            // creando dos notas idénticas porque la respuesta al navegador
            // se perdió después de que la primera ya había comprometido.
            $stmt = $pdo->prepare(
                'SELECT dc.CodiDocu, dc.NumeDocu
                 FROM DetaCont dc
                 INNER JOIN EncaCont ec ON ec.CodiInst = dc.CodiInst AND ec.CodiDocu = dc.CodiDocu AND ec.NumeDocu = dc.NumeDocu
                 WHERE dc.CodiInst = ? AND dc.TiDoRefe = ? AND dc.NuDoRefe = ? AND dc.CodiCont = ?
                   AND dc.CodiDocu <> ? AND ec.Anulado = 0
                 LIMIT 1'
            );
            $stmt->execute([$codiInst, $codiDocuFactura, $numeDocuFactura, $lineaInesperada['CodiCont'], $codiDocuFactura]);
            $ajustePrevio = $stmt->fetch();

            if ($ajustePrevio !== false) {
                $pdo->rollBack();

                return [
                    'ok' => false,
                    'motivo' => "Ya existe un ajuste para la cuenta {$lineaInesperada['CodiCont']} de esta factura: "
                        . "{$ajustePrevio['CodiDocu']}-{$ajustePrevio['NumeDocu']} en SIHOS. No se crea otro.",
                ];
            }

            // 2) Reparto proporcional (ajuste de redondeo en la última línea).
            $valorInesperadoAbs = round(abs((float)$lineaInesperada['Valor']), 2);
            $sumaAbs4312 = array_sum(array_map(static fn (array $l): float => abs((float)$l['Valor']), $lineas4312));

            $repartos = [];
            $acumulado = 0.0;
            $ultimoIndice = count($lineas4312) - 1;
            foreach (array_values($lineas4312) as $indice => $linea) {
                if ($indice === $ultimoIndice) {
                    $monto = round($valorInesperadoAbs - $acumulado, 2);
                } else {
                    $proporcion = $sumaAbs4312 > 0.0 ? abs((float)$linea['Valor']) / $sumaAbs4312 : 0.0;
                    $monto = round($valorInesperadoAbs * $proporcion, 2);
                }
                $acumulado += $monto;
                $repartos[] = ['CodiCont' => $linea['CodiCont'], 'CentCost' => $linea['CentCost'], 'Valor' => $monto];
            }

            // 3) Nuevo NumeDocu (MAX+1 — mismo patrón naive que usa
            // sihos/modulos/glosas/creaenca.php; riesgo de condición de
            // carrera aceptado, acción manual y poco frecuente).
            $stmt = $pdo->prepare('SELECT MAX(CAST(NumeDocu AS UNSIGNED)) FROM EncaCont WHERE CodiInst = ? AND CodiDocu = ?');
            $stmt->execute([$codiInst, $codiDocuNota]);
            $numeDocuNota = (string)((int)$stmt->fetchColumn() + 1);

            // 4) Encabezado EncaCont.
            $concepto = "Reversion cuenta {$lineaInesperada['CodiCont']} contra ingreso 4312 - factura {$codiDocuFactura}-{$numeDocuFactura}";
            $stmt = $pdo->prepare(
                'INSERT INTO EncaCont
                    (CodiInst,CodiAno,CodiDocu,NumeDocu,TipoComp,CodiCent,FechDocu,HoraDocu,Concepto,
                     TiDoTerc,NuDoTerc,TiDoRefe,NuDoRefe,ValoTota,Causado,FechDigi,HoraDigi,UsuaDigi)
                 VALUES (?,?,?,?,0,?,?,CURTIME(),?,?,?,?,?,?,1,CURDATE(),CURTIME(),?)'
            );
            $stmt->execute([
                $codiInst, $codiAno, $codiDocuNota, $numeDocuNota, $factura['CodiCent'], $fecha, $concepto,
                $factura['TiDoTerc'], $factura['NuDoTerc'], $codiDocuFactura, $numeDocuFactura, $valorInesperadoAbs,
                $usuaDigi,
            ]);

            // 5) Líneas: débito a la cuenta inesperada (ConsDeta=1), créditos
            // repartidos a cada línea 4312 (ConsDeta=2..N+1).
            $lineasAInsertar = [
                ['CodiCont' => $lineaInesperada['CodiCont'], 'CentCost' => null, 'Valor' => $valorInesperadoAbs],
            ];
            foreach ($repartos as $reparto) {
                $lineasAInsertar[] = [
                    'CodiCont' => $reparto['CodiCont'],
                    'CentCost' => $reparto['CentCost'],
                    'Valor' => -$reparto['Valor'],
                ];
            }

            $consDeta = 1;
            $lineasSnapshot = [];

            foreach ($lineasAInsertar as $linea) {
                $configCuenta = $this->obtenerConfigCuenta($pdo, $codiInst, $linea['CodiCont'], $codiAno);

                if ($configCuenta === null) {
                    $pdo->rollBack();

                    return [
                        'ok' => false,
                        'motivo' => "La cuenta {$linea['CodiCont']} no existe en el plan de cuentas de SIHOS para el año {$codiAno}.",
                    ];
                }

                $tiDoTercLinea = (int)$configCuenta['OpciTerc'] === 1 ? $factura['TiDoTerc'] : null;
                $nuDoTercLinea = (int)$configCuenta['OpciTerc'] === 1 ? $factura['NuDoTerc'] : null;
                $centCostLinea = (int)$configCuenta['OpciCeCo'] === 1 ? $linea['CentCost'] : null;
                $tiDoRefeLinea = (int)$configCuenta['ManeDoRe'] === 1 ? $codiDocuFactura : $codiDocuNota;
                $nuDoRefeLinea = (int)$configCuenta['ManeDoRe'] === 1 ? $numeDocuFactura : $numeDocuNota;

                $this->insertarLineaDetaCont(
                    $pdo,
                    $codiDocuNota,
                    $numeDocuNota,
                    $consDeta,
                    $codiAno,
                    $linea['CodiCont'],
                    $factura['CodiCent'],
                    $centCostLinea,
                    $tiDoTercLinea,
                    $nuDoTercLinea,
                    $tiDoRefeLinea,
                    $nuDoRefeLinea,
                    $linea['Valor'],
                    $usuaDigi
                );

                $this->actualizarSaldoCuenta(
                    $pdo,
                    $linea['CodiCont'],
                    $linea['Valor'],
                    $codiMes,
                    $codiAno,
                    $tiDoTercLinea,
                    $nuDoTercLinea,
                    $centCostLinea,
                    $configCuenta,
                    $usuaDigi
                );

                if ($maneNIIF) {
                    $stmt = $pdo->prepare('SELECT idPartNIIF FROM HomoNIIF WHERE CodiCont = ?');
                    $stmt->execute([$linea['CodiCont']]);
                    $idPartNIIF = $stmt->fetchColumn();

                    if ($idPartNIIF === false || $idPartNIIF === '') {
                        $pdo->rollBack();

                        return [
                            'ok' => false,
                            'motivo' => "Falta homologación NIIF (tabla HomoNIIF) para la cuenta {$linea['CodiCont']} — configúrela en SIHOS antes de continuar.",
                        ];
                    }

                    $this->insertarLineaDetaNIIF(
                        $pdo,
                        $codiDocuNota,
                        $numeDocuNota,
                        $consDeta,
                        $codiAno,
                        (string)$idPartNIIF,
                        $factura['CodiCent'],
                        $centCostLinea,
                        $tiDoTercLinea,
                        $nuDoTercLinea,
                        $tiDoRefeLinea,
                        $nuDoRefeLinea,
                        $linea['Valor'],
                        $usuaDigi
                    );

                    $this->actualizarSaldoNIIF(
                        $pdo,
                        (string)$idPartNIIF,
                        $linea['Valor'],
                        $codiMes,
                        $codiAno,
                        $tiDoTercLinea,
                        $nuDoTercLinea,
                        $usuaDigi
                    );
                }

                $lineasSnapshot[] = ['CodiCont' => $linea['CodiCont'], 'Valor' => $linea['Valor']];
                $consDeta++;
            }

            $pdo->commit();

            return [
                'ok' => true,
                'codiDocuNota' => $codiDocuNota,
                'numeDocuNota' => $numeDocuNota,
                'fecha' => $fecha,
                'valorTotal' => $valorInesperadoAbs,
                'lineas' => $lineasSnapshot,
            ];
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Rama de mes ABIERTO: reclasifica en sitio cada línea 4312 de vigencia
     * anterior de una nota (sección 5b) — cambia `DetaCont.CodiCont` (y
     * `DetaNIIF.idPartNIIF` si aplica) dejando `Valor`/`CentCost`/tercero
     * intactos, exactamente como ya lo hace el usuario a mano en SIHOS.
     * Revierte la cascada de saldos de la cuenta vieja y aplica la de la
     * cuenta nueva con los mismos helpers ya validados para la sección 5a
     * (`actualizarSaldoCuenta`/`actualizarSaldoNIIF`).
     *
     * Es auto-idempotente: tras el cambio, la línea deja de calzar con
     * `CodiCont LIKE '4312%'`, así que una segunda llamada sobre el mismo
     * documento no encuentra nada que corregir y se rechaza sola.
     *
     * @return array{ok:bool,motivo?:string,lineasAntes?:list<array{ConsDeta:int,CodiCont:string,Valor:float}>,lineasDespues?:list<array{ConsDeta:int,CodiCont:string,Valor:float}>}
     */
    public function reclasificarCuentaEnSitio(
        string $codiDocuNota,
        string $numeDocuNota,
        string $cuentaDestino,
        string $codiAno,
        string $codiMes,
        bool $maneNIIF,
        string $usuaDigi
    ): array {
        return $this->conBloqueo(
            'reclasifica:' . $codiDocuNota . ':' . $numeDocuNota,
            function () use ($codiDocuNota, $numeDocuNota, $cuentaDestino, $codiAno, $codiMes, $maneNIIF, $usuaDigi): array {
                return $this->reclasificarCuentaEnSitioBloqueado(
                    $codiDocuNota, $numeDocuNota, $cuentaDestino, $codiAno, $codiMes, $maneNIIF, $usuaDigi
                );
            }
        );
    }

    /** @see reclasificarCuentaEnSitio (ya con el bloqueo tomado) */
    private function reclasificarCuentaEnSitioBloqueado(
        string $codiDocuNota,
        string $numeDocuNota,
        string $cuentaDestino,
        string $codiAno,
        string $codiMes,
        bool $maneNIIF,
        string $usuaDigi
    ): array {
        set_time_limit(180);

        $pdo = $this->connect();
        $codiInst = $this->codiInst();

        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                "SELECT dc.ConsDeta, dc.CodiCont, dc.CentCost, dc.TiDoTerc, dc.NuDoTerc, dc.Valor
                 FROM DetaCont dc
                 INNER JOIN EncaCont fact ON fact.CodiInst = dc.CodiInst AND fact.CodiDocu = dc.TiDoRefe AND fact.NumeDocu = dc.NuDoRefe
                 INNER JOIN EncaCont nc ON nc.CodiInst = dc.CodiInst AND nc.CodiDocu = dc.CodiDocu AND nc.NumeDocu = dc.NumeDocu
                 WHERE dc.CodiInst = ? AND dc.CodiDocu = ? AND dc.NumeDocu = ?
                   AND dc.CodiCont LIKE '4312%' AND dc.TiDoRefe IS NOT NULL AND dc.TiDoRefe <> ''
                   AND YEAR(fact.FechDocu) < YEAR(nc.FechDocu)
                 ORDER BY dc.ConsDeta"
            );
            $stmt->execute([$codiInst, $codiDocuNota, $numeDocuNota]);
            $lineas = $stmt->fetchAll();

            if ($lineas === []) {
                $pdo->rollBack();

                return ['ok' => false, 'motivo' => 'La nota ya no tiene líneas 4312 de vigencia anterior por corregir — puede que ya se haya corregido.'];
            }

            $lineasAntes = [];
            $lineasDespues = [];

            foreach ($lineas as $linea) {
                $consDeta = (int)$linea['ConsDeta'];
                $cuentaVieja = $linea['CodiCont'];
                $valor = (float)$linea['Valor'];
                $centCost = $linea['CentCost'] !== '' ? $linea['CentCost'] : null;
                $tiDoTerc = $linea['TiDoTerc'] !== '' ? $linea['TiDoTerc'] : null;
                $nuDoTerc = $linea['NuDoTerc'] !== '' ? $linea['NuDoTerc'] : null;

                $configVieja = $this->obtenerConfigCuenta($pdo, $codiInst, $cuentaVieja, $codiAno);
                $configNueva = $this->obtenerConfigCuenta($pdo, $codiInst, $cuentaDestino, $codiAno);

                if ($configVieja === null || $configNueva === null) {
                    $pdo->rollBack();

                    return [
                        'ok' => false,
                        'motivo' => "La cuenta {$cuentaVieja} o {$cuentaDestino} no existe en el plan de cuentas de SIHOS para el año {$codiAno}.",
                    ];
                }

                $lineasAntes[] = ['ConsDeta' => $consDeta, 'CodiCont' => $cuentaVieja, 'Valor' => $valor];

                $stmt = $pdo->prepare(
                    'UPDATE DetaCont SET CodiCont = ? WHERE CodiInst = ? AND CodiDocu = ? AND NumeDocu = ? AND ConsDeta = ?'
                );
                $stmt->execute([$cuentaDestino, $codiInst, $codiDocuNota, $numeDocuNota, $consDeta]);

                $this->actualizarSaldoCuenta($pdo, $cuentaVieja, -$valor, $codiMes, $codiAno, $tiDoTerc, $nuDoTerc, $centCost, $configVieja, $usuaDigi);
                $this->actualizarSaldoCuenta($pdo, $cuentaDestino, $valor, $codiMes, $codiAno, $tiDoTerc, $nuDoTerc, $centCost, $configNueva, $usuaDigi);

                if ($maneNIIF) {
                    $stmt = $pdo->prepare(
                        'SELECT idPartNIIF FROM DetaNIIF WHERE CodiInst = ? AND CodiDocu = ? AND NumeDocu = ? AND ConsDeta = ?'
                    );
                    $stmt->execute([$codiInst, $codiDocuNota, $numeDocuNota, $consDeta]);
                    $idPartViejo = $stmt->fetchColumn();

                    if ($idPartViejo !== false && $idPartViejo !== '') {
                        $stmt = $pdo->prepare('SELECT idPartNIIF FROM HomoNIIF WHERE CodiCont = ?');
                        $stmt->execute([$cuentaDestino]);
                        $idPartNuevo = $stmt->fetchColumn();

                        if ($idPartNuevo === false || $idPartNuevo === '') {
                            $pdo->rollBack();

                            return ['ok' => false, 'motivo' => "Falta homologación NIIF (HomoNIIF) para la cuenta {$cuentaDestino}."];
                        }

                        $stmt = $pdo->prepare(
                            'UPDATE DetaNIIF SET idPartNIIF = ? WHERE CodiInst = ? AND CodiDocu = ? AND NumeDocu = ? AND ConsDeta = ?'
                        );
                        $stmt->execute([$idPartNuevo, $codiInst, $codiDocuNota, $numeDocuNota, $consDeta]);

                        $this->actualizarSaldoNIIF($pdo, (string)$idPartViejo, -$valor, $codiMes, $codiAno, $tiDoTerc, $nuDoTerc, $usuaDigi);
                        $this->actualizarSaldoNIIF($pdo, (string)$idPartNuevo, $valor, $codiMes, $codiAno, $tiDoTerc, $nuDoTerc, $usuaDigi);
                    }
                }

                $lineasDespues[] = ['ConsDeta' => $consDeta, 'CodiCont' => $cuentaDestino, 'Valor' => $valor];
            }

            $pdo->commit();

            return ['ok' => true, 'lineasAntes' => $lineasAntes, 'lineasDespues' => $lineasDespues];
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Rama de mes CERRADO: crea una Nota Contabilidad (NC) que corrige la
     * nota original SIN reescribir su período ya cerrado — por cada línea
     * 4312 de vigencia anterior, una línea que la cancela 1:1 (mismo
     * `CentCost`, signo contrario) más una línea que aplica el mismo valor
     * a `$cuentaDestino` (mismo `CentCost`, mismo signo que la original).
     * A diferencia de la sección 5a NO hay reparto proporcional: cada línea
     * original se corrige exactamente por su propio valor. `TiDoRefe/
     * NuDoRefe` de las líneas nuevas apunta a la NOTA que se corrige, no a
     * la factura — es lo que realmente se está ajustando.
     *
     * @return array{ok:bool,motivo?:string,codiDocuNota?:string,numeDocuNota?:string,fecha?:string,valorTotal?:float,lineas?:list<array{CodiCont:string,Valor:float}>}
     */
    public function crearNotaAjusteVigenciaAnterior(
        string $codiDocuNotaOrigen,
        string $numeDocuNotaOrigen,
        string $cuentaDestino,
        string $codiDocuNota,
        string $fecha,
        string $codiAno,
        string $codiMes,
        bool $maneNIIF,
        string $usuaDigi
    ): array {
        return $this->conBloqueo(
            'ajusteanterior:' . $codiDocuNotaOrigen . ':' . $numeDocuNotaOrigen,
            function () use ($codiDocuNotaOrigen, $numeDocuNotaOrigen, $cuentaDestino, $codiDocuNota,
                            $fecha, $codiAno, $codiMes, $maneNIIF, $usuaDigi): array {
                return $this->crearNotaAjusteVigenciaAnteriorBloqueado(
                    $codiDocuNotaOrigen, $numeDocuNotaOrigen, $cuentaDestino, $codiDocuNota,
                    $fecha, $codiAno, $codiMes, $maneNIIF, $usuaDigi
                );
            }
        );
    }

    /** @see crearNotaAjusteVigenciaAnterior (ya con el bloqueo tomado) */
    private function crearNotaAjusteVigenciaAnteriorBloqueado(
        string $codiDocuNotaOrigen,
        string $numeDocuNotaOrigen,
        string $cuentaDestino,
        string $codiDocuNota,
        string $fecha,
        string $codiAno,
        string $codiMes,
        bool $maneNIIF,
        string $usuaDigi
    ): array {
        set_time_limit(180);

        $pdo = $this->connect();
        $codiInst = $this->codiInst();

        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'SELECT Anulado, TiDoTerc, NuDoTerc, CodiCent FROM EncaCont
                 WHERE CodiInst = ? AND CodiDocu = ? AND NumeDocu = ?'
            );
            $stmt->execute([$codiInst, $codiDocuNotaOrigen, $numeDocuNotaOrigen]);
            $notaOrigen = $stmt->fetch();

            if ($notaOrigen === false) {
                $pdo->rollBack();

                return ['ok' => false, 'motivo' => "La nota {$codiDocuNotaOrigen}-{$numeDocuNotaOrigen} ya no existe."];
            }

            if ((int)$notaOrigen['Anulado'] === 1) {
                $pdo->rollBack();

                return ['ok' => false, 'motivo' => 'La nota está anulada, no se corrige.'];
            }

            $stmt = $pdo->prepare(
                "SELECT dc.ConsDeta, dc.CodiCont, dc.CentCost, dc.Valor
                 FROM DetaCont dc
                 INNER JOIN EncaCont fact ON fact.CodiInst = dc.CodiInst AND fact.CodiDocu = dc.TiDoRefe AND fact.NumeDocu = dc.NuDoRefe
                 INNER JOIN EncaCont nc ON nc.CodiInst = dc.CodiInst AND nc.CodiDocu = dc.CodiDocu AND nc.NumeDocu = dc.NumeDocu
                 WHERE dc.CodiInst = ? AND dc.CodiDocu = ? AND dc.NumeDocu = ?
                   AND dc.CodiCont LIKE '4312%' AND dc.TiDoRefe IS NOT NULL AND dc.TiDoRefe <> ''
                   AND YEAR(fact.FechDocu) < YEAR(nc.FechDocu)
                 ORDER BY dc.ConsDeta"
            );
            $stmt->execute([$codiInst, $codiDocuNotaOrigen, $numeDocuNotaOrigen]);
            $lineas4312 = $stmt->fetchAll();

            if ($lineas4312 === []) {
                $pdo->rollBack();

                return ['ok' => false, 'motivo' => 'La nota ya no tiene líneas 4312 de vigencia anterior por corregir — puede que ya se haya corregido.'];
            }

            // Idempotencia dentro de la transacción — igual patrón que
            // fetchAjustePrevioNota(), pero contra la conexión de escritura.
            $stmt = $pdo->prepare(
                'SELECT dc.CodiDocu, dc.NumeDocu
                 FROM DetaCont dc
                 INNER JOIN EncaCont ec ON ec.CodiInst = dc.CodiInst AND ec.CodiDocu = dc.CodiDocu AND ec.NumeDocu = dc.NumeDocu
                 WHERE dc.CodiInst = ? AND dc.TiDoRefe = ? AND dc.NuDoRefe = ? AND dc.CodiCont = ?
                   AND dc.CodiDocu <> ? AND ec.Anulado = 0
                 LIMIT 1'
            );
            $stmt->execute([$codiInst, $codiDocuNotaOrigen, $numeDocuNotaOrigen, $cuentaDestino, $codiDocuNotaOrigen]);
            $ajustePrevio = $stmt->fetch();

            if ($ajustePrevio !== false) {
                $pdo->rollBack();

                return [
                    'ok' => false,
                    'motivo' => "Ya existe un ajuste para esta nota: {$ajustePrevio['CodiDocu']}-{$ajustePrevio['NumeDocu']} en SIHOS. No se crea otro.",
                ];
            }

            $stmt = $pdo->prepare('SELECT MAX(CAST(NumeDocu AS UNSIGNED)) FROM EncaCont WHERE CodiInst = ? AND CodiDocu = ?');
            $stmt->execute([$codiInst, $codiDocuNota]);
            $numeDocuNota = (string)((int)$stmt->fetchColumn() + 1);

            $valorTotal = round(array_sum(array_map(static fn (array $l): float => abs((float)$l['Valor']), $lineas4312)), 2);
            $concepto = "Reclasificacion vigencia anterior contra cuenta {$cuentaDestino} - nota {$codiDocuNotaOrigen}-{$numeDocuNotaOrigen}";

            $stmt = $pdo->prepare(
                'INSERT INTO EncaCont
                    (CodiInst,CodiAno,CodiDocu,NumeDocu,TipoComp,CodiCent,FechDocu,HoraDocu,Concepto,
                     TiDoTerc,NuDoTerc,TiDoRefe,NuDoRefe,ValoTota,Causado,FechDigi,HoraDigi,UsuaDigi)
                 VALUES (?,?,?,?,0,?,?,CURTIME(),?,?,?,?,?,?,1,CURDATE(),CURTIME(),?)'
            );
            $stmt->execute([
                $codiInst, $codiAno, $codiDocuNota, $numeDocuNota, $notaOrigen['CodiCent'], $fecha, $concepto,
                $notaOrigen['TiDoTerc'], $notaOrigen['NuDoTerc'], $codiDocuNotaOrigen, $numeDocuNotaOrigen, $valorTotal,
                $usuaDigi,
            ]);

            $consDeta = 1;
            $lineasSnapshot = [];
            $configDestino = null;

            foreach ($lineas4312 as $linea) {
                $cuentaOrigen = $linea['CodiCont'];
                $valor = (float)$linea['Valor'];
                $centCost = $linea['CentCost'] !== '' ? $linea['CentCost'] : null;

                $configOrigen = $this->obtenerConfigCuenta($pdo, $codiInst, $cuentaOrigen, $codiAno);
                if ($configOrigen === null) {
                    $pdo->rollBack();

                    return ['ok' => false, 'motivo' => "La cuenta {$cuentaOrigen} no existe en el plan de cuentas de SIHOS para el año {$codiAno}."];
                }

                if ($configDestino === null) {
                    $configDestino = $this->obtenerConfigCuenta($pdo, $codiInst, $cuentaDestino, $codiAno);
                    if ($configDestino === null) {
                        $pdo->rollBack();

                        return ['ok' => false, 'motivo' => "La cuenta {$cuentaDestino} no existe en el plan de cuentas de SIHOS para el año {$codiAno}."];
                    }
                }

                $tiDoTercOrigen = (int)$configOrigen['OpciTerc'] === 1 ? $notaOrigen['TiDoTerc'] : null;
                $nuDoTercOrigen = (int)$configOrigen['OpciTerc'] === 1 ? $notaOrigen['NuDoTerc'] : null;
                $centCostOrigen = (int)$configOrigen['OpciCeCo'] === 1 ? $centCost : null;

                // Línea 1 de cada par: cancela la línea original 1:1 (mismo
                // CentCost, signo contrario).
                $valorCancelacion = -$valor;
                $this->insertarLineaDetaCont(
                    $pdo, $codiDocuNota, $numeDocuNota, $consDeta, $codiAno, $cuentaOrigen, $notaOrigen['CodiCent'],
                    $centCostOrigen, $tiDoTercOrigen, $nuDoTercOrigen, $codiDocuNotaOrigen, $numeDocuNotaOrigen, $valorCancelacion, $usuaDigi
                );
                $this->actualizarSaldoCuenta($pdo, $cuentaOrigen, $valorCancelacion, $codiMes, $codiAno, $tiDoTercOrigen, $nuDoTercOrigen, $centCostOrigen, $configOrigen, $usuaDigi);

                if ($maneNIIF) {
                    $stmt = $pdo->prepare('SELECT idPartNIIF FROM HomoNIIF WHERE CodiCont = ?');
                    $stmt->execute([$cuentaOrigen]);
                    $idPartOrigen = $stmt->fetchColumn();

                    if ($idPartOrigen === false || $idPartOrigen === '') {
                        $pdo->rollBack();

                        return ['ok' => false, 'motivo' => "Falta homologación NIIF (HomoNIIF) para la cuenta {$cuentaOrigen}."];
                    }

                    $this->insertarLineaDetaNIIF(
                        $pdo, $codiDocuNota, $numeDocuNota, $consDeta, $codiAno, (string)$idPartOrigen, $notaOrigen['CodiCent'],
                        $centCostOrigen, $tiDoTercOrigen, $nuDoTercOrigen, $codiDocuNotaOrigen, $numeDocuNotaOrigen, $valorCancelacion, $usuaDigi
                    );
                    $this->actualizarSaldoNIIF($pdo, (string)$idPartOrigen, $valorCancelacion, $codiMes, $codiAno, $tiDoTercOrigen, $nuDoTercOrigen, $usuaDigi);
                }

                $lineasSnapshot[] = ['CodiCont' => $cuentaOrigen, 'Valor' => $valorCancelacion];
                $consDeta++;

                // Línea 2 de cada par: aplica el mismo valor (mismo signo
                // que la original) a la cuenta destino, mismo CentCost.
                $tiDoTercDestino = (int)$configDestino['OpciTerc'] === 1 ? $notaOrigen['TiDoTerc'] : null;
                $nuDoTercDestino = (int)$configDestino['OpciTerc'] === 1 ? $notaOrigen['NuDoTerc'] : null;
                $centCostDestino = (int)$configDestino['OpciCeCo'] === 1 ? $centCost : null;

                $this->insertarLineaDetaCont(
                    $pdo, $codiDocuNota, $numeDocuNota, $consDeta, $codiAno, $cuentaDestino, $notaOrigen['CodiCent'],
                    $centCostDestino, $tiDoTercDestino, $nuDoTercDestino, $codiDocuNotaOrigen, $numeDocuNotaOrigen, $valor, $usuaDigi
                );
                $this->actualizarSaldoCuenta($pdo, $cuentaDestino, $valor, $codiMes, $codiAno, $tiDoTercDestino, $nuDoTercDestino, $centCostDestino, $configDestino, $usuaDigi);

                if ($maneNIIF) {
                    $stmt = $pdo->prepare('SELECT idPartNIIF FROM HomoNIIF WHERE CodiCont = ?');
                    $stmt->execute([$cuentaDestino]);
                    $idPartDestino = $stmt->fetchColumn();

                    if ($idPartDestino === false || $idPartDestino === '') {
                        $pdo->rollBack();

                        return ['ok' => false, 'motivo' => "Falta homologación NIIF (HomoNIIF) para la cuenta {$cuentaDestino}."];
                    }

                    $this->insertarLineaDetaNIIF(
                        $pdo, $codiDocuNota, $numeDocuNota, $consDeta, $codiAno, (string)$idPartDestino, $notaOrigen['CodiCent'],
                        $centCostDestino, $tiDoTercDestino, $nuDoTercDestino, $codiDocuNotaOrigen, $numeDocuNotaOrigen, $valor, $usuaDigi
                    );
                    $this->actualizarSaldoNIIF($pdo, (string)$idPartDestino, $valor, $codiMes, $codiAno, $tiDoTercDestino, $nuDoTercDestino, $usuaDigi);
                }

                $lineasSnapshot[] = ['CodiCont' => $cuentaDestino, 'Valor' => $valor];
                $consDeta++;
            }

            $pdo->commit();

            return [
                'ok' => true,
                'codiDocuNota' => $codiDocuNota,
                'numeDocuNota' => $numeDocuNota,
                'fecha' => $fecha,
                'valorTotal' => $valorTotal,
                'lineas' => $lineasSnapshot,
            ];
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Config de una cuenta contable (jerarquía de cuenta padre + exige
     * tercero/centro de costo/documento de referencia), tal como la usa
     * `funciones.php` (CodiCont, clave CodiInst+Periodo+CodiCont).
     */
    private function obtenerConfigCuenta(PDO $pdo, string $codiInst, string $codiCont, string $anno): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT CodiClas,CodiGrup,CodiCuen,CodiSubc,CodiAux1,CodiAux2,CodiAux3,CodiAux4,OpciTerc,OpciCeCo,ManeDoRe
             FROM CodiCont WHERE CodiInst = ? AND Periodo = ? AND CodiCont = ?'
        );
        $stmt->execute([$codiInst, $anno, $codiCont]);
        $fila = $stmt->fetch();

        return $fila === false ? null : $fila;
    }

    /**
     * Niveles no vacíos de la jerarquía de cuenta padre (clase/grupo/cuenta/
     * subcuenta/auxiliares 1-4) — cada uno se actualiza en SaldCont, igual
     * que hace saldcont() en SIHOS.
     *
     * @return string[]
     */
    private function nivelesJerarquiaCuenta(array $configCuenta): array
    {
        $niveles = [];
        foreach (['CodiClas', 'CodiGrup', 'CodiCuen', 'CodiSubc', 'CodiAux1', 'CodiAux2', 'CodiAux3', 'CodiAux4'] as $campo) {
            $valor = trim((string)($configCuenta[$campo] ?? ''));
            if ($valor !== '') {
                $niveles[] = $valor;
            }
        }

        return $niveles;
    }

    private function mesAnterior(string $mes, string $anno): array
    {
        $mesAnte = (int)$mes - 1;
        $annoAnte = (int)$anno;
        if ($mesAnte === 0) {
            $mesAnte = 12;
            $annoAnte--;
        }

        return [(string)$mesAnte, (string)$annoAnte];
    }

    /**
     * Replica saldcont()+saldterc()+saldcent() de funciones.php: actualiza
     * SaldCont para cada nivel de la jerarquía de la cuenta, el acumulado
     * histórico de la propia CodiCont, y (si aplica) SaldTerc/SaldCent.
     */
    private function actualizarSaldoCuenta(
        PDO $pdo,
        string $cuenta,
        float $valor,
        string $mes,
        string $anno,
        ?string $tiDoTerc,
        ?string $nuDoTerc,
        ?string $centCost,
        array $configCuenta,
        string $usuaDigi
    ): void {
        if ($tiDoTerc !== null && $nuDoTerc !== null && (int)$configCuenta['OpciTerc'] === 1) {
            $this->actualizarSaldoTercero($pdo, $tiDoTerc, $nuDoTerc, $valor, $mes, $anno, $cuenta, $usuaDigi);
        }

        if ($centCost !== null && $centCost !== '' && (int)$configCuenta['OpciCeCo'] === 1) {
            $this->actualizarSaldoCentroCosto($pdo, $centCost, $valor, $mes, $anno, $usuaDigi);
        }

        foreach ($this->nivelesJerarquiaCuenta($configCuenta) as $nivel) {
            $this->upsertSaldCont($pdo, $nivel, $valor, $mes, $anno, $usuaDigi);
            $this->actualizarCodiContAcumulado($pdo, $nivel, $valor, $anno, $usuaDigi);
        }
    }

    /**
     * Upsert en UNA sola consulta (INSERT ... ON DUPLICATE KEY UPDATE) en
     * vez del patrón SELECT-luego-INSERT/UPDATE (2 consultas): multiplicado
     * por cada nivel de jerarquía de cada línea, el patrón de 2 consultas
     * hacía que la escritura completa superara los timeouts del servidor —
     * verificado con casos reales: SIHOS sí completaba la escritura pero la
     * respuesta nunca llegaba al navegador. La aritmética (referenciar
     * AcumDebe/AcumHabe YA actualizados en la misma sentencia para calcular
     * SaldDebe/SaldCred) se validó contra el SIHOS real en una transacción
     * de prueba con ROLLBACK antes de adoptarla — MySQL evalúa el SET de
     * ON DUPLICATE KEY UPDATE de izquierda a derecha, así que una expresión
     * puede usar el valor recién asignado por una expresión anterior de la
     * misma sentencia.
     */
    private function upsertSaldCont(PDO $pdo, string $cuenta, float $valor, string $mes, string $anno, string $usuaDigi): void
    {
        $acumDebe = $valor > 0 ? $valor : 0.0;
        $acumHabe = $valor < 0 ? abs($valor) : 0.0;

        $stmt = $pdo->prepare(
            'INSERT INTO SaldCont
                (CodiInst,CodiAno,CodiMes,CodiCont,SaldDeAn,SaldCrAn,AcumDebe,AcumHabe,SaldDebe,SaldCred,FechDigi,HoraDigi,UsuaDigi,FechModi,HoraModi,UsuaModi)
             VALUES (?,?,?,?,0,0,?,?,?,?,CURDATE(),CURTIME(),?,CURDATE(),CURTIME(),?)
             ON DUPLICATE KEY UPDATE
                 AcumDebe = AcumDebe + VALUES(AcumDebe),
                 AcumHabe = AcumHabe + VALUES(AcumHabe),
                 SaldDebe = SaldDeAn + AcumDebe,
                 SaldCred = SaldCrAn + AcumHabe,
                 FechModi = CURDATE(), HoraModi = CURTIME(), UsuaModi = VALUES(UsuaModi)'
        );
        $stmt->execute([$this->codiInst(), $anno, $mes, $cuenta, $acumDebe, $acumHabe, $acumDebe, $acumHabe, $usuaDigi, $usuaDigi]);
    }

    /**
     * Misma optimización que upsertSaldCont(): un solo UPDATE con
     * autoreferencia (validada) en vez de leer y luego escribir.
     */
    private function actualizarCodiContAcumulado(PDO $pdo, string $cuenta, float $valor, string $anno, string $usuaDigi): void
    {
        $deltaDebe = $valor > 0 ? $valor : 0.0;
        $deltaHabe = $valor < 0 ? abs($valor) : 0.0;

        $stmt = $pdo->prepare(
            'UPDATE CodiCont
             SET AcumDebe = AcumDebe + ?,
                 AcumHabe = AcumHabe + ?,
                 SaldActu = SaldAnte + AcumDebe - AcumHabe,
                 FechDigi = CURDATE(), HoraDigi = CURTIME(), UsuaDigi = ?
             WHERE CodiInst = ? AND Periodo = ? AND CodiCont = ?'
        );
        $stmt->execute([$deltaDebe, $deltaHabe, $usuaDigi, $this->codiInst(), $anno, $cuenta]);
    }

    private function actualizarSaldoTercero(
        PDO $pdo,
        string $tiDoTerc,
        string $nuDoTerc,
        float $valor,
        string $mes,
        string $anno,
        string $cuenta,
        string $usuaDigi
    ): void {
        $acumDebe = $valor > 0 ? $valor : 0.0;
        $acumHabe = $valor < 0 ? abs($valor) : 0.0;

        $stmt = $pdo->prepare(
            'INSERT INTO SaldTerc
                (CodiInst,CodiAno,CodiMes,TipoDocu,NumeTerc,CodiCont,SaldDeAn,SaldCrAn,AcumDebe,AcumHabe,SaldDebe,SaldCred,FechDigi,HoraDigi,UsuaDigi)
             VALUES (?,?,?,?,?,?,0,0,?,?,?,?,CURDATE(),CURTIME(),?)
             ON DUPLICATE KEY UPDATE
                 AcumDebe = AcumDebe + VALUES(AcumDebe),
                 AcumHabe = AcumHabe + VALUES(AcumHabe),
                 SaldDebe = SaldDeAn + AcumDebe,
                 SaldCred = SaldCrAn + AcumHabe,
                 FechDigi = CURDATE(), HoraDigi = CURTIME(), UsuaDigi = VALUES(UsuaDigi)'
        );
        $stmt->execute([
            $this->codiInst(), $anno, $mes, $tiDoTerc, $nuDoTerc, $cuenta, $acumDebe, $acumHabe, $acumDebe, $acumHabe, $usuaDigi,
        ]);
    }

    /**
     * SaldCent es la única de estas tablas cuyo saldo de arranque
     * (SaldDeAn/SaldCrAn) no se lee de su propia fila del mes, sino de
     * SaldDebe/SaldCred del MES ANTERIOR (así lo hace saldcent() en SIHOS) —
     * esa lectura no se puede eliminar (es una fila distinta a la que se
     * escribe), pero el resto sigue siendo un solo upsert.
     */
    private function actualizarSaldoCentroCosto(PDO $pdo, string $centCost, float $valor, string $mes, string $anno, string $usuaDigi): void
    {
        $codiInst = $this->codiInst();
        [$mesAnte, $annoAnte] = $this->mesAnterior($mes, $anno);

        $stmt = $pdo->prepare('SELECT SaldDebe, SaldCred FROM SaldCent WHERE CodiInst = ? AND CodiAno = ? AND CodiMes = ? AND CentCost = ?');
        $stmt->execute([$codiInst, $annoAnte, $mesAnte, $centCost]);
        $filaAnterior = $stmt->fetch();

        $saldDeAn = (float)($filaAnterior['SaldDebe'] ?? 0);
        $saldCrAn = (float)($filaAnterior['SaldCred'] ?? 0);
        $acumDebe = $valor > 0 ? $valor : 0.0;
        $acumHabe = $valor < 0 ? abs($valor) : 0.0;
        $saldDebe = round($saldDeAn + $acumDebe, 2);
        $saldCred = round($saldCrAn + $acumHabe, 2);

        $stmt = $pdo->prepare(
            'INSERT INTO SaldCent
                (CodiInst,CodiAno,CodiMes,CentCost,SaldDeAn,SaldCrAn,AcumDebe,AcumHabe,SaldDebe,SaldCred,FechDigi,HoraDigi,UsuaDigi)
             VALUES (?,?,?,?,0,0,?,?,?,?,CURDATE(),CURTIME(),?)
             ON DUPLICATE KEY UPDATE
                 AcumDebe = AcumDebe + VALUES(AcumDebe),
                 AcumHabe = AcumHabe + VALUES(AcumHabe),
                 SaldDebe = ? + AcumDebe,
                 SaldCred = ? + AcumHabe,
                 FechDigi = CURDATE(), HoraDigi = CURTIME(), UsuaDigi = VALUES(UsuaDigi)'
        );
        $stmt->execute([
            $codiInst, $anno, $mes, $centCost, $acumDebe, $acumHabe, $saldDebe, $saldCred, $usuaDigi, $saldDeAn, $saldCrAn,
        ]);
    }

    /**
     * Replica saldNIIF()+saldtercNIIF(): camina la jerarquía de PartNIIF
     * (por Nivel/idPartNIIF) actualizando SaldNIIF en cada nivel, y
     * SaTeNIIF si la partida hoja exige tercero.
     */
    private function actualizarSaldoNIIF(
        PDO $pdo,
        string $idPartNIIFHoja,
        float $valor,
        string $mes,
        string $anno,
        ?string $tiDoTerc,
        ?string $nuDoTerc,
        string $usuaDigi
    ): void {
        $mapaPartNIIF = $this->partNIIFMap($pdo);

        if ($tiDoTerc !== null && $nuDoTerc !== null) {
            $this->actualizarSaldoTerceroNIIF($pdo, $tiDoTerc, $nuDoTerc, $valor, $mes, $anno, $idPartNIIFHoja, $mapaPartNIIF, $usuaDigi);
        }

        foreach ($this->jerarquiaPartidaNIIF($idPartNIIFHoja, $mapaPartNIIF) as $idPartNIIF) {
            $this->upsertSaldNIIF($pdo, $idPartNIIF, $valor, $mes, $anno, $usuaDigi);
        }
    }

    /**
     * Catálogo completo de PartNIIF (id, idPartNIIF padre, Nivel, OpciTerc)
     * cargado UNA sola vez por escritura y reutilizado en memoria para
     * caminar la jerarquía de cada línea — evita 1 consulta por nivel por
     * línea (era el mayor contribuyente al número de idas y vueltas de esta
     * escritura). El catálogo es pequeño (~5.000 filas, verificado), cabe
     * cómodo en memoria.
     *
     * @return array<string, array{idPartNIIF:string,Nivel:int,OpciTerc:int}>
     */
    private function partNIIFMap(PDO $pdo): array
    {
        if ($this->partNIIFMap === null) {
            $this->partNIIFMap = [];
            $stmt = $pdo->query('SELECT id, idPartNIIF, Nivel, OpciTerc FROM PartNIIF');
            foreach ($stmt->fetchAll() as $fila) {
                $this->partNIIFMap[(string)$fila['id']] = [
                    'idPartNIIF' => (string)$fila['idPartNIIF'],
                    'Nivel' => (int)$fila['Nivel'],
                    'OpciTerc' => (int)$fila['OpciTerc'],
                ];
            }
        }

        return $this->partNIIFMap;
    }

    /**
     * @param array<string, array{idPartNIIF:string,Nivel:int,OpciTerc:int}> $mapaPartNIIF
     * @return string[] desde la partida hoja hasta su(s) padre(s), tantos
     *                   niveles como indique PartNIIF.Nivel de la hoja
     */
    private function jerarquiaPartidaNIIF(string $idPartNIIFHoja, array $mapaPartNIIF): array
    {
        $nivel = $mapaPartNIIF[$idPartNIIFHoja]['Nivel'] ?? 0;

        if ($nivel <= 0) {
            return [$idPartNIIFHoja];
        }

        $niveles = [];
        $idActual = $idPartNIIFHoja;
        for ($i = $nivel; $i >= 1; $i--) {
            $niveles[] = $idActual;
            $idActual = $mapaPartNIIF[$idActual]['idPartNIIF'] ?? '';

            if ($idActual === '') {
                break;
            }
        }

        return $niveles;
    }

    private function upsertSaldNIIF(PDO $pdo, string $idPartNIIF, float $valor, string $mes, string $anno, string $usuaDigi): void
    {
        $acumDebe = $valor > 0 ? $valor : 0.0;
        $acumHabe = $valor < 0 ? abs($valor) : 0.0;

        $stmt = $pdo->prepare(
            'INSERT INTO SaldNIIF
                (CodiInst,CodiAno,CodiMes,idPartNIIF,SaldDeAn,SaldCrAn,AcumDebe,AcumHabe,SaldDebe,SaldCred,FechDigi,HoraDigi,UsuaDigi,FechModi,HoraModi,UsuaModi)
             VALUES (?,?,?,?,0,0,?,?,?,?,CURDATE(),CURTIME(),?,CURDATE(),CURTIME(),?)
             ON DUPLICATE KEY UPDATE
                 AcumDebe = AcumDebe + VALUES(AcumDebe),
                 AcumHabe = AcumHabe + VALUES(AcumHabe),
                 SaldDebe = SaldDeAn + AcumDebe,
                 SaldCred = SaldCrAn + AcumHabe,
                 FechModi = CURDATE(), HoraModi = CURTIME(), UsuaModi = VALUES(UsuaModi)'
        );
        $stmt->execute([$this->codiInst(), $anno, $mes, $idPartNIIF, $acumDebe, $acumHabe, $acumDebe, $acumHabe, $usuaDigi, $usuaDigi]);
    }

    /**
     * @param array<string, array{idPartNIIF:string,Nivel:int,OpciTerc:int}> $mapaPartNIIF
     */
    private function actualizarSaldoTerceroNIIF(
        PDO $pdo,
        string $tiDoTerc,
        string $nuDoTerc,
        float $valor,
        string $mes,
        string $anno,
        string $idPartNIIFHoja,
        array $mapaPartNIIF,
        string $usuaDigi
    ): void {
        if (($mapaPartNIIF[$idPartNIIFHoja]['OpciTerc'] ?? 0) !== 1) {
            return;
        }

        $acumDebe = $valor > 0 ? $valor : 0.0;
        $acumHabe = $valor < 0 ? abs($valor) : 0.0;

        $stmt = $pdo->prepare(
            'INSERT INTO SaTeNIIF
                (CodiInst,CodiAno,CodiMes,TipoDocu,NumeTerc,idPartNIIF,SaldDeAn,SaldCrAn,AcumDebe,AcumHabe,SaldDebe,SaldCred,FechDigi,HoraDigi,UsuaDigi)
             VALUES (?,?,?,?,?,?,0,0,?,?,?,?,CURDATE(),CURTIME(),?)
             ON DUPLICATE KEY UPDATE
                 AcumDebe = AcumDebe + VALUES(AcumDebe),
                 AcumHabe = AcumHabe + VALUES(AcumHabe),
                 SaldDebe = SaldDeAn + AcumDebe,
                 SaldCred = SaldCrAn + AcumHabe,
                 FechDigi = CURDATE(), HoraDigi = CURTIME(), UsuaDigi = VALUES(UsuaDigi)'
        );
        $stmt->execute([
            $this->codiInst(), $anno, $mes, $tiDoTerc, $nuDoTerc, $idPartNIIFHoja, $acumDebe, $acumHabe, $acumDebe, $acumHabe, $usuaDigi,
        ]);
    }

    private function insertarLineaDetaCont(
        PDO $pdo,
        string $codiDocuNota,
        string $numeDocuNota,
        int $consDeta,
        string $anno,
        string $codiCont,
        ?string $codiCent,
        ?string $centCost,
        ?string $tiDoTerc,
        ?string $nuDoTerc,
        string $tiDoRefe,
        string $nuDoRefe,
        float $valor,
        string $usuaDigi
    ): void {
        $stmt = $pdo->prepare(
            'INSERT INTO DetaCont
                (CodiInst,Periodo,CodiDocu,NumeDocu,ConsDeta,CodiCont,CodiCent,CentCost,TiDoTerc,NuDoTerc,TiDoRefe,NuDoRefe,TipoDocu,Valor,FechDigi,HoraDigi,UsuaDigi)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,CURDATE(),CURTIME(),?)'
        );
        // TipoDocu/NumePers en DetaCont son para el tipo de documento del
        // EMPLEADO, exclusivo del módulo de Nómina (ver docblock de
        // sihos/modulos/comun/detacont.php) — no tienen relación con
        // TiDoTerc/NuDoTerc; se dejan sin usar aquí.
        //
        // CentCost/TiDoTerc/NuDoTerc son NOT NULL DEFAULT '' en SIHOS (no
        // aceptan NULL real) — cuando la cuenta no exige tercero/centro de
        // costo, el valor "vacío" correcto es cadena vacía, no NULL.
        $stmt->execute([
            $this->codiInst(), $anno, $codiDocuNota, $numeDocuNota, $consDeta, $codiCont, $codiCent, $centCost ?? '',
            $tiDoTerc ?? '', $nuDoTerc ?? '', $tiDoRefe, $nuDoRefe, null, $valor, $usuaDigi,
        ]);
    }

    private function insertarLineaDetaNIIF(
        PDO $pdo,
        string $codiDocuNota,
        string $numeDocuNota,
        int $consDeta,
        string $anno,
        string $idPartNIIF,
        ?string $codiCent,
        ?string $centCost,
        ?string $tiDoTerc,
        ?string $nuDoTerc,
        string $tiDoRefe,
        string $nuDoRefe,
        float $valor,
        string $usuaDigi
    ): void {
        $stmt = $pdo->prepare(
            'INSERT INTO DetaNIIF
                (CodiInst,CodiAno,CodiDocu,NumeDocu,ConsDeta,idPartNIIF,CodiCent,CentCost,TiDoTerc,NuDoTerc,TiDoRefe,NuDoRefe,TipoDocu,Valor,FechDigi,HoraDigi,UsuaDigi)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,CURDATE(),CURTIME(),?)'
        );
        // Mismo motivo que insertarLineaDetaCont(): CentCost/TiDoTerc/
        // NuDoTerc son NOT NULL DEFAULT '' en SIHOS.
        $stmt->execute([
            $this->codiInst(), $anno, $codiDocuNota, $numeDocuNota, $consDeta, $idPartNIIF, $codiCent, $centCost ?? '',
            $tiDoTerc ?? '', $nuDoTerc ?? '', $tiDoRefe, $nuDoRefe, null, $valor, $usuaDigi,
        ]);
    }

    /**
     * Corrige en `DetaNomi` un concepto de nómina para que el TOTAL
     * (`ValoEmpe`+`ValoPatr`) quede igual al valor que exige el operador de
     * aportes en línea — usado por SihosNominaPilaCorreccionService a partir
     * del CSV de "posibles correcciones" del portal.
     *
     * Por defecto ($ajustarValoEmpe=false) solo toca `ValoPatr`: `ValoEmpe`
     * (aporte del empleado, ya descontado de su pago) queda intacto —
     * decisión explícita del usuario, para no afectar un valor que ya pudo
     * haberse pagado al trabajador. Esto cubre pensión/salud/CCF/SENA/ICBF.
     *
     * EXCEPCIÓN DELIBERADA — $ajustarValoEmpe=true (SOLO para Fondo de
     * Solidaridad Pensional): ese concepto en SIHOS tiene `ValoPatr` SIEMPRE
     * en $0 — no existe aporte patronal para ese fondo, es 100% a cargo del
     * empleado (verificado contra un caso real: ValoEmpe=$129.100,
     * ValoPatr=$0.00). Para ese concepto específico no hay "aporte
     * patronal" que subir o bajar: la única forma real de corregirlo es
     * ajustando `ValoEmpe`. El llamador (SihosNominaPilaCorreccionService)
     * decide este flag SOLO para 'fondo_solidaridad', nunca para los demás
     * conceptos — la protección de `ValoEmpe` sigue intacta para todo lo
     * demás.
     *
     * Re-verifica DENTRO de la transacción, no solo antes de empezar (mismo
     * principio que el resto de esta clase):
     *  1) Que la nómina (el documento contable CodiDocu-NumeDocu en EncaCont)
     *     siga SIN causar — si ya se causó (confirmó) entre la vista previa y
     *     este clic, se rechaza: SIHOS exige una nota de ajuste contable para
     *     tocar una nómina ya confirmada, este método nunca la genera.
     *  2) Que siga habiendo EXACTAMENTE una línea (`ConsConc`) para ese
     *     concepto — si apareció una segunda línea mientras tanto (novedad
     *     registrada después de la vista previa), se rechaza: no hay forma
     *     segura de adivinar en cuál aplicar el ajuste.
     *  3) Relee fresco el valor que se deja fijo (`ValoEmpe` normalmente,
     *     `ValoPatr` si $ajustarValoEmpe) para calcular el nuevo valor (no
     *     el de la vista previa, que puede haber quedado desactualizado).
     *
     * @return array{ok:bool,motivo?:string,valoEmpe?:float,valoPatrAntes?:float,valoPatrDespues?:float}
     * @throws PDOException en errores reales de BD (no en rechazos de negocio, esos se devuelven como ok=false)
     */
    public function corregirValoPatrNomina(
        string $codiDocu,
        string $numeDocu,
        string $codiAno,
        string $codiMes,
        string $tipoDocu,
        string $numePers,
        string $codiConc,
        float $totalEsperado,
        string $usuaDigi,
        bool $ajustarValoEmpe = false
    ): array {
        return $this->conBloqueo(
            'nominapatr:' . $codiDocu . ':' . $numeDocu . ':' . $tipoDocu . ':' . $numePers . ':' . $codiConc,
            function () use ($codiDocu, $numeDocu, $codiAno, $codiMes, $tipoDocu, $numePers, $codiConc, $totalEsperado, $usuaDigi, $ajustarValoEmpe): array {
                return $this->corregirValoPatrNominaBloqueado(
                    $codiDocu, $numeDocu, $codiAno, $codiMes, $tipoDocu, $numePers, $codiConc, $totalEsperado, $usuaDigi, $ajustarValoEmpe
                );
            }
        );
    }

    /** @see corregirValoPatrNomina (ya con el bloqueo tomado) */
    private function corregirValoPatrNominaBloqueado(
        string $codiDocu,
        string $numeDocu,
        string $codiAno,
        string $codiMes,
        string $tipoDocu,
        string $numePers,
        string $codiConc,
        float $totalEsperado,
        string $usuaDigi,
        bool $ajustarValoEmpe
    ): array {
        $pdo = $this->connect();
        $codiInst = $this->codiInst();

        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare('SELECT Causado FROM EncaCont WHERE CodiInst = ? AND CodiDocu = ? AND NumeDocu = ? FOR UPDATE');
            $stmt->execute([$codiInst, $codiDocu, $numeDocu]);
            $causado = $stmt->fetchColumn();

            if ($causado === false) {
                $pdo->rollBack();

                return ['ok' => false, 'motivo' => "El documento de nómina {$codiDocu}-{$numeDocu} ya no existe en SIHOS."];
            }

            if ((int)$causado === 1) {
                $pdo->rollBack();

                return [
                    'ok' => false,
                    'motivo' => "La nómina {$codiDocu}-{$numeDocu} ya está confirmada (causada) en SIHOS — no se modifica aquí. Use la nota de ajuste habitual.",
                ];
            }

            $stmt = $pdo->prepare(
                'SELECT ConsConc, ValoEmpe, ValoPatr FROM DetaNomi
                 WHERE CodiInst = ? AND CodiDocu = ? AND NumeDocu = ? AND CodiAno = ? AND CAST(CodiMes AS SIGNED) = ?
                   AND TipoDocu = ? AND NumePers = ? AND CodiConc = ?
                 FOR UPDATE'
            );
            $stmt->execute([$codiInst, $codiDocu, $numeDocu, $codiAno, $codiMes, $tipoDocu, $numePers, $codiConc]);
            $lineas = $stmt->fetchAll();

            if ($lineas === []) {
                $pdo->rollBack();

                return ['ok' => false, 'motivo' => 'La línea de nómina a corregir ya no existe — puede que ya se haya corregido o borrado.'];
            }

            if (count($lineas) > 1) {
                $pdo->rollBack();

                return [
                    'ok' => false,
                    'motivo' => 'El concepto tiene más de una línea en SIHOS (novedad partida en el período) — no se puede corregir automáticamente.',
                ];
            }

            $consConc = (string)$lineas[0]['ConsConc'];
            $valoEmpeActual = (float)$lineas[0]['ValoEmpe'];
            $valoPatrActual = (float)$lineas[0]['ValoPatr'];

            if ($ajustarValoEmpe) {
                // Fondo de Solidaridad Pensional: ValoPatr se deja fijo
                // (siempre debería ser $0 para este concepto, pero se relee
                // igual en vez de asumirlo) y se ajusta ValoEmpe.
                $valoEmpeDespues = round($totalEsperado - $valoPatrActual, 2);
                $stmt = $pdo->prepare(
                    'UPDATE DetaNomi SET ValoEmpe = ?, FechModi = CURDATE(), HoraModi = CURTIME(), UsuaModi = ?
                     WHERE CodiInst = ? AND CodiDocu = ? AND NumeDocu = ? AND CodiAno = ? AND CAST(CodiMes AS SIGNED) = ?
                       AND TipoDocu = ? AND NumePers = ? AND CodiConc = ? AND ConsConc = ?'
                );
                $stmt->execute([
                    $valoEmpeDespues, $usuaDigi, $codiInst, $codiDocu, $numeDocu, $codiAno, $codiMes,
                    $tipoDocu, $numePers, $codiConc, $consConc,
                ]);

                // FSP es una deducción del empleado (ValoEmpe): el "total
                // deducido"/"neto a pagar" del documento vive únicamente en
                // la línea de SUELDO (`Concepto.EsSueldo='1'`, nunca en las
                // demás líneas — verificado con datos reales: ValoDedu de
                // SUELDO = suma de ValoEmpe de TODAS las líneas de deducción
                // del documento, ValoNeto = ValoDeve - ValoDedu). Si no se
                // ajusta también aquí, el neto que muestra SIHOS queda
                // desactualizado por la diferencia. Los otros 5 conceptos
                // corregibles (pensión/salud/CCF/SENA/ICBF) SIEMPRE ajustan
                // ValoPatr (aporte patronal) — nunca tocan lo que se le
                // deduce al empleado — por eso este ajuste es EXCLUSIVO de
                // FSP, a pedido explícito del usuario.
                $deltaValoEmpe = round($valoEmpeDespues - $valoEmpeActual, 2);
                $sueldoAjustado = false;

                if (abs($deltaValoEmpe) >= 0.01) {
                    $stmtSueldo = $pdo->prepare(
                        'SELECT ConsConc FROM DetaNomi d
                         INNER JOIN Concepto c ON c.CodiInst = d.CodiInst AND c.CodiConc = d.CodiConc
                         WHERE d.CodiInst = ? AND d.CodiDocu = ? AND d.NumeDocu = ? AND d.CodiAno = ? AND CAST(d.CodiMes AS SIGNED) = ?
                           AND d.TipoDocu = ? AND d.NumePers = ? AND c.EsSueldo = \'1\'
                         FOR UPDATE'
                    );
                    $stmtSueldo->execute([$codiInst, $codiDocu, $numeDocu, $codiAno, $codiMes, $tipoDocu, $numePers]);
                    $lineasSueldo = $stmtSueldo->fetchAll();

                    if (count($lineasSueldo) === 1) {
                        $consConcSueldo = (string)$lineasSueldo[0]['ConsConc'];
                        $stmtActualizarSueldo = $pdo->prepare(
                            'UPDATE DetaNomi d
                             INNER JOIN Concepto c ON c.CodiInst = d.CodiInst AND c.CodiConc = d.CodiConc
                             SET d.ValoDedu = d.ValoDedu + ?, d.ValoNeto = d.ValoNeto - ?,
                                 d.FechModi = CURDATE(), d.HoraModi = CURTIME(), d.UsuaModi = ?
                             WHERE d.CodiInst = ? AND d.CodiDocu = ? AND d.NumeDocu = ? AND d.CodiAno = ? AND CAST(d.CodiMes AS SIGNED) = ?
                               AND d.TipoDocu = ? AND d.NumePers = ? AND c.EsSueldo = \'1\' AND d.ConsConc = ?'
                        );
                        $stmtActualizarSueldo->execute([
                            $deltaValoEmpe, $deltaValoEmpe, $usuaDigi,
                            $codiInst, $codiDocu, $numeDocu, $codiAno, $codiMes, $tipoDocu, $numePers, $consConcSueldo,
                        ]);
                        $sueldoAjustado = true;
                    }
                    // Si no hay exactamente una línea de SUELDO en este
                    // documento (0 o >1, caso atípico), se deja tal cual —
                    // la corrección de FSP en sí ya quedó aplicada arriba;
                    // no se bloquea por esto.
                }

                $pdo->commit();

                return [
                    'ok' => true,
                    'valoEmpe' => $valoEmpeDespues,
                    'valoPatrAntes' => $valoPatrActual,
                    'valoPatrDespues' => $valoPatrActual,
                    'valoEmpeAntes' => $valoEmpeActual,
                    'sueldoValoDeduValoNetoAjustado' => $sueldoAjustado,
                ];
            }

            $valoPatrDespues = round($totalEsperado - $valoEmpeActual, 2);

            $stmt = $pdo->prepare(
                'UPDATE DetaNomi SET ValoPatr = ?, FechModi = CURDATE(), HoraModi = CURTIME(), UsuaModi = ?
                 WHERE CodiInst = ? AND CodiDocu = ? AND NumeDocu = ? AND CodiAno = ? AND CAST(CodiMes AS SIGNED) = ?
                   AND TipoDocu = ? AND NumePers = ? AND CodiConc = ? AND ConsConc = ?'
            );
            $stmt->execute([
                $valoPatrDespues, $usuaDigi, $codiInst, $codiDocu, $numeDocu, $codiAno, $codiMes,
                $tipoDocu, $numePers, $codiConc, $consConc,
            ]);

            $pdo->commit();

            return [
                'ok' => true,
                'valoEmpe' => $valoEmpeActual,
                'valoPatrAntes' => $valoPatrActual,
                'valoPatrDespues' => $valoPatrDespues,
            ];
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }
}
