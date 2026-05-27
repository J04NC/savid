<?php

class MenuService
{
    private $pdo;
    private $usuarioId;

    public function __construct($usuarioId)
    {
        $database = new Database();
        $this->pdo = $database->connect();
        $this->usuarioId = $usuarioId;
    }

    /**
     * Deja ítems con permiso "ver" en el contexto actual y ancestros necesarios para el árbol.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function filterItemsWithAncestors(array $items): array
    {
        $byId = [];

        foreach ($items as $item) {
            $byId[$item['id']] = $item;
        }

        $keep = [];

        foreach ($items as $item) {
            $ruta = isset($item['ruta']) ? trim((string)$item['ruta']) : '';
            $itemId = (int)($item['id'] ?? 0);
            if ($itemId <= 0) {
                continue;
            }

            $visible = false;
            if ($ruta !== '') {
                if ($ruta === 'item' && !PermisoService::isSuperAdminSession()) {
                    continue;
                }
                $visible = PermisoService::can($ruta, 'ver');
            }

            if ($visible) {
                $keep[$itemId] = true;
                $pid = $this->normalizeItemPadreId($item['item_padre_id'] ?? null);
                while ($pid !== null && isset($byId[$pid])) {
                    $keep[$pid] = true;
                    $pid = $this->normalizeItemPadreId($byId[$pid]['item_padre_id'] ?? null);
                }
            }
        }

        $out = [];
        foreach ($items as $item) {
            if (!empty($keep[$item['id']])) {
                $out[] = $item;
            }
        }

        return $out;
    }

    public function getMenuPrincipal()
    {

        if (!empty($_SESSION['es_super_admin'])) {
            $modNotDeleted = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'modulo');
            return $this->pdo->query("
                SELECT * FROM modulo
                WHERE estado_id = 1
                {$modNotDeleted}
                ORDER BY orden
            ")->fetchAll(PDO::FETCH_ASSOC);
        }

        $itemNotDeleted = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'item', 'i');
        $modNotDeleted = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'modulo', 'm');

        $stmt = $this->pdo->query("
            SELECT DISTINCT m.*
            FROM modulo m
            INNER JOIN item i ON i.modulo_id = m.id
            WHERE m.estado_id = 1
            AND i.estado_id = 1
            {$itemNotDeleted}
            {$modNotDeleted}
            ORDER BY m.orden
        ");
        $modulos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];

        foreach ($modulos as $m) {

            $itemNotDeleted = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'item');

            $stmt = $this->pdo->prepare("
                SELECT * FROM item
                WHERE modulo_id = ?
                AND estado_id = 1
                {$itemNotDeleted}
                ORDER BY orden
            ");
            $stmt->execute([$m['id']]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $filtered = $this->filterItemsWithAncestors($items);

            if (count($filtered) > 0) {
                $result[] = $m;
            }
        }

        return $result;
    }

    public function getItemsByModulo($moduloId)
    {
        $itemNotDeleted = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'item');

        if (!empty($_SESSION['es_super_admin'])) {
            $stmt = $this->pdo->prepare("
                SELECT * FROM item
                WHERE modulo_id = ?
                AND estado_id = 1
                {$itemNotDeleted}
                ORDER BY orden
            ");
            $stmt->execute([$moduloId]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return $this->buildTree($items);
        }

        $stmt = $this->pdo->prepare("
            SELECT * FROM item
            WHERE modulo_id = ?
            AND estado_id = 1
            {$itemNotDeleted}
            ORDER BY orden
        ");
        $stmt->execute([$moduloId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $filtered = $this->filterItemsWithAncestors($items);

        return $this->buildTree($filtered);
    }

    /**
     * Normaliza item_padre_id vacío / 0 a raíz del módulo.
     */
    private function normalizeItemPadreId(mixed $parentId): ?int
    {
        if ($parentId === null || $parentId === '') {
            return null;
        }
        $id = (int)$parentId;

        return $id > 0 ? $id : null;
    }

    /**
     * Árbol N niveles: raíz (sin padre) y hijos por item_padre_id.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function buildTree(array $items, ?int $parentId = null): array
    {
        $branch = [];

        foreach ($items as $item) {
            if ($this->normalizeItemPadreId($item['item_padre_id'] ?? null) !== $parentId) {
                continue;
            }

            $itemId = (int)($item['id'] ?? 0);
            if ($itemId <= 0) {
                continue;
            }

            $children = $this->buildTree($items, $itemId);
            if ($children !== []) {
                $item['children'] = $children;
            }

            $branch[] = $item;
        }

        usort($branch, function ($a, $b) {
            return ((int)($a['orden'] ?? 0)) <=> ((int)($b['orden'] ?? 0));
        });

        return $branch;
    }

    public function getModuloNombre($moduloId)
    {

        $modNotDeleted = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'modulo');

        $stmt = $this->pdo->prepare("
            SELECT nombre
            FROM modulo
            WHERE id = ?
            {$modNotDeleted}
        ");

        $stmt->execute([$moduloId]);

        $modulo = $stmt->fetch(PDO::FETCH_ASSOC);

        return $modulo ? $modulo['nombre'] : '';
    }

    public function getModulos()
    {

        if (!empty($_SESSION['es_super_admin'])) {
            $modNotDeleted = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'modulo');
            return $this->pdo->query("
                SELECT * FROM modulo
                WHERE estado_id = 1
                {$modNotDeleted}
                ORDER BY orden
            ")->fetchAll(PDO::FETCH_ASSOC);
        }

        return $this->getMenuPrincipal();
    }

    public function getSearchItems()
    {

        $itemNotDeleted = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'item', 'i');

        if (!empty($_SESSION['es_super_admin'])) {
            return $this->pdo->query("
                SELECT DISTINCT i.nombre, i.ruta
                FROM item i
                WHERE i.estado_id = 1
                {$itemNotDeleted}
                ORDER BY i.nombre
            ")->fetchAll(PDO::FETCH_ASSOC);
        }

        $stmt = $this->pdo->query("
            SELECT DISTINCT i.nombre, i.ruta
            FROM item i
            WHERE i.estado_id = 1
            {$itemNotDeleted}
            ORDER BY i.nombre
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $out = [];

        foreach ($rows as $row) {
            $ruta = isset($row['ruta']) ? trim((string)$row['ruta']) : '';
            if ($ruta === 'item' && !PermisoService::isSuperAdminSession()) {
                continue;
            }
            if ($ruta !== '' && PermisoService::can($ruta, 'ver')) {
                $out[] = $row;
            }
        }

        return $out;
    }
}