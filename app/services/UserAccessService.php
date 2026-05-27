<?php

class UserAccessService
{
    private UserAccessRepository $repository;
    private UserScopeRepository $scopeRepository;
    private CompanyRepository $companyRepository;
    private BranchRepository $branchRepository;

    public function __construct()
    {
        $database = new Database();
        $pdo = $database->connect();
        $this->repository = new UserAccessRepository($pdo);
        $this->scopeRepository = new UserScopeRepository($pdo);
        $this->companyRepository = new CompanyRepository($pdo);
        $this->branchRepository = new BranchRepository($pdo);
    }

    /**
     * Empresas asignadas al usuario (usuario_empresa).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getEmpresasAuthorizedForUsuario(int $usuarioId): array
    {
        return $this->companyRepository->findActiveByUserId($usuarioId);
    }

    /**
     * Sedes asignadas al usuario dentro de una empresa (usuario_sede ∩ sede.empresa_id).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSedesAuthorizedForUsuarioEmpresa(int $usuarioId, ?int $empresaId): array
    {
        if ($empresaId === null || $empresaId <= 0) {
            return [];
        }

        return $this->branchRepository->findActiveByUserIdAndEmpresaId($usuarioId, $empresaId);
    }

    /**
     * Ajusta empresa/sede al alcance que el usuario objetivo tiene en usuario_empresa / usuario_sede.
     * Alcance global (null, null) solo si el editor es super admin y no hay empresa concreta.
     *
     * @return array{0: ?int, 1: ?int}
     */
    public function clampPermisoScopeToTargetUsuario(
        int $targetUsuarioId,
        ?int $empresaId,
        ?int $sedeId,
        bool $editorIsSuperAdmin
    ): array {
        $empresas = $this->companyRepository->findActiveByUserId($targetUsuarioId);
        $empIds = array_map('intval', array_column($empresas, 'id'));

        if ($editorIsSuperAdmin && ($empresaId === null || $empresaId <= 0) && ($sedeId === null || $sedeId <= 0)) {
            return [null, null];
        }

        if ($empresaId !== null && $empresaId > 0 && !in_array($empresaId, $empIds, true)) {
            $empresaId = !empty($empresas) ? (int)$empresas[0]['id'] : null;
            $sedeId = null;
        }

        if (($empresaId === null || $empresaId <= 0) && !empty($empresas)) {
            $empresaId = (int)$empresas[0]['id'];
            $sedeId = null;
        }

        if ($empresaId === null || $empresaId <= 0) {
            return [null, null];
        }

        $sedes = $this->branchRepository->findActiveByUserIdAndEmpresaId($targetUsuarioId, $empresaId);
        $sedeIds = array_map('intval', array_column($sedes, 'id'));

        if ($sedeId !== null && $sedeId > 0 && !in_array($sedeId, $sedeIds, true)) {
            $sedeId = null;
        }

        return [$empresaId, $sedeId];
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

        $targetId = (int)$usuarioId;
        $esSuperAdmin = (int)($_SESSION['rol_id'] ?? 0) === 1 || !empty($_SESSION['es_super_admin']);
        $operatorUserId = (int)($_SESSION['user_id'] ?? 0);

        $assignments = $this->repository->findRoleAssignmentsByUserId($targetId);

        if ($assignments === []) {
            $assignments[] = ['rol_id' => null, 'empresa_id' => null, 'sede_id' => null];
        }

        $empresasDisponibles = $this->buildEmpresasListForRolesModal(
            $targetId,
            $assignments,
            $esSuperAdmin,
            $operatorUserId
        );

        $sedesPorEmpresa = [];
        foreach ($empresasDisponibles as $emp) {
            $eid = (int)$emp['id'];
            $sedesPorEmpresa[$eid] = $this->branchRepository->findActiveByEmpresaId($eid);
        }

        $roles = $this->filterRolesForRolesModal(
            $this->repository->findAllActiveRoles(),
            $esSuperAdmin
        );

        return [
            'usuario' => $usuario,
            'roles' => $roles,
            'assignments' => $assignments,
            'empresasDisponibles' => $empresasDisponibles,
            'sedesPorEmpresa' => $sedesPorEmpresa,
        ];
    }

    /**
     * Solo superadministradores pueden asignar el rol Super Admin en el modal.
     *
     * @param array<int, array<string, mixed>> $roles
     * @return array<int, array<string, mixed>>
     */
    private function filterRolesForRolesModal(array $roles, bool $esSuperAdmin): array
    {
        if ($esSuperAdmin) {
            return $roles;
        }

        $superAdminRolId = UsuarioFormValidationService::SUPER_ADMIN_ROL_ID;

        return array_values(array_filter(
            $roles,
            static fn (array $rol): bool => (int)($rol['id'] ?? 0) !== $superAdminRolId
        ));
    }

    /**
     * Empresas del desplegable roles: asociadas al usuario destino (usuario_empresa) y alcance del operador.
     *
     * @param array<int, array<string, mixed>> $assignments
     * @return list<array{id: int, razon_social: string, nombre: string}>
     */
    private function buildEmpresasListForRolesModal(
        int $targetUsuarioId,
        array $assignments,
        bool $esSuperAdmin,
        int $operatorUserId
    ): array {
        $byId = [];

        $add = function (?array $emp) use (&$byId): void {
            if (!$emp || empty($emp['id'])) {
                return;
            }
            $id = (int)$emp['id'];
            $nombre = (string)($emp['razon_social'] ?? $emp['nombre'] ?? '');
            $byId[$id] = [
                'id' => $id,
                'razon_social' => $nombre,
                'nombre' => $nombre,
            ];
        };

        foreach ($this->companyRepository->findActiveByUserId($targetUsuarioId) as $emp) {
            $add($emp);
        }

        foreach ($assignments as $a) {
            $eid = $a['empresa_id'] ?? null;
            if ($eid === null || $eid === '') {
                continue;
            }
            $add($this->companyRepository->findActiveById((int)$eid));
        }

        if ($esSuperAdmin) {
            foreach ($this->companyRepository->findAllActive() as $emp) {
                $add($emp);
            }
        } elseif ($operatorUserId > 0) {
            foreach ($this->companyRepository->findActiveByUserId($operatorUserId) as $emp) {
                $add($emp);
            }
        }

        $list = array_values($byId);
        usort(
            $list,
            static fn (array $a, array $b): int => strcasecmp(
                (string)($a['razon_social'] ?? ''),
                (string)($b['razon_social'] ?? '')
            )
        );

        return $list;
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

            if ($rid === UsuarioFormValidationService::SUPER_ADMIN_ROL_ID && !$esSuperAdmin) {
                return [
                    'success' => false,
                    'message' => 'Solo un superadministrador puede asignar el rol Super Admin.',
                ];
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

    /**
     * Alcance para pantalla de permisos directos (misma regla que rol/permisos).
     *
     * @return array{0: ?int, 1: ?int}
     */
    public function normalizePermissionScope($empresaId, $sedeId): array
    {
        $esSuperAdmin = (int)($_SESSION['rol_id'] ?? 0) === 1 || !empty($_SESSION['es_super_admin']);
        $rps = new RolePermissionService();

        return $rps->normalizeScope($empresaId, $sedeId, $esSuperAdmin, $_SESSION['empresa_id'] ?? null);
    }

    public function buildPermissionMatrix(int $usuarioId, ?int $empresaId, ?int $sedeId): array
    {
        $rows = $this->repository->getPermissionMatrixRows();
        $directIds = $this->repository->getAllowedPermissionItemAccionIdsForScope((int)$usuarioId, $empresaId, $sedeId);
        $directSet = array_flip($directIds);

        $deniedIds = $this->repository->getDeniedPermissionItemAccionIdsForScope((int)$usuarioId, $empresaId, $sedeId);
        $deniedSet = array_flip($deniedIds);

        $roleIds = $this->repository->getRoleGrantedItemAccionIdsForUserScope((int)$usuarioId, $empresaId, $sedeId);
        $roleSet = array_flip($roleIds);

        $matriz = [];

        foreach ($rows as $r) {
            $modulo = (string)$r['modulo'];
            $itemId = (int)$r['item_id'];
            $codigo = (string)$r['codigo'];
            $iaId = (int)$r['item_accion_id'];

            if (!isset($matriz[$modulo])) {
                $matriz[$modulo] = [
                    'acciones' => [],
                    'items' => [],
                ];
            }

            $matriz[$modulo]['acciones'][$codigo] = [
                'nombre' => (string)$r['accion'],
                'accion_id' => (int)$r['accion_id'],
            ];

            if (!isset($matriz[$modulo]['items'][$itemId])) {
                $matriz[$modulo]['items'][$itemId] = [
                    'item' => (string)$r['item'],
                    'acciones' => [],
                ];
            }

            $hasRole = isset($roleSet[$iaId]);
            $hasAllow = isset($directSet[$iaId]);
            $hasDeny = isset($deniedSet[$iaId]);

            if ($hasRole) {
                $checked = !$hasDeny;
                $denied = $hasDeny;
            } else {
                $checked = $hasAllow;
                $denied = false;
            }

            $matriz[$modulo]['items'][$itemId]['acciones'][$codigo] = [
                'id' => $iaId,
                'checked' => $checked,
                'denied' => $denied,
                'from_role' => $hasRole,
            ];
        }

        foreach ($matriz as $modulo => $bloque) {
            $acciones = $bloque['acciones'];
            uasort(
                $acciones,
                static fn (array $a, array $b): int => ($a['accion_id'] ?? 0) <=> ($b['accion_id'] ?? 0)
            );
            $ordenadas = [];
            foreach ($acciones as $codigo => $meta) {
                $ordenadas[$codigo] = $meta['nombre'];
            }
            $matriz[$modulo]['acciones'] = $ordenadas;
        }

        return ['matriz' => $matriz];
    }

    /**
     * @param int[] $grantIds permitir (5), solo válidos si el ítem no viene del rol en este alcance
     * @param int[] $denyIds denegar (6), solo válidos si el ítem viene del rol en este alcance
     * @param int[] $removeIds eliminar fila en permiso para el alcance
     * @return array{success: bool, message?: string}
     */
    public function saveUsuarioPermisosBatch(
        int $usuarioId,
        array $grantIds,
        array $denyIds,
        array $removeIds,
        ?int $empresaId,
        ?int $sedeId
    ): array {
        try {
            $roleSet = array_flip($this->repository->getRoleGrantedItemAccionIdsForUserScope(
                (int)$usuarioId,
                $empresaId,
                $sedeId
            ));

            $grantIds = array_values(array_unique(array_map('intval', $grantIds)));
            $denyIds = array_values(array_unique(array_map('intval', $denyIds)));
            $removeIds = array_values(array_unique(array_map('intval', $removeIds)));

            $grantIds = array_values(array_filter($grantIds, static function (int $id) use ($roleSet): bool {
                return $id > 0 && !isset($roleSet[$id]);
            }));

            $denyIds = array_values(array_filter($denyIds, static function (int $id) use ($roleSet): bool {
                return $id > 0 && isset($roleSet[$id]);
            }));

            $this->repository->applyUsuarioPermisosMutations(
                $usuarioId,
                $removeIds,
                $grantIds,
                $denyIds,
                $empresaId,
                $sedeId
            );

            return ['success' => true];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'No se pudieron guardar los permisos'];
        }
    }
}
