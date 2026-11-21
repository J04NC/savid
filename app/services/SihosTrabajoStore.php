<?php

/**
 * Ciclo de vida compartido por los trabajos SIHOS en segundo plano (Facturación,
 * Liquidación, Cartera2193, Órdenes vs liquidación): estado en storage privado
 * por empresa, lanzamiento del worker CLI vía nohup, chequeo de latido y purga
 * por TTL. Cada *JobService lo usa por composición con su propio prefijo; lo
 * que varía por reporte (archivos adicionales a purgar, forma de "estado",
 * llamada al service de dominio) se queda en el JobService.
 */
class SihosTrabajoStore
{
    private string $prefijo;
    private int $ttlSegundos;

    public function __construct(string $prefijo, int $ttlSegundos = 21600)
    {
        $this->prefijo = $prefijo;
        $this->ttlSegundos = $ttlSegundos;
    }

    public function storage(): StorageService
    {
        return StorageService::instance();
    }

    public function tokenSeguro(string $token): string
    {
        $safe = preg_replace('/[^a-f0-9]/', '', strtolower($token)) ?? '';

        return strlen($safe) === 32 ? $safe : '';
    }

    public function key(int $empresaId, string $token, string $parte, string $ext = 'json'): string
    {
        return $this->storage()->key(StorageService::ZONE_SIHOS_REPORTES, $this->prefijo . '_' . $token . '_' . $parte . '.' . $ext, $empresaId);
    }

    /** @return array<string,mixed>|null */
    public function leerEstado(int $empresaId, string $token): ?array
    {
        $raw = $this->storage()->get($this->key($empresaId, $token, 'estado'));
        $estado = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($estado) && (int)($estado['empresa_id'] ?? 0) === $empresaId ? $estado : null;
    }

    /** @param array<string,mixed> $estado */
    public function guardarEstado(int $empresaId, string $token, array $estado): void
    {
        $this->storage()->putContents($this->key($empresaId, $token, 'estado'), json_encode($estado, JSON_UNESCAPED_UNICODE) ?: '{}');
    }

    /** @param array<string,mixed> $estado */
    public function terminar(int $empresaId, string $token, array $estado, string $final, ?string $error = null): void
    {
        $estado['estado'] = $final;
        $estado['actualizado'] = time();
        if ($error !== null) {
            $estado['error'] = $error;
        }
        $this->guardarEstado($empresaId, $token, $estado);
    }

    /**
     * Si el trabajo sigue "pendiente"/"ejecutando" pero no actualizó su latido en
     * $sinLatidoSegundos, lo da por interrumpido. Devuelve el estado vigente
     * (el mismo si no hubo cambio, el recién terminado si sí).
     *
     * @param array<string,mixed> $estado
     * @return array<string,mixed>
     */
    public function verificarLatido(int $empresaId, string $token, array $estado, int $sinLatidoSegundos): array
    {
        if (in_array($estado['estado'], ['pendiente', 'ejecutando'], true) && time() - (int)$estado['actualizado'] > $sinLatidoSegundos) {
            $this->terminar($empresaId, $token, $estado, 'error', 'El proceso se interrumpió. Intente de nuevo.');

            return $this->leerEstado($empresaId, $token) ?? $estado;
        }

        return $estado;
    }

    /**
     * Borra el estado y los archivos relacionados de los trabajos ya expirados (más allá del TTL).
     *
     * @param list<string> $sufijosExtra sufijos de archivo a borrar junto con el estado (p. ej. '_resultado.json', '_detalle.csv')
     * @param list<string> $globsExtra   patrones glob adicionales a borrar, relativos al nombre del archivo de estado (p. ej. '_sel_*.csv')
     */
    public function purgarExpirados(int $empresaId, array $sufijosExtra = [], array $globsExtra = []): void
    {
        $storage = $this->storage();
        foreach ($storage->glob(StorageService::ZONE_SIHOS_REPORTES, $this->prefijo . '_*_estado.json', $empresaId) as $key) {
            $raw = $storage->get($key);
            $estado = is_string($raw) ? json_decode($raw, true) : null;
            $creado = is_array($estado) ? (int)($estado['creado'] ?? 0) : 0;
            if ($creado > 0 && time() - $creado > $this->ttlSegundos) {
                $storage->delete($key);
                foreach ($sufijosExtra as $sufijo) {
                    $storage->delete(str_replace('_estado.json', $sufijo, $key));
                }
                foreach ($globsExtra as $patron) {
                    foreach ($storage->glob(StorageService::ZONE_SIHOS_REPORTES, str_replace('_estado.json', $patron, basename($key)), $empresaId) as $extra) {
                        $storage->delete($extra);
                    }
                }
            }
        }
    }

    /** Lanza el script CLI del worker en segundo plano (el mismo proceso no espera su salida). */
    public function lanzarWorker(string $scriptPath, int $empresaId, string $token): void
    {
        $php = is_executable(PHP_BINDIR . '/php') ? PHP_BINDIR . '/php' : 'php';
        exec(sprintf(
            'nohup %s %s %d %s > /dev/null 2>&1 &',
            escapeshellarg($php),
            escapeshellarg($scriptPath),
            $empresaId,
            escapeshellarg($token)
        ));
    }
}
