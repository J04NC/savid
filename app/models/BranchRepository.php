<?php

class BranchRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function findActiveByEmpresaId($empresaId)
    {
        $stmt = $this->pdo->prepare("
            SELECT id, nombre
            FROM sede
            WHERE empresa_id = ?
            AND estado_id = 1
            ORDER BY nombre
        ");
        $stmt->execute([$empresaId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findActiveByIdAndEmpresaId($sedeId, $empresaId)
    {
        $stmt = $this->pdo->prepare("
            SELECT id, nombre
            FROM sede
            WHERE id = ?
            AND empresa_id = ?
            AND estado_id = 1
            LIMIT 1
        ");
        $stmt->execute([$sedeId, $empresaId]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
