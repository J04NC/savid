<?php

/**
 * Ejecución en segundo plano del reporte SIHOS "Órdenes vs liquidación" (mismo
 * patrón que SihosLiquidacionJobService: Cloudflare corta a ~100 s). La
 * petición crea el trabajo y lanza scripts/sihos_ordenes_job.php; estado,
 * agregados y el CSV de detalle viven en el storage privado por empresa, solo
 * los descarga quien lo lanzó y se purgan pasadas TTL_SECONDS. Sin datos del paciente.
 */
class SihosOrdenLiquidacionJobService
{
    private const PREFIJO = 'ordenes';
    /** Sin latido durante este tiempo el trabajo se da por interrumpido. */
    private const SIN_LATIDO_SEGUNDOS = 300;

    private SihosTrabajoStore $store;

    public function __construct()
    {
        $this->store = new SihosTrabajoStore(self::PREFIJO);
    }

    /**
     * @param list<string> $actividades vacío = todas
     * @param list<string> $eps CodiAdmi; vacío = todas
     * @return array{ok:bool,token?:string,error?:string}
     */
    public function iniciar(int $empresaId, int $userId, string $desde, string $hasta, array $actividades, array $eps): array
    {
        $error = SihosLiquidacionService::validarRango($desde, $hasta);
        if ($error !== null) {
            return ['ok' => false, 'error' => $error];
        }

        $this->store->purgarExpirados($empresaId, ['_resultado.json', '_detalle.csv']);

        $token = bin2hex(random_bytes(16));
        $this->store->guardarEstado($empresaId, $token, [
            'estado' => 'pendiente', 'empresa_id' => $empresaId, 'user_id' => $userId, 'desde' => $desde, 'hasta' => $hasta,
            'actividades' => array_values(array_intersect($actividades, SihosLiquidacionClasificador::ACTIVIDADES)),
            'eps' => array_values(array_filter(array_map('trim', $eps), static fn (string $e): bool => $e !== '')),
            'creado' => time(), 'actualizado' => time(), 'lineas' => 0,
        ]);

        $this->store->lanzarWorker(BASE_PATH . '/scripts/sihos_ordenes_job.php', $empresaId, $token);

        return ['ok' => true, 'token' => $token];
    }

    /** Lo llama el script CLI: genera el CSV y deja agregados y archivo en el storage. */
    public function ejecutar(int $empresaId, string $token): void
    {
        $token = $this->store->tokenSeguro($token);
        $estado = $token !== '' ? $this->store->leerEstado($empresaId, $token) : null;
        if ($estado === null || $estado['estado'] !== 'pendiente') {
            return;
        }
        $estado['estado'] = 'ejecutando';
        $this->store->guardarEstado($empresaId, $token, $estado);

        $tmp = tempnam(sys_get_temp_dir(), 'ord');
        try {
            $fh = fopen($tmp, 'w');
            // BOM + "sep=;": ver la explicación en SihosController::auditoriaGlosaExportar().
            fwrite($fh, "\xEF\xBB\xBFsep=;\r\n");

            $ultimoLatido = 0;
            $reporte = (new SihosOrdenLiquidacionService())->generar(
                $empresaId, (string)$estado['desde'], (string)$estado['hasta'], (array)$estado['actividades'], (array)$estado['eps'], $fh,
                function (int $lineas) use (&$estado, $empresaId, $token, &$ultimoLatido): void {
                    if (time() - $ultimoLatido >= 2) {
                        $ultimoLatido = time();
                        $estado['lineas'] = $lineas;
                        $estado['actualizado'] = $ultimoLatido;
                        $this->store->guardarEstado($empresaId, $token, $estado);
                    }
                }
            );
            fclose($fh);

            if (!$reporte['ok']) {
                $this->store->terminar($empresaId, $token, $estado, 'error', (string)$reporte['error']);

                return;
            }
            if ($this->store->storage()->putLocalFile(StorageService::ZONE_SIHOS_REPORTES, self::PREFIJO . '_' . $token . '_detalle.csv', $tmp, true, $empresaId) === null) {
                throw new RuntimeException('No se pudo guardar el archivo de detalle.');
            }
            $this->store->storage()->putContents($this->store->key($empresaId, $token, 'resultado'), json_encode($reporte, JSON_UNESCAPED_UNICODE) ?: '{}');
            $estado['lineas'] = (int)$reporte['lineas'];
            $this->store->terminar($empresaId, $token, $estado, 'listo');
        } catch (Throwable $e) {
            error_log('SihosOrdenLiquidacionJobService: ' . $e->getMessage());
            $this->store->terminar($empresaId, $token, $estado, 'error', 'No se pudo generar el reporte. Intente de nuevo.');
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Estado visible para el usuario dueño del trabajo (null si no existe o no es suyo).
     *
     * @return array{estado:string,lineas:int,segundos:int,desde:string,hasta:string,error?:string}|null
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
            'estado' => (string)$estado['estado'], 'lineas' => (int)($estado['lineas'] ?? 0),
            'segundos' => max(0, time() - (int)$estado['creado']),
            'desde' => (string)$estado['desde'], 'hasta' => (string)$estado['hasta'],
        ];
        if (isset($estado['error'])) {
            $salida['error'] = (string)$estado['error'];
        }

        return $salida;
    }

    /** @return array<string,mixed>|null */
    public function resultado(int $empresaId, int $userId, string $token): ?array
    {
        $estado = $this->estado($empresaId, $userId, $token);
        if ($estado === null || $estado['estado'] !== 'listo') {
            return null;
        }

        $raw = $this->store->storage()->get($this->store->key($empresaId, $this->store->tokenSeguro($token), 'resultado'));
        $reporte = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($reporte) ? $reporte : null;
    }

    /** Ruta local del CSV de detalle si el trabajo es del usuario y terminó; null si no. */
    public function rutaArchivo(int $empresaId, int $userId, string $token): ?string
    {
        $estado = $this->estado($empresaId, $userId, $token);
        if ($estado === null || $estado['estado'] !== 'listo') {
            return null;
        }

        return $this->store->storage()->localPath($this->store->key($empresaId, $this->store->tokenSeguro($token), 'detalle', 'csv'));
    }
}
