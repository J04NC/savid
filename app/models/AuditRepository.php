<?php

class AuditRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
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
    public function search(
        array $filters,
        int $limit,
        int $offset,
        bool $includeArchive = false,
        array $scope = [],
        string $orderColumn = 'a.occurred_at',
        string $orderDir = 'desc'
    ): array {
        $table = $includeArchive ? 'auditoria_archivo' : 'auditoria';
        $where = ['1=1'];
        $params = [];
        $scopeService = new ReportScopeService();

        if (!empty($filters['desde'])) {
            $where[] = 'occurred_at >= ?';
            $params[] = $filters['desde'] . ' 00:00:00';
        }

        if (!empty($filters['hasta'])) {
            $where[] = 'occurred_at <= ?';
            $params[] = $filters['hasta'] . ' 23:59:59';
        }

        if (!empty($filters['tabla'])) {
            $where[] = 'tabla = ?';
            $params[] = preg_replace('/[^A-Za-z0-9_]/', '', (string)$filters['tabla']);
        }

        if (!empty($filters['accion'])) {
            $where[] = 'accion = ?';
            $params[] = $filters['accion'];
        }

        if (!empty($filters['usuario_id'])) {
            $where[] = 'usuario_id = ?';
            $params[] = (int)$filters['usuario_id'];
        }

        if (!empty($filters['registro_id'])) {
            $where[] = 'registro_id = ?';
            $params[] = (string)$filters['registro_id'];
        }

        if (!empty($filters['q'])) {
            $where[] = '(sql_resumen LIKE ? OR tabla LIKE ? OR registro_id LIKE ?)';
            $like = '%' . $filters['q'] . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        if ($scope !== []) {
            $scopeService->applyEmpresaScope(
                $where,
                $params,
                'a.empresa_id',
                $scope,
                $scope['filterEmpresaId'] ?? null
            );
            $scopeService->applySedeScope(
                $where,
                $params,
                'a.sede_id',
                $scope,
                $scope['filterSedeId'] ?? null
            );
        }

        $whereSql = implode(' AND ', $where);

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM `{$table}` a WHERE {$whereSql}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $allowedOrderColumns = ['a.occurred_at', 'a.accion', 'a.tabla', 'a.registro_id', 'u.username', 'a.empresa_id', 'a.sede_id'];
        if (!in_array($orderColumn, $allowedOrderColumns, true)) {
            $orderColumn = 'a.occurred_at';
        }
        $orderDir = strtolower($orderDir) === 'asc' ? 'ASC' : 'DESC';

        // Solo columnas livianas: los snapshots JSON (datos_anteriores/datos_nuevos/campos_cambiados)
        // no se usan en el listado, solo en el modal de detalle (findById), que sí trae la fila completa.
        $sql = "
            SELECT a.id, a.occurred_at, a.accion, a.tabla, a.registro_id,
                   a.usuario_id, a.empresa_id, a.sede_id,
                   u.username AS usuario_username
            FROM `{$table}` a
            LEFT JOIN usuario u ON u.id = a.usuario_id
            WHERE {$whereSql}
            ORDER BY {$orderColumn} {$orderDir}, a.id DESC
            LIMIT " . (int)$limit . ' OFFSET ' . (int)$offset;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Total de filas visibles para el alcance (empresa/sede) del usuario, sin sus filtros propios.
     * Es el "recordsTotal" que espera DataTables en modo servidor.
     *
     * @param array{esSuperAdmin: bool, allowedEmpresaIds: list<int>, allowedSedeIds: list<int>, filterEmpresaId: ?int, filterSedeId: ?int} $scope
     */
    public function countScoped(bool $includeArchive, array $scope): int
    {
        $table = $includeArchive ? 'auditoria_archivo' : 'auditoria';
        $where = ['1=1'];
        $params = [];

        if ($scope !== []) {
            $scopeService = new ReportScopeService();
            $scopeService->applyEmpresaScope($where, $params, 'a.empresa_id', $scope, $scope['filterEmpresaId'] ?? null);
            $scopeService->applySedeScope($where, $params, 'a.sede_id', $scope, $scope['filterSedeId'] ?? null);
        }

        $whereSql = implode(' AND ', $where);
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM `{$table}` a WHERE {$whereSql}");
        $stmt->execute($params);

        return (int)$stmt->fetchColumn();
    }

    /**
     * @param array{
     *   esSuperAdmin: bool,
     *   allowedEmpresaIds: list<int>,
     *   allowedSedeIds: list<int>
     * } $scope
     */
    public function findById(int $id, bool $fromArchive = false, array $scope = []): ?array
    {
        $table = $fromArchive ? 'auditoria_archivo' : 'auditoria';

        $stmt = $this->pdo->prepare("
            SELECT a.*, u.username AS usuario_username
            FROM `{$table}` a
            LEFT JOIN usuario u ON u.id = a.usuario_id
            WHERE a.id = ?
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        if ($scope !== [] && !(new ReportScopeService())->canAccessRecord(
            $scope,
            isset($row['empresa_id']) ? (int)$row['empresa_id'] : null,
            isset($row['sede_id']) ? (int)$row['sede_id'] : null
        )) {
            return null;
        }

        return $row;
    }

    /**
     * @return list<string>
     */
    public function listDistinctTables(): array
    {
        $stmt = $this->pdo->query('
            SELECT DISTINCT tabla FROM (
                SELECT tabla FROM auditoria
                UNION
                SELECT tabla FROM auditoria_archivo
            ) t
            ORDER BY tabla
        ');

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /**
     * Mueve registros antiguos a auditoria_archivo y los elimina de auditoria.
     *
     * @return array{moved: int, cutoff: string}
     */
    public function archiveOlderThanMonths(int $months): array
    {
        $months = max(1, $months);
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$months} months"));

        return AuditService::withoutAuditing(function () use ($cutoff) {
            $this->pdo->beginTransaction();

            try {
                $insert = $this->pdo->prepare('
                    INSERT INTO auditoria_archivo (
                        id, occurred_at, accion, tabla, registro_id,
                        datos_anteriores, datos_nuevos, campos_cambiados,
                        sql_resumen, usuario_id, empresa_id, sede_id,
                        ip, user_agent, request_url, archived_at
                    )
                    SELECT
                        id, occurred_at, accion, tabla, registro_id,
                        datos_anteriores, datos_nuevos, campos_cambiados,
                        sql_resumen, usuario_id, empresa_id, sede_id,
                        ip, user_agent, request_url, NOW(3)
                    FROM auditoria
                    WHERE occurred_at < ?
                ');
                $insert->execute([$cutoff]);
                $moved = $insert->rowCount();

                $del = $this->pdo->prepare('DELETE FROM auditoria WHERE occurred_at < ?');
                $del->execute([$cutoff]);

                $this->pdo->commit();

                return ['moved' => $moved, 'cutoff' => $cutoff];
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }

                throw $e;
            }
        });
    }
}
