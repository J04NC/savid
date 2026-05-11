<?php

class UserAccessRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function findActiveUserById($usuarioId)
    {
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM usuario
            WHERE id = ?
            AND estado_id = 1
            LIMIT 1
        ");
        $stmt->execute([$usuarioId]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function findAllActiveRoles()
    {
        $stmt = $this->pdo->query("
            SELECT id, nombre
            FROM rol
            WHERE estado_id = 1
            ORDER BY nombre
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Asignaciones activas rol + alcance (empresa/sede NULL = global o según negocio).
     *
     * @return array<int, array{rol_id:int, empresa_id:?int, sede_id:?int}>
     */
    public function findRoleAssignmentsByUserId(int $usuarioId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT rol_id, empresa_id, sede_id
            FROM usuario_rol
            WHERE usuario_id = ?
            AND estado_id = 1
            ORDER BY rol_id, empresa_id, sede_id
        ");
        $stmt->execute([$usuarioId]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $out = [];

        foreach ($rows as $r) {
            $out[] = [
                'rol_id' => (int)$r['rol_id'],
                'empresa_id' => $r['empresa_id'] === null || $r['empresa_id'] === '' ? null : (int)$r['empresa_id'],
                'sede_id' => $r['sede_id'] === null || $r['sede_id'] === '' ? null : (int)$r['sede_id'],
            ];
        }

        return $out;
    }

    public function deleteRolesByUserId($usuarioId)
    {
        $stmt = $this->pdo->prepare("DELETE FROM usuario_rol WHERE usuario_id = ?");
        $stmt->execute([$usuarioId]);
    }

    public function insertUserRole(int $usuarioId, int $rolId, ?int $empresaId, ?int $sedeId): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO usuario_rol (usuario_id, rol_id, empresa_id, sede_id, estado_id)
            VALUES (?, ?, ?, ?, 1)
        ");
        $stmt->execute([$usuarioId, $rolId, $empresaId, $sedeId]);
    }

    public function getPermissionMatrixRows()
    {
        return $this->pdo->query("
            SELECT
                m.nombre AS modulo,
                i.id AS item_id,
                i.nombre AS item,
                a.nombre AS accion,
                a.codigo,
                ia.id AS item_accion_id
            FROM item_accion ia
            JOIN item i ON i.id = ia.item_id
            JOIN modulo m ON m.id = i.modulo_id
            JOIN accion a ON a.id = ia.accion_id
            WHERE ia.estado_id = 1
            AND i.estado_id = 1
            ORDER BY m.id, i.orden, a.orden
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Permisos directos del usuario para el contexto (empresa/sede) indicado.
     * Coincide con la lógica de alcance de PermisoService::tenantScopeSql.
     */
    public function getAllowedPermissionItemAccionIdsForScope(int $usuarioId, ?int $empresaId, ?int $sedeId): array
    {
        [$scopeSql, $scopeParams] = PermisoService::tenantScopeSql('p', $empresaId, $sedeId);

        $sql = "
            SELECT DISTINCT p.item_accion_id
            FROM permiso p
            WHERE p.usuario_id = ?
            AND p.estado_id = 5
            $scopeSql
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge([$usuarioId], $scopeParams));

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function insertAllowedPermission(int $usuarioId, int $itemAccionId, ?int $empresaId, ?int $sedeId): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO permiso (usuario_id, item_accion_id, estado_id, empresa_id, sede_id)
            VALUES (?, ?, 5, ?, ?)
        ');
        $stmt->execute([$usuarioId, $itemAccionId, $empresaId, $sedeId]);
    }

    public function deleteAllowedPermission(int $usuarioId, int $itemAccionId, ?int $empresaId, ?int $sedeId): void
    {
        $stmt = $this->pdo->prepare('
            DELETE FROM permiso
            WHERE usuario_id = ?
            AND item_accion_id = ?
            AND estado_id = 5
            AND empresa_id <=> ?
            AND sede_id <=> ?
        ');
        $stmt->execute([$usuarioId, $itemAccionId, $empresaId, $sedeId]);
    }
}
