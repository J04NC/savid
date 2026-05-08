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
        $stmt = $this->pdo->prepare("
            SELECT u.*, r.nombre AS rol_nombre
            FROM usuario u
            LEFT JOIN rol r ON r.id = u.rol_id
            WHERE u.username = ?
            AND u.estado_id = 1
            LIMIT 1
        ");
        $stmt->execute([$username]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
