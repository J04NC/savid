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
            if ($ruta === '') {
                continue;
            }
            if (PermisoService::can($ruta, 'ver')) {
                $keep[$item['id']] = true;
                $pid = $item['item_padre_id'] ?? null;
                while ($pid && isset($byId[$pid])) {
                    $keep[$pid] = true;
                    $pid = $byId[$pid]['item_padre_id'] ?? null;
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
            return $this->pdo->query('
                SELECT * FROM modulo
                WHERE estado_id = 1
                ORDER BY orden
            ')->fetchAll(PDO::FETCH_ASSOC);
        }

        $stmt = $this->pdo->query('
            SELECT DISTINCT m.*
            FROM modulo m
            INNER JOIN item i ON i.modulo_id = m.id
            WHERE m.estado_id = 1
            AND i.estado_id = 1
            ORDER BY m.orden
        ');
        $modulos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];

        foreach ($modulos as $m) {

            $stmt = $this->pdo->prepare('
                SELECT * FROM item
                WHERE modulo_id = ?
                AND estado_id = 1
                ORDER BY orden
            ');
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

        if (!empty($_SESSION['es_super_admin'])) {
            $stmt = $this->pdo->prepare('
                SELECT * FROM item
                WHERE modulo_id = ?
                AND estado_id = 1
                ORDER BY orden
            ');
            $stmt->execute([$moduloId]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return $this->buildTree($items);
        }

        $stmt = $this->pdo->prepare('
            SELECT * FROM item
            WHERE modulo_id = ?
            AND estado_id = 1
            ORDER BY orden
        ');
        $stmt->execute([$moduloId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $filtered = $this->filterItemsWithAncestors($items);

        return $this->buildTree($filtered);
    }

    private function buildTree($items, $parentId = null)
    {

        $branch = [];

        foreach ($items as $item) {

            if ($item['item_padre_id'] == $parentId) {

                $children = $this->buildTree($items, $item['id']);

                if ($children) {
                    $item['children'] = $children;
                }

                $branch[] = $item;

            }

        }

        return $branch;
    }

    public function getModuloNombre($moduloId)
    {

        $stmt = $this->pdo->prepare("
            SELECT nombre
            FROM modulo
            WHERE id = ?
        ");

        $stmt->execute([$moduloId]);

        $modulo = $stmt->fetch(PDO::FETCH_ASSOC);

        return $modulo ? $modulo['nombre'] : '';
    }

    public function getModulos()
    {

        if (!empty($_SESSION['es_super_admin'])) {
            return $this->pdo->query('
                SELECT * FROM modulo
                WHERE estado_id = 1
                ORDER BY orden
            ')->fetchAll(PDO::FETCH_ASSOC);
        }

        return $this->getMenuPrincipal();
    }

    public function getSearchItems()
    {

        if (!empty($_SESSION['es_super_admin'])) {
            return $this->pdo->query('
                SELECT DISTINCT i.nombre, i.ruta
                FROM item i
                WHERE i.estado_id = 1
                ORDER BY i.nombre
            ')->fetchAll(PDO::FETCH_ASSOC);
        }

        $stmt = $this->pdo->query('
            SELECT DISTINCT i.nombre, i.ruta
            FROM item i
            WHERE i.estado_id = 1
            ORDER BY i.nombre
        ');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $out = [];

        foreach ($rows as $row) {
            $ruta = isset($row['ruta']) ? trim((string)$row['ruta']) : '';
            if ($ruta !== '' && PermisoService::can($ruta, 'ver')) {
                $out[] = $row;
            }
        }

        return $out;
    }
}