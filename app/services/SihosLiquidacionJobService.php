<?php

/**
 * Ejecución en segundo plano del reporte SIHOS "Liquidación": un rango largo
 * son cientos de miles de líneas y Cloudflare corta a ~100 s (524). La petición
 * solo crea el trabajo y lanza scripts/sihos_liquidacion_job.php; estado,
 * agregados y los dos CSV viven en el storage privado por empresa (contienen
 * nombre y cédula de profesionales, nunca datos de pacientes), solo los
 * descarga quien lo lanzó y se purgan pasadas TTL_SECONDS.
 */
class SihosLiquidacionJobService
{
    private const PREFIJO = 'liquidacion';
    private const TTL_SECONDS = 21600;
    /** Sin latido durante este tiempo el trabajo se da por interrumpido. */
    private const SIN_LATIDO_SEGUNDOS = 300;
    private const ARCHIVOS = ['detalle', 'resumen', 'especialistas'];

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

    /** @return array{ok:bool,token?:string,error?:string} */
    public function iniciar(int $empresaId, int $userId, string $desde, string $hasta): array
    {
        $error = SihosLiquidacionService::validarRango($desde, $hasta);
        if ($error !== null) {
            return ['ok' => false, 'error' => $error];
        }

        $this->purgarExpirados($empresaId);

        $token = bin2hex(random_bytes(16));
        $this->guardarEstado($empresaId, $token, [
            'estado' => 'pendiente', 'empresa_id' => $empresaId, 'user_id' => $userId,
            'desde' => $desde, 'hasta' => $hasta, 'creado' => time(), 'actualizado' => time(), 'lineas' => 0,
        ]);

        $php = is_executable(PHP_BINDIR . '/php') ? PHP_BINDIR . '/php' : 'php';
        exec(sprintf(
            'nohup %s %s %d %s > /dev/null 2>&1 &',
            escapeshellarg($php),
            escapeshellarg(BASE_PATH . '/scripts/sihos_liquidacion_job.php'),
            $empresaId,
            escapeshellarg($token)
        ));

        return ['ok' => true, 'token' => $token];
    }

    /** Lo llama el script CLI: genera los CSV y deja agregados y archivos en el storage. */
    public function ejecutar(int $empresaId, string $token): void
    {
        $token = $this->tokenSeguro($token);
        $estado = $token !== '' ? $this->leerEstado($empresaId, $token) : null;
        if ($estado === null || $estado['estado'] !== 'pendiente') {
            return;
        }
        $estado['estado'] = 'ejecutando';
        $this->guardarEstado($empresaId, $token, $estado);

        $tmp = [];
        foreach (self::ARCHIVOS as $parte) {
            $tmp[$parte] = tempnam(sys_get_temp_dir(), 'liq');
        }
        try {
            $fh = [];
            foreach ($tmp as $parte => $ruta) {
                $fh[$parte] = fopen($ruta, 'w');
                // BOM + "sep=;": ver la explicación en SihosController::auditoriaGlosaExportar().
                fwrite($fh[$parte], "\xEF\xBB\xBF" . "sep=;\r\n");
            }

            $ultimoLatido = 0;
            $reporte = (new SihosLiquidacionService())->generar(
                $empresaId, (string)$estado['desde'], (string)$estado['hasta'], $fh['detalle'], $fh['resumen'], $fh['especialistas'],
                function (int $lineas) use (&$estado, $empresaId, $token, &$ultimoLatido): void {
                    if (time() - $ultimoLatido >= 2) {
                        $ultimoLatido = time();
                        $estado['lineas'] = $lineas;
                        $estado['actualizado'] = $ultimoLatido;
                        $this->guardarEstado($empresaId, $token, $estado);
                    }
                }
            );
            foreach ($fh as $h) {
                fclose($h);
            }

            if (!$reporte['ok']) {
                $this->terminar($empresaId, $token, $estado, 'error', (string)$reporte['error']);

                return;
            }

            foreach (self::ARCHIVOS as $parte) {
                if ($this->storage()->putLocalFile(StorageService::ZONE_SIHOS_REPORTES, self::PREFIJO . '_' . $token . '_' . $parte . '.csv', $tmp[$parte], true, $empresaId) === null) {
                    throw new RuntimeException('No se pudo guardar el archivo ' . $parte . '.');
                }
            }
            $this->storage()->putContents($this->key($empresaId, $token, 'resultado'), json_encode($reporte, JSON_UNESCAPED_UNICODE) ?: '{}');
            $estado['lineas'] = (int)$reporte['lineas'];
            $this->terminar($empresaId, $token, $estado, 'listo');
        } catch (Throwable $e) {
            error_log('SihosLiquidacionJobService: ' . $e->getMessage());
            $this->terminar($empresaId, $token, $estado, 'error', 'No se pudo generar el reporte. Intente de nuevo.');
        } finally {
            foreach ($tmp as $ruta) {
                @unlink($ruta);
            }
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

    /** Ruta local del CSV ('detalle' o 'resumen') si el trabajo es del usuario y terminó; null si no. */
    public function rutaArchivo(int $empresaId, int $userId, string $token, string $parte): ?string
    {
        $estado = $this->estado($empresaId, $userId, $token);
        if ($estado === null || $estado['estado'] !== 'listo' || !in_array($parte, self::ARCHIVOS, true)) {
            return null;
        }

        return $this->storage()->localPath($this->key($empresaId, $this->tokenSeguro($token), $parte, 'csv'));
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

    private function purgarExpirados(int $empresaId): void
    {
        $storage = $this->storage();
        foreach ($storage->glob(StorageService::ZONE_SIHOS_REPORTES, self::PREFIJO . '_*_estado.json', $empresaId) as $key) {
            $raw = $storage->get($key);
            $estado = is_string($raw) ? json_decode($raw, true) : null;
            $creado = is_array($estado) ? (int)($estado['creado'] ?? 0) : 0;
            if ($creado > 0 && time() - $creado > self::TTL_SECONDS) {
                $storage->delete($key);
                $storage->delete(str_replace('_estado.json', '_resultado.json', $key));
                foreach (self::ARCHIVOS as $parte) {
                    $storage->delete(str_replace('_estado.json', '_' . $parte . '.csv', $key));
                }
            }
        }
    }
}
