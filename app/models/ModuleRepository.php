<?php

class ModuleRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function findItemByRuta($ruta)
    {
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM item
            WHERE ruta = ?
            LIMIT 1
        ");
        $stmt->execute([$ruta]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function findModuloById($moduloId)
    {
        $stmt = $this->pdo->prepare("
            SELECT nombre
            FROM modulo
            WHERE id = ?
        ");
        $stmt->execute([$moduloId]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function findItemById($itemId)
    {
        $stmt = $this->pdo->prepare("
            SELECT id, nombre, item_padre_id
            FROM item
            WHERE id = ?
        ");
        $stmt->execute([$itemId]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function findItemDetailById($itemId)
    {
        $stmt = $this->pdo->prepare("
            SELECT id, nombre, item_padre_id, modulo_id
            FROM item
            WHERE id = ?
        ");
        $stmt->execute([$itemId]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function findChildrenByParentId($itemId)
    {
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM item
            WHERE item_padre_id = ?
            AND estado = 1
            ORDER BY orden
        ");
        $stmt->execute([$itemId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function deleteById($tabla, $id)
    {
        $stmt = $this->pdo->prepare("DELETE FROM {$tabla} WHERE id=?");
        return $stmt->execute([$id]);
    }
}
