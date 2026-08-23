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

        // La cookie de sesión vive SESSION_LIFETIME (24h por defecto, ver
        // SessionManager::start), pero el php.ini del sistema recolecta los
        // archivos de sesión a los 1440s (24 min). Con esa discordancia, tras
        // 24 minutos de inactividad el navegador seguía enviando una cookie
        // válida contra una sesión ya borrada: PHP abría una sesión vacía, sin
        // csrf_token, y toda petición POST moría con 403 en el middleware CSRF.
        // Se alinean ambos plazos para el driver de archivos.
        $ttl = max(60, (int)(getenv('SESSION_LIFETIME') ?: 86400));

        if ($driver !== 'redis') {
            ini_set('session.gc_maxlifetime', (string)$ttl);

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

        $auth =is_string($password) && $password !== '' ? '&auth=' . rawurlencode($password) : '';
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
