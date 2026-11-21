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

    private SihosTrabajoStore $store;

    public function __construct()
    {
        $this->store = new SihosTrabajoStore(self::PREFIJO);
    }

    public function storage(): StorageService
    {
        return $this->store->storage();
    }

    public function tokenSeguro(string $token): string
    {
        return $this->store->tokenSeguro($token);
    }

    public function key(int $empresaId, string $token, string $parte, string $ext = 'json'): string
    {
        return $this->store->key($empresaId, $token, $parte, $ext);
    }

    /** @return array<string,mixed>|null */
    public function leerEstado(int $empresaId, string $token): ?array
    {
        return $this->store->leerEstado($empresaId, $token);
    }

    /** @param array<string,mixed> $estado */
    public function guardarEstado(int $empresaId, string $token, array $estado): void
    {
        $this->store->guardarEstado($empresaId, $token, $estado);
    }

    /** @param array<string,mixed> $estado */
    public function terminar(int $empresaId, string $token, array $estado, string $final, ?string $error = null): void
    {
        $this->store->terminar($empresaId, $token, $estado, $final, $error);
    }

    /** @param array<string,mixed> $estado */
    public function verificarLatido(int $empresaId, string $token, array $estado, int $sinLatidoSegundos): array
    {
        return $this->store->verificarLatido($empresaId, $token, $estado, $sinLatidoSegundos);
    }

    public function lanzarWorker(string $scriptPath, int $empresaId, string $token): void
    {
        $this->store->lanzarWorker($scriptPath, $empresaId, $token);
    }

    public function purgarExpirados(int $empresaId): void
    {
        $sufijos = [];
        foreach (self::TABLAS as $tabla) {
            $sufijos[] = '_' . $tabla . '.json';
            $sufijos[] = '_' . $tabla . '.csv';
        }
        $this->store->purgarExpirados($empresaId, $sufijos);
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
