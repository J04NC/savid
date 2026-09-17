<?php

/**
 * Orquesta el modal "Acciones del ítem" (?url=item/acciones/{id}):
 * buscar el ítem, listar el catálogo de acciones y vincular/desvincular/
 * activar-inactivar cada una. Antes esta lógica (con SQL directo) vivía en
 * ItemController.
 */
class ItemAccionesService
{
    private ItemAccionRepository $repo;

    public function __construct()
    {
        $database = new Database();
        $this->repo = new ItemAccionRepository($database->connect());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findItem(int $itemId): ?array
    {
        return $this->repo->findItemConModulo($itemId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listarAcciones(int $itemId): array
    {
        return $this->repo->findAccionesDisponibles($itemId);
    }

    /**
     * @return array{success: bool, message: string, acciones?: list<array<string, mixed>>}
     */
    public function link(int $itemId, int $accionId): array
    {
        if ($accionId <= 0) {
            return ['success' => false, 'message' => 'Acción no especificada.'];
        }

        if (!$this->repo->accionActivaExiste($accionId)) {
            return ['success' => false, 'message' => 'La acción no existe o está inactiva.'];
        }

        try {
            $this->repo->linkAccion($itemId, $accionId);
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'No se pudo vincular: ' . $e->getMessage()];
        }

        return [
            'success' => true,
            'message' => 'Acción vinculada al ítem.',
            'acciones' => $this->listarAcciones($itemId),
        ];
    }

    /**
     * @return array{success: bool, message: string, acciones?: list<array<string, mixed>>}
     */
    public function unlink(int $itemId, int $accionId): array
    {
        if ($accionId <= 0) {
            return ['success' => false, 'message' => 'Acción no especificada.'];
        }

        if ($this->repo->findVinculo($itemId, $accionId) === null) {
            return ['success' => false, 'message' => 'La acción no está vinculada a este ítem.'];
        }

        try {
            $this->repo->unlinkAccion($itemId, $accionId);
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'No se pudo quitar: ' . $e->getMessage()];
        }

        return [
            'success' => true,
            'message' => 'Vínculo eliminado.',
            'acciones' => $this->listarAcciones($itemId),
        ];
    }

    /**
     * @return array{success: bool, message: string, acciones?: list<array<string, mixed>>}
     */
    public function toggle(int $itemId, int $accionId): array
    {
        if ($accionId <= 0) {
            return ['success' => false, 'message' => 'Acción no especificada.'];
        }

        $vinculo = $this->repo->findVinculoConEstado($itemId, $accionId);
        if ($vinculo === null) {
            return ['success' => false, 'message' => 'La acción no está vinculada a este ítem.'];
        }

        $nuevoEstado = ($vinculo['estado_id'] === 1) ? 2 : 1;

        try {
            $this->repo->setEstadoVinculo($vinculo['id'], $nuevoEstado);
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'No se pudo cambiar el estado: ' . $e->getMessage()];
        }

        return [
            'success' => true,
            'message' => $nuevoEstado === 1 ? 'Vínculo activado.' : 'Vínculo inactivado.',
            'acciones' => $this->listarAcciones($itemId),
        ];
    }
}
