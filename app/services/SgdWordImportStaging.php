<?php

/**
 * Guarda HTML convertido de Word fuera de la respuesta JSON (documentos grandes).
 * Archivos en uploads/sgd/{empresa}/{documento}/media/import_{token}.*
 */
class SgdWordImportStaging
{
    private const TTL_SECONDS = 3600;

    private static function storage(): StorageService
    {
        return StorageService::instance();
    }

    private static function tokenSafe(string $token): string
    {
        return preg_replace('/[^a-f0-9]/', '', strtolower($token)) ?? '';
    }

    private static function keys(int $empresaId, int $documentoId, string $token): array
    {
        $safe = self::tokenSafe($token);

        return [
            'html' => self::storage()->key(StorageService::ZONE_SGD_MEDIA, 'import_' . $safe . '.html', $empresaId, $documentoId),
            'meta' => self::storage()->key(StorageService::ZONE_SGD_MEDIA, 'import_' . $safe . '.json', $empresaId, $documentoId),
        ];
    }

    /**
     * @param array<string, mixed> $meta
     */
    public static function store(int $empresaId, int $documentoId, int $userId, string $html, array $meta): string
    {
        self::purgeExpired($empresaId, $documentoId);

        $token = bin2hex(random_bytes(16));
        $keys = self::keys($empresaId, $documentoId, $token);
        $storage = self::storage();

        try {
            $storage->putContents($keys['html'], $html);
        } catch (RuntimeException $e) {
            throw new RuntimeException('No se pudo guardar el contenido convertido en media/.', 0, $e);
        }

        $meta['empresa_id'] = $empresaId;
        $meta['documento_id'] = $documentoId;
        $meta['user_id'] = $userId;
        $meta['created_at'] = time();
        $meta['html_chars'] = mb_strlen($html);

        try {
            $storage->putContents(
                $keys['meta'],
                json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}'
            );
        } catch (RuntimeException $e) {
            $storage->delete($keys['html']);
            throw new RuntimeException('No se pudo guardar metadatos de la importación.', 0, $e);
        }

        return $token;
    }

    /**
     * @return array{html: string, meta: array<string, mixed>}|null
     */
    public static function load(string $token, int $empresaId, int $documentoId, int $userId): ?array
    {
        $safe = self::tokenSafe($token);
        if (strlen($safe) !== 32) {
            return null;
        }

        $keys = self::keys($empresaId, $documentoId, $token);
        $storage = self::storage();
        if (!$storage->exists($keys['meta']) || !$storage->exists($keys['html'])) {
            return null;
        }

        $metaRaw = $storage->get($keys['meta']);
        $meta = is_string($metaRaw) ? json_decode($metaRaw, true) : null;
        if (!is_array($meta)) {
            return null;
        }

        if ((int)($meta['empresa_id'] ?? 0) !== $empresaId
            || (int)($meta['documento_id'] ?? 0) !== $documentoId
            || (int)($meta['user_id'] ?? 0) !== $userId) {
            return null;
        }

        $created = (int)($meta['created_at'] ?? 0);
        if ($created > 0 && (time() - $created) > self::TTL_SECONDS) {
            self::delete($token, $empresaId, $documentoId);

            return null;
        }

        $html = $storage->get($keys['html']);
        if (!is_string($html) || $html === '') {
            return null;
        }

        return ['html' => $html, 'meta' => $meta];
    }

    public static function delete(string $token, int $empresaId, int $documentoId): void
    {
        $safe = self::tokenSafe($token);
        if (strlen($safe) !== 32) {
            return;
        }

        $keys = self::keys($empresaId, $documentoId, $token);
        $storage = self::storage();
        $storage->delete($keys['html']);
        $storage->delete($keys['meta']);
    }

    private static function purgeExpired(int $empresaId, int $documentoId): void
    {
        $storage = self::storage();
        $now = time();
        foreach ($storage->glob(StorageService::ZONE_SGD_MEDIA, 'import_*.json', $empresaId, $documentoId) as $metaKey) {
            $raw = $storage->get($metaKey);
            $meta = is_string($raw) ? json_decode($raw, true) : null;
            $created = is_array($meta) ? (int)($meta['created_at'] ?? 0) : 0;
            if ($created > 0 && ($now - $created) > self::TTL_SECONDS) {
                $basename = basename($metaKey, '.json');
                $token = str_replace('import_', '', $basename);
                self::delete($token, $empresaId, $documentoId);
            }
        }
    }

    public static function sanitizeUtf8(string $text): string
    {
        if ($text === '') {
            return '';
        }
        if (function_exists('mb_convert_encoding')) {
            $clean = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
            if (is_string($clean) && $clean !== '') {
                $text = $clean;
            }
        }
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text) ?? $text;

        return $text;
    }
}
