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
        $notDeleted = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'item');

        $stmt = $this->pdo->prepare("
            SELECT *
            FROM item
            WHERE ruta = ?
            {$notDeleted}
            LIMIT 1
        ");
        $stmt->execute([$ruta]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function findModuloById($moduloId)
    {
        $notDeleted = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'modulo');

        $stmt = $this->pdo->prepare("
            SELECT nombre
            FROM modulo
            WHERE id = ?
            {$notDeleted}
            LIMIT 1
        ");
        $stmt->execute([$moduloId]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function findItemById($itemId)
    {
        $notDeleted = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'item');

        $stmt = $this->pdo->prepare("
            SELECT id, nombre, nombre_es, item_padre_id
            FROM item
            WHERE id = ?
            {$notDeleted}
            LIMIT 1
        ");
        $stmt->execute([$itemId]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function findItemDetailById($itemId)
    {
        $notDeleted = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'item');

        $stmt = $this->pdo->prepare("
            SELECT id, nombre, item_padre_id, modulo_id, ruta
            FROM item
            WHERE id = ?
            {$notDeleted}
            LIMIT 1
        ");
        $stmt->execute([$itemId]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function findChildrenByParentId($itemId)
    {
        $notDeleted = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'item');

        $stmt = $this->pdo->prepare("
            SELECT *
            FROM item
            WHERE item_padre_id = ?
            AND estado_id = 1
            {$notDeleted}
            ORDER BY orden
        ");
        $stmt->execute([$itemId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function deleteById($tabla, $id)
    {
        $tabla = SoftDeleteService::sanitizeTable((string)$tabla);
        $id = (int)$id;

        if ($tabla === '' || $id <= 0) {
            return false;
        }

        if (SoftDeleteService::supports($this->pdo, $tabla)) {
            $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
            $userId = $userId > 0 ? $userId : null;

            if ($tabla === 'item') {
                return $this->softDeleteItemTree($id, $userId);
            }

            $sql = "UPDATE `{$tabla}` SET deleted_at = NOW(3), deleted_by = ? WHERE id = ? AND deleted_at IS NULL";
            $stmt = $this->pdo->prepare($sql);

            return $stmt->execute([$userId, $id]);
        }

        $stmt = $this->pdo->prepare("DELETE FROM `{$tabla}` WHERE id = ?");

        return $stmt->execute([$id]);
    }

  /**
     * Baja lógica del ítem y todos sus descendientes en el menú.
     */
    private function softDeleteItemTree(int $itemId, ?int $userId): bool
    {
        $stmt = $this->pdo->prepare('
            SELECT id FROM item
            WHERE item_padre_id = ?
            AND deleted_at IS NULL
        ');
        $stmt->execute([$itemId]);
        $childIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

        foreach ($childIds as $childId) {
            if ($childId > 0) {
                $this->softDeleteItemTree($childId, $userId);
            }
        }

        $upd = $this->pdo->prepare('
            UPDATE item
            SET deleted_at = NOW(3), deleted_by = ?
            WHERE id = ? AND deleted_at IS NULL
        ');

        return $upd->execute([$userId, $itemId]);
    }
}
