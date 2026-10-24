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

    public const ZONE_ACAD_MEDIA = 'acad_media';

    /** Cache efímero (TTL corto, ver SihosAuditoriaGlosaCache) de reportes SIHOS pesados — nunca público. */
    public const ZONE_SIHOS_REPORTES = 'sihos_reportes';

    private static ?self $instance = null;

    private StorageDriverInterface $defaultDriver;

    /**
     * Driver distinto por zona (hoy solo acad_media puede tener uno propio,
     * vía ACAD_MEDIA_STORAGE_DRIVER=s3 — los audios que suben los estudiantes
     * crecen sin control a diferencia del resto del storage, ver commit que
     * agregó esto). Las zonas sin entrada aquí usan $defaultDriver.
     *
     * @var array<string, StorageDriverInterface>
     */
    private array $driverOverrides = [];

    /** @var array<string, array{visibility: string, path: callable}> */
    private array $zones = [];

    /**
     * @param array<string, StorageDriverInterface> $driverOverrides zona => driver
     */
    public function __construct(StorageDriverInterface $defaultDriver, array $driverOverrides = [])
    {
        $this->defaultDriver = $defaultDriver;
        $this->driverOverrides = $driverOverrides;
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
        $publicPrefixes = self::publicPathPrefixes();
        $defaultDriverName = strtolower(trim((string)(getenv('STORAGE_DRIVER') ?: 'local')));
        $defaultDriver = self::buildDriver($defaultDriverName, $publicPrefixes);

        $overrides = [];
        $acadDriverName = strtolower(trim((string)(getenv('ACAD_MEDIA_STORAGE_DRIVER') ?: '')));
        if ($acadDriverName !== '') {
            $overrides[self::ZONE_ACAD_MEDIA] = self::buildDriver($acadDriverName, $publicPrefixes);
        }

        return new self($defaultDriver, $overrides);
    }

    /**
     * @param list<string> $publicPrefixes
     */
    private static function buildDriver(string $driverName, array $publicPrefixes): StorageDriverInterface
    {
        try {
            return match ($driverName) {
                's3' => new S3StorageDriver($publicPrefixes),
                'local' => new LocalStorageDriver(null, null, $publicPrefixes),
                default => new LocalStorageDriver(null, null, $publicPrefixes),
            };
        } catch (RuntimeException $e) {
            error_log('StorageService: ' . $e->getMessage() . ' — usando driver local.');

            return new LocalStorageDriver(null, null, $publicPrefixes);
        }
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
        $url = $this->driverForKey($key)->publicUrl($key);
        if ($url !== null) {
            return $url;
        }

        if ($this->isPrivateKey($key)) {
            return $this->driverForKey($key)->localPath($key);
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
        $this->driverForKey($key)->putContents($key, $contents);

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
            $this->driverForZone($zone)->putFile($key, $tmp, true);
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
            $this->driverForZone($zone)->putFile($key, $localSourcePath, $move);
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

        return $this->driverForKey($key)->get($key);
    }

    public function exists(string $keyOrUrl): bool
    {
        $key = $this->resolveKey($keyOrUrl);
        if ($key === null) {
            return false;
        }

        return $this->driverForKey($key)->exists($key);
    }

    public function delete(string $keyOrUrl): bool
    {
        $key = $this->resolveKey($keyOrUrl);
        if ($key === null) {
            return false;
        }

        return $this->driverForKey($key)->delete($key);
    }

    public function localPath(string $keyOrUrl): ?string
    {
        $key = $this->resolveKey($keyOrUrl);
        if ($key === null) {
            return null;
        }

        return $this->driverForKey($key)->localPath($key);
    }

    /**
     * @param int|string ...$segments
     */
    public function localDirectory(string $zone, int|string ...$segments): string
    {
        $prefix = $this->key($zone, '', ...$segments);
        $driver = $this->driverForZone($zone);
        if ($driver instanceof LocalStorageDriver) {
            return $driver->localDirectory($prefix);
        }
        if ($driver instanceof S3StorageDriver) {
            return $driver->localDirectory($prefix);
        }

        $path = rtrim($driver->localPath($prefix), '/');
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

        return $this->driverForZone($zone)->listKeys($prefix, $globPattern);
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

    /**
     * Única fuente de verdad de qué zona es pública/privada y bajo qué
     * segmento raíz de key vive — de aquí derivan registerZones() (los
     * `path` callables completos), isPrivateKey() de esta clase, y los
     * prefijos públicos que reciben ambos drivers (ver
     * publicPathPrefixes() y createFromEnv()). Antes de esto había 3
     * copias sueltas y desincronizadas de este mismo criterio (corregido
     * 2026-09-11: una zona nueva marcada 'private' aquí podía terminar
     * sirviéndose como pública porque los drivers tenían su propia lista
     * aparte) — cualquier zona nueva se agrega SOLO aquí.
     *
     * @return array<string, array{visibility: string, root: string}>
     */
    private static function zoneDefinitions(): array
    {
        return [
            self::ZONE_SGD => ['visibility' => 'public', 'root' => 'sgd'],
            self::ZONE_SGD_MEDIA => ['visibility' => 'public', 'root' => 'sgd'],
            self::ZONE_EMPRESAS => ['visibility' => 'public', 'root' => 'empresas'],
            self::ZONE_USUARIOS => ['visibility' => 'public', 'root' => 'usuarios'],
            self::ZONE_SGD_IMPORTS => ['visibility' => 'private', 'root' => 'sgd_imports'],
            self::ZONE_ACAD_MEDIA => ['visibility' => 'public', 'root' => 'acad'],
            self::ZONE_SIHOS_REPORTES => ['visibility' => 'private', 'root' => 'sihos_reportes'],
        ];
    }

    /**
     * Prefijos de key ("root/") de las zonas públicas — para los drivers,
     * que no tienen acceso a esta clase (evita duplicar la lista ahí).
     * Privado por defecto: cualquier zona que no aparezca aquí debe
     * tratarse como privada.
     *
     * @return list<string>
     */
    public static function publicPathPrefixes(): array
    {
        $raices = [];
        foreach (self::zoneDefinitions() as $def) {
            if ($def['visibility'] === 'public') {
                $raices[$def['root']] = true;
            }
        }

        return array_map(static fn (string $root): string => $root . '/', array_keys($raices));
    }

    private function registerZones(): void
    {
        $definiciones = self::zoneDefinitions();

        $this->zones = [
            self::ZONE_SGD => [
                'visibility' => $definiciones[self::ZONE_SGD]['visibility'],
                'path' => static fn (array $segments): array => array_merge(
                    ['sgd'],
                    array_map(static fn ($s) => (string)$s, $segments)
                ),
            ],
            self::ZONE_SGD_MEDIA => [
                'visibility' => $definiciones[self::ZONE_SGD_MEDIA]['visibility'],
                'path' => static fn (array $segments): array => array_merge(
                    ['sgd'],
                    array_map(static fn ($s) => (string)$s, $segments),
                    ['media']
                ),
            ],
            self::ZONE_EMPRESAS => [
                'visibility' => $definiciones[self::ZONE_EMPRESAS]['visibility'],
                'path' => static fn (array $segments): array => ['empresas'],
            ],
            self::ZONE_USUARIOS => [
                'visibility' => $definiciones[self::ZONE_USUARIOS]['visibility'],
                'path' => static fn (array $segments): array => ['usuarios'],
            ],
            self::ZONE_SGD_IMPORTS => [
                'visibility' => $definiciones[self::ZONE_SGD_IMPORTS]['visibility'],
                'path' => static fn (array $segments): array => array_merge(
                    ['sgd_imports'],
                    array_map(static fn ($s) => (string)$s, $segments)
                ),
            ],
            self::ZONE_ACAD_MEDIA => [
                'visibility' => $definiciones[self::ZONE_ACAD_MEDIA]['visibility'],
                'path' => static fn (array $segments): array => array_merge(
                    ['acad'],
                    array_map(static fn ($s) => (string)$s, $segments),
                    ['media']
                ),
            ],
            self::ZONE_SIHOS_REPORTES => [
                'visibility' => $definiciones[self::ZONE_SIHOS_REPORTES]['visibility'],
                'path' => static fn (array $segments): array => array_merge(
                    ['sihos_reportes'],
                    array_map(static fn ($s) => (string)$s, $segments)
                ),
            ],
        ];
    }

    private function reference(string $key): string
    {
        $driver = $this->driverForKey($key);
        $public = $driver->publicUrl($key);
        if ($public !== null) {
            return $public;
        }

        return $driver->localPath($key);
    }

    private function driverForZone(string $zone): StorageDriverInterface
    {
        return $this->driverOverrides[$zone] ?? $this->defaultDriver;
    }

    /**
     * Para operaciones que reciben una key ya armada (sin la zona explícita):
     * infiere la zona por el segmento raíz de la key (ver zoneDefinitions())
     * para saber si debe usar un driver distinto al por defecto.
     */
    private function driverForKey(string $key): StorageDriverInterface
    {
        foreach ($this->driverOverrides as $zone => $driver) {
            $root = self::zoneDefinitions()[$zone]['root'] . '/';
            if (str_starts_with($key, $root)) {
                return $driver;
            }
        }

        return $this->defaultDriver;
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
        // Una ruta absoluta de filesystem solo tiene sentido para el driver
        // local (S3/R2 no tiene "ruta absoluta" real) — se resuelve siempre
        // contra el driver por defecto, nunca contra un override de zona.
        if (!$this->defaultDriver instanceof LocalStorageDriver) {
            return null;
        }

        $norm = str_replace('\\', '/', $absolutePath);
        $publicRoot = rtrim(str_replace('\\', '/', $this->defaultDriver->publicRoot()), '/') . '/';
        if (str_starts_with($norm, $publicRoot)) {
            return ltrim(substr($norm, strlen($publicRoot)), '/');
        }

        $privateRoot = rtrim(str_replace('\\', '/', $this->defaultDriver->privateRoot()), '/') . '/';
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
        foreach (self::publicPathPrefixes() as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return false;
            }
        }

        return true;
    }
}
