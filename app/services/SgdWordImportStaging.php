<?php

/**
 * Guarda HTML convertido de Word fuera de la respuesta JSON (documentos grandes).
 * Archivos en uploads/sgd/{empresa}/{documento}/media/import_{token}.*
 */
class SgdWordImportStaging
{
    private const TTL_SECONDS = 3600;

    private static function mediaDir(int $empresaId, int $documentoId): string
    {
        $media = BASE_PATH . '/public/uploads/sgd/' . $empresaId . '/' . $documentoId . '/media';
        if (!is_dir($media) && !@mkdir($media, 0755, true) && !is_dir($media)) {
            throw new RuntimeException('No se pudo acceder a la carpeta de medios del documento.');
        }

        return $media;
    }

    private static function paths(int $empresaId, int $documentoId, string $token): array
    {
        $dir = self::mediaDir($empresaId, $documentoId);
        $safe = preg_replace('/[^a-f0-9]/', '', strtolower($token)) ?? '';

        return [
            'html' => $dir . '/import_' . $safe . '.html',
            'meta' => $dir . '/import_' . $safe . '.json',
        ];
    }

    /**
     * @param array<string, mixed> $meta
     */
    public static function store(int $empresaId, int $documentoId, int $userId, string $html, array $meta): string
    {
        self::purgeExpired($empresaId, $documentoId);

        $token = bin2hex(random_bytes(16));
        $paths = self::paths($empresaId, $documentoId, $token);

        if (@file_put_contents($paths['html'], $html) === false) {
            throw new RuntimeException('No se pudo guardar el contenido convertido en media/.');
        }

        $meta['empresa_id'] = $empresaId;
        $meta['documento_id'] = $documentoId;
        $meta['user_id'] = $userId;
        $meta['created_at'] = time();
        $meta['html_chars'] = mb_strlen($html);

        if (@file_put_contents(
            $paths['meta'],
            json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
        ) === false) {
            @unlink($paths['html']);
            throw new RuntimeException('No se pudo guardar metadatos de la importación.');
        }

        return $token;
    }

    /**
     * @return array{html: string, meta: array<string, mixed>}|null
     */
    public static function load(string $token, int $empresaId, int $documentoId, int $userId): ?array
    {
        $token = preg_replace('/[^a-f0-9]/', '', strtolower($token)) ?? '';
        if (strlen($token) !== 32) {
            return null;
        }

        $paths = self::paths($empresaId, $documentoId, $token);
        if (!is_readable($paths['meta']) || !is_readable($paths['html'])) {
            return null;
        }

        $metaRaw = @file_get_contents($paths['meta']);
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

        $html = @file_get_contents($paths['html']);
        if (!is_string($html) || $html === '') {
            return null;
        }

        return ['html' => $html, 'meta' => $meta];
    }

    public static function delete(string $token, int $empresaId, int $documentoId): void
    {
        $token = preg_replace('/[^a-f0-9]/', '', strtolower($token)) ?? '';
        if (strlen($token) !== 32) {
            return;
        }
        $paths = self::paths($empresaId, $documentoId, $token);
        @unlink($paths['html']);
        @unlink($paths['meta']);
    }

    private static function purgeExpired(int $empresaId, int $documentoId): void
    {
        $dir = self::mediaDir($empresaId, $documentoId);
        $now = time();
        foreach (glob($dir . '/import_*.json') ?: [] as $metaPath) {
            $raw = @file_get_contents($metaPath);
            $meta = is_string($raw) ? json_decode($raw, true) : null;
            $created = is_array($meta) ? (int)($meta['created_at'] ?? 0) : 0;
            if ($created > 0 && ($now - $created) > self::TTL_SECONDS) {
                $token = str_replace('import_', '', basename($metaPath, '.json'));
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
