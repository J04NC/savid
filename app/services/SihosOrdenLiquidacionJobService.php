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
    private const TTL_SECONDS = 21600;
    /** Sin latido durante este tiempo el trabajo se da por interrumpido. */
    private const SIN_LATIDO_SEGUNDOS = 300;

    private function storage(): StorageService
    {
        return StorageService::instance();
    }

    private function tokenSeguro(string $token): string
    {
        $safe = preg_replace('/[^a-f0-9]/', '', strtolower($token)) ?? '';

        return strlen($safe) === 32 ? $safe : '';
    }

    private function key(int $empresaId, string $token, string $parte, string $ext = 'json'): string
    {
        return $this->storage()->key(StorageService::ZONE_SIHOS_REPORTES, self::PREFIJO . '_' . $token . '_' . $parte . '.' . $ext, $empresaId);
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

        $token = bin2hex(random_bytes(16));
        $this->guardarEstado($empresaId, $token, [
            'estado' => 'pendiente', 'empresa_id' => $empresaId, 'user_id' => $userId, 'desde' => $desde, 'hasta' => $hasta,
            'actividades' => array_values(array_intersect($actividades, SihosLiquidacionClasificador::ACTIVIDADES)),
            'eps' => array_values(array_filter(array_map('trim', $eps), static fn (string $e): bool => $e !== '')),
            'creado' => time(), 'actualizado' => time(), 'lineas' => 0,
        ]);

        $php = is_executable(PHP_BINDIR . '/php') ? PHP_BINDIR . '/php' : 'php';
        exec(sprintf(
            'nohup %s %s %d %s > /dev/null 2>&1 &',
            escapeshellarg($php),
            escapeshellarg(BASE_PATH . '/scripts/sihos_ordenes_job.php'),
            $empresaId,
            escapeshellarg($token)
        ));

        return ['ok' => true, 'token' => $token];
    }

    /** Lo llama el script CLI: genera el CSV y deja agregados y archivo en el storage. */
    public function ejecutar(int $empresaId, string $token): void
    {
        $token = $this->tokenSeguro($token);
        $estado = $token !== '' ? $this->leerEstado($empresaId, $token) : null;
        if ($estado === null || $estado['estado'] !== 'pendiente') {
            return;
        }
        $estado['estado'] = 'ejecutando';
        $this->guardarEstado($empresaId, $token, $estado);

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
                        $this->guardarEstado($empresaId, $token, $estado);
                    }
                }
            );
            fclose($fh);

            if (!$reporte['ok']) {
                $this->terminar($empresaId, $token, $estado, 'error', (string)$reporte['error']);

                return;
            }
            if ($this->storage()->putLocalFile(StorageService::ZONE_SIHOS_REPORTES, self::PREFIJO . '_' . $token . '_detalle.csv', $tmp, true, $empresaId) === null) {
                throw new RuntimeException('No se pudo guardar el archivo de detalle.');
            }
            $this->storage()->putContents($this->key($empresaId, $token, 'resultado'), json_encode($reporte, JSON_UNESCAPED_UNICODE) ?: '{}');
            $estado['lineas'] = (int)$reporte['lineas'];
            $this->terminar($empresaId, $token, $estado, 'listo');
        } catch (Throwable $e) {
            error_log('SihosOrdenLiquidacionJobService: ' . $e->getMessage());
            $this->terminar($empresaId, $token, $estado, 'error', 'No se pudo generar el reporte. Intente de nuevo.');
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
        $token = $this->tokenSeguro($token);
        $estado = $token !== '' ? $this->leerEstado($empresaId, $token) : null;
        if ($estado === null || (int)$estado['user_id'] !== $userId) {
            return null;
        }

        if (in_array($estado['estado'], ['pendiente', 'ejecutando'], true) && time() - (int)$estado['actualizado'] > self::SIN_LATIDO_SEGUNDOS) {
            $this->terminar($empresaId, $token, $estado, 'error', 'El proceso se interrumpió. Intente de nuevo.');
            $estado = $this->leerEstado($empresaId, $token) ?? $estado;
        }

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

        $raw = $this->storage()->get($this->key($empresaId, $this->tokenSeguro($token), 'resultado'));
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

        return $this->storage()->localPath($this->key($empresaId, $this->tokenSeguro($token), 'detalle', 'csv'));
    }

    /** @param array<string,mixed> $estado */
    private function terminar(int $empresaId, string $token, array $estado, string $final, ?string $error = null): void
    {
        $estado['estado'] = $final;
        $estado['actualizado'] = time();
        if ($error !== null) {
            $estado['error'] = $error;
        }
        $this->guardarEstado($empresaId, $token, $estado);
    }

    /** @return array<string,mixed>|null */
    private function leerEstado(int $empresaId, string $token): ?array
    {
        $raw = $this->storage()->get($this->key($empresaId, $token, 'estado'));
        $estado = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($estado) && (int)($estado['empresa_id'] ?? 0) === $empresaId ? $estado : null;
    }

    /** @param array<string,mixed> $estado */
    private function guardarEstado(int $empresaId, string $token, array $estado): void
    {
        $this->storage()->putContents($this->key($empresaId, $token, 'estado'), json_encode($estado, JSON_UNESCAPED_UNICODE) ?: '{}');
    }
}
