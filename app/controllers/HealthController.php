<?php

/**
 * Endpoint para balanceadores y monitoreo (?url=health o ?url=health/index).
 * No requiere sesión.
 */
class HealthController
{
    public function index(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        $checks = [
            'app' => ['ok' => true, 'message' => 'SAVID'],
            'database' => $this->checkDatabase(),
            'storage' => $this->checkStorage(),
            'session' => $this->checkSession(),
            'redis' => $this->checkRedis(),
        ];

        $critical = ['database', 'storage'];
        $healthy = true;
        foreach ($critical as $name) {
            if (empty($checks[$name]['ok'])) {
                $healthy = false;
                break;
            }
        }

        http_response_code($healthy ? 200 : 503);
        echo json_encode([
            'status' => $healthy ? 'ok' : 'degraded',
            'time' => date('c'),
            'checks' => $checks,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function checkDatabase(): array
    {
        try {
            $pdo = (new Database())->connect();
            $pdo->query('SELECT 1');

            return ['ok' => true, 'message' => 'Conexión MySQL activa'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'MySQL: ' . $e->getMessage()];
        }
    }

    /**
     * @return array{ok: bool, message: string, driver?: string}
     */
    private function checkStorage(): array
    {
        try {
            $storage = StorageService::instance();
            $driver = getenv('STORAGE_DRIVER') ?: 'local';
            $testKey = 'empresas/.health_' . bin2hex(random_bytes(4)) . '.txt';
            $storage->putContents($testKey, 'ok');
            $ok = $storage->get($testKey) === 'ok';
            $storage->delete($testKey);

            return [
                'ok' => $ok,
                'message' => $ok ? 'Escritura de archivos OK' : 'No se pudo verificar almacenamiento',
                'driver' => $driver,
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Storage: ' . $e->getMessage()];
        }
    }

    /**
     * @return array{ok: bool, message: string, driver?: string}
     */
    private function checkSession(): array
    {
        $driver = getenv('SESSION_DRIVER') ?: 'files';
        if ($driver !== 'redis') {
            return ['ok' => true, 'message' => 'Sesiones en archivos (single-node)', 'driver' => 'files'];
        }

        if (!extension_loaded('redis')) {
            return ['ok' => false, 'message' => 'SESSION_DRIVER=redis sin extensión php-redis', 'driver' => 'redis'];
        }

        return ['ok' => true, 'message' => 'Sesiones Redis configuradas', 'driver' => 'redis'];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function checkRedis(): array
    {
        if (!extension_loaded('redis') || !class_exists('Redis', false)) {
            return ['ok' => true, 'message' => 'Redis no requerido en este nodo'];
        }

        $host = getenv('REDIS_HOST') ?: '';
        if ($host === '') {
            return ['ok' => true, 'message' => 'Redis no configurado'];
        }

        try {
            $redis = new Redis();
            $port = (int)(getenv('REDIS_PORT') ?: 6379);
            if (!$redis->connect($host, $port, 1.5)) {
                return ['ok' => false, 'message' => 'No se pudo conectar a Redis'];
            }
            $password = getenv('REDIS_PASSWORD');
            if (is_string($password) && $password !== '') {
                $redis->auth($password);
            }
            $pong = $redis->ping();

            return ['ok' => $pong !== false, 'message' => 'Redis responde'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Redis: ' . $e->getMessage()];
        }
    }
}
