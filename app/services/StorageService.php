<?php

/**
 * Punto único de lectura/escritura de archivos de la aplicación.
 * Las rutas públicas (/uploads/...) se conservan en BD y vistas; internamente se usan storage keys.
 */
class StorageService
{
    public const ZONE_SGD = 'sgd';

    public const ZONE_SGD_MEDIA = 'sgd_media';

    public const ZONE_EMPRESAS = 'empresas';

    public const ZONE_USUARIOS = 'usuarios';

    public const ZONE_SGD_IMPORTS = 'sgd_imports';

    private static ?self $instance = null;

    private StorageDriverInterface $driver;

    /** @var array<string, array{visibility: string, path: callable}> */
    private array $zones = [];

    public function __construct(StorageDriverInterface $driver)
    {
        $this->driver = $driver;
        $this->registerZones();
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = self::createFromEnv();
        }

        return self::$instance;
    }

    public static function setInstance(?self $instance): void
    {
        self::$instance = $instance;
    }

    public static function createFromEnv(): self
    {
        $driverName = strtolower(trim((string)(getenv('STORAGE_DRIVER') ?: 'local')));

        try {
            $driver = match ($driverName) {
                's3' => new S3StorageDriver(),
                'local' => new LocalStorageDriver(),
                default => new LocalStorageDriver(),
            };
        } catch (RuntimeException $e) {
            error_log('StorageService: ' . $e->getMessage() . ' — usando driver local.');
            $driver = new LocalStorageDriver();
        }

        return new self($driver);
    }

    /**
     * @param int|string ...$segments
     */
    public function key(string $zone, string $filename, int|string ...$segments): string
    {
        $zoneDef = $this->zone($zone);
        $parts = ($zoneDef['path'])($segments);
        if ($filename !== '') {
            $parts[] = $filename;
        }

        return implode('/', $parts);
    }

    public function publicUrl(string $key): string
    {
        $key = $this->normalizeIncoming($key);
        $url = $this->driver->publicUrl($key);
        if ($url !== null) {
            return $url;
        }

        if ($this->isPrivateKey($key)) {
            return $this->driver->localPath($key);
        }

        throw new InvalidArgumentException('La clave no tiene URL pública: ' . $key);
    }

    public function publicUrlToKey(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $url = '/' . ltrim(str_replace('\\', '/', trim($url)), '/');
        if (str_starts_with($url, '/uploads/')) {
            return ltrim(substr($url, strlen('/uploads/')), '/');
        }

        return null;
    }

    /**
     * @return string URL pública (/uploads/...) o ruta local absoluta si la zona es privada
     */
    public function putContents(string $key, string $contents): string
    {
        $key = $this->normalizeIncoming($key);
        $this->driver->putContents($key, $contents);

        return $this->reference($key);
    }

    /**
     * @param array<string, mixed> $file elemento de $_FILES
     * @param int|string ...$segments
     */
    public function putUploadedFile(string $zone, string $filename, array $file, int|string ...$segments): ?string
    {
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return null;
        }

        $key = $this->key($zone, $filename, ...$segments);

        try {
            $this->driver->putFile($key, $tmp, true);
        } catch (RuntimeException) {
            return null;
        }

        return $this->reference($key);
    }

    /**
     * @param int|string ...$segments
     */
    public function putLocalFile(
        string $zone,
        string $filename,
        string $localSourcePath,
        bool $move = true,
        int|string ...$segments
    ): ?string {
        if (!is_readable($localSourcePath)) {
            return null;
        }

        $key = $this->key($zone, $filename, ...$segments);

        try {
            $this->driver->putFile($key, $localSourcePath, $move);
        } catch (RuntimeException) {
            return null;
        }

        return $this->reference($key);
    }

    public function get(string $keyOrUrl): ?string
    {
        $key = $this->resolveKey($keyOrUrl);
        if ($key === null) {
            return null;
        }

        return $this->driver->get($key);
    }

    public function exists(string $keyOrUrl): bool
    {
        $key = $this->resolveKey($keyOrUrl);
        if ($key === null) {
            return false;
        }

        return $this->driver->exists($key);
    }

    public function delete(string $keyOrUrl): bool
    {
        $key = $this->resolveKey($keyOrUrl);
        if ($key === null) {
            return false;
        }

        return $this->driver->delete($key);
    }

    public function localPath(string $keyOrUrl): ?string
    {
        $key = $this->resolveKey($keyOrUrl);
        if ($key === null) {
            return null;
        }

        return $this->driver->localPath($key);
    }

    /**
     * @param int|string ...$segments
     */
    public function localDirectory(string $zone, int|string ...$segments): string
    {
        $prefix = $this->key($zone, '', ...$segments);
        if ($this->driver instanceof LocalStorageDriver) {
            return $this->driver->localDirectory($prefix);
        }
        if ($this->driver instanceof S3StorageDriver) {
            return $this->driver->localDirectory($prefix);
        }

        $path = rtrim($this->driver->localPath($prefix), '/');
        if (!is_dir($path) && !@mkdir($path, 0755, true) && !is_dir($path)) {
            throw new RuntimeException('No se pudo crear el directorio de almacenamiento.');
        }

        return $path;
    }

    /**
     * @param int|string ...$segments
     * @return list<string>
     */
    public function glob(string $zone, string $globPattern, int|string ...$segments): array
    {
        $prefix = $this->key($zone, '', ...$segments);

        return $this->driver->listKeys($prefix, $globPattern);
    }

    public function isPublicReference(string $reference): bool
    {
        return str_starts_with($reference, '/uploads/');
    }

    /**
     * @return array{visibility: string, path: callable}
     */
    private function zone(string $zone): array
    {
        if (!isset($this->zones[$zone])) {
            throw new InvalidArgumentException('Zona de almacenamiento desconocida: ' . $zone);
        }

        return $this->zones[$zone];
    }

    private function registerZones(): void
    {
        $this->zones = [
            self::ZONE_SGD => [
                'visibility' => 'public',
                'path' => static fn (array $segments): array => array_merge(
                    ['sgd'],
                    array_map(static fn ($s) => (string)$s, $segments)
                ),
            ],
            self::ZONE_SGD_MEDIA => [
                'visibility' => 'public',
                'path' => static fn (array $segments): array => array_merge(
                    ['sgd'],
                    array_map(static fn ($s) => (string)$s, $segments),
                    ['media']
                ),
            ],
            self::ZONE_EMPRESAS => [
                'visibility' => 'public',
                'path' => static fn (array $segments): array => ['empresas'],
            ],
            self::ZONE_USUARIOS => [
                'visibility' => 'public',
                'path' => static fn (array $segments): array => ['usuarios'],
            ],
            self::ZONE_SGD_IMPORTS => [
                'visibility' => 'private',
                'path' => static fn (array $segments): array => array_merge(
                    ['sgd_imports'],
                    array_map(static fn ($s) => (string)$s, $segments)
                ),
            ],
        ];
    }

    private function reference(string $key): string
    {
        $public = $this->driver->publicUrl($key);
        if ($public !== null) {
            return $public;
        }

        return $this->driver->localPath($key);
    }

    private function resolveKey(string $keyOrUrl): ?string
    {
        $keyOrUrl = trim($keyOrUrl);
        if ($keyOrUrl === '') {
            return null;
        }

        if (str_starts_with($keyOrUrl, '/uploads/')) {
            return $this->publicUrlToKey($keyOrUrl);
        }

        if (str_starts_with($keyOrUrl, '/')) {
            return $this->absolutePathToKey($keyOrUrl);
        }

        return $this->normalizeIncoming($keyOrUrl);
    }

    private function absolutePathToKey(string $absolutePath): ?string
    {
        if (!$this->driver instanceof LocalStorageDriver) {
            return null;
        }

        $norm = str_replace('\\', '/', $absolutePath);
        $publicRoot = rtrim(str_replace('\\', '/', $this->driver->publicRoot()), '/') . '/';
        if (str_starts_with($norm, $publicRoot)) {
            return ltrim(substr($norm, strlen($publicRoot)), '/');
        }

        $privateRoot = rtrim(str_replace('\\', '/', $this->driver->privateRoot()), '/') . '/';
        if (str_starts_with($norm, $privateRoot)) {
            return ltrim(substr($norm, strlen($privateRoot)), '/');
        }

        return null;
    }

    private function normalizeIncoming(string $key): string
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
        return str_starts_with($key, 'sgd_imports/');
    }
}
