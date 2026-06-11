<?php

/**
 * Lee archivos .docx (ZIP OOXML) con ZipArchive o, si no está disponible, con unzip del sistema.
 */
class DocxArchiveReader
{
    private ?ZipArchive $zip = null;

    private ?string $tempDir = null;

    public static function open(string $path): ?self
    {
        if (!is_readable($path)) {
            return null;
        }

        $reader = new self();
        if (class_exists(ZipArchive::class)) {
            $zip = new ZipArchive();
            if ($zip->open($path) === true) {
                $reader->zip = $zip;

                return $reader;
            }
        }

        return $reader->openViaUnzip($path) ? $reader : null;
    }

    private function openViaUnzip(string $path): bool
    {
        if (!$this->canUseUnzip()) {
            return false;
        }

        $base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
        $this->tempDir = $base . DIRECTORY_SEPARATOR . 'sgd_docx_' . bin2hex(random_bytes(8));
        if (!@mkdir($this->tempDir, 0700, true) && !is_dir($this->tempDir)) {
            $this->tempDir = null;

            return false;
        }

        $cmd = 'unzip -qq -o ' . escapeshellarg($path) . ' -d ' . escapeshellarg($this->tempDir);
        $output = [];
        $exitCode = 1;
        if ($this->canExec()) {
            exec($cmd, $output, $exitCode);
        } else {
            $exitCode = $this->runProcess($cmd);
        }

        if ($exitCode !== 0 || !is_file($this->tempDir . '/word/document.xml')) {
            $this->close();

            return false;
        }

        return true;
    }

    public function getFromName(string $name): string|false
    {
        $name = ltrim(str_replace('\\', '/', $name), '/');
        if ($name === '' || str_contains($name, '..')) {
            return false;
        }

        if ($this->zip instanceof ZipArchive) {
            $data = $this->zip->getFromName($name);

            return $data === false ? false : $data;
        }

        if ($this->tempDir === null) {
            return false;
        }

        $path = $this->tempDir . '/' . $name;
        if (!is_readable($path) || !is_file($path)) {
            return false;
        }

        $data = @file_get_contents($path);

        return $data === false ? false : $data;
    }

    public function close(): void
    {
        if ($this->zip instanceof ZipArchive) {
            $this->zip->close();
            $this->zip = null;
        }

        if ($this->tempDir !== null && is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
            $this->tempDir = null;
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    private function canUseUnzip(): bool
    {
        $candidates = ['/usr/bin/unzip', '/bin/unzip'];
        foreach ($candidates as $bin) {
            if (is_executable($bin)) {
                return true;
            }
        }

        $which = $this->canExec() ? trim((string)shell_exec('command -v unzip 2>/dev/null')) : '';

        return $which !== '' && is_executable($which);
    }

    private function canExec(): bool
    {
        if (!function_exists('exec')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));

        return !in_array('exec', $disabled, true);
    }

    private function runProcess(string $cmd): int
    {
        if (!function_exists('proc_open')) {
            return 1;
        }
        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        if (in_array('proc_open', $disabled, true)) {
            return 1;
        }

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            return 1;
        }
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process);
    }

    private function removeDirectory(string $dir): void
    {
        $items = @scandir($dir);
        if ($items === false) {
            @rmdir($dir);

            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
