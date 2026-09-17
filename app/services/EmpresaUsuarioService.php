<?php

/**
 * Orquesta el modal "Usuarios de la empresa" (?url=empresa/usuarios/{id}):
 * listado de usuarios vinculados (por usuario_empresa o por sede), búsqueda
 * de candidatos para vincular, y las mutaciones de vínculo
 * (link/unlink/toggle/delete). Antes esta lógica (con SQL directo) vivía en
 * EmpresaController.
 */
class EmpresaUsuarioService
{
    private EmpresaUsuarioRepository $repo;

    public function __construct()
    {
        $database = new Database();
        $this->repo = new EmpresaUsuarioRepository($database->connect());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function obtenerEmpresa(int $empresaId): ?array
    {
        return $this->repo->findEmpresaConRazonSocial($empresaId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchUsuarios(int $empresaId): array
    {
        return $this->repo->fetchUsuarios($empresaId);
    }

    /**
     * @return array{success: bool, message: string, usuarios?: list<array<string, mixed>>}
     */
    public function eliminar(int $empresaId, int $usuarioId, int $currentUid): array
    {
        if ($usuarioId <= 0) {
            return ['success' => false, 'message' => 'Usuario no especificado.'];
        }

        if ($this->repo->esSuperAdminUsuario($usuarioId)) {
            return ['success' => false, 'message' => 'No puede eliminar cuentas de superadministrador.'];
        }

        if ($usuarioId === $currentUid) {
            return ['success' => false, 'message' => 'No puede eliminar su propio vínculo con la empresa.'];
        }

        $tieneVinculoEmpresa = $this->repo->tieneVinculoEmpresa($usuarioId, $empresaId);
        $tieneAsignacionSede = $this->repo->tieneAsignacionSede($empresaId, $usuarioId);

        if (!$tieneVinculoEmpresa && !$tieneAsignacionSede) {
            return ['success' => false, 'message' => 'El usuario no tiene vínculo ni sedes en esta empresa.'];
        }

        $pdo = $this->repo->getPdo();
        $pdo->beginTransaction();
        try {
            $this->repo->deleteAsignacionesSede($empresaId, $usuarioId);

            if ($tieneVinculoEmpresa) {
                $this->repo->deleteVinculoEmpresa($usuarioId, $empresaId);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();

            return ['success' => false, 'message' => 'No se pudo eliminar: ' . $e->getMessage()];
        }

        return [
            'success' => true,
            'message' => 'Vínculo de usuario eliminado de la empresa.',
            'usuarios' => $this->fetchUsuarios($empresaId),
        ];
    }

    /**
     * @return array{success: bool, options: list<array<string, mixed>>}
     */
    public function buscar(string $term, int $empresaId, bool $esSuperAdmin, int $currentUid): array
    {
        $term = trim($term);
        if ($term === '' || mb_strlen($term) < 2) {
            return ['success' => true, 'options' => []];
        }

        $rows = $this->repo->buscarCandidatos($term, $empresaId);

        if (!$esSuperAdmin && $currentUid > 0) {
            $rows = array_values(array_filter(
                $rows,
                fn ($r) => $this->usuarioVisibleAlLogueado((int)$r['id'], $currentUid)
            ));
        }

        return ['success' => true, 'options' => $rows];
    }

    /**
     * @return array{success: bool, message: string, usuarios?: list<array<string, mixed>>}
     */
    public function vincular(int $empresaId, int $usuarioId, bool $esSuperAdmin, int $currentUid): array
    {
        if ($usuarioId <= 0) {
            return ['success' => false, 'message' => 'Usuario no especificado.'];
        }

        if ($this->repo->findUsuario($usuarioId) === null) {
            return ['success' => false, 'message' => 'Usuario no encontrado.'];
        }

        if ($this->repo->esSuperAdminUsuario($usuarioId)) {
            return ['success' => false, 'message' => 'No puede modificar cuentas de superadministrador.'];
        }

        if (!$esSuperAdmin && !$this->usuarioVisibleAlLogueado($usuarioId, $currentUid)) {
            return ['success' => false, 'message' => 'Solo puede vincular usuarios que ya están en su alcance.'];
        }

        try {
            $this->repo->upsertVinculo($usuarioId, $empresaId);
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'No se pudo vincular: ' . $e->getMessage()];
        }

        return [
            'success' => true,
            'message' => 'Usuario vinculado a la empresa.',
            'usuarios' => $this->fetchUsuarios($empresaId),
        ];
    }

    /**
     * @return array{success: bool, message: string, usuarios?: list<array<string, mixed>>}
     */
    public function alternarEstado(
        int $empresaId,
        int $usuarioId,
        int $currentUid,
        int $sessionEmpresaId,
        bool $esSuperAdmin
    ): array {
        if ($usuarioId <= 0) {
            return ['success' => false, 'message' => 'Usuario no especificado.'];
        }

        if ($usuarioId === $currentUid && $empresaId === $sessionEmpresaId && !$esSuperAdmin) {
            return [
                'success' => false,
                'message' => 'No puede inactivarse a sí mismo en la empresa de su sesión actual.',
            ];
        }

        $current = $this->repo->findEstadoVinculo($usuarioId, $empresaId);
        if ($current === null) {
            return ['success' => false, 'message' => 'El usuario no está vinculado a esta empresa.'];
        }

        $new = ($current === 1) ? 2 : 1;

        $pdo = $this->repo->getPdo();
        $pdo->beginTransaction();
        try {
            $this->repo->updateEstadoVinculo($usuarioId, $empresaId, $new);

            if ($new === 2) {
                $this->repo->inactivarSedesDeEmpresa($usuarioId, $empresaId);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();

            return ['success' => false, 'message' => 'No se pudo cambiar el estado: ' . $e->getMessage()];
        }

        return [
            'success' => true,
            'message' => $new === 1 ? 'Vínculo activado.' : 'Vínculo inactivado (y sedes asociadas).',
            'usuarios' => $this->fetchUsuarios($empresaId),
        ];
    }

    public function usuarioVisibleAlLogueado(int $targetUsuarioId, int $currentUid): bool
    {
        if ($currentUid <= 0) {
            return false;
        }

        return $this->repo->existeVinculoComunActivo($targetUsuarioId, $currentUid);
    }
}
