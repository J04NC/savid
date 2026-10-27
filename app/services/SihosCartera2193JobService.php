<?php

/**
 * Ejecución en segundo plano del reporte 2193 CARTERA: con todas las
 * entidades tarda minutos y Cloudflare corta a ~100 s (524). La petición solo
 * crea el trabajo y lanza scripts/sihos_cartera2193_job.php; estado y resultado
 * (agregado por deudor, sin datos de pacientes) viven en el storage privado
 * por empresa, solo los lee quien lo lanzó y se purgan pasadas TTL_SECONDS.
 */
class SihosCartera2193JobService
{
    private const PREFIJO = 'cartera2193';
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

    private function key(int $empresaId, string $token, string $parte): string
    {
        return $this->storage()->key(StorageService::ZONE_SIHOS_REPORTES, self::PREFIJO . '_' . $token . '_' . $parte . '.json', $empresaId);
    }

    /**
     * @param string[] $tiposUsuario
     * @return array{ok:bool,token?:string,error?:string}
     */
    public function iniciar(int $empresaId, int $userId, string $fechaCorte, array $tiposUsuario, ?string $nit): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaCorte)) {
            return ['ok' => false, 'error' => 'La fecha de corte es inválida.'];
        }

        $this->purgarExpirados($empresaId);

        $token = bin2hex(random_bytes(16));
        $this->guardarEstado($empresaId, $token, [
            'estado' => 'pendiente',
            'empresa_id' => $empresaId,
            'user_id' => $userId,
            'fecha_corte' => $fechaCorte,
            'tipos' => array_values($tiposUsuario),
            'nit' => $nit,
            'creado' => time(),
            'actualizado' => time(),
            'facturas' => 0,
        ]);

        $php = is_executable(PHP_BINDIR . '/php') ? PHP_BINDIR . '/php' : 'php';
        $comando = sprintf(
            'nohup %s %s %d %s > /dev/null 2>&1 &',
            escapeshellarg($php),
            escapeshellarg(BASE_PATH . '/scripts/sihos_cartera2193_job.php'),
            $empresaId,
            escapeshellarg($token)
        );
        exec($comando);

        return ['ok' => true, 'token' => $token];
    }

    /** Lo llama el script CLI: calcula el reporte y deja el resultado en el storage. */
    public function ejecutar(int $empresaId, string $token): void
    {
        $token = $this->tokenSeguro($token);
        $estado = $token !== '' ? $this->leerEstado($empresaId, $token) : null;
        if ($estado === null || $estado['estado'] !== 'pendiente') {
            return;
        }

        $estado['estado'] = 'ejecutando';
        $this->guardarEstado($empresaId, $token, $estado);

        try {
            $ultimoLatido = 0;
            $reporte = (new SihosCartera2193Service())->buildReporte(
                $empresaId,
                (string)$estado['fecha_corte'],
                (array)$estado['tipos'],
                $estado['nit'],
                function (int $facturas) use (&$estado, $empresaId, $token, &$ultimoLatido): void {
                    $ahora = time();
                    if ($ahora - $ultimoLatido >= 2) {
                        $ultimoLatido = $ahora;
                        $estado['facturas'] = $facturas;
                        $estado['actualizado'] = $ahora;
                        $this->guardarEstado($empresaId, $token, $estado);
                    }
                }
            );

            if (!$reporte['ok']) {
                $this->terminar($empresaId, $token, $estado, 'error', (string)$reporte['error']);

                return;
            }

            $this->storage()->putContents($this->key($empresaId, $token, 'resultado'), json_encode($reporte, JSON_UNESCAPED_UNICODE) ?: '{}');
            $estado['facturas'] = (int)$reporte['facturas'];
            $this->terminar($empresaId, $token, $estado, 'listo');
        } catch (Throwable $e) {
            error_log('SihosCartera2193JobService: ' . $e->getMessage());
            $this->terminar($empresaId, $token, $estado, 'error', 'No se pudo generar el reporte. Intente de nuevo.');
        }
    }

    /**
     * Estado visible para el usuario dueño del trabajo (null si no existe o no es suyo).
     *
     * @return array{estado:string,facturas:int,segundos:int,error?:string}|null
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
            'estado' => (string)$estado['estado'],
            'facturas' => (int)($estado['facturas'] ?? 0),
            'segundos' => max(0, time() - (int)$estado['creado']),
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
            }
        }
    }
}
