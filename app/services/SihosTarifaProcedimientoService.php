<?php

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * SIHOS/PROCESOS/TARIFA PROCEDIMIENTOS — carga masiva de tarifas de
 * `TariProc` a partir de un archivo Excel, con vista previa antes de
 * escribir. Reemplaza al importador CSV legado y obsoleto de SIHOS
 * (`modulos/procesos/tarifas/importartarifa.class.php`, sin ítem de menú
 * activo — ver conversación con el usuario, 2026-09-16): se conserva la
 * regla de negocio real (exclusión mutua entre ValoUnit/GrupQuir/UVR, cada
 * fila se tarifa de una sola forma), pero con validaciones más estrictas,
 * columnas con nombre en vez de posición ciega, transacción real con
 * bloqueo, y auditoría en SAVID.
 *
 * `TipoTari` no lo lee ningún módulo de facturación de SIHOS (confirmado
 * contra el código fuente completo, 2026-09-16) — aquí se usa como
 * discriminador propio de qué combinación de columnas aplica a cada fila,
 * documentado en TIPOS_VALIDOS. `IndiUVB` tampoco lo lee ningún módulo de
 * SIHOS: es puramente informativo (el mismo criterio que ya usa el cliente
 * en su propio proceso de "Actualizar tarifarios" — ver
 * Actualizar_tarifarios_2026.xlsx, columna UVB = ROUND(ValoUnit/valorUVB,2))
 * y se calcula aquí con el "valor UVB" que la persona indica una sola vez
 * por lote, nunca automático — no existe ninguna tabla en SIHOS con ese
 * valor vigente.
 */
class SihosTarifaProcedimientoService
{
    public const TIPO_TARI_PLANO = 0;
    public const TIPO_TARI_GRUPO_QUIRURGICO = 1;
    public const TIPO_TARI_UVR = 2;
    public const TIPO_TARI_PLANO_UVB = 5;

    private const TIPOS_VALIDOS = [
        self::TIPO_TARI_PLANO => 'Plano (solo ValoUnit)',
        self::TIPO_TARI_GRUPO_QUIRURGICO => 'Grupo quirúrgico (GrupQuir)',
        self::TIPO_TARI_UVR => 'UVR (UVR/UVRMax)',
        self::TIPO_TARI_PLANO_UVB => 'Plano + UVB (ValoUnit, calcula IndiUVB)',
    ];

    private const ENCABEZADOS_PLANTILLA = ['CodiProc', 'TipoTari', 'ValoUnit', 'GrupQuir', 'UVR', 'UVRMax', 'NombProc'];

    private const EJEMPLO_PLANTILLA = [
        ['890201', self::TIPO_TARI_PLANO, 47000, '', '', '', 'CONSULTA DE PRIMERA VEZ POR MEDICINA GENERAL'],
        ['033101', self::TIPO_TARI_PLANO_UVB, 83800, '', '', '', 'PUNCION LUMBAR (DIAGNOSTICA O TERAPEUTICA)'],
        ['038201', self::TIPO_TARI_GRUPO_QUIRURGICO, '', '12', '', '', ''],
        ['13721', self::TIPO_TARI_UVR, '', '', 2000, 20000, ''],
    ];

    private SihosEmpresaConfigRepository $configRepository;
    private SihosUsuaDigiResolver $usuaDigiResolver;

    public function __construct()
    {
        $this->configRepository = new SihosEmpresaConfigRepository();
        $this->usuaDigiResolver = new SihosUsuaDigiResolver();
    }

    /** @return array{ok:bool,error?:string,configFila?:array} */
    private function resolverConfigLectura(int $empresaId): array
    {
        $configFila = $this->configRepository->findByEmpresaId($empresaId);
        if ($configFila === null || $configFila['host'] === '' || $configFila['base_datos'] === '') {
            return ['ok' => false, 'error' => 'Esta empresa no tiene conexión a SIHOS configurada.'];
        }

        $codiInst = trim((string)($configFila['codi_inst'] ?? ''));
        if ($codiInst === '') {
            return ['ok' => false, 'error' => 'Falta configurar el CodiInst de esta empresa en Conexión SIHOS.'];
        }

        return ['ok' => true, 'configFila' => $configFila];
    }

    private function repositorioLectura(array $configFila): SihosExternalRepository
    {
        return new SihosExternalRepository([
            'host' => $configFila['host'],
            'port' => (string)$configFila['puerto'],
            'database' => $configFila['base_datos'],
            'username' => $configFila['usuario'],
            'codiInst' => trim((string)$configFila['codi_inst']),
            'password' => SihosCredentialCipher::decrypt($configFila['password_cifrado'] ?? null),
            'charset' => $configFila['charset'],
        ]);
    }

    /**
     * Catálogo de manuales para el selector de la pantalla.
     *
     * @return array{ok:bool,error?:string,manuales?:array<string,string>}
     */
    public function buildManualOptions(int $empresaId): array
    {
        $config = $this->resolverConfigLectura($empresaId);
        if (!$config['ok']) {
            return $config;
        }

        try {
            $manuales = $this->repositorioLectura($config['configFila'])->fetchManuales();
        } catch (PDOException $e) {
            return ['ok' => false, 'error' => 'No se pudo consultar SIHOS: ' . $e->getMessage()];
        }

        return ['ok' => true, 'manuales' => $manuales];
    }

    /**
     * Catálogo de planes para el selector de la pantalla.
     *
     * @return array{ok:bool,error?:string,planes?:array<string,string>}
     */
    public function buildPlanOptions(int $empresaId): array
    {
        $config = $this->resolverConfigLectura($empresaId);
        if (!$config['ok']) {
            return $config;
        }

        try {
            $planes = $this->repositorioLectura($config['configFila'])->fetchPlanes();
        } catch (PDOException $e) {
            return ['ok' => false, 'error' => 'No se pudo consultar SIHOS: ' . $e->getMessage()];
        }

        return ['ok' => true, 'planes' => $planes];
    }

    /**
     * Tarifas actuales del manual+plan elegido, para la rejilla de la
     * pantalla.
     *
     * @return array{ok:bool,error?:string,filas?:list<array<string,mixed>>}
     */
    public function buildGrid(int $empresaId, string $codiManu, string $codiPlan): array
    {
        $config = $this->resolverConfigLectura($empresaId);
        if (!$config['ok']) {
            return $config;
        }

        try {
            $tarifas = $this->repositorioLectura($config['configFila'])->fetchTarifasPorManual($codiManu, $codiPlan);
        } catch (PDOException $e) {
            return ['ok' => false, 'error' => 'No se pudo consultar SIHOS: ' . $e->getMessage()];
        }

        $filas = [];
        foreach ($tarifas as $codiProc => $t) {
            $filas[] = ['codiProc' => $codiProc] + $t;
        }

        return ['ok' => true, 'filas' => $filas];
    }

    /**
     * Genera el .xlsx de plantilla (encabezados + filas de ejemplo, una por
     * cada TipoTari válido) y lo escribe en `$destino` ('php://output' o una
     * ruta de archivo).
     */
    public function generarPlantillaXlsx(string $destino): void
    {
        $spreadsheet = new Spreadsheet();
        $hoja = $spreadsheet->getActiveSheet();
        $hoja->setTitle('Tarifas');

        // $strictNullComparison=true (4º parámetro): por defecto fromArray()
        // compara con '==', y '0 == null' es TRUE en PHP — sin esto, el
        // TipoTari=0 (Plano) de las filas de ejemplo se escribiría como
        // celda vacía en vez de '0', confundiendo a quien descarga la
        // plantilla (verificado: así se comporta con el valor por defecto).
        $hoja->fromArray(self::ENCABEZADOS_PLANTILLA, null, 'A1', true);
        $hoja->fromArray(self::EJEMPLO_PLANTILLA, null, 'A2', true);

        $filaAyuda = count(self::EJEMPLO_PLANTILLA) + 3;
        $hoja->setCellValue('A' . $filaAyuda, 'TipoTari:');
        foreach (self::TIPOS_VALIDOS as $codigo => $etiqueta) {
            $filaAyuda++;
            $hoja->setCellValue('A' . $filaAyuda, $codigo);
            $hoja->setCellValue('B' . $filaAyuda, $etiqueta);
        }
        $filaAyuda += 2;
        $hoja->setCellValue('A' . $filaAyuda, 'NombProc es solo referencial: se ignora al cargar, no hace falta llenarlo.');

        foreach (range('A', 'G') as $columna) {
            $hoja->getColumnDimension($columna)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        $writer->save($destino);
    }

    /**
     * Lee y valida el archivo cargado, cruzándolo contra los catálogos de
     * SIHOS (CodiProc, GrupQuir por manual) y las tarifas actuales del
     * manual+plan elegido. Nunca escribe nada — solo arma la vista previa y,
     * si hay al menos una fila válida, la guarda en
     * SihosTarifaProcedimientoCache para que tarifaProcedimientoConfirmar()
     * la use sin volver a subir/parsear el archivo.
     *
     * @return array{
     *   ok:bool, error?:string, token?:string,
     *   resumen?:array{nuevos:int,actualizar:int,sin_cambio:int,excluidos:int,total:int},
     *   filas?:list<array<string,mixed>>
     * }
     */
    public function validarArchivo(
        int $empresaId,
        int $userId,
        string $rutaArchivo,
        string $codiManu,
        string $codiPlan,
        string $modo,
        ?float $valorUvbLote
    ): array {
        if (!in_array($modo, ['insertar', 'actualizar', 'ambos'], true)) {
            return ['ok' => false, 'error' => 'Modo de carga inválido.'];
        }

        $config = $this->resolverConfigLectura($empresaId);
        if (!$config['ok']) {
            return $config;
        }

        $filasCrudas = $this->leerArchivo($rutaArchivo);
        if (!$filasCrudas['ok']) {
            return $filasCrudas;
        }

        $repositorio = $this->repositorioLectura($config['configFila']);

        try {
            $gruposValidos = array_flip($repositorio->fetchGruposQuirurgicosPorManual($codiManu));
            $tarifasActuales = $repositorio->fetchTarifasPorManual($codiManu, $codiPlan);
        } catch (PDOException $e) {
            return ['ok' => false, 'error' => 'No se pudo consultar SIHOS: ' . $e->getMessage()];
        }

        // 1) Parseo + validación de forma de cada fila (sin tocar SIHOS
        // todavía) y detección de códigos duplicados dentro del archivo.
        $filasParseadas = [];
        $conteoCodigos = [];
        $requiereUvb = false;

        foreach ($filasCrudas['filas'] as $numeroFila => $cruda) {
            $codiProc = trim((string)($cruda['CodiProc'] ?? ''));
            if ($codiProc === '') {
                continue;
            }
            $conteoCodigos[$codiProc] = ($conteoCodigos[$codiProc] ?? 0) + 1;

            $parseada = $this->parsearFila($numeroFila, $cruda);
            $filasParseadas[] = $parseada;

            if ($parseada['error'] === null && $parseada['tipoTari'] === self::TIPO_TARI_PLANO_UVB) {
                $requiereUvb = true;
            }
        }

        if ($filasParseadas === []) {
            return ['ok' => false, 'error' => 'El archivo no tiene ninguna fila con CodiProc.'];
        }

        if ($requiereUvb && ($valorUvbLote === null || $valorUvbLote <= 0)) {
            return [
                'ok' => false,
                'error' => 'El archivo tiene filas con TipoTari=5 (Plano + UVB) — indique el "valor UVB de referencia" del lote (el valor vigente del año, el mismo que usa su proceso actual de Excel) antes de cargar.',
            ];
        }

        $codigosUnicos = array_values(array_unique(array_column($filasParseadas, 'codiProc')));
        try {
            $catalogoProc = $repositorio->fetchProcedimientosPorCodigo($codigosUnicos);
        } catch (PDOException $e) {
            return ['ok' => false, 'error' => 'No se pudo consultar SIHOS: ' . $e->getMessage()];
        }

        // 2) Segunda pasada: cruce contra catálogos + tarifas actuales +
        // duplicados, para armar la vista previa final.
        $vistaPrevia = [];
        $filasParaEscribir = [];
        $resumen = ['nuevos' => 0, 'actualizar' => 0, 'sin_cambio' => 0, 'excluidos' => 0, 'total' => count($filasParseadas)];

        foreach ($filasParseadas as $fila) {
            $codiProc = $fila['codiProc'];
            $nombProc = $catalogoProc[$codiProc]['NombProc'] ?? null;
            $actual = $tarifasActuales[$codiProc] ?? null;

            $motivo = $fila['error'];

            if ($motivo === null && ($conteoCodigos[$codiProc] ?? 0) > 1) {
                $motivo = 'Código duplicado dentro del archivo.';
            }

            if ($motivo === null && !isset($catalogoProc[$codiProc])) {
                $motivo = 'No se encontró en el catálogo de procedimientos (CodiProc) de esta institución.';
            }

            if ($motivo === null && $fila['tipoTari'] === self::TIPO_TARI_GRUPO_QUIRURGICO && !isset($gruposValidos[$fila['grupQuir']])) {
                $motivo = "El grupo quirúrgico '{$fila['grupQuir']}' no existe para el manual seleccionado.";
            }

            $indiUVB = null;
            if ($motivo === null && $fila['tipoTari'] === self::TIPO_TARI_PLANO_UVB) {
                $indiUVB = round($fila['valoUnit'] / $valorUvbLote, 2);
            }

            $estado = 'error';
            if ($motivo === null) {
                if ($actual === null) {
                    $estado = $modo === 'actualizar' ? 'no_existe' : 'nuevo';
                } else {
                    // Las filas antiguas de SIHOS (digitadas por su propia
                    // pantalla, que solo inserta ValoUnit) quedan con UVR/
                    // UVRMax en el DEFAULT de la columna (0.00), no en NULL —
                    // verificado con datos reales de Roldanillo. Sin
                    // normalizar, una tarifa plana sin cambios real siempre
                    // se marcaba "actualizar" (0.00 !== null). GrupQuir='' y
                    // NULL tampoco son distinguibles en la práctica (SIHOS
                    // guarda ambas formas según la vía de entrada).
                    $normalizarNumero = static fn (?float $v): ?float => $v === null || $v == 0.0 ? null : $v;
                    $mismoValor = $actual['ValoUnit'] === $fila['valoUnit']
                        && (($actual['GrupQuir'] ?: null) === ($fila['grupQuir'] ?: null))
                        && $normalizarNumero($actual['UVR']) === $normalizarNumero($fila['uvr'])
                        && $normalizarNumero($actual['UVRMax']) === $normalizarNumero($fila['uvrMax']);
                    if ($modo === 'insertar') {
                        $estado = 'ya_existe';
                    } else {
                        $estado = $mismoValor ? 'sin_cambio' : 'actualizar';
                    }
                }
            }

            if ($motivo === null && $estado === 'no_existe') {
                $motivo = 'No existe todavía una tarifa para este procedimiento en este manual — no se puede actualizar (use modo Insertar).';
            }
            if ($motivo === null && $estado === 'ya_existe') {
                $motivo = 'Ya existe una tarifa para este procedimiento en este manual — no se puede insertar de nuevo (use modo Actualizar).';
            }

            $vistaPrevia[] = [
                'fila' => $fila['numeroFila'],
                'codiProc' => $codiProc,
                'nombProc' => $nombProc,
                'tipoTari' => $fila['tipoTari'],
                'grupQuir' => $fila['grupQuir'],
                'uvr' => $fila['uvr'],
                'uvrMax' => $fila['uvrMax'],
                'valorActual' => $actual['ValoUnit'] ?? null,
                'valorNuevo' => $fila['valoUnit'],
                'diferencia' => $fila['valoUnit'] !== null && ($actual['ValoUnit'] ?? null) !== null
                    ? $fila['valoUnit'] - $actual['ValoUnit']
                    : null,
                'indiUVB' => $indiUVB,
                'estado' => $estado,
                'motivo' => $motivo,
            ];

            if ($motivo !== null || !in_array($estado, ['nuevo', 'actualizar'], true)) {
                $resumen['excluidos']++;
                continue;
            }

            $resumen[$estado === 'nuevo' ? 'nuevos' : 'actualizar']++;
            $filasParaEscribir[] = [
                'codiProc' => $codiProc,
                'tipoTari' => $fila['tipoTari'],
                'valoUnit' => $fila['valoUnit'],
                'grupQuir' => $fila['grupQuir'],
                'uvr' => $fila['uvr'],
                'uvrMax' => $fila['uvrMax'],
                'indiUVB' => $indiUVB,
            ];
        }

        // 'sin_cambio' cuenta aparte del resumen de exclusión general —
        // no es un error, simplemente no hay nada que escribir.
        foreach ($vistaPrevia as $v) {
            if ($v['estado'] === 'sin_cambio') {
                $resumen['sin_cambio']++;
                $resumen['excluidos']--;
            }
        }

        $token = null;
        if ($filasParaEscribir !== []) {
            $token = SihosTarifaProcedimientoCache::store($empresaId, $userId, $filasParaEscribir, [
                'codiManu' => $codiManu,
                'codiPlan' => $codiPlan,
                'modo' => $modo,
            ]);
        }

        return ['ok' => true, 'token' => $token, 'resumen' => $resumen, 'filas' => $vistaPrevia];
    }

    /**
     * Confirma la carga: relee la vista previa cacheada (nunca confía en un
     * payload nuevo del navegador) y delega la escritura real a
     * SihosExternalWriteRepository::upsertTarifasProcedimiento(), dentro de
     * una única transacción con bloqueo por manual. Registra el resultado en
     * la auditoría de SAVID y borra el token (uso único).
     *
     * @return array{ok:bool,error?:string,insertados?:int,actualizados?:int,rechazados?:list<array<string,mixed>>}
     */
    public function confirmarCarga(int $empresaId, int $userId, string $token): array
    {
        $cache = SihosTarifaProcedimientoCache::load($token, $empresaId, $userId);
        if ($cache === null) {
            return ['ok' => false, 'error' => 'La vista previa expiró o no existe. Vuelva a cargar el archivo.'];
        }

        $configFila = $this->configRepository->findByEmpresaId($empresaId);
        if ($configFila === null || $configFila['host'] === '' || $configFila['base_datos'] === '') {
            return ['ok' => false, 'error' => 'Esta empresa no tiene conexión a SIHOS configurada.'];
        }

        $codiInst = trim((string)($configFila['codi_inst'] ?? ''));
        $usuarioEscritura = trim((string)($configFila['usuario_escritura'] ?? ''));
        if ($codiInst === '' || $usuarioEscritura === '' || empty($configFila['password_escritura_cifrado'])) {
            return [
                'ok' => false,
                'error' => 'Configure las credenciales de escritura de esta empresa en Conexión SIHOS antes de usar esta acción.',
            ];
        }

        $codiManu = (string)($cache['meta']['codiManu'] ?? '');
        $codiPlan = (string)($cache['meta']['codiPlan'] ?? '');
        $modo = (string)($cache['meta']['modo'] ?? '');
        $filas = $cache['filas'];

        if ($codiManu === '' || $codiPlan === '' || !in_array($modo, ['insertar', 'actualizar', 'ambos'], true) || $filas === []) {
            return ['ok' => false, 'error' => 'La vista previa guardada está incompleta. Vuelva a cargar el archivo.'];
        }

        $repositorioLectura = $this->repositorioLectura($configFila);
        $usuaDigi = $this->usuaDigiResolver->resolver($userId, $repositorioLectura);

        $repositorioEscritura = new SihosExternalWriteRepository([
            'host' => $configFila['host'],
            'port' => (string)$configFila['puerto'],
            'database' => $configFila['base_datos'],
            'username' => $usuarioEscritura,
            'codiInst' => $codiInst,
            'password' => SihosCredentialCipher::decrypt($configFila['password_escritura_cifrado']),
            'charset' => $configFila['charset'],
        ]);

        try {
            $resultado = $repositorioEscritura->upsertTarifasProcedimiento($filas, $codiManu, $codiPlan, $modo, $usuaDigi);
        } catch (SihosOperacionEnCursoException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        } catch (PDOException $e) {
            return ['ok' => false, 'error' => 'No se pudo escribir en SIHOS: ' . $e->getMessage()];
        }

        SihosTarifaProcedimientoCache::delete($token, $empresaId);

        $this->registrarAuditoria($empresaId, $codiInst, $codiManu, $codiPlan, $modo, $resultado);

        return [
            'ok' => true,
            'insertados' => $resultado['insertados'],
            'actualizados' => $resultado['actualizados'],
            'rechazados' => $resultado['rechazados'],
        ];
    }

    /**
     * Registro manual en la auditoría de SAVID: AuditingPDO solo cubre
     * escrituras en la BD propia de SAVID, no ésta contra SIHOS. Mismo
     * formato de columnas que SihosNominaPilaCorreccionService::registrarAuditoria().
     */
    private function registrarAuditoria(int $empresaId, string $codiInst, string $codiManu, string $codiPlan, string $modo, array $resultado): void
    {
        $pdo = (new Database())->connect();

        $stmt = $pdo->prepare('
            INSERT INTO auditoria (
                accion, tabla, registro_id,
                datos_anteriores, datos_nuevos, campos_cambiados,
                sql_resumen, usuario_id, empresa_id, sede_id,
                ip, user_agent, request_url
            ) VALUES (?, ?, ?, NULL, ?, NULL, ?, ?, ?, ?, ?, ?, ?)
        ');

        $stmt->execute([
            // 'accion' es ENUM('INSERT','UPDATE','DELETE') en SAVID — un lote
            // en modo 'ambos' puede tener de las dos; se reporta la que
            // predomine en cantidad de filas (el detalle completo, con el
            // desglose real insertados/actualizados, queda en datos_nuevos).
            $resultado['insertados'] >= $resultado['actualizados'] ? 'INSERT' : 'UPDATE',
            'sihos.TariProc',
            "{$codiInst}-{$codiManu}-{$codiPlan}",
            json_encode([
                'insertados' => $resultado['insertados'],
                'actualizados' => $resultado['actualizados'],
                'rechazados' => $resultado['rechazados'],
                'detalle' => $resultado['detalle'],
            ], JSON_UNESCAPED_UNICODE),
            "Carga masiva de tarifas de procedimientos (SIHOS/PROCESOS/TARIFA PROCEDIMIENTOS), manual {$codiManu}, plan {$codiPlan}, modo {$modo}: "
                . "{$resultado['insertados']} insertada(s), {$resultado['actualizados']} actualizada(s), " . count($resultado['rechazados']) . ' rechazada(s).',
            $_SESSION['user_id'] ?? null,
            $empresaId,
            $_SESSION['sede_id'] ?? null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
            $_SERVER['REQUEST_URI'] ?? null,
        ]);
    }

    /**
     * Lee el .xlsx subido con PhpSpreadsheet, ubica la fila de encabezados
     * por contenido (busca 'CodiProc' en vez de asumir que es la fila 1, más
     * tolerante a filas de título arriba) y devuelve una fila por registro,
     * indexada por el nombre real del encabezado.
     *
     * @return array{ok:bool,error?:string,filas?:array<int,array<string,mixed>>} filas indexadas por número de fila real del archivo (para los mensajes de error)
     */
    private function leerArchivo(string $rutaArchivo): array
    {
        try {
            $hoja = IOFactory::load($rutaArchivo)->getActiveSheet();
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'No se pudo leer el archivo. Verifique que sea un .xlsx válido (' . $e->getMessage() . ').'];
        }

        $datos = $hoja->toArray(null, true, true, false);

        $indiceEncabezado = null;
        foreach ($datos as $i => $fila) {
            if (in_array('CodiProc', array_map('strval', $fila), true)) {
                $indiceEncabezado = $i;
                break;
            }
        }

        if ($indiceEncabezado === null) {
            return ['ok' => false, 'error' => 'El archivo no tiene la columna "CodiProc" — descargue la plantilla y no cambie los nombres de columna.'];
        }

        $encabezados = array_map(static fn ($h): string => trim((string)$h), $datos[$indiceEncabezado]);

        $filas = [];
        foreach ($datos as $i => $fila) {
            if ($i <= $indiceEncabezado) {
                continue;
            }
            if (implode('', array_map('strval', $fila)) === '') {
                continue;
            }

            $combinada = [];
            foreach ($encabezados as $columna => $nombre) {
                if ($nombre === '') {
                    continue;
                }
                $combinada[$nombre] = $fila[$columna] ?? null;
            }
            // Fila 1-based tal como la vería la persona en Excel.
            $filas[$i + 1] = $combinada;
        }

        return ['ok' => true, 'filas' => $filas];
    }

    /**
     * Normaliza y valida UNA fila cruda del archivo (tipos, exclusión mutua
     * entre ValoUnit/GrupQuir/UVR según TipoTari). No toca SIHOS — eso pasa
     * después, en validarArchivo(), una vez agregados todos los códigos.
     *
     * @param array<string,mixed> $cruda
     * @return array{numeroFila:int,codiProc:string,tipoTari:int,valoUnit:?int,grupQuir:?string,uvr:?float,uvrMax:?float,error:?string}
     */
    private function parsearFila(int $numeroFila, array $cruda): array
    {
        $codiProc = trim((string)($cruda['CodiProc'] ?? ''));
        $tipoTariCrudo = trim((string)($cruda['TipoTari'] ?? ''));
        $valoUnitCrudo = trim((string)($cruda['ValoUnit'] ?? ''));
        $grupQuirCrudo = trim((string)($cruda['GrupQuir'] ?? ''));
        $uvrCrudo = trim((string)($cruda['UVR'] ?? ''));
        $uvrMaxCrudo = trim((string)($cruda['UVRMax'] ?? ''));

        $base = [
            'numeroFila' => $numeroFila,
            'codiProc' => $codiProc,
            'tipoTari' => 0,
            'valoUnit' => null,
            'grupQuir' => null,
            'uvr' => null,
            'uvrMax' => null,
            'error' => null,
        ];

        if (!is_numeric($tipoTariCrudo) || !array_key_exists((int)$tipoTariCrudo, self::TIPOS_VALIDOS)) {
            return $base + ['error' => "TipoTari inválido ('{$tipoTariCrudo}') — use uno de: " . implode(', ', array_keys(self::TIPOS_VALIDOS)) . '.'];
        }
        $tipoTari = (int)$tipoTariCrudo;
        $base['tipoTari'] = $tipoTari;

        $valoUnit = $valoUnitCrudo !== '' && is_numeric($valoUnitCrudo) ? (int)round((float)$valoUnitCrudo) : null;
        $grupQuir = $grupQuirCrudo !== '' ? $grupQuirCrudo : null;
        $uvr = $uvrCrudo !== '' && is_numeric($uvrCrudo) ? (float)$uvrCrudo : null;
        $uvrMax = $uvrMaxCrudo !== '' && is_numeric($uvrMaxCrudo) ? (float)$uvrMaxCrudo : null;

        if ($valoUnitCrudo !== '' && $valoUnit === null) {
            return $base + ['error' => "ValoUnit no es numérico ('{$valoUnitCrudo}')."];
        }
        if ($uvrCrudo !== '' && $uvr === null) {
            return $base + ['error' => "UVR no es numérico ('{$uvrCrudo}')."];
        }
        if ($uvrMaxCrudo !== '' && $uvrMax === null) {
            return $base + ['error' => "UVRMax no es numérico ('{$uvrMaxCrudo}')."];
        }

        switch ($tipoTari) {
            case self::TIPO_TARI_PLANO:
            case self::TIPO_TARI_PLANO_UVB:
                if ($valoUnit === null || $valoUnit < 0) {
                    return $base + ['error' => 'TipoTari ' . $tipoTari . ' requiere ValoUnit (numérico, mayor o igual a cero).'];
                }
                if ($grupQuir !== null || $uvr !== null || $uvrMax !== null) {
                    return $base + ['error' => 'TipoTari ' . $tipoTari . ' es plano — GrupQuir/UVR/UVRMax deben ir vacíos.'];
                }
                break;

            case self::TIPO_TARI_GRUPO_QUIRURGICO:
                if ($grupQuir === null) {
                    return $base + ['error' => 'TipoTari 1 requiere GrupQuir.'];
                }
                if ($valoUnit !== null || $uvr !== null || $uvrMax !== null) {
                    return $base + ['error' => 'TipoTari 1 es de grupo quirúrgico — ValoUnit/UVR/UVRMax deben ir vacíos.'];
                }
                break;

            case self::TIPO_TARI_UVR:
                if ($uvr === null || $uvrMax === null || $uvr < 0 || $uvrMax < 0) {
                    return $base + ['error' => 'TipoTari 2 requiere UVR y UVRMax (numéricos, mayores o iguales a cero).'];
                }
                if ($uvr > $uvrMax) {
                    return $base + ['error' => 'UVR no puede ser mayor que UVRMax.'];
                }
                if ($valoUnit !== null || $grupQuir !== null) {
                    return $base + ['error' => 'TipoTari 2 es de UVR — ValoUnit/GrupQuir deben ir vacíos.'];
                }
                break;
        }

        return [
            'numeroFila' => $numeroFila,
            'codiProc' => $codiProc,
            'tipoTari' => $tipoTari,
            'valoUnit' => $valoUnit,
            'grupQuir' => $grupQuir,
            'uvr' => $uvr,
            'uvrMax' => $uvrMax,
            'error' => null,
        ];
    }
}
