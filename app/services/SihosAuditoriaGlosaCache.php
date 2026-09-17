<?php

/**
 * Cache efímero (TTL corto) del reporte SIHOS "Auditoría Glosa" ya
 * calculado, para paginación server-side de la tabla (ver
 * SihosController::auditoriaGlosaDatos()) sin volver a golpear SIHOS ni
 * repetir el cómputo en PHP en cada página.
 *
 * Mismo patrón que SgdWordImportStaging (token aleatorio + TTL + purga de
 * expirados vía StorageService, zona privada) — reutilizado en vez de
 * inventar un mecanismo nuevo. Se prefiere el filesystem (vía
 * StorageService) sobre una tabla de la BD de SAVID a propósito: las filas
 * incluyen nombre de paciente (columna NombTerc) — dato clínico-adyacente
 * que no conviene persistir en la BD propia de SAVID más allá de lo
 * estrictamente necesario, ni siquiera temporalmente.
 */
class SihosAuditoriaGlosaCache
{
    private const TTL_SECONDS = 1800;

    /**
     * IMPORTANTE (caso real 2026-09-11): con "Desde" vacío el cache puede
     * tener ~87.000 filas (~40 MB de JSON); decodificar eso agota el
     * memory_limit real de PHP-FPM (128M) si nadie sube el límite antes —
     * con display_errors=Off en producción el Fatal Error resultante no se
     * imprime, dejando el body vacío (justo lo que DataTables reporta como
     * "Invalid JSON response"). Quien llame a load() debe envolver TODO su
     * trabajo posterior (no solo esta llamada) en
     * SihosMemoryGuard::ejecutar() — ver SihosController::auditoriaGlosaDatos()
     * y ::auditoriaGlosaExportar(). Restaurar el límite aquí adentro sería
     * demasiado pronto: el array grande sigue vivo después de que load()
     * retorna.
     */

    private static function storage(): StorageService
    {
        return StorageService::instance();
    }

    private static function tokenSafe(string $token): string
    {
        return preg_replace('/[^a-f0-9]/', '', strtolower($token)) ?? '';
    }

    private static function keys(int $empresaId, string $token): array
    {
        $safe = self::tokenSafe($token);

        return [
            'datos' => self::storage()->key(StorageService::ZONE_SIHOS_REPORTES, 'auditoria_glosa_' . $safe . '.json', $empresaId),
            'meta' => self::storage()->key(StorageService::ZONE_SIHOS_REPORTES, 'auditoria_glosa_' . $safe . '_meta.json', $empresaId),
        ];
    }

    /**
     * @param list<array<string, mixed>> $filas
     * @param array<string, mixed> $meta
     */
    public static function store(int $empresaId, int $userId, array $filas, array $meta): string
    {
        self::purgeExpired($empresaId);

        $token = bin2hex(random_bytes(16));
        $keys = self::keys($empresaId, $token);
        $storage = self::storage();

        $storage->putContents($keys['datos'], json_encode($filas, JSON_UNESCAPED_UNICODE) ?: '[]');

        $meta['empresa_id'] = $empresaId;
        $meta['user_id'] = $userId;
        $meta['created_at'] = time();
        $meta['total'] = count($filas);

        $storage->putContents($keys['meta'], json_encode($meta, JSON_UNESCAPED_UNICODE) ?: '{}');

        return $token;
    }

    /**
     * @return array{filas: list<array<string, mixed>>, meta: array<string, mixed>}|null
     */
    public static function load(string $token, int $empresaId, int $userId): ?array
    {
        $safe = self::tokenSafe($token);
        if (strlen($safe) !== 32) {
            return null;
        }

        $keys = self::keys($empresaId, $token);
        $storage = self::storage();
        if (!$storage->exists($keys['meta']) || !$storage->exists($keys['datos'])) {
            return null;
        }

        $metaRaw = $storage->get($keys['meta']);
        $meta = is_string($metaRaw) ? json_decode($metaRaw, true) : null;
        if (!is_array($meta)) {
            return null;
        }

        if ((int)($meta['empresa_id'] ?? 0) !== $empresaId || (int)($meta['user_id'] ?? 0) !== $userId) {
            return null;
        }

        $created = (int)($meta['created_at'] ?? 0);
        if ($created > 0 && (time() - $created) > self::TTL_SECONDS) {
            self::delete($token, $empresaId);

            return null;
        }

        $datosRaw = $storage->get($keys['datos']);
        $filas = is_string($datosRaw) ? json_decode($datosRaw, true) : null;
        if (!is_array($filas)) {
            return null;
        }

        return ['filas' => $filas, 'meta' => $meta];
    }

    public static function delete(string $token, int $empresaId): void
    {
        $safe = self::tokenSafe($token);
        if (strlen($safe) !== 32) {
            return;
        }

        $keys = self::keys($empresaId, $token);
        $storage = self::storage();
        $storage->delete($keys['datos']);
        $storage->delete($keys['meta']);
    }

    private static function purgeExpired(int $empresaId): void
    {
        $storage = self::storage();
        $now = time();
        foreach ($storage->glob(StorageService::ZONE_SIHOS_REPORTES, 'auditoria_glosa_*_meta.json', $empresaId) as $metaKey) {
            $raw = $storage->get($metaKey);
            $meta = is_string($raw) ? json_decode($raw, true) : null;
            $created = is_array($meta) ? (int)($meta['created_at'] ?? 0) : 0;
            if ($created > 0 && ($now - $created) > self::TTL_SECONDS) {
                $basename = basename($metaKey, '_meta.json');
                $token = str_replace('auditoria_glosa_', '', $basename);
                self::delete($token, $empresaId);
            }
        }
    }

}
