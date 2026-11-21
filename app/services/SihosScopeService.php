<?php

/**
 * Alcance SIHOS por empresa: empresa de sesión; superadmin puede elegir
 * empresa en las pantallas de SIHOS. Mismo patrón que SgdScopeService.
 */
class SihosScopeService
{
    private ReportScopeService $reportScope;

    public function __construct()
    {
        $this->reportScope = new ReportScopeService();
    }

    public function isSuperAdmin(): bool
    {
        return PermisoService::isSuperAdminSession();
    }

    /**
     * @return array<string, mixed>
     */
    public function buildScope(array $query = []): array
    {
        $report = $this->reportScope->buildForReports($query);
        $sessionEmpresa = isset($_SESSION['empresa_id']) ? (int)$_SESSION['empresa_id'] : 0;

        $empresaId = (int)($query['empresa_id'] ?? 0);
        if ($empresaId <= 0 && $sessionEmpresa > 0) {
            $empresaId = $sessionEmpresa;
        }

        if (!$report['esSuperAdmin']) {
            if ($empresaId <= 0 || !in_array($empresaId, $report['allowedEmpresaIds'], true)) {
                $empresaId = $sessionEmpresa > 0 ? $sessionEmpresa : 0;
            }
        } elseif ($empresaId <= 0 && count($report['empresas']) === 1) {
            $empresaId = (int)$report['empresas'][0]['id'];
        }

        return array_merge($report, [
            'empresaId' => $empresaId > 0 ? $empresaId : null,
        ]);
    }

    public function canAccessEmpresa(int $empresaId, array $query = []): bool
    {
        $scope = $this->buildScope($query);

        return $this->reportScope->canAccessRecord($scope, $empresaId, null);
    }

    /**
     * Como buildScope(), pero para endpoints que ESCRIBEN en SIHOS: a un no
     * superadmin nunca se le deja elegir empresa por request (empresa_id en
     * el POST) aunque tenga acceso de lectura a varias — siempre se fuerza
     * la de sesión, para que un permiso de escritura otorgado para su propia
     * empresa no sirva para escribir en otra donde solo tiene acceso de
     * lectura. Superadmin sigue pudiendo elegir empresa libremente.
     */
    public function buildWriteScope(array $query = []): array
    {
        $report = $this->reportScope->buildForReports($query);
        $sessionEmpresa = isset($_SESSION['empresa_id']) ? (int)$_SESSION['empresa_id'] : 0;

        if ($report['esSuperAdmin']) {
            $empresaId = (int)($query['empresa_id'] ?? 0);
            if ($empresaId <= 0 && count($report['empresas']) === 1) {
                $empresaId = (int)$report['empresas'][0]['id'];
            }
        } else {
            $empresaId = $sessionEmpresa;
        }

        return array_merge($report, [
            'empresaId' => $empresaId > 0 ? $empresaId : null,
        ]);
    }
}
