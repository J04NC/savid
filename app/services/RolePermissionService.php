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

    /**
     * Matriz por módulo: cada bloque trae solo las acciones usadas en ítems de ese módulo.
     *
     * @return array{matriz: array<string, array{acciones: array<string, string>, items: array<int, array<string, mixed>>}>}
     */
    private function buildMatrix($rows, $actuales): array
    {
        $matriz = [];

        foreach ($rows as $r) {
            $modulo = (string)$r['modulo'];
            $itemId = (int)$r['item_id'];
            $codigo = (string)$r['codigo'];

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

            $matriz[$modulo]['items'][$itemId]['acciones'][$codigo] = [
                'id' => (int)$r['item_accion_id'],
                'checked' => in_array((int)$r['item_accion_id'], $actuales, true),
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
     * Etiqueta corta para cabecera de columna (nombre completo en title).
     *
     * @return array{short: string, full: string}
     */
    public static function actionHeaderLabels(string $codigo, string $nombre): array
    {
        $full = strtoupper(trim($nombre));
        $fromCodigo = strtoupper(str_replace('_', ' ', trim($codigo)));

        if (mb_strlen($full) <= 14) {
            return ['short' => $full, 'full' => $full];
        }

        if (mb_strlen($fromCodigo) <= 14) {
            return ['short' => $fromCodigo, 'full' => $full];
        }

        return ['short' => mb_substr($fromCodigo, 0, 12) . '…', 'full' => $full];
    }
}
