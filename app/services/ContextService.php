<?php

class ContextService
{
    private CompanyRepository $companyRepository;
    private BranchRepository $branchRepository;
    private SubscriptionRepository $subscriptionRepository;

    public function __construct()
    {
        $database = new Database();
        $pdo = $database->connect();

        $this->companyRepository = new CompanyRepository($pdo);
        $this->branchRepository = new BranchRepository($pdo);
        $this->subscriptionRepository = new SubscriptionRepository($pdo);
    }

    /**
     * Sedes disponibles para el usuario al elegir contexto (filtradas por usuario salvo Super Admin).
     */
    public function getSedesForContextSelection($empresaId)
    {
        $empresaId = (int)$empresaId;
        if ($empresaId <= 0) {
            return [];
        }

        if (!empty($_SESSION['es_super_admin'])) {
            return $this->branchRepository->findActiveByEmpresaId($empresaId);
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);

        return $this->branchRepository->findActiveByUserIdAndEmpresaId($userId, $empresaId);
    }

    public function changeContext($empresaId, $sedeId)
    {
        $empresaId = (int)$empresaId;
        $sedeId = ($sedeId !== null && $sedeId !== '') ? (int)$sedeId : null;
        $userId = (int)($_SESSION['user_id'] ?? 0);

        if ($empresaId <= 0) {
            return ['success' => false, 'error' => 'Seleccione empresa'];
        }

        $esSuper = !empty($_SESSION['es_super_admin']);

        if ($esSuper) {
            $empresa = $this->companyRepository->findActiveById($empresaId);
            if (!$empresa) {
                return ['success' => false, 'error' => 'Empresa inválida'];
            }
        } else {
            $empresas = $this->companyRepository->findActiveByUserId($userId);
            $allowedEmpresaIds = array_column($empresas, 'id');
            if (!in_array($empresaId, $allowedEmpresaIds, true)) {
                return ['success' => false, 'error' => 'Empresa no autorizada'];
            }
            $empresa = $this->companyRepository->findActiveById($empresaId);
            if (!$empresa) {
                return ['success' => false, 'error' => 'Empresa inválida'];
            }
        }

        $sub = $this->applyActiveSubscriptionOrFail($empresaId, $empresa['razon_social'] ?? '');
        if (!$sub['success']) {
            return $sub;
        }

        $_SESSION['empresa_id'] = $empresa['id'];
        $_SESSION['empresa'] = $empresa['razon_social'];

        if (!$sedeId || $sedeId <= 0) {
            $_SESSION['sede_id'] = null;
            $_SESSION['sede'] = 'Seleccione sede';

            return ['success' => false, 'error' => 'Seleccione sede'];
        }

        if ($esSuper) {
            $sede = $this->branchRepository->findActiveByIdAndEmpresaId($sedeId, $empresaId);
        } else {
            $sedesUsuario = $this->branchRepository->findActiveByUserIdAndEmpresaId($userId, $empresaId);
            $permitidas = array_column($sedesUsuario, 'id');
            if (!in_array($sedeId, $permitidas, true)) {
                return ['success' => false, 'error' => 'Sede no autorizada'];
            }
            $sede = $this->branchRepository->findActiveByIdAndEmpresaId($sedeId, $empresaId);
        }

        if (!$sede) {
            return ['success' => false, 'error' => 'Sede inválida'];
        }

        $_SESSION['sede_id'] = $sede['id'];
        $_SESSION['sede'] = $sede['nombre'];

        return [
            'success' => true,
            'empresa' => $_SESSION['empresa'],
            'sede' => $_SESSION['sede'],
        ];
    }

    /**
     * @return array{success:bool, error?:string}
     */
    private function applyActiveSubscriptionOrFail(int $empresaId, string $empresaNombre): array
    {
        $suscripcion = $this->subscriptionRepository->findActiveLatestByEmpresaId($empresaId);

        if (!$suscripcion) {
            return ['success' => false, 'error' => "La empresa '{$empresaNombre}' no tiene plan activo"];
        }

        $fechaFin = date('Y-m-d', strtotime($suscripcion['fecha_fin']));
        $hoy = date('Y-m-d');

        if ($fechaFin < $hoy) {
            return [
                'success' => false,
                'error' => "El plan de '{$empresaNombre}' venció el " . date('d/m/Y', strtotime($suscripcion['fecha_fin'])),
            ];
        }

        $_SESSION['plan_id'] = $suscripcion['plan_id'];
        $_SESSION['plan_nombre'] = $suscripcion['plan_nombre'];
        $_SESSION['fecha_fin'] = $suscripcion['fecha_fin'];

        return ['success' => true];
    }

    public function getEmpresasForContextSelection(int $usuarioId): array
    {
        if (!empty($_SESSION['es_super_admin'])) {
            return $this->companyRepository->findAllActive();
        }

        return $this->companyRepository->findActiveByUserId($usuarioId);
    }
}
