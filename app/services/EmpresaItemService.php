<?php

class EmpresaItemService
{
    private PDO $pdo;
    private EmpresaItemRepository $repo;

    public function __construct()
    {
        $database = new Database();
        $this->pdo = $database->connect();
        $this->repo = new EmpresaItemRepository($this->pdo);
    }

    /**
     * Ítems agrupados por módulo, cada uno con su estado habilitado/no para la empresa.
     *
     * @return array<string, array{modulo_id: int, items: list<array{id: int, nombre: string, habilitado: bool}>}>
     */
    public function listItemsGroupedForEmpresa(int $empresaId): array
    {
        $allowed = array_flip($this->repo->getAllowedItemIds($empresaId));

        $stmt = $this->pdo->query('
            SELECT i.id, i.nombre, i.item_padre_id, i.modulo_id, m.nombre AS modulo_nombre, m.orden AS modulo_orden
            FROM item i
            INNER JOIN modulo m ON m.id = i.modulo_id
            WHERE i.estado_id = 1
            AND i.deleted_at IS NULL
            AND i.ruta IS NOT NULL AND TRIM(i.ruta) <> \'\'
            ORDER BY m.orden, i.orden, i.nombre
        ');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $grouped = [];
        foreach ($rows as $row) {
            $moduloNombre = (string)$row['modulo_nombre'];
            if (!isset($grouped[$moduloNombre])) {
                $grouped[$moduloNombre] = [
                    'modulo_id' => (int)$row['modulo_id'],
                    'items' => [],
                ];
            }
            $grouped[$moduloNombre]['items'][] = [
                'id' => (int)$row['id'],
                'nombre' => (string)$row['nombre'],
                'habilitado' => isset($allowed[(int)$row['id']]),
            ];
        }

        return $grouped;
    }

    /**
     * @param list<int> $itemIds
     * @return array{success: bool, message?: string}
     */
    public function guardar(int $empresaId, array $itemIds): array
    {
        if ($empresaId <= 0) {
            return ['success' => false, 'message' => 'Empresa inválida.'];
        }

        $validIds = $this->validItemIds();
        $itemIds = array_values(array_intersect(array_map('intval', $itemIds), $validIds));

        $this->repo->replaceAllowedItems($empresaId, $itemIds);

        return ['success' => true];
    }

    /**
     * @return list<int>
     */
    private function validItemIds(): array
    {
        $stmt = $this->pdo->query('SELECT id FROM item WHERE estado_id = 1 AND deleted_at IS NULL');

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
