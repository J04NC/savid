<?php

class EmpresaRolService
{
    private PDO $pdo;
    private EmpresaRolRepository $repo;

    public function __construct()
    {
        $database = new Database();
        $this->pdo = $database->connect();
        $this->repo = new EmpresaRolRepository($this->pdo);
    }

    /**
     * @return list<array{id: int, nombre: string, descripcion: string, habilitado: bool}>
     */
    public function listRolesForEmpresa(int $empresaId): array
    {
        $allowed = array_flip($this->repo->getAllowedRolIds($empresaId));

        $stmt = $this->pdo->query('
            SELECT id, nombre, descripcion
            FROM rol
            WHERE estado_id = 1
            AND deleted_at IS NULL
            AND id <> 1
            ORDER BY nombre
        ');

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = [
                'id' => (int)$row['id'],
                'nombre' => (string)$row['nombre'],
                'descripcion' => (string)($row['descripcion'] ?? ''),
                'habilitado' => isset($allowed[(int)$row['id']]),
            ];
        }

        return $out;
    }

    /**
     * @param list<int> $rolIds
     * @return array{success: bool, message?: string}
     */
    public function guardar(int $empresaId, array $rolIds): array
    {
        if ($empresaId <= 0) {
            return ['success' => false, 'message' => 'Empresa inválida.'];
        }

        $validIds = $this->validRolIds();
        $rolIds = array_values(array_intersect(array_map('intval', $rolIds), $validIds));

        $this->repo->replaceAllowedRoles($empresaId, $rolIds);

        return ['success' => true];
    }

    /**
     * @return list<int>
     */
    private function validRolIds(): array
    {
        $stmt = $this->pdo->query('SELECT id FROM rol WHERE estado_id = 1 AND deleted_at IS NULL AND id <> 1');

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
