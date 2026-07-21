<?php

class SesionReportService
{
    private UsuarioSesionRepository $repo;
    private ReportScopeService $scopeService;

    public function __construct()
    {
        $database = new Database();
        $this->repo = new UsuarioSesionRepository($database->connect());
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

        return PermisoService::can('sesiones', 'ver');
    }

    /**
     * Cierra una sesión ajena (admin), solo si está dentro del alcance del usuario actual.
     *
     * @return array{success: bool, error?: string}
     */
    public function close(int $sesionId): array
    {
        if (!$this->canView()) {
            return ['success' => false, 'error' => 'No tiene permiso para gestionar sesiones.'];
        }

        $row = $this->repo->findById($sesionId);
        if (!$row) {
            return ['success' => false, 'error' => 'Sesión no encontrada.'];
        }

        if ($row['logout_at'] !== null) {
            return ['success' => false, 'error' => 'Esa sesión ya estaba cerrada.'];
        }

        $scope = $this->scopeService->buildForReports([]);
        $empresaId = $row['empresa_id'] !== null ? (int)$row['empresa_id'] : null;
        $sedeId = $row['sede_id'] !== null ? (int)$row['sede_id'] : null;

        if (!$this->scopeService->canAccessRecord($scope, $empresaId, $sedeId)) {
            return ['success' => false, 'error' => 'No tiene alcance sobre esa sesión.'];
        }

        $this->repo->closeSession($sesionId, 'admin');

        return ['success' => true];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int, summary: array{activas: int, total_hoy: int}}
     */
    public function listPage(array $query, int $perPage = 50): array
    {
        $page = max(1, (int)($query['page'] ?? 1));
        $offset = ($page - 1) * $perPage;
        $reportScope = $this->scopeService->buildForReports($query);

        $filters = [
            'desde' => trim((string)($query['desde'] ?? '')),
            'hasta' => trim((string)($query['hasta'] ?? '')),
            'usuario_id' => (int)($query['usuario_id'] ?? 0) ?: null,
            'solo_activas' => !empty($query['solo_activas']),
            'inactividad_min' => (int)($query['inactividad_min'] ?? 30),
            'q' => trim((string)($query['q'] ?? '')),
        ];

        $scope = [
            'esSuperAdmin' => $reportScope['esSuperAdmin'],
            'allowedEmpresaIds' => $reportScope['allowedEmpresaIds'],
            'allowedSedeIds' => $reportScope['allowedSedeIds'],
            'filterEmpresaId' => $reportScope['filterEmpresaId'],
            'filterSedeId' => $reportScope['filterSedeId'],
        ];

        $result = $this->repo->searchReport($filters, $perPage, $offset, $scope);
        $summary = $this->repo->countSummary($scope);
        $pages = (int)max(1, ceil($result['total'] / $perPage));

        return [
            'rows' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => $pages,
            'summary' => $summary,
            'reportScope' => $reportScope,
        ];
    }
}
