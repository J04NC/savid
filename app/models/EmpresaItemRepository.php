<?php

/**
 * Qué ítems del menú puede usar cada empresa (tabla `empresa_item`).
 * Fuente de verdad para: filtrar el menú lateral, filtrar las matrices de
 * asignación de permisos, y el bloqueo duro en PermisoService::can().
 */
class EmpresaItemRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @return list<int>
     */
    public function getAllowedItemIds(int $empresaId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT item_id FROM empresa_item
            WHERE empresa_id = ? AND estado_id = 1 AND deleted_at IS NULL
        ');
        $stmt->execute([$empresaId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function isItemAllowed(int $empresaId, int $itemId): bool
    {
        $stmt = $this->pdo->prepare('
            SELECT 1 FROM empresa_item
            WHERE empresa_id = ? AND item_id = ? AND estado_id = 1 AND deleted_at IS NULL
            LIMIT 1
        ');
        $stmt->execute([$empresaId, $itemId]);

        return (bool)$stmt->fetchColumn();
    }

    /**
     * Reemplaza el conjunto completo de ítems habilitados para la empresa
     * (diff insertar/reactivar/desactivar dentro de una transacción).
     *
     * Nota: evita `INSERT ... ON DUPLICATE KEY UPDATE` a propósito —
     * TrackableColumnsService reescribe el SQL de INSERT para inyectar
     * created_at/created_by y no preserva la cláusula ON DUPLICATE KEY,
     * lo que rompe el upsert. Se resuelve con INSERT/UPDATE explícitos.
     *
     * Nota 2: cada INSERT/UPDATE se prepara de nuevo en cada vuelta del loop
     * (no se reutiliza el mismo PDOStatement para varias filas). Reutilizar
     * el mismo statement rompe con tablas trackable: AuditingPDOStatement
     * reescribe el SQL para agregar el placeholder de updated_by solo en la
     * primera ejecución y lo deja cacheado en el objeto; en la siguiente
     * ejecución ya no lo vuelve a agregar (detecta que "ya está"), pero los
     * parámetros que se le pasan siguen sin contarlo → "Invalid parameter
     * number". Es el mismo patrón que ya usan insertRolePermission() y
     * similares en el resto del código (prepare fresco por llamada).
     *
     * @param list<int> $itemIds
     */
    public function replaceAllowedItems(int $empresaId, array $itemIds): void
    {
        $itemIds = array_values(array_unique(array_map('intval', $itemIds)));
        $desiredSet = array_flip($itemIds);

        $this->pdo->beginTransaction();
        try {
            $existingStmt = $this->pdo->prepare('
                SELECT item_id, estado_id FROM empresa_item WHERE empresa_id = ?
            ');
            $existingStmt->execute([$empresaId]);
            $existingRows = [];
            foreach ($existingStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $existingRows[(int)$row['item_id']] = (int)$row['estado_id'];
            }

            foreach ($itemIds as $itemId) {
                if (!array_key_exists($itemId, $existingRows)) {
                    $insert = $this->pdo->prepare('
                        INSERT INTO empresa_item (empresa_id, item_id, estado_id) VALUES (?, ?, 1)
                    ');
                    $insert->execute([$empresaId, $itemId]);
                } elseif ($existingRows[$itemId] !== 1) {
                    $update = $this->pdo->prepare('
                        UPDATE empresa_item SET estado_id = ? WHERE empresa_id = ? AND item_id = ?
                    ');
                    $update->execute([1, $empresaId, $itemId]);
                }
            }

            foreach ($existingRows as $itemId => $estadoId) {
                if ($estadoId === 1 && !isset($desiredSet[$itemId])) {
                    $update = $this->pdo->prepare('
                        UPDATE empresa_item SET estado_id = ? WHERE empresa_id = ? AND item_id = ?
                    ');
                    $update->execute([0, $empresaId, $itemId]);
                }
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }
}
