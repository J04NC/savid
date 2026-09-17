<?php

/**
 * Cache efímero (TTL corto) de la vista previa ya validada de una carga de
 * SIHOS/PROCESOS/TARIFA PROCEDIMIENTOS, entre el POST que valida el archivo
 * (SihosController::tarifaProcedimiento()) y el POST que confirma la
 * escritura (SihosController::tarifaProcedimientoConfirmar()). Evita volver a
 * subir/parsear un archivo de miles de filas solo para confirmarlo, y evita
 * confiar en un payload que el navegador podría alterar (el token solo
 * referencia datos ya validados server-side).
 *
 * Mismo patrón que SihosAuditoriaGlosaCache (token aleatorio + TTL + purga de
 * expirados vía StorageService, zona privada) — reutilizado en vez de
 * inventar un mecanismo nuevo. No son datos clínicos, pero sí siguen la
 * misma zona privada por empresa (`ZONE_SIHOS_REPORTES`) para no crear una
 * zona nueva solo para esto.
 */
class SihosTarifaProcedimientoCache
{
    private const TTL_SECONDS = 1800;

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
            'datos' => self::storage()->key(StorageService::ZONE_SIHOS_REPORTES, 'tarifa_procedimiento_' . $safe . '.json', $empresaId),
            'meta' => self::storage()->key(StorageService::ZONE_SIHOS_REPORTES, 'tarifa_procedimiento_' . $safe . '_meta.json', $empresaId),
        ];
    }

    /**
     * @param list<array<string, mixed>> $filasValidas filas listas para escribir (ver SihosTarifaProcedimientoService::validarArchivo())
     * @param array<string, mixed> $meta codiManu, codiPlan, modo, usuarioId, empresaId
     */
    public static function store(int $empresaId, int $userId, array $filasValidas, array $meta): string
    {
        self::purgeExpired($empresaId);

        $token = bin2hex(random_bytes(16));
        $keys = self::keys($empresaId, $token);
        $storage = self::storage();

        $storage->putContents($keys['datos'], json_encode($filasValidas, JSON_UNESCAPED_UNICODE) ?: '[]');

        $meta['empresa_id'] = $empresaId;
        $meta['user_id'] = $userId;
        $meta['created_at'] = time();
        $meta['total'] = count($filasValidas);

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
        foreach ($storage->glob(StorageService::ZONE_SIHOS_REPORTES, 'tarifa_procedimiento_*_meta.json', $empresaId) as $metaKey) {
            $raw = $storage->get($metaKey);
            $meta = is_string($raw) ? json_decode($raw, true) : null;
            $created = is_array($meta) ? (int)($meta['created_at'] ?? 0) : 0;
            if ($created > 0 && ($now - $created) > self::TTL_SECONDS) {
                $basename = basename($metaKey, '_meta.json');
                $token = str_replace('tarifa_procedimiento_', '', $basename);
                self::delete($token, $empresaId);
            }
        }
    }
}
