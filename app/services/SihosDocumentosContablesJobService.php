<?php

/**
 * Ejecución en segundo plano del reporte SIHOS "Documentos contables" (mismo
 * patrón que SihosOrdenLiquidacionJobService: Cloudflare corta a ~100 s y una
 * empresa real tarda ~95 s solo en la consulta de 9 meses). La petición crea
 * el trabajo y lanza scripts/sihos_documentos_contables_job.php; estado y el
 * .xlsx viven en el storage privado por empresa, solo los descarga quien lo
 * lanzó y se purgan pasadas TTL de SihosTrabajoStore.
 */
class SihosDocumentosContablesJobService
{
    private const PREFIJO = 'doccont';
    /** Sin latido durante este tiempo el trabajo se da por interrumpido. */
    private const SIN_LATIDO_SEGUNDOS = 300;

    private SihosTrabajoStore $store;

    public function __construct()
    {
        $this->store = new SihosTrabajoStore(self::PREFIJO);
    }

    /**
     * @param array<string,mixed> $entrada filtros del formulario (ver SihosDocumentosContablesService::validarFiltros)
     * @return array{ok:bool,token?:string,error?:string}
     */
    public function iniciar(int $empresaId, int $userId, array $entrada): array
    {
        $validacion = SihosDocumentosContablesService::validarFiltros($entrada);
        if (!$validacion['ok']) {
            return ['ok' => false, 'error' => $validacion['error']];
        }

        $this->store->purgarExpirados($empresaId, ['_libro.xlsx']);

        $token = bin2hex(random_bytes(16));
        $this->store->guardarEstado($empresaId, $token, [
            'estado' => 'pendiente', 'empresa_id' => $empresaId, 'user_id' => $userId,
            'filtros' => $validacion['filtros'],
            'creado' => time(), 'actualizado' => time(), 'lineas' => 0,
        ]);

        $this->store->lanzarWorker(BASE_PATH . '/scripts/sihos_documentos_contables_job.php', $empresaId, $token);

        return ['ok' => true, 'token' => $token];
    }

    /** Lo llama el script CLI: genera el .xlsx y lo deja en el storage. */
    public function ejecutar(int $empresaId, string $token): void
    {
        $token = $this->store->tokenSeguro($token);
        $estado = $token !== '' ? $this->store->leerEstado($empresaId, $token) : null;
        if ($estado === null || $estado['estado'] !== 'pendiente') {
            return;
        }
        $estado['estado'] = 'ejecutando';
        $this->store->guardarEstado($empresaId, $token, $estado);

        $tmp = tempnam(sys_get_temp_dir(), 'dcc');
        try {
            $ultimoLatido = 0;
            $reporte = (new SihosDocumentosContablesService())->generar(
                $empresaId, (array)$estado['filtros'], $tmp,
                function (int $lineas) use (&$estado, $empresaId, $token, &$ultimoLatido): void {
                    if (time() - $ultimoLatido >= 2) {
                        $ultimoLatido = time();
                        $estado['lineas'] = $lineas;
                        $estado['actualizado'] = $ultimoLatido;
                        $this->store->guardarEstado($empresaId, $token, $estado);
                    }
                }
            );

            if (!$reporte['ok']) {
                $this->store->terminar($empresaId, $token, $estado, 'error', (string)$reporte['error']);

                return;
            }
            if ($this->store->storage()->putLocalFile(StorageService::ZONE_SIHOS_REPORTES, self::PREFIJO . '_' . $token . '_libro.xlsx', $tmp, true, $empresaId) === null) {
                throw new RuntimeException('No se pudo guardar el archivo Excel.');
            }
            $estado['lineas'] = (int)$reporte['lineas'];
            $estado['hojas'] = (int)$reporte['hojas'];
            $this->store->terminar($empresaId, $token, $estado, 'listo');
        } catch (Throwable $e) {
            error_log('SihosDocumentosContablesJobService: ' . $e->getMessage());
            $this->store->terminar($empresaId, $token, $estado, 'error', 'No se pudo generar el reporte. Intente de nuevo.');
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Estado visible para el usuario dueño del trabajo (null si no existe o no es suyo).
     *
     * @return array{estado:string,lineas:int,hojas:int,segundos:int,filtros:array<string,string>,error?:string}|null
     */
    public function estado(int $empresaId, int $userId, string $token): ?array
    {
        $token = $this->store->tokenSeguro($token);
        $estado = $token !== '' ? $this->store->leerEstado($empresaId, $token) : null;
        if ($estado === null || (int)$estado['user_id'] !== $userId) {
            return null;
        }

        $estado = $this->store->verificarLatido($empresaId, $token, $estado, self::SIN_LATIDO_SEGUNDOS);

        $salida = [
            'estado' => (string)$estado['estado'], 'lineas' => (int)($estado['lineas'] ?? 0), 'hojas' => (int)($estado['hojas'] ?? 1),
            'segundos' => max(0, time() - (int)$estado['creado']),
            'filtros' => (array)$estado['filtros'],
        ];
        if (isset($estado['error'])) {
            $salida['error'] = (string)$estado['error'];
        }

        return $salida;
    }

    /** Ruta local del .xlsx si el trabajo es del usuario y terminó; null si no. */
    public function rutaArchivo(int $empresaId, int $userId, string $token): ?string
    {
        $estado = $this->estado($empresaId, $userId, $token);
        if ($estado === null || $estado['estado'] !== 'listo') {
            return null;
        }

        return $this->store->storage()->localPath($this->store->key($empresaId, $this->store->tokenSeguro($token), 'libro', 'xlsx'));
    }

    /** Nombre sugerido del archivo descargado, p. ej. documentos_niif_2026-01-01_2026-09-30.xlsx */
    public static function nombreArchivo(array $filtros): string
    {
        $cuenta = match ($filtros['modo_cuenta'] ?? 'todas') {
            'inicia', 'igual' => '_cta' . $filtros['cuenta'],
            'entre' => '_cta' . $filtros['cuenta'] . '-' . $filtros['cuenta_hasta'],
            default => '',
        };

        return 'documentos_' . $filtros['contabilidad'] . '_' . $filtros['desde'] . '_' . $filtros['hasta'] . $cuenta . '.xlsx';
    }
}
