<?php

class SgdConfigService
{
    private SgdRepository $repo;
    private SgdScopeService $scope;

    public function __construct()
    {
        $this->repo = new SgdRepository();
        $this->scope = new SgdScopeService();
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfigPageData(array $query): array
    {
        $scope = $this->scope->buildScope($query);
        $empresaId = $scope['empresaId'] ?? null;
        $config = null;
        $counts = [];

        if ($empresaId) {
            $config = $this->repo->findConfigByEmpresaId($empresaId);
            $counts = $this->repo->countCatalog($empresaId);
        }

        return [
            'scope' => $scope,
            'config' => $config,
            'counts' => $counts,
            'tipos' => $empresaId ? $this->repo->listTiposByEmpresa($empresaId) : [],
        ];
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function saveConfig(array $post, array $query): array
    {
        try {
            $empresaId = $this->scope->requireEmpresaId($query);
        } catch (RuntimeException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        if (!$this->scope->canAccessEmpresa($empresaId, $query)) {
            return ['success' => false, 'message' => 'Sin permiso para esta empresa.'];
        }

        $anio = trim((string)($post['ccd_anio'] ?? ''));
        $existing = $this->repo->findConfigByEmpresaId($empresaId);
        $configJson = $this->buildConfigJsonFromPost($post, $existing);

        $this->repo->upsertConfig($empresaId, [
            'sgd_activo' => !empty($post['sgd_activo']) ? 1 : 0,
            'patron_documento' => trim((string)($post['patron_documento'] ?? '')) ?: null,
            'patron_carpeta' => trim((string)($post['patron_carpeta'] ?? '')) ?: null,
            'ccd_vigencia' => trim((string)($post['ccd_vigencia'] ?? '')) ?: null,
            'ccd_anio' => $anio !== '' && ctype_digit($anio) ? (int)$anio : null,
            'config_json' => $configJson,
        ]);

        return ['success' => true, 'message' => 'Configuración guardada.'];
    }

    /**
     * @return array{success: bool, message: string, inserted?: int}
     */
    public function loadTiposPlantilla(array $query): array
    {
        try {
            $empresaId = $this->scope->requireEmpresaId($query);
        } catch (RuntimeException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        $path = BASE_PATH . '/config/sgd_tipos_plantilla.json';
        if (!is_readable($path)) {
            $path = BASE_PATH . '/config.example/sgd_tipos_plantilla.json';
        }
        if (!is_readable($path)) {
            return ['success' => false, 'message' => 'Plantilla de tipos no encontrada.'];
        }

        $items = json_decode((string)file_get_contents($path), true);
        if (!is_array($items)) {
            return ['success' => false, 'message' => 'Plantilla de tipos inválida.'];
        }

        $n = 0;
        foreach ($items as $tipo) {
            if (empty($tipo['codigo']) || empty($tipo['nombre'])) {
                continue;
            }
            $this->repo->insertTipoDocumental($empresaId, $tipo);
            $n++;
        }

        return [
            'success' => true,
            'message' => "Tipos documentales cargados desde plantilla ({$n}).",
            'inserted' => $n,
        ];
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function importSeccionesPlantilla(array $query): array
    {
        $seccionService = new SgdSeccionService();

        return $seccionService->importPlantillaM4($query, true);
    }

    /**
     * @return array{success: bool, message: string, inserted?: int}
     */
    public function importBloquesOperativos(array $query): array
    {
        return (new SgdArquetipoOperativoService())->importBloquesOperativos($query);
    }

    /**
     * @param array<string, mixed>|null $existing
     */
    private function buildConfigJsonFromPost(array $post, ?array $existing): ?string
    {
        $prev = [];
        if ($existing && !empty($existing['config_json'])) {
            $decoded = json_decode((string)$existing['config_json'], true);
            if (is_array($decoded)) {
                $prev = $decoded;
            }
        }

        $defaults = SgdSeccionService::defaultFormatoPdf();
        $margenes = $defaults['margenes'] ?? [];
        if (isset($prev['formato_pdf']['margenes']) && is_array($prev['formato_pdf']['margenes'])) {
            $margenes = array_merge($margenes, $prev['formato_pdf']['margenes']);
        }

        foreach (['superior', 'inferior', 'izquierdo', 'derecho'] as $k) {
            $raw = trim((string)($post['margen_' . $k] ?? ''));
            if ($raw !== '' && is_numeric($raw)) {
                $margenes[$k] = (float)$raw;
            }
        }

        $pie = trim((string)($post['pie_pagina'] ?? ''));
        if ($pie === '' && isset($prev['formato_pdf']['pie_pagina'])) {
            $pie = (string)$prev['formato_pdf']['pie_pagina'];
        }
        if ($pie === '' && isset($defaults['pie_pagina'])) {
            $pie = (string)$defaults['pie_pagina'];
        }

        $fuente = trim((string)($post['fuente_documento'] ?? ''));
        if ($fuente === '') {
            $fuente = (string)($prev['formato_pdf']['fuente_cuerpo'] ?? $defaults['fuente_cuerpo'] ?? 'Arial');
        }

        $tamanoRaw = trim((string)($post['tamano_documento'] ?? ''));
        $tamano = ($tamanoRaw !== '' && ctype_digit($tamanoRaw))
            ? (int)$tamanoRaw
            : (int)($prev['formato_pdf']['tamano_cuerpo'] ?? $defaults['tamano_cuerpo'] ?? 11);

        $titulos = $this->buildTitulosFromPost($post);

        $formato = [
            'margenes' => $margenes,
            'fuente_cuerpo' => $fuente,
            'tamano_cuerpo' => $tamano,
            'titulos' => $titulos,
            'pie_pagina' => $pie,
        ];

        $merged = array_merge($prev, ['formato_pdf' => $formato]);

        return json_encode($merged, JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<string, mixed>
     */
    public static function parseConfigJson(?array $config): array
    {
        if (!$config || empty($config['config_json'])) {
            return ['formato_pdf' => SgdSeccionService::defaultFormatoPdf()];
        }
        $decoded = json_decode((string)$config['config_json'], true);
        if (!is_array($decoded)) {
            return ['formato_pdf' => SgdSeccionService::defaultFormatoPdf()];
        }
        if (empty($decoded['formato_pdf'])) {
            $decoded['formato_pdf'] = SgdSeccionService::defaultFormatoPdf();
        }
        if (!empty($decoded['formato_pdf']) && is_array($decoded['formato_pdf'])) {
            $decoded['formato_pdf']['titulos'] = SgdSeccionService::normalizeTitulosConfig(
                $decoded['formato_pdf']['titulos'] ?? null
            );
        }

        return $decoded;
    }

    /**
     * @return array{niveles: list<array{nombre: string, ejemplo: string, mayusculas: bool, mayusculas_inicial: bool, negrilla: bool, subrayado: bool, vinetas: bool}>}
     */
    private function buildTitulosFromPost(array $post): array
    {
        $count = (int)($post['titulo_nivel_count'] ?? 0);
        $flags = array_keys(SgdSeccionService::tituloFlagKeys());
        $niveles = [];

        for ($i = 0; $i < $count; $i++) {
            $row = [
                'nombre' => trim((string)($post['titulo_nombre'][$i] ?? '')),
                'ejemplo' => trim((string)($post['titulo_ejemplo'][$i] ?? '')),
            ];
            foreach ($flags as $flag) {
                $raw = $post['titulo_' . $flag][$i] ?? null;
                if (is_array($raw)) {
                    $row[$flag] = in_array('1', $raw, true) || in_array(1, $raw, true);
                } else {
                    $row[$flag] = ($raw === '1' || $raw === 1);
                }
            }
            $normalized = SgdSeccionService::normalizeTituloNivelRow($row);
            if (!SgdSeccionService::isTituloNivelVacio($normalized)) {
                $niveles[] = $normalized;
            }
        }

        return ['niveles' => $niveles];
    }

    /**
     * @return list<string>
     */
    public function listSampleFiles(): array
    {
        $dir = BASE_PATH . '/docs/sgd/SGD';
        if (!is_dir($dir)) {
            return [];
        }

        $files = [];
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            if (in_array($ext, ['xlsx', 'xls'], true)) {
                $files[] = $f;
            }
        }
        sort($files);

        return $files;
    }
}
