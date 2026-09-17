<?php

/**
 * Datos del modal "Usuarios de la empresa" (?url=empresa/usuarios/{id}):
 * listado de usuarios vinculados (por usuario_empresa o por sede), búsqueda
 * de candidatos, y las mutaciones de vínculo (link/unlink/toggle/delete).
 * Antes esta lógica (con SQL directo) vivía en EmpresaController.
 */
class EmpresaUsuarioRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findEmpresaConRazonSocial(int $empresaId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT e.id, t.razon_social
             FROM empresa e
             INNER JOIN tercero t ON t.id = e.tercero_id
             WHERE e.id = ? LIMIT 1'
        );
        $stmt->execute([$empresaId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchUsuarios(int $empresaId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT u.id, u.username, u.estado_id,
                    ti.numero AS nit_or_doc,
                    t.nombres, t.apellidos, t.razon_social,
                    ue.estado_id AS link_estado_id,
                    CASE WHEN ue.usuario_id IS NOT NULL THEN 1 ELSE 0 END AS vinculo_empresa,
                    (
                        SELECT COUNT(*)
                        FROM usuario_sede us
                        INNER JOIN sede s ON s.id = us.sede_id AND s.empresa_id = ?
                        WHERE us.usuario_id = u.id AND us.estado_id = 1
                    ) AS sedes_activas
               FROM usuario u
               INNER JOIN (
                    SELECT usuario_id FROM usuario_empresa WHERE empresa_id = ?
                    UNION
                    SELECT DISTINCT us.usuario_id
                      FROM usuario_sede us
                      INNER JOIN sede s ON s.id = us.sede_id AND s.empresa_id = ?
               ) AS eu ON eu.usuario_id = u.id
               LEFT JOIN usuario_empresa ue
                      ON ue.usuario_id = u.id AND ue.empresa_id = ?
               LEFT JOIN terceroidentificacion ti ON ti.id = u.terceroidentificacion_id
               LEFT JOIN tercero t ON t.id = ti.tercero_id
              ORDER BY vinculo_empresa DESC, COALESCE(ue.estado_id, 0) DESC, u.username ASC"
        );
        $stmt->execute([$empresaId, $empresaId, $empresaId, $empresaId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function esSuperAdminUsuario(int $usuarioId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM usuario_rol WHERE usuario_id = ? AND rol_id = 1 AND estado_id = 1 LIMIT 1'
        );
        $stmt->execute([$usuarioId]);

        return (bool)$stmt->fetchColumn();
    }

    public function tieneVinculoEmpresa(int $usuarioId, int $empresaId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM usuario_empresa WHERE usuario_id = ? AND empresa_id = ? LIMIT 1');
        $stmt->execute([$usuarioId, $empresaId]);

        return (bool)$stmt->fetchColumn();
    }

    public function tieneAsignacionSede(int $empresaId, int $usuarioId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM usuario_sede us
             INNER JOIN sede s ON s.id = us.sede_id AND s.empresa_id = ?
             WHERE us.usuario_id = ? LIMIT 1'
        );
        $stmt->execute([$empresaId, $usuarioId]);

        return (bool)$stmt->fetchColumn();
    }

    public function deleteAsignacionesSede(int $empresaId, int $usuarioId): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE us FROM usuario_sede us
             INNER JOIN sede s ON s.id = us.sede_id AND s.empresa_id = ?
             WHERE us.usuario_id = ?'
        );
        $stmt->execute([$empresaId, $usuarioId]);
    }

    public function deleteVinculoEmpresa(int $usuarioId, int $empresaId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM usuario_empresa WHERE usuario_id = ? AND empresa_id = ?');
        $stmt->execute([$usuarioId, $empresaId]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findUsuario(int $usuarioId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, estado_id FROM usuario WHERE id = ? LIMIT 1');
        $stmt->execute([$usuarioId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function upsertVinculo(int $usuarioId, int $empresaId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO usuario_empresa (usuario_id, empresa_id, estado_id)
             VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE estado_id = 1'
        );
        $stmt->execute([$usuarioId, $empresaId]);
    }

    public function findEstadoVinculo(int $usuarioId, int $empresaId): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT estado_id FROM usuario_empresa WHERE usuario_id = ? AND empresa_id = ? LIMIT 1'
        );
        $stmt->execute([$usuarioId, $empresaId]);
        $estado = $stmt->fetchColumn();

        return $estado !== false ? (int)$estado : null;
    }

    public function updateEstadoVinculo(int $usuarioId, int $empresaId, int $nuevoEstado): void
    {
        $stmt = $this->pdo->prepare('UPDATE usuario_empresa SET estado_id = ? WHERE usuario_id = ? AND empresa_id = ?');
        $stmt->execute([$nuevoEstado, $usuarioId, $empresaId]);
    }

    public function inactivarSedesDeEmpresa(int $usuarioId, int $empresaId): void
    {
        // UPDATE de una sola tabla (subquery en vez de JOIN): un JOIN aquí hace que
        // TrackableColumnsService inyecte `updated_at=NOW(3)` sin prefijo, ambiguo
        // entre usuario_sede y sede (ambas tienen esa columna) y el UPDATE falla.
        $stmt = $this->pdo->prepare(
            'UPDATE usuario_sede
             SET estado_id = 2
             WHERE usuario_id = ?
               AND sede_id IN (SELECT id FROM sede WHERE empresa_id = ?)'
        );
        $stmt->execute([$usuarioId, $empresaId]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function buscarCandidatos(string $term, int $empresaId): array
    {
        $like = '%' . $term . '%';

        $sql = "SELECT u.id, u.username, ti.numero AS doc, t.nombres, t.apellidos, t.razon_social,
                       (
                         EXISTS (
                           SELECT 1 FROM usuario_empresa ue
                           WHERE ue.usuario_id = u.id AND ue.empresa_id = ? AND ue.estado_id = 1
                         )
                         OR EXISTS (
                           SELECT 1 FROM usuario_sede us
                           INNER JOIN sede s ON s.id = us.sede_id AND s.empresa_id = ?
                           WHERE us.usuario_id = u.id
                         )
                       ) AS ya_vinculado
                  FROM usuario u
                  LEFT JOIN terceroidentificacion ti ON ti.id = u.terceroidentificacion_id
                  LEFT JOIN tercero t ON t.id = ti.tercero_id
                 WHERE (u.estado_id IS NULL OR u.estado_id = 1)
                   AND (u.username LIKE ?
                        OR ti.numero LIKE ?
                        OR t.nombres LIKE ?
                        OR t.apellidos LIKE ?
                        OR t.razon_social LIKE ?)
                   AND NOT EXISTS (
                     SELECT 1 FROM usuario_rol ur
                     WHERE ur.usuario_id = u.id AND ur.rol_id = 1 AND ur.estado_id = 1
                   )
                 ORDER BY u.username ASC
                 LIMIT 15";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$empresaId, $empresaId, $like, $like, $like, $like, $like]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function existeVinculoComunActivo(int $targetUsuarioId, int $usuarioLogueadoId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1
               FROM usuario_empresa ue_target
               INNER JOIN usuario_empresa ue_self
                 ON ue_self.empresa_id = ue_target.empresa_id
                AND ue_self.estado_id = 1
              WHERE ue_target.usuario_id = ? AND ue_target.estado_id = 1
                AND ue_self.usuario_id = ?
              LIMIT 1'
        );
        $stmt->execute([$targetUsuarioId, $usuarioLogueadoId]);

        return (bool)$stmt->fetchColumn();
    }
}
