<?php

/**
 * Configura el handler de sesión PHP antes de session_start().
 * files = disco local (default); redis = compartido entre varios VPS.
 */
class SessionConfigurator
{
    public static function apply(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        $driver = strtolower(trim((string)(getenv('SESSION_DRIVER') ?: 'files')));

        if ($driver !== 'redis') {
            return;
        }

        if (!extension_loaded('redis')) {
            error_log('SESSION_DRIVER=redis pero la extensión php-redis no está instalada. Usando sesiones en archivos.');

            return;
        }

        $host = getenv('REDIS_HOST') ?: '127.0.0.1';
        $port = (int)(getenv('REDIS_PORT') ?: 6379);
        $password = getenv('REDIS_PASSWORD');
        $database = (int)(getenv('REDIS_SESSION_DB') ?: 0);
        $prefix = getenv('REDIS_SESSION_PREFIX') ?: 'savid:sess:';
        $ttl = max(60, (int)(getenv('SESSION_LIFETIME') ?: 86400));

        $auth = is_string($password) && $password !== '' ? '&auth=' . rawurlencode($password) : '';
        $savePath = sprintf(
            'tcp://%s:%d?database=%d&prefix=%s&timeout=2%s',
            $host,
            $port,
            $database,
            rawurlencode($prefix),
            $auth
        );

        ini_set('session.save_handler', 'redis');
        ini_set('session.save_path', $savePath);
        ini_set('session.gc_maxlifetime', (string)$ttl);
    }
}
