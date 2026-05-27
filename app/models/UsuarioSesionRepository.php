<?php

class UsuarioSesionRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function insertOpenSession(
        int $usuarioId,
        ?string $phpSessionId,
        ?int $empresaId,
        ?int $sedeId,
        ?string $ip,
        ?string $userAgent
    ): int {
        $stmt = $this->pdo->prepare('
            INSERT INTO usuario_sesion (
                usuario_id, php_session_id, empresa_id, sede_id,
                ip, user_agent, login_at, last_activity_at
            ) VALUES (?, ?, ?, ?, ?, ?, NOW(3), NOW(3))
        ');
        $stmt->execute([
            $usuarioId,
            $phpSessionId,
            $empresaId > 0 ? $empresaId : null,
            $sedeId > 0 ? $sedeId : null,
            $ip,
            $userAgent,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function touchSession(
        int $sesionId,
        ?int $empresaId,
        ?int $sedeId
    ): void {
        $stmt = $this->pdo->prepare('
            UPDATE usuario_sesion
            SET last_activity_at = NOW(3),
                empresa_id = ?,
                sede_id = ?,
                updated_at = NOW(3)
            WHERE id = ?
            AND logout_at IS NULL
            AND deleted_at IS NULL
        ');
        $stmt->execute([
            $empresaId > 0 ? $empresaId : null,
            $sedeId > 0 ? $sedeId : null,
            $sesionId,
        ]);
    }

    public function closeSession(int $sesionId, string $motivo): void
    {
        $motivo = substr(preg_replace('/[^a-z_]/', '', strtolower($motivo)), 0, 32);

        $stmt = $this->pdo->prepare('
            UPDATE usuario_sesion
            SET logout_at = NOW(3),
                logout_motivo = ?,
                updated_at = NOW(3)
            WHERE id = ?
            AND logout_at IS NULL
        ');
        $stmt->execute([$motivo !== '' ? $motivo : 'logout', $sesionId]);
    }

    public function closeOpenSessionsByPhpSessionId(string $phpSessionId, string $motivo): void
    {
        if ($phpSessionId === '') {
            return;
        }

        $motivo = substr(preg_replace('/[^a-z_]/', '', strtolower($motivo)), 0, 32);

        $stmt = $this->pdo->prepare('
            UPDATE usuario_sesion
            SET logout_at = NOW(3),
                logout_motivo = ?,
                updated_at = NOW(3)
            WHERE php_session_id = ?
            AND logout_at IS NULL
        ');
        $stmt->execute([$motivo !== '' ? $motivo : 'logout', $phpSessionId]);
    }

    /**
     * @param array{
     *   esSuperAdmin: bool,
     *   allowedEmpresaIds: list<int>,
     *   allowedSedeIds: list<int>,
     *   filterEmpresaId: ?int,
     *   filterSedeId: ?int
     * } $scope
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function searchReport(array $filters, int $limit, int $offset, array $scope): array
    {
        $where = ['us.deleted_at IS NULL'];
        $params = [];
        $scopeService = new ReportScopeService();

        if (!empty($filters['desde'])) {
            $where[] = 'us.login_at >= ?';
            $params[] = $filters['desde'] . ' 00:00:00';
        }

        if (!empty($filters['hasta'])) {
            $where[] = 'us.login_at <= ?';
            $params[] = $filters['hasta'] . ' 23:59:59';
        }

        if (!empty($filters['usuario_id'])) {
            $where[] = 'us.usuario_id = ?';
            $params[] = (int)$filters['usuario_id'];
        }

        if (!empty($filters['solo_activas'])) {
            $where[] = 'us.logout_at IS NULL';
            $where[] = 'us.last_activity_at >= DATE_SUB(NOW(3), INTERVAL ? MINUTE)';
            $params[] = max(5, (int)($filters['inactividad_min'] ?? 30));
        }

        $scopeService->applyEmpresaScope(
            $where,
            $params,
            'us.empresa_id',
            $scope,
            $scope['filterEmpresaId'] ?? null
        );
        $scopeService->applySedeScope(
            $where,
            $params,
            'us.sede_id',
            $scope,
            $scope['filterSedeId'] ?? null
        );

        if (!empty($filters['q'])) {
            $where[] = '(u.username LIKE ? OR us.ip LIKE ? OR us.php_session_id LIKE ?)';
            $like = '%' . $filters['q'] . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $whereSql = implode(' AND ', $where);

        $countStmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM usuario_sesion us
            INNER JOIN usuario u ON u.id = us.usuario_id
            WHERE {$whereSql}
        ");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $sql = "
            SELECT
                us.*,
                u.username,
                u.sesion_idle_minutos,
                t.razon_social AS empresa_nombre,
                s.nombre AS sede_nombre,
                CASE
                    WHEN us.logout_at IS NOT NULL THEN 'cerrada'
                    WHEN us.last_activity_at >= DATE_SUB(NOW(3), INTERVAL COALESCE(NULLIF(u.sesion_idle_minutos, 0), 30) MINUTE) THEN 'activa'
                    ELSE 'inactiva'
                END AS estado_sesion,
                TIMESTAMPDIFF(
                    SECOND,
                    us.login_at,
                    COALESCE(us.logout_at, NOW(3))
                ) AS duracion_segundos
            FROM usuario_sesion us
            INNER JOIN usuario u ON u.id = us.usuario_id
            LEFT JOIN empresa e ON e.id = us.empresa_id
            LEFT JOIN tercero t ON t.id = e.tercero_id
            LEFT JOIN sede s ON s.id = us.sede_id
            WHERE {$whereSql}
            ORDER BY us.last_activity_at DESC, us.id DESC
            LIMIT " . (int)$limit . ' OFFSET ' . (int)$offset;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return [
            'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'total' => $total,
        ];
    }

    /**
     * @param array{
     *   esSuperAdmin: bool,
     *   allowedEmpresaIds: list<int>,
     *   allowedSedeIds: list<int>,
     *   filterEmpresaId: ?int,
     *   filterSedeId: ?int
     * } $scope
     * @return array{activas: int, total_hoy: int}
     */
    public function countSummary(array $scope): array
    {
        $scopeService = new ReportScopeService();
        $where = ['us.deleted_at IS NULL'];
        $params = [];

        $scopeService->applyEmpresaScope(
            $where,
            $params,
            'us.empresa_id',
            $scope,
            $scope['filterEmpresaId'] ?? null
        );
        $scopeService->applySedeScope(
            $where,
            $params,
            'us.sede_id',
            $scope,
            $scope['filterSedeId'] ?? null
        );

        $scopeSql = count($where) > 1
            ? ' AND ' . implode(' AND ', array_slice($where, 1))
            : '';

        $activasStmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM usuario_sesion us
            INNER JOIN usuario u ON u.id = us.usuario_id
            WHERE us.deleted_at IS NULL
            AND us.logout_at IS NULL
            AND us.last_activity_at >= DATE_SUB(NOW(3), INTERVAL COALESCE(NULLIF(u.sesion_idle_minutos, 0), 30) MINUTE)
            {$scopeSql}
        ");
        $activasStmt->execute($params);
        $activas = (int)$activasStmt->fetchColumn();

        $hoyStmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM usuario_sesion us
            WHERE us.deleted_at IS NULL
            AND DATE(us.login_at) = CURDATE()
            {$scopeSql}
        ");
        $hoyStmt->execute($params);
        $hoy = (int)$hoyStmt->fetchColumn();

        return ['activas' => $activas, 'total_hoy' => $hoy];
    }
}
