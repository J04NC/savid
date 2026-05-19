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

        $esSuperAdmin = (int)($_SESSION['rol_id'] ?? 0) === 1
            || !empty($_SESSION['es_super_admin']);
        $currentUserId = (int)$_SESSION['user_id'];
        $sessionEmpresaId = isset($_SESSION['empresa_id']) && $_SESSION['empresa_id'] !== ''
            ? (int)$_SESSION['empresa_id']
            : 0;
        $isSelfEdit = ((int)$targetUsuarioId === $currentUserId);

        if ($esSuperAdmin) {
            $empresasDisponibles = $this->companyRepository->findAllActive();
        } else {
            $empresasDisponibles = $this->companyRepository->findActiveByUserId($currentUserId);
        }

        $selectedEmpresaIdsAll = array_map('intval', $this->scopeRepository->findEmpresaIdsByUsuario($targetUsuarioId));
        $selectedSedeIdsAll = array_map('intval', $this->scopeRepository->findSedeIdsByUsuario($targetUsuarioId));

        $allowedEmpresaIds = array_map('intval', array_column($empresasDisponibles, 'id'));
        $allowedMap = array_flip($allowedEmpresaIds);

        $selectedEmpresaIds = array_values(array_intersect($selectedEmpresaIdsAll, $allowedEmpresaIds));
        $outOfScopeEmpresaIds = array_values(array_diff($selectedEmpresaIdsAll, $allowedEmpresaIds));
        $outOfScopeEmpresaCount = count($outOfScopeEmpresaIds);

        $sedesPorEmpresa = [];
        foreach ($empresasDisponibles as $emp) {
            $sedesPorEmpresa[(int)$emp['id']] = $this->branchRepository->findActiveByEmpresaId($emp['id']);
        }

        $outOfScopeSedeCount = 0;
        $selectedSedeIds = [];
        foreach ($selectedSedeIdsAll as $sid) {
            $eid = $this->scopeRepository->getEmpresaIdForSede($sid);
            if ($eid !== null && isset($allowedMap[$eid])) {
                $selectedSedeIds[] = $sid;
            } else {
                $outOfScopeSedeCount++;
            }
        }

        $targetHasSessionEmpresa = $sessionEmpresaId > 0
            && in_array($sessionEmpresaId, $selectedEmpresaIdsAll, true);

        return [
            'usuario' => $usuario,
            'empresasDisponibles' => $empresasDisponibles,
            'selectedEmpresaIds' => $selectedEmpresaIds,
            'selectedSedeIds' => $selectedSedeIds,
            'sedesPorEmpresa' => $sedesPorEmpresa,
            'sessionEmpresaId' => $sessionEmpresaId,
            'isSelfEdit' => $isSelfEdit,
            'esSuperAdmin' => $esSuperAdmin,
            'outOfScopeEmpresaCount' => $outOfScopeEmpresaCount,
            'outOfScopeSedeCount' => $outOfScopeSedeCount,
            'targetHasSessionEmpresa' => $targetHasSessionEmpresa,
        ];
    }

    public function saveEmpresaSede($targetUsuarioId, array $empresaIds, array $sedeIds)
    {
        $targetUsuarioId = (int)$targetUsuarioId;

        $usuario = $this->userAccessRepository->findActiveUserById($targetUsuarioId);

        if (!$usuario) {
            return ['success' => false, 'message' => 'Usuario no encontrado'];
        }

        $esSuperAdmin = (int)($_SESSION['rol_id'] ?? 0) === 1
            || !empty($_SESSION['es_super_admin']);
        $currentUserId = (int)$_SESSION['user_id'];
        $sessionEmpresaId = isset($_SESSION['empresa_id']) && $_SESSION['empresa_id'] !== ''
            ? (int)$_SESSION['empresa_id']
            : 0;

        if (!$esSuperAdmin && $targetUsuarioId === $currentUserId) {
            return [
                'success' => false,
                'message' => 'No puede modificar sus propias empresas/sedes desde aquí. Solicite a otro administrador.',
            ];
        }

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

        $allowedMap = array_flip($allowedEmpresaIds);

        $empresaIdsInScope = array_values(array_intersect($empresaIds, $allowedEmpresaIds));
        $empresaIdsInScopeMap = array_flip($empresaIdsInScope);

        $sedesValidas = [];
        foreach ($sedeIds as $sedeId) {
            if ($sedeId <= 0) {
                continue;
            }

            $empresaDeSede = $this->scopeRepository->getEmpresaIdForSede($sedeId);

            if ($empresaDeSede === null) {
                continue;
            }

            if (!isset($empresaIdsInScopeMap[$empresaDeSede])) {
                continue;
            }

            $sedesValidas[] = $sedeId;
        }
        $sedesValidas = array_values(array_unique($sedesValidas));
        $sedesValidasMap = array_flip($sedesValidas);

        $database = new Database();
        $pdo = $database->connect();

        $pdo->beginTransaction();

        try {
            $scope = new UserScopeRepository($pdo);

            $currentEmpresas = array_map('intval', $scope->findEmpresaIdsByUsuario($targetUsuarioId));
            $currentEmpresasMap = array_flip($currentEmpresas);

            $toRemoveEmpresas = [];
            foreach ($currentEmpresas as $eid) {
                if (!isset($allowedMap[$eid])) {
                    continue;
                }
                if (!isset($empresaIdsInScopeMap[$eid])) {
                    $toRemoveEmpresas[] = $eid;
                }
            }

            $toAddEmpresas = [];
            foreach ($empresaIdsInScope as $eid) {
                if (!isset($currentEmpresasMap[$eid])) {
                    $toAddEmpresas[] = $eid;
                }
            }

            if (!empty($toRemoveEmpresas)) {
                $scope->removeEmpresasForUsuario($targetUsuarioId, $toRemoveEmpresas);
                $scope->removeSedesForUsuarioByEmpresas($targetUsuarioId, $toRemoveEmpresas);
            }
            if (!empty($toAddEmpresas)) {
                $scope->addEmpresasForUsuario($targetUsuarioId, $toAddEmpresas);
            }

            $currentSedes = array_map('intval', $scope->findSedeIdsByUsuario($targetUsuarioId));
            $currentSedesMap = array_flip($currentSedes);

            $toRemoveSedes = [];
            foreach ($currentSedes as $sid) {
                $eid = $scope->getEmpresaIdForSede($sid);
                if ($eid === null || !isset($allowedMap[$eid])) {
                    continue;
                }
                if (!isset($sedesValidasMap[$sid])) {
                    $toRemoveSedes[] = $sid;
                }
            }

            $toAddSedes = [];
            foreach ($sedesValidas as $sid) {
                if (!isset($currentSedesMap[$sid])) {
                    $toAddSedes[] = $sid;
                }
            }

            if (!empty($toRemoveSedes)) {
                $scope->removeSedesForUsuario($targetUsuarioId, $toRemoveSedes);
            }
            if (!empty($toAddSedes)) {
                $scope->addSedesForUsuario($targetUsuarioId, $toAddSedes);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();

            return ['success' => false, 'message' => 'No se pudo guardar: ' . $e->getMessage()];
        }

        $lostVisibility = false;
        if (!$esSuperAdmin
            && $targetUsuarioId !== $currentUserId
            && $sessionEmpresaId > 0
        ) {
            $afterScope = new UserScopeRepository($pdo);
            $lostVisibility = !$afterScope->targetHasEmpresa($targetUsuarioId, $sessionEmpresaId);
        }

        return [
            'success' => true,
            'lost_visibility' => $lostVisibility,
            'message' => $lostVisibility
                ? 'Cambios guardados. Ya no comparte la empresa de sesión con este usuario.'
                : 'Empresas y sedes guardadas.',
        ];
    }
}
