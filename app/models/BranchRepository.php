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

    public function findActiveByUserId($userId)
    {
        $stmt = $this->pdo->prepare("
            SELECT s.id, s.nombre
            FROM usuario_sede us
            JOIN sede s ON s.id = us.sede_id
            WHERE us.usuario_id = ?
            AND us.estado_id = 1
            AND s.estado_id = 1
            ORDER BY s.id
        ");
        $stmt->execute([$userId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Sedes a las que el usuario tiene acceso dentro de una empresa concreta.
     */
    public function findActiveByUserIdAndEmpresaId($userId, $empresaId)
    {
        $stmt = $this->pdo->prepare("
            SELECT s.id, s.nombre
            FROM usuario_sede us
            JOIN sede s ON s.id = us.sede_id
            WHERE us.usuario_id = ?
            AND us.estado_id = 1
            AND s.estado_id = 1
            AND s.empresa_id = ?
            ORDER BY s.nombre
        ");
        $stmt->execute([$userId, $empresaId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
