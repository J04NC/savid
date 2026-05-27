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
        $this->repo->upsertConfig($empresaId, [
            'sgd_activo' => !empty($post['sgd_activo']) ? 1 : 0,
            'patron_documento' => trim((string)($post['patron_documento'] ?? '')) ?: null,
            'patron_carpeta' => trim((string)($post['patron_carpeta'] ?? '')) ?: null,
            'ccd_vigencia' => trim((string)($post['ccd_vigencia'] ?? '')) ?: null,
            'ccd_anio' => $anio !== '' && ctype_digit($anio) ? (int)$anio : null,
            'config_json' => null,
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
