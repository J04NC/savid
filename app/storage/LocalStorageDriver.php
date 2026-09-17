<?php

class LocalStorageDriver implements StorageDriverInterface
{
    private string $publicRoot;

    private string $privateRoot;

    /**
     * Prefijos de key que SÍ son públicos — inyectados desde
     * StorageService::publicPathPrefixes() (derivados de
     * StorageService::zoneDefinitions(), la única fuente de verdad).
     * Privado por defecto (deny-by-default): cualquier zona nueva que se
     * olvide de agregar ahí queda privada automáticamente, no al revés.
     * Corrige un caso real (2026-09-11): ZONE_SIHOS_REPORTES se creó con
     * 'visibility' => 'private' en StorageService pero antes de este fix
     * este driver mantenía su PROPIA lista suelta (nunca actualizada) y
     * trataba como pública cualquier zona no listada explícitamente como
     * privada — justo al revés de lo seguro.
     *
     * @var list<string>
     */
    private array $publicPrefixes;

    public function __construct(?string $publicRoot = null, ?string $privateRoot = null, ?array $publicPrefixes = null)
    {
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        $this->publicRoot = rtrim($publicRoot ?: (getenv('STORAGE_PUBLIC_ROOT') ?: $base . '/public/uploads'), '/');
        $this->privateRoot = rtrim($privateRoot ?: (getenv('STORAGE_PRIVATE_ROOT') ?: $base . '/storage'), '/');
        // Fallback si se instancia fuera de StorageService::createFromEnv()
        // (p. ej. un test que construya el driver directo): debe coincidir
        // con StorageService::zoneDefinitions() al momento de escribir esto.
        $this->publicPrefixes = $publicPrefixes ?? ['sgd/', 'empresas/', 'usuarios/', 'acad/'];
    }

    public function putContents(string $key, string $contents): void
    {
        $path = $this->localPath($key);
        $this->ensureParentDir($path);
        if (@file_put_contents($path, $contents) === false) {
            throw new RuntimeException('No se pudo guardar el archivo: ' . $key);
        }
        // Igual que en ensureParentDir(): sin esto el archivo queda 0644
        // (el umask del proceso quita el bit de grupo), y si el creador no
        // es el mismo usuario que luego necesita sobrescribirlo (p. ej. un
        // cron corriendo como "ing" reescribiendo algo que creó "nginx", o
        // viceversa) la segunda escritura falla igual que el mkdir original.
        @chmod($path, 0664);
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
        if (!is_dir($dir)) {
            // Ver comentario de ensureParentDir(): umask(0) para que el
            // 0775 pedido no quede neutralizado por el umask del proceso.
            $umaskPrevio = umask(0);
            $creado = @mkdir($dir, 0775, true);
            umask($umaskPrevio);
            if (!$creado && !is_dir($dir)) {
                throw new RuntimeException('No se pudo crear el directorio de almacenamiento.');
            }
        }

        return $dir;
    }

    public function publicUrl(string $key): ?string
    {
        $key = $this->normalizeKey($key);
        if ($this->isPrivateKey($key)) {
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
        foreach ($this->publicPrefixes as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return false;
            }
        }

        return true;
    }

    private function ensureParentDir(string $path): void
    {
        $dir = dirname($path);
        if (is_dir($dir)) {
            return;
        }
        // umask(0) alrededor del mkdir: el umask por defecto de este
        // servidor (0022) neutraliza el bit de escritura de grupo del modo
        // pedido (0775 & ~0022 = 0755) — sin esto, un directorio creado por
        // un proceso CLI (usuario "ing") queda sin permiso de escritura
        // para el grupo "nginx" (PHP-FPM), y viceversa. Aplica a TODOS los
        // niveles que mkdir(..., true) cree de una vez, no solo al último.
        $umaskPrevio = umask(0);
        $creado = @mkdir($dir, 0775, true);
        umask($umaskPrevio);
        if (!$creado && !is_dir($dir)) {
            throw new RuntimeException('No se pudo crear el directorio: ' . $dir);
        }
    }
}
