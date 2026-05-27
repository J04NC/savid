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
        $eNd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'empresa', 'e');

        $stmt = $this->pdo->prepare("
            SELECT e.id, t.razon_social
            FROM empresa e
            INNER JOIN tercero t ON t.id = e.tercero_id
            WHERE e.id = ?
            AND e.estado_id = 1
            {$eNd}
            LIMIT 1
        ");
        $stmt->execute([$empresaId]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function findActiveByUserId($userId)
    {
        $eNd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'empresa', 'e');

        $stmt = $this->pdo->prepare("
            SELECT e.id, t.razon_social
            FROM usuario_empresa ue
            JOIN empresa e ON e.id = ue.empresa_id
            INNER JOIN tercero t ON t.id = e.tercero_id
            WHERE ue.usuario_id = ?
            AND ue.estado_id = 1
            AND e.estado_id = 1
            {$eNd}
            ORDER BY t.razon_social
        ");
        $stmt->execute([$userId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findAllActive()
    {
        $eNd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'empresa', 'e');

        $stmt = $this->pdo->query("
            SELECT e.id, t.razon_social
            FROM empresa e
            INNER JOIN tercero t ON t.id = e.tercero_id
            WHERE e.estado_id = 1
            {$eNd}
            ORDER BY t.razon_social
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
