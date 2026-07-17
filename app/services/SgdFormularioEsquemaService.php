<?php

/**
 * Esquema v3: lista unificada `elementos[]` (bloques + campos intercalados).
 * Compatible con v2 (bloques + campos separados).
 */
class SgdFormularioEsquemaService
{
  /** @var list<string> */
    public const TIPOS_CAMPO = ['texto', 'textarea', 'numero', 'fecha', 'lista', 'firma'];

    /**
     * @param array<string, mixed> $esquema
     * @return array{version: int, arquetipo: string, elementos: list<array<string, mixed>>}
     */
    public function normalize(array $esquema): array
    {
        $version = (int)($esquema['version'] ?? 1);
        if ($version >= 3 && !empty($esquema['elementos']) && is_array($esquema['elementos'])) {
            return $this->normalizeV3($esquema);
        }

        return $this->fromV2($this->normalizeV2Legacy($esquema));
    }

    /**
     * @param array<string, mixed> $esquema
     * @return list<array<string, mixed>>
     */
    public function elementosActivos(array $esquema): array
    {
        $norm = $this->normalize($esquema);
        $out = [];
        foreach ($norm['elementos'] as $el) {
            if (($el['tipo'] ?? '') === 'bloque' && ($el['estado'] ?? 'aplica') === 'no_aplica') {
                continue;
            }
            $out[] = $el;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $esquema
     */
    public function tieneContenido(array $esquema): bool
    {
        foreach ($this->normalize($esquema)['elementos'] as $el) {
            if (($el['tipo'] ?? '') === 'campo') {
                return true;
            }
            if (($el['tipo'] ?? '') === 'bloque' && ($el['estado'] ?? 'aplica') !== 'no_aplica') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $esquema
     * @return array{bloques: list<array<string, mixed>>, campos: list<array<string, mixed>>}
     */
    public function toLegacyLists(array $esquema): array
    {
        $bloques = [];
        $campos = [];
        foreach ($this->elementosActivos($esquema) as $el) {
            if (($el['tipo'] ?? '') === 'bloque') {
                $bloques[] = $el;
            } elseif (($el['tipo'] ?? '') === 'campo') {
                $campos[] = $el;
            }
        }

        return ['bloques' => $bloques, 'campos' => $campos];
    }

    /**
     * @param array<string, mixed> $esquema
     * @return array{version: int, arquetipo: string, elementos: list<array<string, mixed>>}
     */
    private function normalizeV3(array $esquema): array
    {
        $arquetipo = strtolower(trim((string)($esquema['arquetipo'] ?? 'libre')));
        if (!in_array($arquetipo, SgdArquetipoOperativoService::ARQUETIPOS, true)) {
            $arquetipo = 'libre';
        }

        $elementos = [];
        $orden = 10;
        foreach ($esquema['elementos'] ?? [] as $el) {
            if (!is_array($el)) {
                continue;
            }
            $tipo = (string)($el['tipo'] ?? '');
            if ($tipo === 'bloque') {
                $codigo = strtolower(trim((string)($el['seccion_codigo'] ?? '')));
                if ($codigo === '') {
                    continue;
                }
                $estado = (string)($el['estado'] ?? 'aplica');
                if (!in_array($estado, ['aplica', 'no_aplica', 'opcional'], true)) {
                    $estado = 'aplica';
                }
                $item = [
                    'tipo' => 'bloque',
                    'seccion_codigo' => $codigo,
                    'nombre' => trim((string)($el['nombre'] ?? $codigo)),
                    'widget' => trim((string)($el['widget'] ?? 'grupo_campos')),
                    'estado' => $estado,
                    'orden' => (int)($el['orden'] ?? $orden),
                ];
                if (!empty($el['definicion']) && is_array($el['definicion'])) {
                    $item['definicion'] = $el['definicion'];
                }
                $definicion = is_array($item['definicion'] ?? null) ? $item['definicion'] : [];
                $widget = (string)$item['widget'];
                $rawConfig = is_array($el['config'] ?? null) ? $el['config'] : null;
                if (SgdChecklistConfigService::isChecklistBlock($codigo)) {
                    $checklistCfg = SgdChecklistConfigService::normalizeConfig($rawConfig, $definicion);
                    if ($checklistCfg !== null) {
                        $item['config'] = self::mergeBloqueMetaConfig($checklistCfg, $rawConfig);
                    }
                } else {
                    $config = SgdBloqueConfigService::normalizeConfig(
                        $rawConfig,
                        $definicion,
                        $widget,
                        $codigo
                    );
                    if ($config !== null) {
                        $item['config'] = $config;
                    }
                }
                $elementos[] = $item;
            } elseif ($tipo === 'campo') {
                $id = $this->slugCampoId((string)($el['id'] ?? ''));
                if ($id === '') {
                    continue;
                }
                $tipoCampo = strtolower(trim((string)($el['tipo_campo'] ?? $el['tipo'] ?? 'texto')));
                if (!in_array($tipoCampo, self::TIPOS_CAMPO, true)) {
                    $tipoCampo = 'texto';
                }
                $item = [
                    'tipo' => 'campo',
                    'id' => $id,
                    'tipo_campo' => $tipoCampo,
                    'label' => trim((string)($el['label'] ?? $id)),
                    'requerido' => !empty($el['requerido']),
                    'orden' => (int)($el['orden'] ?? $orden),
                ];
                if ($tipoCampo === 'lista' && !empty($el['opciones']) && is_array($el['opciones'])) {
                    $item['opciones'] = array_values(array_filter(array_map('strval', $el['opciones'])));
                }
                $elementos[] = $item;
            }
            $orden += 10;
        }

        usort($elementos, static fn($a, $b) => ($a['orden'] ?? 0) <=> ($b['orden'] ?? 0));
        $orden = 10;
        foreach ($elementos as &$el) {
            $el['orden'] = $orden;
            $orden += 10;
        }
        unset($el);

        return [
            'version' => 3,
            'arquetipo' => $arquetipo,
            'elementos' => $elementos,
        ];
    }

    /**
     * @param array<string, mixed> $esquema
     * @return array{version: int, arquetipo: string, bloques: list<array<string, mixed>>, campos: list<array<string, mixed>>}
     */
    private function normalizeV2Legacy(array $esquema): array
    {
        $arquetipo = strtolower(trim((string)($esquema['arquetipo'] ?? 'libre')));
        $bloques = [];
        $orden = 10;
        foreach ($esquema['bloques'] ?? [] as $bloque) {
            if (!is_array($bloque) || empty($bloque['seccion_codigo'])) {
                continue;
            }
            $estado = (string)($bloque['estado'] ?? 'aplica');
            if (!in_array($estado, ['aplica', 'no_aplica', 'opcional'], true)) {
                $estado = 'aplica';
            }
            $item = [
                'seccion_codigo' => strtolower(trim((string)$bloque['seccion_codigo'])),
                'nombre' => trim((string)($bloque['nombre'] ?? $bloque['seccion_codigo'])),
                'widget' => trim((string)($bloque['widget'] ?? 'grupo_campos')),
                'estado' => $estado,
                'orden' => (int)($bloque['orden'] ?? $orden),
            ];
            if (!empty($bloque['definicion']) && is_array($bloque['definicion'])) {
                $item['definicion'] = $bloque['definicion'];
            }
            $bloques[] = $item;
            $orden += 10;
        }

        $campos = [];
        foreach ($esquema['campos'] ?? [] as $campo) {
            if (!is_array($campo)) {
                continue;
            }
            $id = $this->slugCampoId((string)($campo['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $tipo = strtolower(trim((string)($campo['tipo'] ?? 'texto')));
            if (!in_array($tipo, self::TIPOS_CAMPO, true)) {
                $tipo = 'texto';
            }
            $item = [
                'id' => $id,
                'tipo' => $tipo,
                'label' => trim((string)($campo['label'] ?? $id)),
                'requerido' => !empty($campo['requerido']),
                'orden' => (int)($campo['orden'] ?? $orden),
            ];
            if ($tipo === 'lista' && !empty($campo['opciones']) && is_array($campo['opciones'])) {
                $item['opciones'] = array_values(array_filter(array_map('strval', $campo['opciones'])));
            }
            $campos[] = $item;
            $orden += 10;
        }

        usort($bloques, static fn($a, $b) => ($a['orden'] ?? 0) <=> ($b['orden'] ?? 0));
        usort($campos, static fn($a, $b) => ($a['orden'] ?? 0) <=> ($b['orden'] ?? 0));

        return [
            'version' => 2,
            'arquetipo' => $arquetipo,
            'bloques' => $bloques,
            'campos' => $campos,
        ];
    }

    /**
     * @param array{version: int, arquetipo: string, bloques: list<array<string, mixed>>, campos: list<array<string, mixed>>} $v2
     * @return array{version: int, arquetipo: string, elementos: list<array<string, mixed>>}
     */
    private function fromV2(array $v2): array
    {
        $elementos = [];
        $orden = 10;
        foreach ($v2['bloques'] as $bloque) {
            $elementos[] = array_merge(['tipo' => 'bloque', 'orden' => $orden], $bloque);
            $orden += 10;
        }
        foreach ($v2['campos'] as $campo) {
            $elementos[] = [
                'tipo' => 'campo',
                'id' => $campo['id'],
                'tipo_campo' => $campo['tipo'],
                'label' => $campo['label'],
                'requerido' => $campo['requerido'] ?? false,
                'orden' => $orden,
                'opciones' => $campo['opciones'] ?? null,
            ];
            $orden += 10;
        }

        return [
            'version' => 3,
            'arquetipo' => $v2['arquetipo'],
            'elementos' => array_values(array_filter($elementos, static fn($e) => $e !== null)),
        ];
    }

    private function slugCampoId(string $raw): string
    {
        $raw = strtolower(trim($raw));
        $raw = preg_replace('/[^a-z0-9_]+/', '_', $raw) ?? '';

        return trim($raw, '_');
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed>|null $raw
     * @return array<string, mixed>
     */
    private function mergeBloqueMetaConfig(array $base, ?array $raw): array
    {
        if (!is_array($raw)) {
            return $base;
        }
        $titulo = trim((string)($raw['titulo'] ?? ''));
        if ($titulo !== '') {
            $base['titulo'] = mb_substr($titulo, 0, 120);
        }
        $ayuda = trim((string)($raw['ayuda'] ?? ''));
        if ($ayuda !== '') {
            $base['ayuda'] = mb_substr($ayuda, 0, 500);
        }

        return $base;
    }
}
