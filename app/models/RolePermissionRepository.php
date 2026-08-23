<?php

class RolePermissionRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @param list<int>|null $allowedItemIds si viene no-null, restringe a esos item_id
     *        (ítems habilitados para la empresa en foco vía empresa_item). Null = sin
     *        restringir (solo cuando el superadmin ve "todas las empresas").
     */
    public function getMatrixRows(?array $allowedItemIds = null)
    {
        $itemNd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'item', 'i');
        $modNd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'modulo', 'm');
        $accNd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'accion', 'a');

        $params = [];
        $allowedSql = '';
        if ($allowedItemIds !== null) {
            if ($allowedItemIds === []) {
                return [];
            }
            $placeholders = implode(',', array_fill(0, count($allowedItemIds), '?'));
            $allowedSql = "AND i.id IN ({$placeholders})";
            $params = $allowedItemIds;
        }

        $stmt = $this->pdo->prepare("
            SELECT
                m.nombre AS modulo,
                i.id AS item_id,
                i.nombre AS item,
                a.id AS accion_id,
                a.nombre AS accion,
                a.codigo,
                ia.id AS item_accion_id
            FROM item_accion ia
            JOIN item i ON i.id = ia.item_id
            JOIN modulo m ON m.id = i.modulo_id
            JOIN accion a ON a.id = ia.accion_id
            WHERE ia.estado_id = 1
            AND i.estado_id = 1
            {$itemNd}
            {$modNd}
            {$accNd}
            {$allowedSql}
            ORDER BY m.id, i.orden, a.id
        ");
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getCheckedItemAccionIdsByScope($rolId, $empresaId, $sedeId)
    {
        $params = [$rolId];
        $sql = "
            SELECT item_accion_id
            FROM rol_permiso
            WHERE rol_id = ?
        ";

        if ($empresaId === null) {
            $sql .= " AND empresa_id IS NULL ";
        } else {
            $sql .= " AND empresa_id = ? ";
            $params[] = $empresaId;
        }

        if ($sedeId === null) {
            $sql .= " AND sede_id IS NULL ";
        } else {
            $sql .= " AND sede_id = ? ";
            $params[] = $sedeId;
        }

        $sql .= " AND estado_id = 5";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function insertRolePermission($rolId, $empresaId, $sedeId, $itemAccionId)
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO rol_permiso
            (rol_id, empresa_id, sede_id, item_accion_id, estado_id)
            VALUES (?,?,?,?,5)
        ");

        $stmt->execute([
            $rolId,
            $empresaId,
            $sedeId,
            $itemAccionId,
        ]);
    }

    public function deleteRolePermissionByScope($rolId, $empresaId, $sedeId, $itemAccionId)
    {
        $sql = "DELETE FROM rol_permiso WHERE rol_id = ?";
        $params = [$rolId];

        if ($empresaId === null) {
            $sql .= " AND empresa_id IS NULL ";
        } else {
            $sql .= " AND empresa_id = ? ";
            $params[] = $empresaId;
        }

        if ($sedeId === null) {
            $sql .= " AND sede_id IS NULL ";
        } else {
            $sql .= " AND sede_id = ? ";
            $params[] = $sedeId;
        }

        $sql .= " AND item_accion_id = ?";
        $params[] = $itemAccionId;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }
}
