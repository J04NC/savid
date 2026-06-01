<?php

/**
 * Reglas de qué tipos documentales pueden ser padre de otro tipo (por empresa).
 */
class SgdTipoDocumentalPadreService
{
    private SgdRepository $repo;
    private SgdScopeService $scope;

    public function __construct()
    {
        $this->repo = new SgdRepository();
        $this->scope = new SgdScopeService();
    }

    /**
     * @return array{
     *   tipo: array<string, mixed>,
     *   tipos: list<array<string, mixed>>,
     *   padresPermitidosIds: list<int>
     * }|null
     */
    public function getModalData(int $tipoId, array $query): ?array
    {
        try {
            $empresaId = $this->scope->requireEmpresaId($query);
        } catch (RuntimeException) {
            return null;
        }

        if (!$this->scope->canAccessEmpresa($empresaId, $query)) {
            return null;
        }

        $tipo = $this->repo->findTipoDocumentalById($empresaId, $tipoId);
        if ($tipo === null) {
            return null;
        }

        $tipos = $this->repo->listTiposByEmpresa($empresaId);
        $padresPermitidosIds = $this->repo->listTiposPadrePermitidosIds($empresaId, $tipoId);

        return [
            'tipo' => $tipo,
            'tipos' => $tipos,
            'padresPermitidosIds' => $padresPermitidosIds,
        ];
    }

    /**
     * @param list<int|string> $padreTipoIds
     * @return array{success: bool, message: string}
     */
    public function save(int $tipoId, array $padreTipoIds, array $query): array
    {
        try {
            $empresaId = $this->scope->requireEmpresaId($query);
        } catch (RuntimeException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        if (!$this->scope->canAccessEmpresa($empresaId, $query)) {
            return ['success' => false, 'message' => 'Sin permiso para esta empresa.'];
        }

        $tipo = $this->repo->findTipoDocumentalById($empresaId, $tipoId);
        if ($tipo === null) {
            return ['success' => false, 'message' => 'Tipo documental no encontrado.'];
        }

        $validIds = [];
        foreach ($this->repo->listTiposByEmpresa($empresaId) as $row) {
            $validIds[(int)$row['id']] = true;
        }

        $filtered = [];
        foreach ($padreTipoIds as $raw) {
            $id = (int)$raw;
            if ($id <= 0 || $id === $tipoId || !isset($validIds[$id])) {
                continue;
            }
            $filtered[$id] = $id;
        }

        $this->repo->replaceTiposPadrePermitidos($empresaId, $tipoId, array_values($filtered));

        return [
            'success' => true,
            'message' => 'Padres permitidos actualizados.',
            'padresPermitidosIds' => array_values($filtered),
        ];
    }
}
