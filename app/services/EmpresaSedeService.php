<?php

/**
 * Orquesta el modal "Sedes de la empresa" (?url=empresa/sedes/{id}): listar,
 * crear/editar (con validación de nombre único por empresa), inactivar y
 * eliminar (retirando antes las asignaciones `usuario_sede` de esa sede).
 * Antes esta lógica (con SQL directo) vivía en EmpresaController.
 */
class EmpresaSedeService
{
    private EmpresaSedeRepository $repo;

    public function __construct()
    {
        $database = new Database();
        $this->repo = new EmpresaSedeRepository($database->connect());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function obtenerSedes(int $empresaId): array
    {
        return $this->repo->fetchSedes($empresaId);
    }

    /**
     * @return array{success: bool, message: string, sedes?: list<array<string, mixed>>}
     */
    public function eliminar(int $empresaId, int $sedeId, bool $esSuperAdmin): array
    {
        if (!$esSuperAdmin) {
            return ['success' => false, 'message' => 'Solo un superadministrador puede eliminar sedes.'];
        }

        if ($sedeId <= 0) {
            return ['success' => false, 'message' => 'Sede no especificada.'];
        }

        if (!$this->repo->sedePerteneceAEmpresa($sedeId, $empresaId)) {
            return ['success' => false, 'message' => 'La sede no pertenece a esta empresa.'];
        }

        $asignaciones = $this->repo->countAsignacionesUsuario($sedeId);

        $pdo = $this->repo->getPdo();
        $pdo->beginTransaction();
        try {
            if ($asignaciones > 0) {
                $this->repo->deleteAsignacionesUsuario($sedeId);
            }

            $this->repo->deleteSede($sedeId, $empresaId);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();

            return ['success' => false, 'message' => 'No se pudo eliminar: ' . $e->getMessage()];
        }

        $msg = 'Sede eliminada.';
        if ($asignaciones > 0) {
            $msg .= ' Se retiraron ' . $asignaciones . ' asignación(es) de usuario en esa sede.';
        }

        return [
            'success' => true,
            'message' => $msg,
            'sedes' => $this->obtenerSedes($empresaId),
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @return array{success: bool, message: string, sede_id?: int, sedes?: list<array<string, mixed>>, errors?: array<string, string>}
     */
    public function guardar(int $empresaId, array $post): array
    {
        $sedeId = (int)($post['sede_id'] ?? 0);
        $nombre = trim((string)($post['nombre'] ?? ''));
        $direccion = trim((string)($post['direccion'] ?? ''));
        $telefono = trim((string)($post['telefono'] ?? ''));
        $codigoInterno = trim((string)($post['codigo_interno'] ?? ''));
        $estadoIdRaw = (int)($post['estado_id'] ?? 1);
        $estadoId = $estadoIdRaw === 2 ? 2 : 1;

        $errors = [];

        if ($nombre === '') {
            $errors['nombre'] = 'El nombre es obligatorio.';
        } elseif (mb_strlen($nombre) > 100) {
            $errors['nombre'] = 'Máximo 100 caracteres.';
        }

        if ($direccion !== '' && mb_strlen($direccion) > 150) {
            $errors['direccion'] = 'Máximo 150 caracteres.';
        }
        if ($telefono !== '' && mb_strlen($telefono) > 150) {
            $errors['telefono'] = 'Máximo 150 caracteres.';
        }
        if ($codigoInterno !== '' && mb_strlen($codigoInterno) > 150) {
            $errors['codigo_interno'] = 'Máximo 150 caracteres.';
        }

        if ($nombre !== '' && $this->repo->existeNombreDuplicado($empresaId, $nombre, $sedeId)) {
            $errors['nombre'] = 'Ya existe una sede con ese nombre en la empresa.';
        }

        if ($sedeId > 0 && !$this->repo->sedePerteneceAEmpresa($sedeId, $empresaId)) {
            return ['success' => false, 'message' => 'La sede no pertenece a esta empresa.'];
        }

        if ($errors !== []) {
            return [
                'success' => false,
                'message' => 'Revise los campos resaltados.',
                'errors' => $errors,
            ];
        }

        $datos = [
            'nombre' => $nombre,
            'direccion' => $direccion !== '' ? $direccion : null,
            'telefono' => $telefono !== '' ? $telefono : null,
            'codigo_interno' => $codigoInterno !== '' ? $codigoInterno : null,
            'estado_id' => $estadoId,
        ];

        try {
            if ($sedeId > 0) {
                $this->repo->updateSede($sedeId, $empresaId, $datos);
                $savedId = $sedeId;
                $msg = 'Sede actualizada.';
            } else {
                $savedId = $this->repo->insertSede($empresaId, $datos);
                $msg = 'Sede creada.';
            }
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'No se pudo guardar: ' . $e->getMessage()];
        }

        return [
            'success' => true,
            'message' => $msg,
            'sede_id' => $savedId,
            'sedes' => $this->obtenerSedes($empresaId),
        ];
    }

    /**
     * @return array{success: bool, message: string, sede_id?: int, estado_id?: int, sedes?: list<array<string, mixed>>}
     */
    public function alternarEstado(int $empresaId, int $sedeId): array
    {
        if ($sedeId <= 0) {
            return ['success' => false, 'message' => 'Sede no especificada.'];
        }

        $current = $this->repo->findEstado($sedeId, $empresaId);
        if ($current === null) {
            return ['success' => false, 'message' => 'La sede no pertenece a esta empresa.'];
        }

        $new = ($current === 1) ? 2 : 1;

        try {
            $this->repo->updateEstado($sedeId, $empresaId, $new);
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'No se pudo cambiar el estado: ' . $e->getMessage()];
        }

        return [
            'success' => true,
            'message' => $new === 1 ? 'Sede activada.' : 'Sede inactivada.',
            'sede_id' => $sedeId,
            'estado_id' => $new,
            'sedes' => $this->obtenerSedes($empresaId),
        ];
    }
}
