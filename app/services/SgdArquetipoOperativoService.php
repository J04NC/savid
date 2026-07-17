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
     * Política de archivo oficial del esqueleto: pdf_auto | dual | xlsx_upload | docx_upload | upload.
     */
    public static function resolveFormatoArchivo(string $arquetipoCodigo): string
    {
        $arquetipo = self::findArquetipo($arquetipoCodigo);
        $formato = strtolower(trim((string)($arquetipo['formato_archivo'] ?? 'pdf_auto')));
        $allowed = ['pdf_auto', 'dual', 'xlsx_upload', 'docx_upload', 'upload'];

        return in_array($formato, $allowed, true) ? $formato : 'pdf_auto';
    }

    public static function canAutoGeneratePdfSkeleton(string $arquetipoCodigo): bool
    {
        $formato = self::resolveFormatoArchivo($arquetipoCodigo);

        return $formato === 'pdf_auto' || $formato === 'dual';
    }

    public static function requiresXlsxUpload(string $arquetipoCodigo): bool
    {
        return self::resolveFormatoArchivo($arquetipoCodigo) === 'xlsx_upload';
    }

    public static function requiresDocxUpload(string $arquetipoCodigo): bool
    {
        return self::resolveFormatoArchivo($arquetipoCodigo) === 'docx_upload';
    }

    public static function requiresArchivoUpload(string $arquetipoCodigo): bool
    {
        $formato = self::resolveFormatoArchivo($arquetipoCodigo);

        return in_array($formato, ['upload', 'xlsx_upload', 'docx_upload'], true);
    }

    /**
     * @return list<string>
     */
    public static function allowedUploadExtensions(string $arquetipoCodigo): array
    {
        return match (self::resolveFormatoArchivo($arquetipoCodigo)) {
            'xlsx_upload' => ['xlsx', 'xls'],
            'docx_upload' => ['docx', 'doc'],
            'upload' => ['pdf', 'xlsx', 'xls', 'docx', 'doc'],
            'dual' => ['pdf', 'xlsx', 'xls', 'docx', 'doc'],
            default => ['pdf', 'docx', 'doc'],
        };
    }

    /**
     * Etiqueta corta para el selector de archivo (p. ej. «PDF o Word»).
     *
     * @param list<string> $extensions
     */
    public static function uploadFormatsLabel(array $extensions): string
    {
        $parts = [];
        if (in_array('pdf', $extensions, true)) {
            $parts[] = 'PDF';
        }
        if (array_intersect(['xlsx', 'xls'], $extensions) !== []) {
            $parts[] = 'Excel';
        }
        if (array_intersect(['docx', 'doc'], $extensions) !== []) {
            $parts[] = 'Word';
        }
        if ($parts === []) {
            return 'archivo';
        }
        if (count($parts) === 1) {
            return $parts[0];
        }
        if (count($parts) === 2) {
            return $parts[0] . ' o ' . $parts[1];
        }

        return implode(', ', array_slice($parts, 0, -1)) . ' o ' . $parts[count($parts) - 1];
    }

    public static function detectArchivoTipo(?string $archivoRuta): string
    {
        $ext = strtolower(pathinfo(trim((string)$archivoRuta), PATHINFO_EXTENSION));

        return match ($ext) {
            'xlsx', 'xls' => 'xlsx',
            'docx', 'doc' => 'docx',
            default => 'pdf',
        };
    }

    public static function archivoTipoLabel(string $tipo): string
    {
        return match ($tipo) {
            'xlsx' => 'Excel',
            'docx' => 'Word',
            default => 'PDF',
        };
    }

    /**
     * @return list<string>
     */
    public static function bibliotecaBloques(string $arquetipoCodigo): array
    {
        $arquetipoCodigo = self::normalizeCodigo($arquetipoCodigo);
        $catalog = self::loadCatalog();
        $bib = $catalog['biblioteca_arquetipo'] ?? [];
        if (!is_array($bib) || empty($bib[$arquetipoCodigo])) {
            return array_keys(self::perfilBloques($arquetipoCodigo));
        }

        return array_values(array_filter(array_map('strval', $bib[$arquetipoCodigo])));
    }

    /**
     * @return array<string, string>
     */
    public static function plantillaSemilla(string $arquetipoCodigo): array
    {
        $arquetipoCodigo = self::normalizeCodigo($arquetipoCodigo);
        $catalog = self::loadCatalog();
        $semillas = $catalog['plantillas_semilla_arquetipo'] ?? $catalog['perfiles_arquetipo'] ?? [];
        $semilla = $semillas[$arquetipoCodigo] ?? null;

        return is_array($semilla) ? $semilla : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findBloqueDef(string $seccionCodigo): ?array
    {
        $seccionCodigo = strtolower(trim($seccionCodigo));
        foreach (self::loadCatalog()['bloques_operativos'] ?? [] as $bloque) {
            if (is_array($bloque) && strtolower((string)($bloque['codigo'] ?? '')) === $seccionCodigo) {
                return $bloque;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function buildElementoBloque(string $seccionCodigo, string $estado = 'aplica'): ?array
    {
        $def = self::findBloqueDef($seccionCodigo);
        if ($def === null) {
            return null;
        }

        return [
            'tipo' => 'bloque',
            'seccion_codigo' => (string)$def['codigo'],
            'nombre' => (string)($def['nombre'] ?? $def['codigo']),
            'widget' => (string)($def['widget'] ?? 'grupo_campos'),
            'estado' => $estado,
            'definicion' => self::extractWidgetDef($def),
        ];
    }

    /**
     * Config inicial al agregar bloque checklist a plantilla.
     *
     * @param array<string, mixed> $definicion
     * @return array<string, mixed>
     */
    public static function defaultChecklistPlantillaConfig(array $definicion): array
    {
        return SgdChecklistConfigService::buildDefaultConfig($definicion);
    }

    /**
     * Esquema v3 semilla (p. ej. GE-PD3-F1) — subconjunto del arquetipo, no la biblioteca completa.
     *
     * @return array{version: int, arquetipo: string, elementos: list<array<string, mixed>>}
     */
    public static function buildEsquemaSemillaV3(string $arquetipoCodigo): array
    {
        $arquetipoCodigo = self::normalizeCodigo($arquetipoCodigo);
        if ($arquetipoCodigo === 'libre') {
            return ['version' => 3, 'arquetipo' => 'libre', 'elementos' => []];
        }

        $semilla = self::plantillaSemilla($arquetipoCodigo);
        $elementos = [];
        $orden = 10;
        foreach ($semilla as $secCodigo => $estado) {
            if ($estado === 'no_aplica') {
                continue;
            }
            $el = self::buildElementoBloque((string)$secCodigo, (string)$estado);
            if ($el === null) {
                continue;
            }
            if (SgdChecklistConfigService::isChecklistBlock((string)$secCodigo)) {
                $def = is_array($el['definicion'] ?? null) ? $el['definicion'] : [];
                $el['config'] = SgdChecklistConfigService::buildDefaultConfig($def);
                if ($arquetipoCodigo === 'checklist') {
                    $el['config']['modo'] = 'filas_fijas';
                }
            }
            $el['orden'] = $orden;
            $elementos[] = $el;
            $orden += 10;
        }

        return [
            'version' => 3,
            'arquetipo' => $arquetipoCodigo,
            'elementos' => $elementos,
        ];
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

        if ($arquetipoCodigo === 'libre') {
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
