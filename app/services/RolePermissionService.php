<?php

class RolePermissionService
{
    private CompanyRepository $companyRepository;
    private BranchRepository $branchRepository;
    private RolePermissionRepository $rolePermissionRepository;

    public function __construct()
    {
        $database = new Database();
        $pdo = $database->connect();

        $this->companyRepository = new CompanyRepository($pdo);
        $this->branchRepository = new BranchRepository($pdo);
        $this->rolePermissionRepository = new RolePermissionRepository($pdo);
    }

    public function normalizeScope($empresaId, $sedeId, $esSuperAdmin, $sessionEmpresaId)
    {
        if (!$esSuperAdmin) {
            $empresaId = $sessionEmpresaId ?? null;
        }

        if ($empresaId === '') {
            $empresaId = null;
        }

        if ($sedeId === '') {
            $sedeId = null;
        }

        return [$empresaId, $sedeId];
    }

    public function getSedesForEmpresa($empresaId)
    {
        if (!$empresaId) {
            return [];
        }

        return $this->branchRepository->findActiveByEmpresaId($empresaId);
    }

    public function buildMatrixResponse($rolId, $empresaId, $sedeId)
    {
        $rows = $this->rolePermissionRepository->getMatrixRows();
        $actuales = $this->rolePermissionRepository->getCheckedItemAccionIdsByScope($rolId, $empresaId, $sedeId);

        return $this->buildMatrix($rows, $actuales);
    }

    public function saveRolePermissions($rolId, $empresaId, $sedeId, $checks)
    {
        $actuales = $this->rolePermissionRepository->getCheckedItemAccionIdsByScope($rolId, $empresaId, $sedeId);

        $checks = array_map('intval', $checks);
        $actuales = array_map('intval', $actuales);

        $insertar = array_diff($checks, $actuales);
        $eliminar = array_diff($actuales, $checks);

        foreach ($insertar as $itemAccionId) {
            $this->rolePermissionRepository->insertRolePermission($rolId, $empresaId, $sedeId, $itemAccionId);
        }

        foreach ($eliminar as $itemAccionId) {
            $this->rolePermissionRepository->deleteRolePermissionByScope($rolId, $empresaId, $sedeId, $itemAccionId);
        }

        return ['success' => true];
    }

    public function getEmpresasForPermissionScreen($esSuperAdmin, $userId)
    {
        if ($esSuperAdmin) {
            return $this->companyRepository->findAllActive();
        }

        return $this->companyRepository->findActiveByUserId($userId);
    }

    private function buildMatrix($rows, $actuales)
    {
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
                'checked' => in_array($r['item_accion_id'], $actuales),
            ];
        }

        return [
            'acciones' => $acciones,
            'matriz' => $matriz,
        ];
    }
}
