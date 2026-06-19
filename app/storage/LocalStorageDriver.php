<?php

class LocalStorageDriver implements StorageDriverInterface
{
    private string $publicRoot;

    private string $privateRoot;

    /** @var list<string> */
    private array $privatePrefixes = ['sgd_imports/'];

    public function __construct(?string $publicRoot = null, ?string $privateRoot = null)
    {
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        $this->publicRoot = rtrim($publicRoot ?: (getenv('STORAGE_PUBLIC_ROOT') ?: $base . '/public/uploads'), '/');
        $this->privateRoot = rtrim($privateRoot ?: (getenv('STORAGE_PRIVATE_ROOT') ?: $base . '/storage'), '/');
    }

    public function putContents(string $key, string $contents): void
    {
        $path = $this->localPath($key);
        $this->ensureParentDir($path);
        if (@file_put_contents($path, $contents) === false) {
            throw new RuntimeException('No se pudo guardar el archivo: ' . $key);
        }
    }

    public function putFile(string $key, string $localSourcePath, bool $move = true): void
    {
        if (!is_readable($localSourcePath)) {
            throw new RuntimeException('Archivo origen no legible.');
        }

        $path = $this->localPath($key);
        $this->ensureParentDir($path);

        $moved = $move && @rename($localSourcePath, $path);
        if ($moved) {
            return;
        }

        if (@copy($localSourcePath, $path) === false) {
            throw new RuntimeException('No se pudo copiar el archivo: ' . $key);
        }

        if ($move) {
            @unlink($localSourcePath);
        }
    }

    public function get(string $key): ?string
    {
        $path = $this->localPath($key);
        if (!is_readable($path) || !is_file($path)) {
            return null;
        }

        $data = @file_get_contents($path);

        return is_string($data) ? $data : null;
    }

    public function exists(string $key): bool
    {
        $path = $this->localPath($key);

        return is_file($path);
    }

    public function delete(string $key): bool
    {
        $path = $this->localPath($key);
        if (!is_file($path)) {
            return false;
        }

        return @unlink($path);
    }

    public function localPath(string $key): string
    {
        $key = $this->normalizeKey($key);
        $root = $this->isPrivateKey($key) ? $this->privateRoot : $this->publicRoot;

        return $root . '/' . $key;
    }

    public function listKeys(string $prefix, string $globPattern = '*'): array
    {
        $prefix = $this->normalizeKey($prefix);
        $dir = rtrim($this->localPath($prefix), '/');
        if (!is_dir($dir)) {
            return [];
        }

        $pattern = $dir . '/' . ltrim($globPattern, '/');
        $files = glob($pattern) ?: [];
        $keys = [];
        $root = $this->isPrivateKey($prefix) ? $this->privateRoot : $this->publicRoot;
        $rootPrefix = rtrim($root, '/') . '/';

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file, strlen($rootPrefix)));
            if ($relative !== '') {
                $keys[] = $relative;
            }
        }

        sort($keys);

        return $keys;
    }

    public function localDirectory(string $keyPrefix): string
    {
        $keyPrefix = $this->normalizeKey($keyPrefix);
        $dir = rtrim($this->localPath($keyPrefix), '/');
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('No se pudo crear el directorio de almacenamiento.');
        }

        return $dir;
    }

    public function publicUrl(string $key): ?string
    {
        $key = $this->normalizeKey($key);
        if ($this->isPrivateKey($key)) {
            return null;
        }

        if (!str_starts_with($key, 'sgd/')
            && !str_starts_with($key, 'empresas/')
            && !str_starts_with($key, 'usuarios/')) {
            return null;
        }

        return '/uploads/' . $key;
    }

    public function publicRoot(): string
    {
        return $this->publicRoot;
    }

    public function privateRoot(): string
    {
        return $this->privateRoot;
    }

    private function normalizeKey(string $key): string
    {
        $key = str_replace('\\', '/', trim($key));
        $key = ltrim($key, '/');

        if (str_contains($key, '..')) {
            throw new InvalidArgumentException('Clave de almacenamiento no válida.');
        }

        return $key;
    }

    private function isPrivateKey(string $key): bool
    {
        foreach ($this->privatePrefixes as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function ensureParentDir(string $path): void
    {
        $dir = dirname($path);
        if (is_dir($dir)) {
            return;
        }
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('No se pudo crear el directorio: ' . $dir);
        }
    }
}
