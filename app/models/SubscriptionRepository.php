<?php

class SubscriptionRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function findActiveLatestByEmpresaId($empresaId)
    {
        $stmt = $this->pdo->prepare("
            SELECT s.*, p.nombre AS plan_nombre
            FROM suscripcion s
            JOIN plan p ON p.id = s.plan_id
            WHERE s.empresa_id = ?
            AND s.activa = 1
            AND s.estado_id = 1
            ORDER BY s.id DESC
            LIMIT 1
        ");
        $stmt->execute([$empresaId]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
