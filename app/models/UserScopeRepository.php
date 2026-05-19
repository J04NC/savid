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

    public function removeEmpresasForUsuario($usuarioId, array $empresaIds)
    {
        if (empty($empresaIds)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($empresaIds), '?'));
        $params = array_merge([$usuarioId], array_map('intval', $empresaIds));

        $stmt = $this->pdo->prepare(
            "DELETE FROM usuario_empresa WHERE usuario_id = ? AND empresa_id IN ($placeholders)"
        );
        $stmt->execute($params);
    }

    public function addEmpresasForUsuario($usuarioId, array $empresaIds)
    {
        if (empty($empresaIds)) {
            return;
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO usuario_empresa (usuario_id, empresa_id, estado_id)
            VALUES (?, ?, 1)
            ON DUPLICATE KEY UPDATE estado_id = 1
        ");

        foreach ($empresaIds as $empresaId) {
            $stmt->execute([$usuarioId, (int)$empresaId]);
        }
    }

    public function removeSedesForUsuario($usuarioId, array $sedeIds)
    {
        if (empty($sedeIds)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($sedeIds), '?'));
        $params = array_merge([$usuarioId], array_map('intval', $sedeIds));

        $stmt = $this->pdo->prepare(
            "DELETE FROM usuario_sede WHERE usuario_id = ? AND sede_id IN ($placeholders)"
        );
        $stmt->execute($params);
    }

    public function addSedesForUsuario($usuarioId, array $sedeIds)
    {
        if (empty($sedeIds)) {
            return;
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO usuario_sede (usuario_id, sede_id, estado_id)
            VALUES (?, ?, 1)
            ON DUPLICATE KEY UPDATE estado_id = 1
        ");

        foreach ($sedeIds as $sedeId) {
            $stmt->execute([$usuarioId, (int)$sedeId]);
        }
    }

    public function removeSedesForUsuarioByEmpresas($usuarioId, array $empresaIds)
    {
        if (empty($empresaIds)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($empresaIds), '?'));
        $params = array_merge([$usuarioId], array_map('intval', $empresaIds));

        $stmt = $this->pdo->prepare("
            DELETE us FROM usuario_sede us
            INNER JOIN sede s ON s.id = us.sede_id
            WHERE us.usuario_id = ?
            AND s.empresa_id IN ($placeholders)
        ");
        $stmt->execute($params);
    }

    public function targetHasEmpresa($usuarioId, $empresaId): bool
    {
        $stmt = $this->pdo->prepare('
            SELECT 1 FROM usuario_empresa
            WHERE usuario_id = ? AND empresa_id = ? AND estado_id = 1
            LIMIT 1
        ');
        $stmt->execute([(int)$usuarioId, (int)$empresaId]);

        return (bool)$stmt->fetchColumn();
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
