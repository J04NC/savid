<?php

class UserAccessService
{
    private UserAccessRepository $repository;
    private UserScopeRepository $scopeRepository;
    private CompanyRepository $companyRepository;

    public function __construct()
    {
        $database = new Database();
        $pdo = $database->connect();
        $this->repository = new UserAccessRepository($pdo);
        $this->scopeRepository = new UserScopeRepository($pdo);
        $this->companyRepository = new CompanyRepository($pdo);
    }

    public function getUserOrNull($usuarioId)
    {
        return $this->repository->findActiveUserById($usuarioId);
    }

    public function getRolesContext($usuarioId)
    {
        $usuario = $this->repository->findActiveUserById($usuarioId);

        if (!$usuario) {
            return null;
        }

        $scopeSvc = new UserScopeService();
        $scopeData = $scopeSvc->getEmpresaSedeModalData($usuarioId);

        if (!$scopeData) {
            return null;
        }

        $assignments = $this->repository->findRoleAssignmentsByUserId((int)$usuarioId);

        if ($assignments === []) {
            $assignments[] = ['rol_id' => null, 'empresa_id' => null, 'sede_id' => null];
        }

        return [
            'usuario' => $usuario,
            'roles' => $this->repository->findAllActiveRoles(),
            'assignments' => $assignments,
            'empresasDisponibles' => $scopeData['empresasDisponibles'],
            'sedesPorEmpresa' => $scopeData['sedesPorEmpresa'],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $assignmentRows desde POST assignments[i][rol_id|empresa_id|sede_id]
     * @return array{success: bool, message?: string}
     */
    public function saveRoles(int $usuarioId, array $assignmentRows): array
    {
        $usuario = $this->repository->findActiveUserById($usuarioId);

        if (!$usuario) {
            return ['success' => false, 'message' => 'Usuario no encontrado'];
        }

        $esSuperAdmin = (int)($_SESSION['rol_id'] ?? 0) === 1 || !empty($_SESSION['es_super_admin']);
        $currentUserId = (int)($_SESSION['user_id'] ?? 0);

        if ($esSuperAdmin) {
            $allowedEmpresaIds = array_map('intval', array_column($this->companyRepository->findAllActive(), 'id'));
        } else {
            $allowedEmpresaIds = array_map('intval', array_column(
                $this->companyRepository->findActiveByUserId($currentUserId),
                'id'
            ));
        }

        $allowedEmpresaMap = array_flip($allowedEmpresaIds);

        $normalized = [];

        foreach ($assignmentRows as $row) {
            $rid = isset($row['rol_id']) ? (int)$row['rol_id'] : 0;

            if ($rid <= 0) {
                continue;
            }

            $eid = isset($row['empresa_id']) && $row['empresa_id'] !== '' ? (int)$row['empresa_id'] : null;
            $sid = isset($row['sede_id']) && $row['sede_id'] !== '' ? (int)$row['sede_id'] : null;

            if ($eid !== null && $eid <= 0) {
                $eid = null;
            }
            if ($sid !== null && $sid <= 0) {
                $sid = null;
            }

            if ($sid !== null && $eid === null) {
                $eid = $this->scopeRepository->getEmpresaIdForSede($sid);
            }

            if ($eid === null && $sid === null && !$esSuperAdmin) {
                return [
                    'success' => false,
                    'message' => 'Debe indicar empresa (y opcionalmente sede) para cada rol, salvo administradores globales.',
                ];
            }

            if ($eid !== null && !isset($allowedEmpresaMap[$eid])) {
                return ['success' => false, 'message' => 'Empresa no permitida en una asignación de rol.'];
            }

            if ($sid !== null) {
                $empresaDeSede = $this->scopeRepository->getEmpresaIdForSede($sid);

                if ($empresaDeSede === null) {
                    return ['success' => false, 'message' => 'Sede inválida o inactiva.'];
                }

                if ($eid !== null && (int)$empresaDeSede !== (int)$eid) {
                    return ['success' => false, 'message' => 'La sede no pertenece a la empresa elegida.'];
                }

                $eid = (int)$empresaDeSede;
            }

            $key = $rid . '|' . ($eid ?? 'null') . '|' . ($sid ?? 'null');

            if (isset($normalized[$key])) {
                continue;
            }

            $normalized[$key] = [
                'rol_id' => $rid,
                'empresa_id' => $eid,
                'sede_id' => $sid,
            ];
        }

        $this->repository->deleteRolesByUserId($usuarioId);

        foreach ($normalized as $n) {
            $this->repository->insertUserRole(
                $usuarioId,
                $n['rol_id'],
                $n['empresa_id'],
                $n['sede_id']
            );
        }

        return ['success' => true];
    }

    private function permisoScopeFromSession(): array
    {
        $e = isset($_SESSION['empresa_id']) ? (int)$_SESSION['empresa_id'] : null;
        $s = isset($_SESSION['sede_id']) ? (int)$_SESSION['sede_id'] : null;

        if ($e !== null && $e <= 0) {
            $e = null;
        }
        if ($s !== null && $s <= 0) {
            $s = null;
        }

        return [$e, $s];
    }

    public function buildPermissionMatrix($usuarioId)
    {
        $rows = $this->repository->getPermissionMatrixRows();
        [$empresaId, $sedeId] = $this->permisoScopeFromSession();
        $actuales = $this->repository->getAllowedPermissionItemAccionIdsForScope((int)$usuarioId, $empresaId, $sedeId);

        $acciones = [];
        $matriz = [];

        foreach ($rows as $r) {
            $acciones[$r['codigo']] = $r['accion'];
            $modulo = $r['modulo'];
            $itemId = $r['item_id'];

            if (!isset($matriz[$modulo])) {
                $matriz[$modulo] = [];
            }

            if (!isset($matriz[$modulo][$itemId])) {
                $matriz[$modulo][$itemId] = [
                    'item' => $r['item'],
                    'acciones' => [],
                ];
            }

            $matriz[$modulo][$itemId]['acciones'][$r['codigo']] = [
                'id' => $r['item_accion_id'],
                'checked' => in_array((int)$r['item_accion_id'], $actuales, true),
            ];
        }

        return [
            'acciones' => $acciones,
            'matriz' => $matriz,
        ];
    }

    public function saveDirectPermissions($usuarioId, $checks)
    {
        $checks = array_map('intval', $checks);
        [$empresaId, $sedeId] = $this->permisoScopeFromSession();
        $actuales = $this->repository->getAllowedPermissionItemAccionIdsForScope((int)$usuarioId, $empresaId, $sedeId);

        $insertar = array_diff($checks, $actuales);
        $eliminar = array_diff($actuales, $checks);

        foreach ($insertar as $itemAccionId) {
            $this->repository->insertAllowedPermission((int)$usuarioId, $itemAccionId, $empresaId, $sedeId);
        }

        foreach ($eliminar as $itemAccionId) {
            $this->repository->deleteAllowedPermission((int)$usuarioId, $itemAccionId, $empresaId, $sedeId);
        }

        return ['success' => true];
    }
}
