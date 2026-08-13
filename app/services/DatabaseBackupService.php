<?php

/**
 * Backup de la base de datos (mysqldump comprimido) con retención configurable.
 * Usado tanto por el cron (database/scripts/backup_database.php) como por el
 * botón "Generar backup ahora" en Estado del sistema — misma lógica en ambos casos.
 */
class DatabaseBackupService
{
    private const FILE_PATTERN = 'savid_*.sql.gz';
    private const FILE_REGEX = '/^savid_[A-Za-z0-9_]+_\d{8}_\d{6}\.sql\.gz$/';

    public function backupDir(): string
    {
        return BASE_PATH . '/storage/backups';
    }

    /**
     * @return array{ok: bool, archivo: ?string, tamanoMb: ?float, error: ?string, borrados: int}
     */
    public function crear(int $retentionDays = 14): array
    {
        $retentionDays = max(1, min(365, $retentionDays));

        Database::bootstrapEnv();
        $host = getenv('DB_HOST') ?: 'localhost';
        $dbname = getenv('DB_DATABASE') ?: 'savid';
        $username = getenv('DB_USERNAME') ?: 'root';
        $password = getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : '';

        $backupDir = $this->backupDir();
        if (!is_dir($backupDir) && !mkdir($backupDir, 0750, true) && !is_dir($backupDir)) {
            return ['ok' => false, 'archivo' => null, 'tamanoMb' => null, 'error' => "No se pudo crear el directorio de backups: {$backupDir}", 'borrados' => 0];
        }

        $mysqldump = trim((string)(getenv('MYSQLDUMP_BIN') ?: 'mysqldump'));

        // Credenciales vía --defaults-extra-file: no quedan expuestas en argv (ps aux)
        // ni hace falta pasarlas por variables de entorno del proceso hijo.
        $credFile = tempnam(sys_get_temp_dir(), 'savid_db_');
        if ($credFile === false) {
            return ['ok' => false, 'archivo' => null, 'tamanoMb' => null, 'error' => 'No se pudo crear el archivo temporal de credenciales.', 'borrados' => 0];
        }
        chmod($credFile, 0600);
        file_put_contents($credFile, "[client]\nuser={$username}\npassword={$password}\nhost={$host}\n");

        $timestamp = date('Ymd_His');
        $sqlFile = "{$backupDir}/savid_{$dbname}_{$timestamp}.sql";
        $gzFile = "{$sqlFile}.gz";

        try {
            $cmd = sprintf(
                '%s --defaults-extra-file=%s --single-transaction --quick --routines --triggers %s > %s 2>&1',
                escapeshellcmd($mysqldump),
                escapeshellarg($credFile),
                escapeshellarg($dbname),
                escapeshellarg($sqlFile)
            );

            exec($cmd, $dumpOutput, $dumpExitCode);

            if ($dumpExitCode !== 0 || !is_file($sqlFile) || filesize($sqlFile) === 0) {
                $detalle = implode("\n", $dumpOutput);
                if (is_file($sqlFile)) {
                    unlink($sqlFile);
                }

                return ['ok' => false, 'archivo' => null, 'tamanoMb' => null, 'error' => "mysqldump falló (código {$dumpExitCode}): {$detalle}", 'borrados' => 0];
            }

            exec(sprintf('gzip -f %s 2>&1', escapeshellarg($sqlFile)), $gzOutput, $gzExitCode);
            if ($gzExitCode !== 0 || !is_file($gzFile)) {
                return ['ok' => false, 'archivo' => null, 'tamanoMb' => null, 'error' => 'No se pudo comprimir el backup: ' . implode("\n", $gzOutput), 'borrados' => 0];
            }
        } finally {
            unlink($credFile);
        }

        $borrados = $this->purgarAntiguos($retentionDays);

        return [
            'ok' => true,
            'archivo' => basename($gzFile),
            'tamanoMb' => round(filesize($gzFile) / 1024 / 1024, 2),
            'error' => null,
            'borrados' => $borrados,
        ];
    }

    private function purgarAntiguos(int $retentionDays): int
    {
        $cutoff = time() - ($retentionDays * 86400);
        $borrados = 0;
        foreach (glob($this->backupDir() . '/' . self::FILE_PATTERN) ?: [] as $file) {
            if (filemtime($file) < $cutoff) {
                unlink($file);
                $borrados++;
            }
        }

        return $borrados;
    }

    /**
     * @return list<array{nombre: string, fecha: int, tamanoMb: float}>
     */
    public function listar(): array
    {
        $files = glob($this->backupDir() . '/' . self::FILE_PATTERN) ?: [];
        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));

        return array_map(fn($f) => [
            'nombre' => basename($f),
            'fecha' => filemtime($f),
            'tamanoMb' => round(filesize($f) / 1024 / 1024, 2),
        ], $files);
    }

    /**
     * Valida que $nombre sea exactamente uno de los backups reales en disco (evita path
     * traversal y cualquier acceso fuera de storage/backups), y devuelve su ruta completa.
     */
    public function rutaSegura(string $nombre): ?string
    {
        if (!preg_match(self::FILE_REGEX, $nombre)) {
            return null;
        }

        $ruta = $this->backupDir() . '/' . $nombre;

        return is_file($ruta) ? $ruta : null;
    }
}
