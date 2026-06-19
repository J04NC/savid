<?php

interface StorageDriverInterface
{
    public function putContents(string $key, string $contents): void;

    /**
     * @param bool $move Si true intenta rename; si false copia.
     */
    public function putFile(string $key, string $localSourcePath, bool $move = true): void;

    public function get(string $key): ?string;

    public function exists(string $key): bool;

    public function delete(string $key): bool;

    /** Ruta absoluta en disco para lectura por PHP, Dompdf o scripts externos. */
    public function localPath(string $key): string;

    /**
     * @return list<string> claves bajo $prefix que coinciden con $globPattern (basename)
     */
    public function listKeys(string $prefix, string $globPattern = '*'): array;

    /** URL pública para el navegador; null si la clave es privada. */
    public function publicUrl(string $key): ?string;
}
