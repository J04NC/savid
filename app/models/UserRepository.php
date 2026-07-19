<?php

class UserRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function findActiveByUsername($username)
    {
        $uNd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'usuario', 'u');

        $stmt = $this->pdo->prepare("
            SELECT u.*,
                t.foto_ruta,
                t.email,
                COALESCE(
                    NULLIF(TRIM(CONCAT_WS(' ', t.nombres, t.apellidos)), ''),
                    u.username
                ) AS nombre,
                (
                    SELECT ur.rol_id
                    FROM usuario_rol ur
                    WHERE ur.usuario_id = u.id AND ur.estado_id = 1
                    ORDER BY ur.rol_id
                    LIMIT 1
                ) AS rol_id,
                (
                    SELECT r.nombre
                    FROM usuario_rol ur
                    INNER JOIN rol r ON r.id = ur.rol_id AND r.estado_id = 1
                    WHERE ur.usuario_id = u.id AND ur.estado_id = 1
                    ORDER BY ur.rol_id
                    LIMIT 1
                ) AS rol_nombre
            FROM usuario u
            LEFT JOIN terceroidentificacion ti ON ti.id = u.terceroidentificacion_id
            LEFT JOIN tercero t ON t.id = ti.tercero_id
            WHERE u.username = ?
            AND u.estado_id = 1
            {$uNd}
            LIMIT 1
        ");
        $stmt->execute([$username]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Igual que findActiveByUsername pero por id — usado para retomar el login tras verificar 2FA.
     */
    public function findActiveById(int $usuarioId)
    {
        $uNd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'usuario', 'u');

        $stmt = $this->pdo->prepare("
            SELECT u.*,
                t.foto_ruta,
                t.email,
                COALESCE(
                    NULLIF(TRIM(CONCAT_WS(' ', t.nombres, t.apellidos)), ''),
                    u.username
                ) AS nombre,
                (
                    SELECT ur.rol_id
                    FROM usuario_rol ur
                    WHERE ur.usuario_id = u.id AND ur.estado_id = 1
                    ORDER BY ur.rol_id
                    LIMIT 1
                ) AS rol_id,
                (
                    SELECT r.nombre
                    FROM usuario_rol ur
                    INNER JOIN rol r ON r.id = ur.rol_id AND r.estado_id = 1
                    WHERE ur.usuario_id = u.id AND ur.estado_id = 1
                    ORDER BY ur.rol_id
                    LIMIT 1
                ) AS rol_nombre
            FROM usuario u
            LEFT JOIN terceroidentificacion ti ON ti.id = u.terceroidentificacion_id
            LEFT JOIN tercero t ON t.id = ti.tercero_id
            WHERE u.id = ?
            AND u.estado_id = 1
            {$uNd}
            LIMIT 1
        ");
        $stmt->execute([$usuarioId]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Usado por recuperación de contraseña: acepta username o correo del tercero asociado.
     */
    public function findActiveByUsernameOrEmail(string $identificador): ?array
    {
        $uNd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'usuario', 'u');

        $stmt = $this->pdo->prepare("
            SELECT u.id, u.username, u.estado_id,
                t.email,
                COALESCE(
                    NULLIF(TRIM(CONCAT_WS(' ', t.nombres, t.apellidos)), ''),
                    u.username
                ) AS nombre
            FROM usuario u
            LEFT JOIN terceroidentificacion ti ON ti.id = u.terceroidentificacion_id
            LEFT JOIN tercero t ON t.id = ti.tercero_id
            WHERE u.estado_id = 1
            {$uNd}
            AND (u.username = :identificador OR LOWER(TRIM(t.email)) = LOWER(TRIM(:identificador2)))
            ORDER BY u.id ASC
            LIMIT 1
        ");
        $stmt->execute([
            'identificador' => $identificador,
            'identificador2' => $identificador,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function updatePassword(int $usuarioId, string $passwordHash): void
    {
        $stmt = $this->pdo->prepare('UPDATE usuario SET password = ? WHERE id = ?');
        $stmt->execute([$passwordHash, $usuarioId]);
    }

    /**
     * Rol activo en usuario_rol (ej. Super Admin = rol_id 1).
     */
    public function hasRole(int $userId, int $roleId): bool
    {
        $stmt = $this->pdo->prepare('
            SELECT 1
            FROM usuario_rol
            WHERE usuario_id = ?
            AND rol_id = ?
            AND estado_id = 1
            LIMIT 1
        ');
        $stmt->execute([$userId, $roleId]);

        return (bool) $stmt->fetchColumn();
    }
}
