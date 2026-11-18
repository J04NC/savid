<?php

/**
 * Acceso al storage privado por empresa del reporte SIHOS "Facturación"
 * (estado del trabajo, las dos tablas en JSON y sus CSV). Lo usa
 * SihosFacturacionJobService; todo cuelga de la zona ZONE_SIHOS_REPORTES.
 */
class SihosFacturacionTrabajoStore
{
    public const TABLAS = ['facturas', 'notas'];
    private const PREFIJO = 'facturacion';
    private const TTL_SECONDS = 21600;

    public function storage(): StorageService
    {
        return StorageService::instance();
    }

    public function tokenSeguro(string $token): string
    {
        $safe = preg_replace('/[^a-f0-9]/', '', strtolower($token)) ?? '';

        return strlen($safe) === 32 ? $safe : '';
    }

    public function key(int $empresaId, string $token, string $parte, string $ext = 'json'): string
    {
        return $this->storage()->key(StorageService::ZONE_SIHOS_REPORTES, self::PREFIJO . '_' . $token . '_' . $parte . '.' . $ext, $empresaId);
    }

    /** @return array<string,mixed>|null */
    public function leerEstado(int $empresaId, string $token): ?array
    {
        $raw = $this->storage()->get($this->key($empresaId, $token, 'estado'));
        $estado = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($estado) && (int)($estado['empresa_id'] ?? 0) === $empresaId ? $estado : null;
    }

    /** @param array<string,mixed> $estado */
    public function guardarEstado(int $empresaId, string $token, array $estado): void
    {
        $this->storage()->putContents($this->key($empresaId, $token, 'estado'), json_encode($estado, JSON_UNESCAPED_UNICODE) ?: '{}');
    }

    public function purgarExpirados(int $empresaId): void
    {
        $storage = $this->storage();
        foreach ($storage->glob(StorageService::ZONE_SIHOS_REPORTES, self::PREFIJO . '_*_estado.json', $empresaId) as $key) {
            $raw = $storage->get($key);
            $estado = is_string($raw) ? json_decode($raw, true) : null;
            $creado = is_array($estado) ? (int)($estado['creado'] ?? 0) : 0;
            if ($creado > 0 && time() - $creado > self::TTL_SECONDS) {
                $storage->delete($key);
                foreach (self::TABLAS as $tabla) {
                    $storage->delete(str_replace('_estado.json', '_' . $tabla . '.json', $key));
                    $storage->delete(str_replace('_estado.json', '_' . $tabla . '.csv', $key));
                }
            }
        }
    }

    /**
     * Guarda la tabla (JSON) y mueve su CSV ya generado al storage.
     *
     * @param list<array<string,mixed>> $filas
     */
    public function guardarTabla(int $empresaId, string $token, string $tabla, string $rutaCsv, array $filas): bool
    {
        if ($this->storage()->putLocalFile(StorageService::ZONE_SIHOS_REPORTES, self::PREFIJO . '_' . $token . '_' . $tabla . '.csv', $rutaCsv, true, $empresaId) === null) {
            return false;
        }
        $this->storage()->putContents($this->key($empresaId, $token, $tabla), json_encode($filas, JSON_UNESCAPED_UNICODE) ?: '[]');

        return true;
    }

    /** @return list<array<string,mixed>>|null */
    public function leerTabla(int $empresaId, string $token, string $tabla): ?array
    {
        $raw = $this->storage()->get($this->key($empresaId, $token, $tabla));
        $filas = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($filas) ? $filas : null;
    }

    public function rutaCsv(int $empresaId, string $token, string $tabla): ?string
    {
        return $this->storage()->localPath($this->key($empresaId, $token, $tabla, 'csv'));
    }
}
