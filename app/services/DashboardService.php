<?php

class DashboardService
{
    private MenuService $menuService;
    private ModuleRepository $moduleRepository;

    public function __construct($userId)
    {
        $database = new Database();
        $pdo = $database->connect();

        $this->menuService = new MenuService($userId);
        $this->moduleRepository = new ModuleRepository($pdo);
    }

    public function getMenuPrincipal()
    {
        return $this->menuService->getMenuPrincipal();
    }

    public function getModuloData($moduloId)
    {
        return [
            'items' => $this->menuService->getItemsByModulo($moduloId),
            'moduloNombre' => $this->menuService->getModuloNombre($moduloId),
        ];
    }

    public function getItemData($itemId)
    {
        $items = $this->moduleRepository->findChildrenByParentId($itemId);
        $currentItem = $this->moduleRepository->findItemDetailById($itemId);

        $breadcrumb = '<a href="?url=dashboard">INICIO</a>';

        if ($currentItem) {
            $modulo = $this->moduleRepository->findModuloById($currentItem['modulo_id']);

            if ($modulo) {
                $breadcrumb .= ' <span class="separator"> / </span> ';
                $breadcrumb .= '<a href="?url=dashboard/modulo/' . $currentItem['modulo_id'] . '">' . strtoupper($modulo['nombre']) . '</a>';
            }

            $currentId = $itemId;
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
                $breadcrumb .= '<a href="?url=dashboard/item/' . $p['id'] . '">' . strtoupper($p['nombre']) . '</a>';
            }
        }

        return [
            'items' => $items,
            'breadcrumb' => $breadcrumb,
        ];
    }
}
