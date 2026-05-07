<?php

class ModuleService
{
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

        if ($modulo) {
            $breadcrumb .= ' <span class="separator"> / </span> ';
            $breadcrumb .= '<a href="?url=dashboard/modulo/' . $currentItem['modulo_id'] . '">' . strtoupper($modulo['nombre']) . '</a>';
        }

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

        foreach ($path as $p) {
            $breadcrumb .= ' <span class="separator"> / </span> ';
            $breadcrumb .= '<span>' . strtoupper($p['nombre']) . '</span>';
        }

        return $breadcrumb;
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
        $relationData = [];

        foreach ($relations as $campo => $tablaRelacion) {
            $comment = null;

            foreach ($columns as $col) {
                if ($col['Field'] == $campo) {
                    $comment = $col['COLUMN_COMMENT'] ?? null;
                }
            }

            $relationData[$campo] = $this->crudService->getRelationData(
                $tablaRelacion,
                $campo,
                $comment
            );
        }

        return [
            'data' => $data,
            'columns' => $columns,
            'acciones' => $acciones,
            'relationData' => $relationData,
        ];
    }

    public function save($tabla, $postData)
    {
        return $this->crudService->save($tabla, $postData);
    }
}
