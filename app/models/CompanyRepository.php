<?php

class CompanyRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function findActiveById($empresaId)
    {
        $stmt = $this->pdo->prepare("
            SELECT id, razon_social
            FROM empresa
            WHERE id = ?
            AND estado_id = 1
            LIMIT 1
        ");
        $stmt->execute([$empresaId]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function findActiveByUserId($userId)
    {
        $stmt = $this->pdo->prepare("
            SELECT e.id, e.razon_social
            FROM usuario_empresa ue
            JOIN empresa e ON e.id = ue.empresa_id
            WHERE ue.usuario_id = ?
            AND ue.estado_id = 1
            AND e.estado_id = 1
            ORDER BY e.razon_social
        ");
        $stmt->execute([$userId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findAllActive()
    {
        $stmt = $this->pdo->query("
            SELECT id, razon_social
            FROM empresa
            WHERE estado_id = 1
            ORDER BY razon_social
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
