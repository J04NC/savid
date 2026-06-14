<?php

/**
 * Catálogo de arquetipos operativos (F/R) y perfiles de bloques en sgd_seccion.
 */
class SgdArquetipoOperativoService
{
    public const ARQUETIPO_PILOTO = 'acta';

    /** @var list<string> */
    public const ARQUETIPOS = [
        'acta',
        'bitacora',
        'checklist',
        'analisis',
        'consentimiento',
        'contrato',
        'lista_maestra',
        'matriz',
        'libre',
        'solo_archivo',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function loadCatalog(): array
    {
        $path = BASE_PATH . '/config/sgd_arquetipos_operativos.json';
        if (!is_readable($path)) {
            $path = BASE_PATH . '/config.example/sgd_arquetipos_operativos.json';
        }
        if (!is_readable($path)) {
            return ['version' => 1, 'arquetipos' => [], 'bloques_operativos' => [], 'perfiles_arquetipo' => []];
        }

        $data = json_decode((string)file_get_contents($path), true);

        return is_array($data) ? $data : ['version' => 1, 'arquetipos' => [], 'bloques_operativos' => [], 'perfiles_arquetipo' => []];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listArquetipos(): array
    {
        $catalog = self::loadCatalog();
        $items = $catalog['arquetipos'] ?? [];
        if (!is_array($items)) {
            return [];
        }

        usort($items, static fn($a, $b) => ($a['orden'] ?? 0) <=> ($b['orden'] ?? 0));

        return $items;
    }

    public static function findArquetipo(string $codigo): ?array
    {
        $codigo = self::normalizeCodigo($codigo);
        foreach (self::listArquetipos() as $item) {
            if (self::normalizeCodigo((string)($item['codigo'] ?? '')) === $codigo) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @return array<string, string> seccion_codigo => aplica|no_aplica|opcional
     */
    public static function perfilBloques(string $arquetipoCodigo): array
    {
        $arquetipoCodigo = self::normalizeCodigo($arquetipoCodigo);
        $catalog = self::loadCatalog();
        $perfiles = $catalog['perfiles_arquetipo'] ?? [];
        if (!is_array($perfiles)) {
            return [];
        }
        $perfil = $perfiles[$arquetipoCodigo] ?? null;

        return is_array($perfil) ? $perfil : [];
    }

    /**
     * Esquema inicial v2 para diseñador operativo basado en arquetipo.
     *
     * @return array{version: int, arquetipo: string, bloques: list<array<string, mixed>>, campos: list<array<string, mixed>>}
     */
    public static function buildEsquemaFromArquetipo(string $arquetipoCodigo): array
    {
        $arquetipoCodigo = self::normalizeCodigo($arquetipoCodigo);
        if ($arquetipoCodigo === '' || !in_array($arquetipoCodigo, self::ARQUETIPOS, true)) {
            $arquetipoCodigo = 'libre';
        }

        if ($arquetipoCodigo === 'libre' || $arquetipoCodigo === 'solo_archivo') {
            return [
                'version' => 2,
                'arquetipo' => $arquetipoCodigo,
                'bloques' => [],
                'campos' => [],
            ];
        }

        $catalog = self::loadCatalog();
        $bloquesDef = [];
        foreach ($catalog['bloques_operativos'] ?? [] as $bloque) {
            if (!is_array($bloque) || empty($bloque['codigo'])) {
                continue;
            }
            $bloquesDef[(string)$bloque['codigo']] = $bloque;
        }

        $perfil = self::perfilBloques($arquetipoCodigo);
        $bloques = [];
        $orden = 10;
        foreach ($perfil as $secCodigo => $estado) {
            if ($estado === 'no_aplica') {
                continue;
            }
            $def = $bloquesDef[$secCodigo] ?? null;
            if ($def === null) {
                continue;
            }
            $bloques[] = [
                'seccion_codigo' => $secCodigo,
                'nombre' => (string)($def['nombre'] ?? $secCodigo),
                'widget' => (string)($def['widget'] ?? 'grupo_campos'),
                'estado' => $estado,
                'orden' => $orden,
                'definicion' => self::extractWidgetDef($def),
            ];
            $orden += 10;
        }

        return [
            'version' => 2,
            'arquetipo' => $arquetipoCodigo,
            'bloques' => $bloques,
            'campos' => [],
        ];
    }

    /**
     * @param array<string, mixed> $bloqueDef
     * @return array<string, mixed>
     */
    private static function extractWidgetDef(array $bloqueDef): array
    {
        $keys = ['campos', 'columnas', 'item', 'roles'];
        $out = [];
        foreach ($keys as $key) {
            if (isset($bloqueDef[$key])) {
                $out[$key] = $bloqueDef[$key];
            }
        }

        return $out;
    }

    /**
     * @return array{success: bool, message: string, inserted?: int}
     */
    public function importBloquesOperativos(array $query): array
    {
        $scope = new SgdScopeService();
        try {
            $empresaId = $scope->requireEmpresaId($query);
        } catch (RuntimeException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        if (!$scope->canAccessEmpresa($empresaId, $query)) {
            return ['success' => false, 'message' => 'Sin permiso para esta empresa.'];
        }

        $catalog = self::loadCatalog();
        $bloques = $catalog['bloques_operativos'] ?? [];
        if (!is_array($bloques) || $bloques === []) {
            return ['success' => false, 'message' => 'Catálogo de bloques operativos no encontrado.'];
        }

        $repo = new SgdRepository();
        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $inserted = 0;

        foreach ($bloques as $sec) {
            if (empty($sec['codigo']) || empty($sec['nombre'])) {
                continue;
            }
            $existing = $repo->findSeccionByCodigo($empresaId, (string)$sec['codigo']);
            $repo->upsertSeccion($empresaId, [
                'id' => $existing ? (int)$existing['id'] : 0,
                'codigo' => $sec['codigo'],
                'nombre' => $sec['nombre'],
                'clase' => 'operativo',
                'orden' => (int)($sec['orden'] ?? 0),
                'ayuda' => $sec['ayuda'] ?? '',
            ], $userId);
            if (!$existing) {
                $inserted++;
            }
        }

        return [
            'success' => true,
            'message' => "Bloques operativos importados ({$inserted} nuevos).",
            'inserted' => $inserted,
        ];
    }

    private static function normalizeCodigo(string $codigo): string
    {
        return strtolower(trim($codigo));
    }
}
