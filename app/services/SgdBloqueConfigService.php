<?php

/**
 * Configuración por plantilla de sub-elementos dentro de un bloque operativo.
 */
class SgdBloqueConfigService
{
    /** Bloques que admiten autocompletar desde maestro tercero. */
    private const AUTOCOMPLETE_TERCERO_BLOQUES = ['op_encabezado_tercero'];

    public static function configKey(string $widget): ?string
    {
        return match ($widget) {
            'grupo_campos' => 'campos',
            'tabla_repetible' => 'columnas',
            'bloque_firmas' => 'roles',
            default => null,
        };
    }

    public static function isConfigurable(string $widget): bool
    {
        return self::configKey($widget) !== null;
    }

    public static function supportsTerceroAutocomplete(string $seccionCodigo): bool
    {
        return in_array(strtolower(trim($seccionCodigo)), self::AUTOCOMPLETE_TERCERO_BLOQUES, true);
    }

    /**
     * @param array<string, mixed> $bloque
     */
    public static function resolveTitulo(array $bloque): string
    {
        $config = is_array($bloque['config'] ?? null) ? $bloque['config'] : [];
        $titulo = trim((string)($config['titulo'] ?? ''));

        return $titulo !== ''
            ? $titulo
            : trim((string)($bloque['nombre'] ?? $bloque['seccion_codigo'] ?? ''));
    }

    /**
     * @param array<string, mixed> $bloque
     */
    public static function resolveAyuda(array $bloque, ?array $catalogDef = null): string
    {
        $config = is_array($bloque['config'] ?? null) ? $bloque['config'] : [];
        $ayuda = trim((string)($config['ayuda'] ?? ''));
        if ($ayuda !== '') {
            return $ayuda;
        }
        if ($catalogDef !== null) {
            return trim((string)($catalogDef['ayuda'] ?? ''));
        }

        return '';
    }

    /**
     * @param array<string, mixed> $bloque
     */
    public static function resolveAutocompletarMaestro(array $bloque): ?string
    {
        $config = is_array($bloque['config'] ?? null) ? $bloque['config'] : [];
        $maestro = strtolower(trim((string)($config['autocompletar_maestro'] ?? '')));
        if ($maestro === 'tercero' && self::supportsTerceroAutocomplete((string)($bloque['seccion_codigo'] ?? ''))) {
            return 'tercero';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $bloque
     * @return array<string, string>
     */
    public static function resolveDefaults(array $bloque): array
    {
        $key = self::configKey((string)($bloque['widget'] ?? 'grupo_campos'));
        if ($key === null) {
            return [];
        }
        $config = is_array($bloque['config'] ?? null) ? $bloque['config'] : [];
        $out = [];
        foreach ($config[$key] ?? [] as $row) {
            if (!is_array($row) || empty($row['id']) || !array_key_exists('default', $row)) {
                continue;
            }
            $val = trim((string)$row['default']);
            if ($val !== '') {
                $out[(string)$row['id']] = $val;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $definicion
     * @return array<string, list<array<string, mixed>>>
     */
    public static function buildDefaultConfig(array $definicion, string $widget): array
    {
        $key = self::configKey($widget);
        if ($key === null) {
            return [];
        }

        $items = $definicion[$key] ?? [];
        if (!is_array($items)) {
            return [];
        }

        $out = [];
        foreach ($items as $item) {
            if (!is_array($item) || empty($item['id'])) {
                continue;
            }
            $entry = [
                'id' => (string)$item['id'],
                'visible' => true,
            ];
            if (array_key_exists('requerido', $item)) {
                $entry['requerido'] = !empty($item['requerido']);
            }
            $out[] = $entry;
        }

        return [$key => $out];
    }

    /**
     * @param array<string, mixed> $definicion
     * @param array<string, mixed>|null $config
     * @return array<string, mixed>
     */
    public static function applyConfig(array $definicion, ?array $config, string $widget): array
    {
        $key = self::configKey($widget);
        if ($key === null) {
            return $definicion;
        }

        $catalogItems = $definicion[$key] ?? [];
        if (!is_array($catalogItems) || $catalogItems === []) {
            return $definicion;
        }

        $effective = (is_array($config) && !empty($config[$key]) && is_array($config[$key]))
            ? $config
            : self::buildDefaultConfig($definicion, $widget);

        $byId = [];
        foreach ($catalogItems as $item) {
            if (is_array($item) && !empty($item['id'])) {
                $byId[(string)$item['id']] = $item;
            }
        }

        $merged = [];
        foreach ($effective[$key] ?? [] as $cfg) {
            if (!is_array($cfg) || empty($cfg['id'])) {
                continue;
            }
            $id = (string)$cfg['id'];
            if (empty($cfg['visible']) || !isset($byId[$id])) {
                continue;
            }
            $out = $byId[$id];
            if (array_key_exists('requerido', $cfg)) {
                $out['requerido'] = !empty($cfg['requerido']);
            }
            if (!empty($cfg['label'])) {
                $out['label'] = trim((string)$cfg['label']);
            }
            if (array_key_exists('default', $cfg)) {
                $out['default'] = trim((string)$cfg['default']);
            }
            $merged[] = $out;
        }

        if ($merged === [] && $catalogItems !== []) {
            foreach ($catalogItems as $item) {
                if (is_array($item) && !empty($item['id'])) {
                    $merged[] = $item;
                }
            }
        }

        $result = $definicion;
        $result[$key] = $merged;

        return $result;
    }

    /**
     * @param array<string, mixed>|null $config
     * @param array<string, mixed> $definicion
     * @return array<string, mixed>|null
     */
    public static function normalizeConfig(?array $config, array $definicion, string $widget, string $seccionCodigo = ''): ?array
    {
        $key = self::configKey($widget);
        if ($key === null) {
            return null;
        }

        if (!is_array($config) || $config === []) {
            return null;
        }

        $normalized = [];
        $titulo = trim((string)($config['titulo'] ?? ''));
        if ($titulo !== '') {
            $normalized['titulo'] = mb_substr($titulo, 0, 120);
        }
        $ayuda = trim((string)($config['ayuda'] ?? ''));
        if ($ayuda !== '') {
            $normalized['ayuda'] = mb_substr($ayuda, 0, 500);
        }
        $auto = strtolower(trim((string)($config['autocompletar_maestro'] ?? '')));
        if ($auto === 'tercero' && self::supportsTerceroAutocomplete($seccionCodigo)) {
            $normalized['autocompletar_maestro'] = 'tercero';
        }

        $catalogIds = [];
        $catalogDefaults = [];
        foreach ($definicion[$key] ?? [] as $item) {
            if (is_array($item) && !empty($item['id'])) {
                $id = (string)$item['id'];
                $catalogIds[$id] = true;
                $catalogDefaults[$id] = !empty($item['requerido']);
            }
        }

        $rows = [];
        $seen = [];
        if (!empty($config[$key]) && is_array($config[$key])) {
            foreach ($config[$key] as $entry) {
                if (!is_array($entry) || empty($entry['id'])) {
                    continue;
                }
                $id = (string)$entry['id'];
                if (!isset($catalogIds[$id]) || isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $row = [
                    'id' => $id,
                    'visible' => !empty($entry['visible']),
                ];
                if (array_key_exists('requerido', $entry)) {
                    $row['requerido'] = !empty($entry['requerido']);
                }
                $label = trim((string)($entry['label'] ?? ''));
                if ($label !== '') {
                    $row['label'] = mb_substr($label, 0, 120);
                }
                if (array_key_exists('default', $entry)) {
                    $default = trim((string)$entry['default']);
                    if ($default !== '') {
                        $row['default'] = mb_substr($default, 0, 500);
                    }
                }
                $rows[] = $row;
            }
        }

        foreach (array_keys($catalogIds) as $id) {
            if (!isset($seen[$id])) {
                $rows[] = [
                    'id' => $id,
                    'visible' => true,
                    'requerido' => $catalogDefaults[$id] ?? false,
                ];
            }
        }

        $visibleCount = count(array_filter($rows, static fn($r) => !empty($r['visible'])));
        if ($visibleCount === 0 && $rows !== []) {
            $rows[0]['visible'] = true;
        }

        if ($rows !== []) {
            $normalized[$key] = $rows;
        }

        return $normalized === [] ? null : $normalized;
    }

    /**
     * @param array<string, mixed> $bloque
     * @return array<string, mixed>
     */
    public static function resolveDefinicion(array $bloque): array
    {
        $def = is_array($bloque['definicion'] ?? null) ? $bloque['definicion'] : [];
        $widget = (string)($bloque['widget'] ?? 'grupo_campos');
        $config = is_array($bloque['config'] ?? null) ? $bloque['config'] : null;

        return self::applyConfig($def, $config, $widget);
    }
}
