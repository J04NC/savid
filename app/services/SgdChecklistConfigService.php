<?php

/**
 * Configuración de plantilla para bloque op_items_checklist.
 * Modos: filas_libres | filas_fijas; columnas libres; filas editables en diseño.
 */
class SgdChecklistConfigService
{
    public const BLOQUE_CODIGO = 'op_items_checklist';

    /** @var list<string> */
    public const TIPOS_COLUMNA = ['texto', 'textarea', 'lista', 'fecha', 'numero'];

    /** @var list<string> */
    private const CONTENIDO_IDS = ['numero', 'seccion', 'aspecto', 'referencia'];

    public static function isChecklistBlock(string $seccionCodigo): bool
    {
        return strtolower(trim($seccionCodigo)) === self::BLOQUE_CODIGO;
    }

    /**
     * @param array<string, mixed> $definicion
     * @return array<string, mixed>
     */
    public static function buildDefaultConfig(array $definicion): array
    {
        $columnas = [];
        $orden = 10;
        foreach ($definicion['columnas'] ?? [] as $col) {
            if (!is_array($col) || empty($col['id'])) {
                continue;
            }
            $id = (string)$col['id'];
            $tipo = strtolower(trim((string)($col['tipo'] ?? 'texto')));
            if (!in_array($tipo, self::TIPOS_COLUMNA, true)) {
                $tipo = 'texto';
            }
            $columnas[] = [
                'id' => $id,
                'label' => trim((string)($col['label'] ?? $id)),
                'tipo' => $tipo,
                'rol' => in_array($id, self::CONTENIDO_IDS, true) ? 'contenido' : 'respuesta',
                'requerido' => !empty($col['requerido']),
                'visible' => true,
                'orden' => $orden,
                'opciones' => ($tipo === 'lista' && !empty($col['opciones']) && is_array($col['opciones']))
                    ? array_values(array_map('strval', $col['opciones']))
                    : [],
            ];
            $orden += 10;
        }

        return [
            'modo' => 'filas_libres',
            'columnas' => $columnas,
            'filas' => [],
        ];
    }

    /**
     * @param array<string, mixed>|null $config
     * @param array<string, mixed> $definicion
     * @return array<string, mixed>|null
     */
    public static function normalizeConfig(?array $config, array $definicion): ?array
    {
        $defaults = self::buildDefaultConfig($definicion);
        if (!is_array($config) || $config === []) {
            return $defaults;
        }

        $modo = strtolower(trim((string)($config['modo'] ?? 'filas_libres')));
        if (!in_array($modo, ['filas_libres', 'filas_fijas'], true)) {
            $modo = 'filas_libres';
        }

        $normalized = [
            'modo' => $modo,
            'columnas' => self::normalizeColumnas($config['columnas'] ?? [], $defaults['columnas']),
            'filas' => self::normalizeFilas($config['filas'] ?? [], $modo),
        ];

        return $normalized;
    }

    /**
     * @param array<string, mixed> $bloque
     * @return list<array<string, mixed>>
     */
    public static function resolveColumnas(array $bloque): array
    {
        $def = is_array($bloque['definicion'] ?? null) ? $bloque['definicion'] : [];
        $config = is_array($bloque['config'] ?? null)
            ? self::normalizeConfig($bloque['config'], $def)
            : self::buildDefaultConfig($def);
        if ($config === null) {
            return [];
        }

        $out = [];
        foreach ($config['columnas'] as $col) {
            if (empty($col['visible'])) {
                continue;
            }
            $out[] = $col;
        }
        usort($out, static fn($a, $b) => ($a['orden'] ?? 0) <=> ($b['orden'] ?? 0));

        return $out;
    }

    /**
     * @param array<string, mixed> $bloque
     * @return list<array<string, mixed>>
     */
    public static function resolveFilasPlantilla(array $bloque): array
    {
        $def = is_array($bloque['definicion'] ?? null) ? $bloque['definicion'] : [];
        $config = is_array($bloque['config'] ?? null)
            ? self::normalizeConfig($bloque['config'], $def)
            : self::buildDefaultConfig($def);
        if ($config === null || ($config['modo'] ?? '') !== 'filas_fijas') {
            return [];
        }

        $filas = $config['filas'] ?? [];
        usort($filas, static fn($a, $b) => ($a['orden'] ?? 0) <=> ($b['orden'] ?? 0));

        return $filas;
    }

    public static function resolveModo(array $bloque): string
    {
        $def = is_array($bloque['definicion'] ?? null) ? $bloque['definicion'] : [];
        $config = is_array($bloque['config'] ?? null)
            ? self::normalizeConfig($bloque['config'], $def)
            : self::buildDefaultConfig($def);

        return (string)($config['modo'] ?? 'filas_libres');
    }

    /**
     * Tipo efectivo al diligenciar: lista sin opciones → texto libre.
     *
     * @param array<string, mixed> $columna
     */
    public static function effectiveTipoColumna(array $columna): string
    {
        $tipo = strtolower(trim((string)($columna['tipo'] ?? 'texto')));
        if ($tipo === 'lista') {
            $opts = $columna['opciones'] ?? [];
            if (!is_array($opts) || $opts === []) {
                return 'texto';
            }
        }

        return $tipo;
    }

    /**
     * @param list<array<string, mixed>> $raw
     * @param list<array<string, mixed>> $fallback
     * @return list<array<string, mixed>>
     */
    private static function normalizeColumnas(array $raw, array $fallback): array
    {
        if ($raw === []) {
            return $fallback;
        }

        $isLegacy = true;
        foreach ($raw as $entry) {
            if (is_array($entry) && (!empty($entry['tipo']) || !empty($entry['label']))) {
                $isLegacy = false;
                break;
            }
        }

        if ($isLegacy) {
            $byId = [];
            foreach ($fallback as $fb) {
                $byId[$fb['id']] = $fb;
            }
            foreach ($raw as $entry) {
                if (!is_array($entry) || empty($entry['id'])) {
                    continue;
                }
                $id = (string)$entry['id'];
                if (!isset($byId[$id])) {
                    continue;
                }
                $byId[$id]['visible'] = !empty($entry['visible']);
                if (array_key_exists('requerido', $entry)) {
                    $byId[$id]['requerido'] = !empty($entry['requerido']);
                }
                if (!empty($entry['label'])) {
                    $byId[$id]['label'] = mb_substr(trim((string)$entry['label']), 0, 120);
                }
            }

            return array_values($byId);
        }

        $normalized = [];
        $seen = [];
        $orden = 10;
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $id = self::slugId((string)($entry['id'] ?? ''));
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $tipo = strtolower(trim((string)($entry['tipo'] ?? 'texto')));
            if (!in_array($tipo, self::TIPOS_COLUMNA, true)) {
                $tipo = 'texto';
            }
            $rol = strtolower(trim((string)($entry['rol'] ?? 'respuesta')));
            if (!in_array($rol, ['contenido', 'respuesta'], true)) {
                $rol = in_array($id, self::CONTENIDO_IDS, true) ? 'contenido' : 'respuesta';
            }
            $col = [
                'id' => $id,
                'label' => mb_substr(trim((string)($entry['label'] ?? $id)), 0, 120),
                'tipo' => $tipo,
                'rol' => $rol,
                'requerido' => !empty($entry['requerido']),
                'visible' => !array_key_exists('visible', $entry) || !empty($entry['visible']),
                'orden' => (int)($entry['orden'] ?? $orden),
            ];
            if ($tipo === 'lista' && !empty($entry['opciones']) && is_array($entry['opciones'])) {
                $opts = array_values(array_filter(array_map(static fn($o) => trim((string)$o), $entry['opciones'])));
                $col['opciones'] = array_slice($opts, 0, 30);
            } else {
                $col['opciones'] = [];
            }
            $normalized[] = $col;
            $orden += 10;
        }

        if ($normalized === []) {
            return $fallback;
        }

        usort($normalized, static fn($a, $b) => ($a['orden'] ?? 0) <=> ($b['orden'] ?? 0));
        $orden = 10;
        foreach ($normalized as &$col) {
            $col['orden'] = $orden;
            $orden += 10;
        }
        unset($col);

        $visible = count(array_filter($normalized, static fn($c) => !empty($c['visible'])));
        if ($visible === 0) {
            $normalized[0]['visible'] = true;
        }

        return $normalized;
    }

    /**
     * @param list<array<string, mixed>> $raw
     * @return list<array<string, mixed>>
     */
    private static function normalizeFilas(array $raw, string $modo): array
    {
        if ($modo !== 'filas_fijas') {
            return [];
        }

        $filas = [];
        $seen = [];
        $orden = 10;
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $id = self::slugId((string)($entry['id'] ?? ''));
            if ($id === '') {
                $id = 'fila_' . ($orden / 10);
            }
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $celdas = [];
            if (!empty($entry['celdas']) && is_array($entry['celdas'])) {
                foreach ($entry['celdas'] as $k => $v) {
                    $cid = self::slugId((string)$k);
                    if ($cid !== '') {
                        $celdas[$cid] = mb_substr(trim((string)$v), 0, 2000);
                    }
                }
            }
            $filas[] = [
                'id' => $id,
                'numero' => mb_substr(trim((string)($entry['numero'] ?? '')), 0, 32),
                'orden' => (int)($entry['orden'] ?? $orden),
                'celdas' => $celdas,
            ];
            $orden += 10;
        }

        usort($filas, static fn($a, $b) => ($a['orden'] ?? 0) <=> ($b['orden'] ?? 0));

        return $filas;
    }

    private static function slugId(string $raw): string
    {
        $raw = strtolower(trim($raw));
        $raw = preg_replace('/[^a-z0-9_]+/', '_', $raw) ?? '';

        return trim($raw, '_');
    }
}
