<?php

/**
 * Alcance de reportes por empresa/sede según asociaciones del usuario.
 */
class ReportScopeService
{
    private CompanyRepository $companyRepository;
    private BranchRepository $branchRepository;

    public function __construct()
    {
        $database = new Database();
        $pdo = $database->connect();
        $this->companyRepository = new CompanyRepository($pdo);
        $this->branchRepository = new BranchRepository($pdo);
    }

    /**
     * @return array{
     *   esSuperAdmin: bool,
     *   empresas: list<array<string, mixed>>,
     *   sedes: list<array<string, mixed>>,
     *   allowedEmpresaIds: list<int>,
     *   allowedSedeIds: list<int>,
     *   filterEmpresaId: ?int,
     *   filterSedeId: ?int,
     *   showEmpresaFilter: bool,
     *   showSedeFilter: bool
     * }
     */
    public function buildForReports(array $query): array
    {
        $esSuperAdmin = PermisoService::isSuperAdminSession();
        $userId = (int)($_SESSION['user_id'] ?? 0);

        if ($esSuperAdmin) {
            $empresas = $this->companyRepository->findAllActive();
            $allowedEmpresaIds = array_map('intval', array_column($empresas, 'id'));
        } else {
            $empresas = $this->companyRepository->findActiveByUserId($userId);
            $allowedEmpresaIds = array_map('intval', array_column($empresas, 'id'));
        }

        $filterEmpresaId = (int)($query['empresa_id'] ?? 0) ?: null;
        $filterSedeId = (int)($query['sede_id'] ?? 0) ?: null;

        if (!$esSuperAdmin) {
            if ($filterEmpresaId !== null && !in_array($filterEmpresaId, $allowedEmpresaIds, true)) {
                $filterEmpresaId = null;
            }
        }

        $allowedSedeIds = $this->collectAllowedSedeIds($esSuperAdmin, $userId, $allowedEmpresaIds);
        $sedes = $this->sedesForFilter($esSuperAdmin, $userId, $filterEmpresaId, $allowedEmpresaIds, $empresas);

        if ($filterSedeId !== null) {
            $validSedeIds = array_map('intval', array_column($sedes, 'id'));
            if ($validSedeIds === []) {
                $validSedeIds = $allowedSedeIds;
            }
            if (!$esSuperAdmin && !in_array($filterSedeId, $validSedeIds, true)) {
                $filterSedeId = null;
            } elseif ($esSuperAdmin && $filterEmpresaId !== null && !in_array($filterSedeId, $validSedeIds, true)) {
                $filterSedeId = null;
            }
        }

        $showEmpresaFilter = $esSuperAdmin ? count($empresas) > 0 : count($empresas) > 1;
        $showSedeFilter = $esSuperAdmin
            ? count($empresas) > 0
            : (count($sedes) > 1
                || ($filterEmpresaId !== null && count($sedes) >= 1)
                || count($allowedSedeIds) > 1);

        return [
            'esSuperAdmin' => $esSuperAdmin,
            'empresas' => $empresas,
            'sedes' => $sedes,
            'allowedEmpresaIds' => $allowedEmpresaIds,
            'allowedSedeIds' => $allowedSedeIds,
            'filterEmpresaId' => $filterEmpresaId,
            'filterSedeId' => $filterSedeId,
            'showEmpresaFilter' => $showEmpresaFilter,
            'showSedeFilter' => $showSedeFilter,
        ];
    }

    /**
     * @param list<int> $allowedEmpresaIds
     * @return list<int>
     */
    private function collectAllowedSedeIds(bool $esSuperAdmin, int $userId, array $allowedEmpresaIds): array
    {
        $ids = [];

        foreach ($allowedEmpresaIds as $empresaId) {
            $sedes = $esSuperAdmin
                ? $this->branchRepository->findActiveByEmpresaId($empresaId)
                : $this->branchRepository->findActiveByUserIdAndEmpresaId($userId, $empresaId);

            foreach ($sedes as $sede) {
                $ids[] = (int)$sede['id'];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param list<array<string, mixed>> $empresas
     * @param list<int> $allowedEmpresaIds
     * @return list<array<string, mixed>>
     */
    private function sedesForFilter(
        bool $esSuperAdmin,
        int $userId,
        ?int $filterEmpresaId,
        array $allowedEmpresaIds,
        array $empresas
    ): array {
        $empresaForSedes = $filterEmpresaId;

        if ($empresaForSedes === null && count($empresas) === 1) {
            $empresaForSedes = (int)$empresas[0]['id'];
        }

        if ($empresaForSedes === null || $empresaForSedes <= 0) {
            return [];
        }

        if (!$esSuperAdmin && !in_array($empresaForSedes, $allowedEmpresaIds, true)) {
            return [];
        }

        return $esSuperAdmin
            ? $this->branchRepository->findActiveByEmpresaId($empresaForSedes)
            : $this->branchRepository->findActiveByUserIdAndEmpresaId($userId, $empresaForSedes);
    }

    /**
     * @param array{
     *   esSuperAdmin: bool,
     *   allowedEmpresaIds: list<int>,
     *   allowedSedeIds: list<int>
     * } $scope
     */
    public function applyEmpresaScope(
        array &$where,
        array &$params,
        string $column,
        array $scope,
        ?int $filterEmpresaId
    ): void {
        if ($scope['esSuperAdmin']) {
            if ($filterEmpresaId !== null && $filterEmpresaId > 0) {
                $where[] = "{$column} = ?";
                $params[] = $filterEmpresaId;
            }

            return;
        }

        $allowed = $scope['allowedEmpresaIds'];

        if ($allowed === []) {
            $where[] = '1=0';

            return;
        }

        if ($filterEmpresaId !== null && $filterEmpresaId > 0) {
            if (!in_array($filterEmpresaId, $allowed, true)) {
                $where[] = '1=0';

                return;
            }
            $where[] = "{$column} = ?";
            $params[] = $filterEmpresaId;

            return;
        }

        $placeholders = implode(',', array_fill(0, count($allowed), '?'));
        $where[] = "{$column} IN ({$placeholders})";
        foreach ($allowed as $id) {
            $params[] = $id;
        }
    }

    /**
     * @param array{
     *   esSuperAdmin: bool,
     *   allowedSedeIds: list<int>
     * } $scope
     */
    public function applySedeScope(
        array &$where,
        array &$params,
        string $column,
        array $scope,
        ?int $filterSedeId
    ): void {
        if ($filterSedeId === null || $filterSedeId <= 0) {
            return;
        }

        if (!$scope['esSuperAdmin'] && !in_array($filterSedeId, $scope['allowedSedeIds'], true)) {
            $where[] = '1=0';

            return;
        }

        $where[] = "{$column} = ?";
        $params[] = $filterSedeId;
    }

    /**
     * @param array{
     *   esSuperAdmin: bool,
     *   allowedEmpresaIds: list<int>,
     *   allowedSedeIds: list<int>
     * } $scope
     */
    public function canAccessRecord(array $scope, ?int $empresaId, ?int $sedeId): bool
    {
        if ($scope['esSuperAdmin']) {
            return true;
        }

        if ($empresaId === null || $empresaId <= 0) {
            return false;
        }

        if (!in_array($empresaId, $scope['allowedEmpresaIds'], true)) {
            return false;
        }

        if ($sedeId !== null && $sedeId > 0 && !in_array($sedeId, $scope['allowedSedeIds'], true)) {
            return false;
        }

        return true;
    }
}
