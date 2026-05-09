<?php

class UserScopeRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function findEmpresaIdsByUsuario($usuarioId)
    {
        $stmt = $this->pdo->prepare("
            SELECT empresa_id
            FROM usuario_empresa
            WHERE usuario_id = ?
            AND estado_id = 1
        ");
        $stmt->execute([$usuarioId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function findSedeIdsByUsuario($usuarioId)
    {
        $stmt = $this->pdo->prepare("
            SELECT sede_id
            FROM usuario_sede
            WHERE usuario_id = ?
            AND estado_id = 1
        ");
        $stmt->execute([$usuarioId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function replaceEmpresasForUsuario($usuarioId, array $empresaIds)
    {
        $stmt = $this->pdo->prepare("DELETE FROM usuario_empresa WHERE usuario_id = ?");
        $stmt->execute([$usuarioId]);

        $stmt = $this->pdo->prepare("
            INSERT INTO usuario_empresa (usuario_id, empresa_id, estado_id)
            VALUES (?, ?, 1)
        ");

        foreach ($empresaIds as $empresaId) {
            $stmt->execute([$usuarioId, (int)$empresaId]);
        }
    }

    public function replaceSedesForUsuario($usuarioId, array $sedeIds)
    {
        $stmt = $this->pdo->prepare("DELETE FROM usuario_sede WHERE usuario_id = ?");
        $stmt->execute([$usuarioId]);

        $stmt = $this->pdo->prepare("
            INSERT INTO usuario_sede (usuario_id, sede_id, estado_id)
            VALUES (?, ?, 1)
        ");

        foreach ($sedeIds as $sedeId) {
            $stmt->execute([$usuarioId, (int)$sedeId]);
        }
    }

    public function getEmpresaIdForSede($sedeId)
    {
        $stmt = $this->pdo->prepare("
            SELECT empresa_id
            FROM sede
            WHERE id = ?
            AND estado_id = 1
            LIMIT 1
        ");
        $stmt->execute([$sedeId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? (int)$row['empresa_id'] : null;
    }
}
