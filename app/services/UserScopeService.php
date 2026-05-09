<?php

class UserScopeService
{
    private UserScopeRepository $scopeRepository;
    private CompanyRepository $companyRepository;
    private BranchRepository $branchRepository;
    private UserAccessRepository $userAccessRepository;

    public function __construct()
    {
        $database = new Database();
        $pdo = $database->connect();

        $this->scopeRepository = new UserScopeRepository($pdo);
        $this->companyRepository = new CompanyRepository($pdo);
        $this->branchRepository = new BranchRepository($pdo);
        $this->userAccessRepository = new UserAccessRepository($pdo);
    }

    public function getEmpresaSedeModalData($targetUsuarioId)
    {
        $usuario = $this->userAccessRepository->findActiveUserById($targetUsuarioId);

        if (!$usuario) {
            return null;
        }

        $esSuperAdmin = (int)($_SESSION['rol_id'] ?? 0) === 1;
        $currentUserId = (int)$_SESSION['user_id'];

        if ($esSuperAdmin) {
            $empresasDisponibles = $this->companyRepository->findAllActive();
        } else {
            $empresasDisponibles = $this->companyRepository->findActiveByUserId($currentUserId);
        }

        $selectedEmpresaIds = $this->scopeRepository->findEmpresaIdsByUsuario($targetUsuarioId);
        $selectedSedeIds = $this->scopeRepository->findSedeIdsByUsuario($targetUsuarioId);

        $sedesPorEmpresa = [];

        foreach ($empresasDisponibles as $emp) {
            $sedesPorEmpresa[(int)$emp['id']] = $this->branchRepository->findActiveByEmpresaId($emp['id']);
        }

        return [
            'usuario' => $usuario,
            'empresasDisponibles' => $empresasDisponibles,
            'selectedEmpresaIds' => $selectedEmpresaIds,
            'selectedSedeIds' => $selectedSedeIds,
            'sedesPorEmpresa' => $sedesPorEmpresa,
        ];
    }

    public function saveEmpresaSede($targetUsuarioId, array $empresaIds, array $sedeIds)
    {
        $usuario = $this->userAccessRepository->findActiveUserById($targetUsuarioId);

        if (!$usuario) {
            return ['success' => false, 'message' => 'Usuario no encontrado'];
        }

        $esSuperAdmin = (int)($_SESSION['rol_id'] ?? 0) === 1;
        $currentUserId = (int)$_SESSION['user_id'];

        $empresaIds = array_values(array_unique(array_map('intval', $empresaIds)));
        $sedeIds = array_values(array_unique(array_map('intval', $sedeIds)));

        if ($esSuperAdmin) {
            $allowedEmpresaIds = array_map('intval', array_column($this->companyRepository->findAllActive(), 'id'));
        } else {
            $allowedEmpresaIds = array_map('intval', array_column(
                $this->companyRepository->findActiveByUserId($currentUserId),
                'id'
            ));
        }

        $empresaIds = array_values(array_intersect($empresaIds, $allowedEmpresaIds));

        $empresaIdsMap = array_flip($empresaIds);

        $sedesValidas = [];

        foreach ($sedeIds as $sedeId) {

            if ($sedeId <= 0) {
                continue;
            }

            $empresaDeSede = $this->scopeRepository->getEmpresaIdForSede($sedeId);

            if ($empresaDeSede === null) {
                continue;
            }

            if (!isset($empresaIdsMap[$empresaDeSede])) {
                continue;
            }

            $sedesValidas[] = $sedeId;
        }

        $sedesValidas = array_values(array_unique($sedesValidas));

        $database = new Database();
        $pdo = $database->connect();

        $pdo->beginTransaction();

        try {
            $scope = new UserScopeRepository($pdo);
            $scope->replaceEmpresasForUsuario($targetUsuarioId, $empresaIds);
            $scope->replaceSedesForUsuario($targetUsuarioId, $sedesValidas);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();

            return ['success' => false, 'message' => 'No se pudo guardar: ' . $e->getMessage()];
        }

        return ['success' => true];
    }
}
