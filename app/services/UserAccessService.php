<?php

class UserAccessService
{
    private UserAccessRepository $repository;

    public function __construct()
    {
        $database = new Database();
        $pdo = $database->connect();
        $this->repository = new UserAccessRepository($pdo);
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

        return [
            'usuario' => $usuario,
            'roles' => $this->repository->findAllActiveRoles(),
            'selectedRoles' => $this->repository->findRoleIdsByUserId($usuarioId),
        ];
    }

    public function saveRoles($usuarioId, $roles)
    {
        $this->repository->deleteRolesByUserId($usuarioId);

        foreach ($roles as $rolId) {
            $this->repository->insertUserRole($usuarioId, (int)$rolId);
        }
    }

    public function buildPermissionMatrix($usuarioId)
    {
        $rows = $this->repository->getPermissionMatrixRows();
        $actuales = $this->repository->getAllowedPermissionItemAccionIdsByUserId($usuarioId);

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
        $actuales = $this->repository->getAllowedPermissionItemAccionIdsByUserId($usuarioId);

        $insertar = array_diff($checks, $actuales);
        $eliminar = array_diff($actuales, $checks);

        foreach ($insertar as $itemAccionId) {
            $this->repository->insertAllowedPermission($usuarioId, $itemAccionId);
        }

        foreach ($eliminar as $itemAccionId) {
            $this->repository->deleteAllowedPermission($usuarioId, $itemAccionId);
        }

        return ['success' => true];
    }
}
