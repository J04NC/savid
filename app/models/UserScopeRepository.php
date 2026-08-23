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

        // Se prepara dentro del bucle a propósito: usuario_empresa tiene columnas
        // rastreables, así que TrackableColumnsService reescribe el INSERT en el
        // primer execute() (más placeholders para created_by/updated_by) y muta
        // el statement en sitio. Reusar ese mismo statement en la vuelta
        // siguiente, pasando de nuevo solo 2 valores, viola el número de
        // parámetros que el statement ya reescrito espera (SQLSTATE[HY093]).
        foreach ($empresaIds as $empresaId) {
            $stmt = $this->pdo->prepare("
                INSERT INTO usuario_empresa (usuario_id, empresa_id, estado_id)
                VALUES (?, ?, 1)
            ");
            $stmt->execute([$usuarioId, (int)$empresaId]);
        }
    }

    public function replaceSedesForUsuario($usuarioId, array $sedeIds)
    {
        $stmt = $this->pdo->prepare("DELETE FROM usuario_sede WHERE usuario_id = ?");
        $stmt->execute([$usuarioId]);

        // Ver la nota en replaceEmpresasForUsuario: usuario_sede también tiene
        // columnas rastreables.
        foreach ($sedeIds as $sedeId) {
            $stmt = $this->pdo->prepare("
                INSERT INTO usuario_sede (usuario_id, sede_id, estado_id)
                VALUES (?, ?, 1)
            ");
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

        // Preparar dentro del bucle: ver la nota en replaceEmpresasForUsuario.
        // Reproducido exactamente este caso al seleccionar 4 empresas: la 1ª
        // se guardaba bien (reescribía el statement a 4 placeholders) y las
        // siguientes 3 fallaban con SQLSTATE[HY093] al pasarle solo 2 valores.
        foreach ($empresaIds as $empresaId) {
            $stmt = $this->pdo->prepare("
                INSERT INTO usuario_empresa (usuario_id, empresa_id, estado_id)
                VALUES (?, ?, 1)
                ON DUPLICATE KEY UPDATE estado_id = 1
            ");
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

        // Preparar dentro del bucle: ver la nota en addEmpresasForUsuario.
        foreach ($sedeIds as $sedeId) {
            $stmt = $this->pdo->prepare("
                INSERT INTO usuario_sede (usuario_id, sede_id, estado_id)
                VALUES (?, ?, 1)
                ON DUPLICATE KEY UPDATE estado_id = 1
            ");
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
