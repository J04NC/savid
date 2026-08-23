<?php

/**
 * Qué roles puede usar cada empresa (tabla `empresa_rol`). Fuente de verdad
 * para filtrar el listado de Roles, el picker de usuario/roles y el acceso
 * a rol/permisos, ya que `rol` es una tabla global (sin empresa_id).
 */
class EmpresaRolRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @return list<int>
     */
    public function getAllowedRolIds(int $empresaId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT rol_id FROM empresa_rol
            WHERE empresa_id = ? AND estado_id = 1 AND deleted_at IS NULL
        ');
        $stmt->execute([$empresaId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Unión de roles habilitados para cualquiera de las empresas dadas.
     *
     * @param list<int> $empresaIds
     * @return list<int>
     */
    public function getAllowedRolIdsForEmpresas(array $empresaIds): array
    {
        $empresaIds = array_values(array_unique(array_map('intval', $empresaIds)));
        if ($empresaIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($empresaIds), '?'));
        $stmt = $this->pdo->prepare("
            SELECT DISTINCT rol_id FROM empresa_rol
            WHERE empresa_id IN ({$placeholders}) AND estado_id = 1 AND deleted_at IS NULL
        ");
        $stmt->execute($empresaIds);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function isRolAllowed(int $empresaId, int $rolId): bool
    {
        $stmt = $this->pdo->prepare('
            SELECT 1 FROM empresa_rol
            WHERE empresa_id = ? AND rol_id = ? AND estado_id = 1 AND deleted_at IS NULL
            LIMIT 1
        ');
        $stmt->execute([$empresaId, $rolId]);

        return (bool)$stmt->fetchColumn();
    }

    /**
     * Reemplaza el conjunto completo de roles habilitados para la empresa.
     * Cada INSERT/UPDATE se prepara de nuevo por fila (ver nota en
     * EmpresaItemRepository::replaceAllowedItems sobre por qué reutilizar
     * el mismo PDOStatement rompe con AuditingPDOStatement/TrackableColumnsService).
     *
     * @param list<int> $rolIds
     */
    public function replaceAllowedRoles(int $empresaId, array $rolIds): void
    {
        $rolIds = array_values(array_unique(array_map('intval', $rolIds)));
        $desiredSet = array_flip($rolIds);

        $this->pdo->beginTransaction();
        try {
            $existingStmt = $this->pdo->prepare('
                SELECT rol_id, estado_id FROM empresa_rol WHERE empresa_id = ?
            ');
            $existingStmt->execute([$empresaId]);
            $existingRows = [];
            foreach ($existingStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $existingRows[(int)$row['rol_id']] = (int)$row['estado_id'];
            }

            foreach ($rolIds as $rolId) {
                if (!array_key_exists($rolId, $existingRows)) {
                    $insert = $this->pdo->prepare('
                        INSERT INTO empresa_rol (empresa_id, rol_id, estado_id) VALUES (?, ?, 1)
                    ');
                    $insert->execute([$empresaId, $rolId]);
                } elseif ($existingRows[$rolId] !== 1) {
                    $update = $this->pdo->prepare('
                        UPDATE empresa_rol SET estado_id = ? WHERE empresa_id = ? AND rol_id = ?
                    ');
                    $update->execute([1, $empresaId, $rolId]);
                }
            }

            foreach ($existingRows as $rolId => $estadoId) {
                if ($estadoId === 1 && !isset($desiredSet[$rolId])) {
                    $update = $this->pdo->prepare('
                        UPDATE empresa_rol SET estado_id = ? WHERE empresa_id = ? AND rol_id = ?
                    ');
                    $update->execute([0, $empresaId, $rolId]);
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
