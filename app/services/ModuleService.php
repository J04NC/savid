<?php

class ModuleService
{
    private const CRUD_CATALOG_AUTO_THRESHOLD = 250;

    private ModuleRepository $moduleRepository;
    private CrudService $crudService;

    public function __construct()
    {
        $database = new Database();
        $pdo = $database->connect();

        $this->moduleRepository = new ModuleRepository($pdo);
        $this->crudService = new CrudService();
    }

    public function findCurrentItem($ruta)
    {
        return $this->moduleRepository->findItemByRuta($ruta);
    }

    public function buildBreadcrumb($currentItem)
    {
        $breadcrumb = '<a href="?url=dashboard">INICIO</a>';

        if (!$currentItem) {
            return $breadcrumb;
        }

        $modulo = $this->moduleRepository->findModuloById($currentItem['modulo_id']);

        $currentId = $currentItem['id'];
        $path = [];

        while ($currentId) {
            $item = $this->moduleRepository->findItemById($currentId);

            if (!$item) {
                break;
            }

            $path[] = $item;
            $currentId = $item['item_padre_id'];
        }

        $path = array_reverse($path);
        $lastIndex = count($path) - 1;

        $moduloLabel = $modulo ? strtoupper((string)$modulo['nombre']) : '';
        $singleRootSameAsModulo = $modulo !== null
            && count($path) === 1
            && strcasecmp(trim((string)($path[0]['nombre'] ?? '')), trim((string)$modulo['nombre'])) === 0;

        if ($modulo && !$singleRootSameAsModulo) {
            $breadcrumb .= ' <span class="separator"> / </span> ';
            $breadcrumb .= '<a href="?url=dashboard/modulo/' . $currentItem['modulo_id'] . '">'
                . htmlspecialchars($moduloLabel, ENT_QUOTES, 'UTF-8') . '</a>';
        }

        foreach ($path as $index => $p) {
            $breadcrumb .= ' <span class="separator"> / </span> ';
            $label = strtoupper((string)($p['nombre'] ?? ''));
            if ($index === $lastIndex) {
                $breadcrumb .= '<span>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
            } else {
                $breadcrumb .= '<a href="?url=dashboard/item/' . (int)$p['id'] . '">'
                    . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
            }
        }

        return $breadcrumb;
    }

    public function buildBreadcrumbForRuta(string $ruta): string
    {
        return $this->buildBreadcrumb($this->findCurrentItem($ruta));
    }

    public function canExecuteAction($itemId, $codigoAccion)
    {
        $acciones = $this->crudService->getAcciones($itemId);

        foreach ($acciones as $acc) {
            if ($acc['codigo'] === $codigoAccion && PermisoService::canByItemAccion($acc['item_accion_id'])) {
                return true;
            }
        }

        return false;
    }

    public function deleteRecord($tabla, $id)
    {
        if ($tabla === 'usuario') {
            $validator = new UsuarioFormValidationService();
            $validator->assertUsuarioGestionableEnSesion((int)$id);
        }

        return $this->moduleRepository->deleteById($tabla, $id);
    }

    public function getCrudData($currentItem)
    {
        $tabla = $currentItem['ruta'];
        $itemId = $currentItem['id'];

        $data = $this->crudService->getTableData($tabla);
        $columns = $this->crudService->getColumns($tabla);
        $acciones = $this->crudService->getAcciones($itemId);

        $relations = $this->crudService->getRelations($tabla);

        if ($tabla === 'empresa') {
            foreach ($this->crudService->getEmpresaInternalFkFields() as $internalFk) {
                unset($relations[$internalFk]);
            }
        }

        $columns = $this->prepareCrudColumnsForItem($tabla, $columns, $relations);

        $relationData = $this->buildRelationData($tabla, $columns, $relations);
        $catalogRegistry = $this->buildCatalogRegistry($tabla, $columns, $relations);

        $tipodocumentoMeta = in_array($tabla, ['usuario', 'empresa'], true)
            ? $this->crudService->getTipodocumentoMetaById()
            : [];

        return [
            'data' => $data,
            'columns' => $columns,
            'acciones' => $acciones,
            'relations' => $relations,
            'relationData' => $relationData,
            'catalogRegistry' => $catalogRegistry,
            'tipodocumentoMetaById' => $tipodocumentoMeta,
            'usuarioTableColumnFields' => $tabla === 'usuario'
                ? $this->crudService->getUsuarioCrudTableColumnFields()
                : [],
        ];
    }

    /**
     * Columnas y relaciones del ítem antes de armar relationData / catalogRegistry.
     *
     * @param list<array<string, mixed>> $columns
     * @param array<string, string> $relations
     * @return list<array<string, mixed>>
     */
    private function prepareCrudColumnsForItem(string $tabla, array $columns, array &$relations): array
    {
        if ($tabla === 'usuario') {
            $existingFields = array_column($columns, 'Field');

            foreach ($this->crudService->getUsuarioPersonaSyntheticColumns() as $syn) {
                if (!in_array($syn['Field'], $existingFields, true)) {
                    $columns[] = $syn;
                }
            }

            $columns = $this->crudService->applyUsuarioCrudColumnPresentation($columns);
            $columns = $this->crudService->applyUsuarioFotoFirmaUploadPresentation($columns);
            $columns = $this->crudService->applyUsuarioCrudTableVisibility($columns);

            if (in_array('tipodocumento_id', array_column($columns, 'Field'), true)) {
                $relations['tipodocumento_id'] = 'tipodocumento';
            }

            return $columns;
        }

        if ($tabla === 'tercero') {
            $existingFields = array_column($columns, 'Field');

            foreach ($this->crudService->getTerceroIdentificacionSyntheticColumns() as $syn) {
                if (!in_array($syn['Field'], $existingFields, true)) {
                    $columns[] = $syn;
                }
            }

            if (in_array('tipodocumento_id', array_column($columns, 'Field'), true)) {
                $relations['tipodocumento_id'] = 'tipodocumento';
            }

            return $columns;
        }

        if ($tabla === 'tercero_nomina') {
            $existingFields = array_column($columns, 'Field');

            foreach ($this->crudService->getTerceroNominaIdentidadSyntheticColumns() as $syn) {
                if (!in_array($syn['Field'], $existingFields, true)) {
                    $columns[] = $syn;
                }
            }

            $columns = $this->crudService->applyTerceroNominaColumnPresentation($columns);

            if (in_array('tipodocumento_id', array_column($columns, 'Field'), true)) {
                $relations['tipodocumento_id'] = 'tipodocumento';
            }

            return $columns;
        }

        if ($tabla === 'empresa') {
            $existingFields = array_column($columns, 'Field');

            foreach ($this->crudService->getEmpresaPersonaSyntheticColumns() as $syn) {
                if (!in_array($syn['Field'], $existingFields, true)) {
                    $columns[] = $syn;
                }
            }

            $columns = $this->crudService->applyEmpresaCrudColumnPresentation($columns);
            $columns = $this->crudService->applyEmpresaLogoUploadPresentation($columns);

            foreach ($this->crudService->getEmpresaUbicacionFkMap() as $campo => $tablaRel) {
                if (in_array($campo, array_column($columns, 'Field'), true)) {
                    $relations[$campo] = $tablaRel;
                }
            }

            if (in_array('rep_tipodocumento_id', array_column($columns, 'Field'), true)) {
                $relations['rep_tipodocumento_id'] = 'tipodocumento';
            }

            return $columns;
        }

        return $columns;
    }

    /**
     * @param list<array<string, mixed>> $columns
     * @param array<string, string> $relations
     * @return array<string, list<array<string, mixed>>>
     */
    private function buildRelationData(string $tabla, array $columns, array $relations): array
    {
        $relationData = [];

        foreach ($relations as $campo => $tablaRelacion) {
            $relationData[$campo] = $this->crudService->getRelationData(
                $tablaRelacion,
                $campo,
                $this->columnCommentForField($columns, $campo)
            );
        }

        return $relationData;
    }

    /**
     * @param list<array<string, mixed>> $columns
     * @param array<string, string> $relations
     * @return array<string, array{parent_fields: list<string>, referenced_table: string}>
     */
    private function buildCatalogRegistry(string $tabla, array $columns, array $relations): array
    {
        $catalogRegistry = [];

        foreach ($relations as $campo => $tablaRelacion) {
            $comment = $this->columnCommentForField($columns, $campo);
            $relmode = $this->crudService->extractRelModeFromComment($comment);
            $useCatalog = false;

            if ($relmode === 'autocomplete') {
                $useCatalog = true;
            } elseif ($relmode === 'auto') {
                $useCatalog = $this->crudService->getApproxTableRows($tablaRelacion) > self::CRUD_CATALOG_AUTO_THRESHOLD;
            }

            if (!$useCatalog) {
                continue;
            }

            $meta = $this->crudService->buildCatalogMetaForFk($tabla, $campo);

            if ($meta === null) {
                continue;
            }

            $catalogRegistry[$campo] = [
                'parent_fields' => $meta['parent_fields'],
                'referenced_table' => $meta['referenced_table'],
            ];
        }

        return $catalogRegistry;
    }

    /**
     * @param list<array<string, mixed>> $columns
     */
    private function columnCommentForField(array $columns, string $field): ?string
    {
        foreach ($columns as $col) {
            if (($col['Field'] ?? '') === $field) {
                return $col['COLUMN_COMMENT'] ?? null;
            }
        }

        return null;
    }

    public function save($tabla, $postData)
    {
        return $this->crudService->save($tabla, $postData);
    }
}
