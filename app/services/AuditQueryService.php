<?php

class AuditQueryService
{
    /** Índice de columna DataTables (0-based, según <th> en la vista) → columna real ordenable. */
    private const ORDERABLE_COLUMNS = [
        0 => 'a.occurred_at',
        1 => 'a.accion',
        2 => 'a.tabla',
        3 => 'a.registro_id',
        4 => 'u.username',
        5 => 'a.empresa_id',
        6 => 'a.sede_id',
    ];

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
     * Alcance empresa/sede para prellenar los selects del formulario de filtros,
     * sin necesidad de traer filas de auditoría.
     */
    public function getReportScope(array $query): array
    {
        return $this->scopeService->buildForReports($query);
    }

    /**
     * @return array{filters: array<string, mixed>, includeArchive: bool, scope: array<string, mixed>, reportScope: array<string, mixed>}
     */
    private function buildFiltersAndScope(array $query): array
    {
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

        return ['filters' => $filters, 'includeArchive' => $includeArchive, 'scope' => $scope, 'reportScope' => $reportScope];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int}
     */
    public function listPage(array $query, int $perPage = 50): array
    {
        $page = max(1, (int)($query['page'] ?? 1));
        $offset = ($page - 1) * $perPage;
        $built = $this->buildFiltersAndScope($query);

        $result = $this->repo->search($built['filters'], $perPage, $offset, $built['includeArchive'], $built['scope']);
        $pages = (int)max(1, ceil($result['total'] / $perPage));

        return [
            'rows' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => $pages,
            'reportScope' => $built['reportScope'],
        ];
    }

    /**
     * Fuente de datos para DataTables en modo servidor: pagina, ordena y filtra en la BD.
     *
     * @return array{data: list<list<mixed>>, recordsTotal: int, recordsFiltered: int}
     */
    public function listForDataTable(array $query, int $start, int $length, int $orderColIndex, string $orderDir, string $globalSearch): array
    {
        $built = $this->buildFiltersAndScope($query);
        $filters = $built['filters'];

        $globalSearch = trim($globalSearch);
        if ($globalSearch !== '' && ($filters['q'] ?? '') === '') {
            $filters['q'] = $globalSearch;
        }

        $orderColumn = self::ORDERABLE_COLUMNS[$orderColIndex] ?? 'a.occurred_at';

        $result = $this->repo->search($filters, $length, $start, $built['includeArchive'], $built['scope'], $orderColumn, $orderDir);
        $recordsTotal = $this->repo->countScoped($built['includeArchive'], $built['scope']);

        $rows = array_map(
            fn(array $r) => $this->formatRowForDataTable($r, $built['includeArchive']),
            $result['rows']
        );

        return [
            'data' => $rows,
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $result['total'],
        ];
    }

    /**
     * @return list<string>
     */
    private function formatRowForDataTable(array $r, bool $includeArchive): array
    {
        $accion = (string)($r['accion'] ?? '');
        $accionLower = strtolower($accion);
        $accionClass = in_array($accionLower, ['insert', 'update', 'delete'], true)
            ? 'auditoria-badge-' . $accionLower
            : 'auditoria-badge-default';

        $occurredAt = (string)($r['occurred_at'] ?? '');
        $ts = $occurredAt !== '' ? strtotime($occurredAt) : false;
        $fechaFmt = $ts ? date('d/m/Y H:i:s', $ts) : ($occurredAt !== '' ? $occurredAt : '—');

        $usuario = htmlspecialchars((string)($r['usuario_username'] ?? ''), ENT_QUOTES, 'UTF-8');
        if (!empty($r['usuario_id'])) {
            $usuario .= ' <span class="auditoria-muted">#' . (int)$r['usuario_id'] . '</span>';
        }

        return [
            '<span title="' . htmlspecialchars($occurredAt, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($fechaFmt, ENT_QUOTES, 'UTF-8') . '</span>',
            '<span class="auditoria-badge ' . $accionClass . '">' . htmlspecialchars($accion !== '' ? $accion : '—', ENT_QUOTES, 'UTF-8') . '</span>',
            '<code class="auditoria-code">' . htmlspecialchars((string)($r['tabla'] ?? '—'), ENT_QUOTES, 'UTF-8') . '</code>',
            htmlspecialchars((string)($r['registro_id'] ?? '—'), ENT_QUOTES, 'UTF-8'),
            $usuario,
            !empty($r['empresa_id']) ? (string)(int)$r['empresa_id'] : '—',
            !empty($r['sede_id']) ? (string)(int)$r['sede_id'] : '—',
            '<button type="button" class="auditoria-btn-icon btn-auditoria-detalle" title="Ver detalle" data-id="'
                . (int)($r['id'] ?? 0) . '" data-archivo="' . ($includeArchive ? '1' : '0') . '">👁</button>',
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
