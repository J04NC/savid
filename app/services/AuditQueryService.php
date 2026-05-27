<?php

class AuditQueryService
{
    private AuditRepository $repo;
    private ReportScopeService $scopeService;

    public function __construct()
    {
        $database = new Database();
        $this->repo = new AuditRepository($database->connect());
        $this->scopeService = new ReportScopeService();
    }

    public function canView(): bool
    {
        if (!isset($_SESSION['user_id'])) {
            return false;
        }

        if (PermisoService::isSuperAdminSession()) {
            return true;
        }

        return PermisoService::can('auditoria', 'ver');
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int}
     */
    public function listPage(array $query, int $perPage = 50): array
    {
        $page = max(1, (int)($query['page'] ?? 1));
        $offset = ($page - 1) * $perPage;
        $reportScope = $this->scopeService->buildForReports($query);

        $filters = [
            'desde' => trim((string)($query['desde'] ?? '')),
            'hasta' => trim((string)($query['hasta'] ?? '')),
            'tabla' => trim((string)($query['tabla'] ?? '')),
            'accion' => trim((string)($query['accion'] ?? '')),
            'usuario_id' => (int)($query['usuario_id'] ?? 0) ?: null,
            'registro_id' => trim((string)($query['registro_id'] ?? '')),
            'q' => trim((string)($query['q'] ?? '')),
            'empresa_id' => $reportScope['filterEmpresaId'],
            'sede_id' => $reportScope['filterSedeId'],
        ];

        $includeArchive = !empty($query['archivo']);
        $scope = [
            'esSuperAdmin' => $reportScope['esSuperAdmin'],
            'allowedEmpresaIds' => $reportScope['allowedEmpresaIds'],
            'allowedSedeIds' => $reportScope['allowedSedeIds'],
            'filterEmpresaId' => $reportScope['filterEmpresaId'],
            'filterSedeId' => $reportScope['filterSedeId'],
        ];
        $result = $this->repo->search($filters, $perPage, $offset, $includeArchive, $scope);
        $pages = (int)max(1, ceil($result['total'] / $perPage));

        return [
            'rows' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => $pages,
            'reportScope' => $reportScope,
        ];
    }

    public function getDetail(int $id, bool $fromArchive): ?array
    {
        $reportScope = $this->scopeService->buildForReports([]);
        $scope = [
            'esSuperAdmin' => $reportScope['esSuperAdmin'],
            'allowedEmpresaIds' => $reportScope['allowedEmpresaIds'],
            'allowedSedeIds' => $reportScope['allowedSedeIds'],
        ];
        $row = $this->repo->findById($id, $fromArchive, $scope);

        if (!$row) {
            return null;
        }

        foreach (['datos_anteriores', 'datos_nuevos', 'campos_cambiados'] as $jsonField) {
            if (!empty($row[$jsonField]) && is_string($row[$jsonField])) {
                $decoded = json_decode($row[$jsonField], true);
                $row[$jsonField] = is_array($decoded) ? $decoded : $row[$jsonField];
            }
        }

        return $row;
    }

    /**
     * @return list<string>
     */
    public function getTableNames(): array
    {
        return $this->repo->listDistinctTables();
    }

    /**
     * @return array{moved: int, cutoff: string}
     */
    public function archiveHotData(int $retentionMonths = 24): array
    {
        return $this->repo->archiveOlderThanMonths($retentionMonths);
    }
}
