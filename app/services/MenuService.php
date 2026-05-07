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

    public function getMenuPrincipal()
    {

        $sql = "
            SELECT DISTINCT m.*
            FROM modulo m
            JOIN item i ON i.modulo_id = m.id
            JOIN item_accion ia ON ia.item_id = i.id
            JOIN accion a ON a.id = ia.accion_id
            JOIN permiso p ON p.item_accion_id = ia.id
            WHERE p.usuario_id = ?
            AND a.codigo = 'ver'
            AND p.estado_id = 5
            AND m.estado_id = 1
            ORDER BY m.orden
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$this->usuarioId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getItemsByModulo($moduloId)
    {

        $sql = "
            SELECT DISTINCT i.*
            FROM item i
            JOIN item_accion ia ON ia.item_id = i.id
            JOIN accion a ON a.id = ia.accion_id
            JOIN permiso p ON p.item_accion_id = ia.id
            WHERE i.modulo_id = ?
            AND p.usuario_id = ?
            AND a.codigo = 'ver'
            AND p.estado_id = 5
            AND i.estado_id = 1
            ORDER BY i.orden
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            $moduloId,
            $this->usuarioId
        ]);

        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->buildTree($items);
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

        $sql = "
            SELECT DISTINCT m.*
            FROM modulo m
            JOIN item i ON i.modulo_id = m.id
            JOIN item_accion ia ON ia.item_id = i.id
            JOIN accion a ON a.id = ia.accion_id
            JOIN permiso p ON p.item_accion_id = ia.id
            WHERE p.usuario_id = ?
            AND a.codigo = 'ver'
            AND p.estado_id = 5
            AND m.estado_id = 1
            ORDER BY m.orden
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$this->usuarioId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getSearchItems()
    {

        $sql = "
            SELECT DISTINCT i.nombre, i.ruta
            FROM item i
            JOIN item_accion ia ON ia.item_id = i.id
            JOIN accion a ON a.id = ia.accion_id
            JOIN permiso p ON p.item_accion_id = ia.id
            WHERE p.usuario_id = ?
            AND a.codigo = 'ver'
            AND p.estado_id = 5
            AND i.estado_id = 1
            ORDER BY i.nombre
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$this->usuarioId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}