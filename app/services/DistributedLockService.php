<?php

/**
 * Bloqueo para tareas que solo deben ejecutarse en un nodo (cron, archivos, etc.).
 * file = flock en disco (válido con NFS compartido); redis = SET NX entre VPS.
 */
class DistributedLockService
{
    /** @var resource|null */
    private static $fileHandle = null;

    private static ?string $fileLockName = null;

    public static function acquire(string $name, int $ttlSeconds = 300): bool
    {
        $driver = strtolower(trim((string)(getenv('LOCK_DRIVER') ?: 'file')));

        if ($driver === 'redis' && extension_loaded('redis')) {
            return self::acquireRedis($name, $ttlSeconds);
        }

        return self::acquireFile($name);
    }

    public static function release(string $name): void
    {
        $driver = strtolower(trim((string)(getenv('LOCK_DRIVER') ?: 'file')));

        if ($driver === 'redis' && extension_loaded('redis')) {
            self::releaseRedis($name);

            return;
        }

        self::releaseFile($name);
    }

    private static function acquireFile(string $name): bool
    {
        $safe = preg_replace('/[^a-z0-9_-]/', '_', strtolower($name)) ?? 'lock';
        $dir = self::lockDirectory();
        $path = $dir . '/' . $safe . '.lock';

        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            return false;
        }

        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        self::$fileHandle = $handle;
        self::$fileLockName = $name;

        return true;
    }

    private static function releaseFile(string $name): void
    {
        if (self::$fileHandle === null || self::$fileLockName !== $name) {
            return;
        }

        @flock(self::$fileHandle, LOCK_UN);
        fclose(self::$fileHandle);
        self::$fileHandle = null;
        self::$fileLockName = null;
    }

    private static function acquireRedis(string $name, int $ttlSeconds): bool
    {
        $redis = self::redisConnection();
        if ($redis === null) {
            return self::acquireFile($name);
        }

        $key = self::redisKey($name);
        $token = bin2hex(random_bytes(16));
        $ok = (bool)$redis->set($key, $token, ['nx', 'ex' => max(30, $ttlSeconds)]);
        if ($ok) {
            self::$fileLockName = $name . ':' . $token;
        }

        return $ok;
    }

    private static function releaseRedis(string $name): void
    {
        if (self::$fileLockName === null || !str_starts_with(self::$fileLockName, $name . ':')) {
            return;
        }

        $redis = self::redisConnection();
        if ($redis !== null) {
            $redis->del(self::redisKey($name));
        }

        self::$fileLockName = null;
    }

    private static function lockDirectory(): string
    {
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__);
        $dir = getenv('LOCK_FILE_DIR') ?: ($base . '/storage/locks');
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('No se pudo crear el directorio de locks: ' . $dir);
        }

        return $dir;
    }

    private static function redisKey(string $name): string
    {
        $prefix = getenv('REDIS_LOCK_PREFIX') ?: 'savid:lock:';
        $safe = preg_replace('/[^a-z0-9_-]/', '_', strtolower($name)) ?? 'lock';

        return $prefix . $safe;
    }

    private static function redisConnection(): ?object
    {
        if (!class_exists('Redis', false)) {
            return null;
        }

        try {
            $redis = new Redis();
            $host = getenv('REDIS_HOST') ?: '127.0.0.1';
            $port = (int)(getenv('REDIS_PORT') ?: 6379);
            if (!$redis->connect($host, $port, 2.0)) {
                return null;
            }
            $password = getenv('REDIS_PASSWORD');
            if (is_string($password) && $password !== '') {
                $redis->auth($password);
            }
            $db = (int)(getenv('REDIS_LOCK_DB') ?: getenv('REDIS_SESSION_DB') ?: 0);
            if ($db > 0) {
                $redis->select($db);
            }

            return $redis;
        } catch (Throwable) {
            return null;
        }
    }
}
