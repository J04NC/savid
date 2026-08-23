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

    /**
     * Hijos directos de $itemId, filtrados por permiso (misma lógica de "mantener
     * ancestros" que ya usa el menú lateral) — usado por el drill-down del dashboard
     * (?url=dashboard/item/{id}), que antes traía los hijos con una consulta cruda
     * sin ningún filtro de permiso/empresa_item.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getFilteredChildrenOfItem(int $itemId, int $moduloId): array
    {
        if (!empty($_SESSION['es_super_admin'])) {
            $itemNotDeleted = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'item');
            $stmt = $this->pdo->prepare("
                SELECT * FROM item
                WHERE item_padre_id = ?
                AND estado_id = 1
                {$itemNotDeleted}
                ORDER BY orden
            ");
            $stmt->execute([$itemId]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $itemNotDeleted = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'item');
        $stmt = $this->pdo->prepare("
            SELECT * FROM item
            WHERE modulo_id = ?
            AND estado_id = 1
            {$itemNotDeleted}
            ORDER BY orden
        ");
        $stmt->execute([$moduloId]);
        $filtered = $this->filterItemsWithAncestors($stmt->fetchAll(PDO::FETCH_ASSOC));

        return array_values(array_filter(
            $filtered,
            fn (array $item): bool => $this->normalizeItemPadreId($item['item_padre_id'] ?? null) === $itemId
        ));
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
            $items = $this->applyNombreEsLocale($stmt->fetchAll(PDO::FETCH_ASSOC));

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
     * Para superadmin: sustituye `nombre` por `nombre_es` cuando exista,
     * sin afectar lo que ven docentes/estudiantes (siempre en inglés).
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    public function applyNombreEsLocale(array $items): array
    {
        if (empty($_SESSION['es_super_admin'])) {
            return $items;
        }

        foreach ($items as &$item) {
            if (!empty($item['nombre_es'])) {
                $item['nombre'] = $item['nombre_es'];
            }
        }
        unset($item);

        return $items;
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
            $rows = $this->pdo->query("
                SELECT DISTINCT i.nombre, i.nombre_es, i.ruta
                FROM item i
                WHERE i.estado_id = 1
                {$itemNotDeleted}
                ORDER BY i.nombre
            ")->fetchAll(PDO::FETCH_ASSOC);

            return $this->applyNombreEsLocale($rows);
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