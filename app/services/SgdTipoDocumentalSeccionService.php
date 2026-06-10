<?php

class SgdTipoDocumentalSeccionService
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
     *   secciones: list<array<string, mixed>>,
     *   estados: array<int, string>
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

        if (($tipo['modo'] ?? '') !== 'maestro') {
            return null;
        }

        return [
            'tipo' => $tipo,
            'secciones' => $this->repo->listSeccionesByEmpresa($empresaId),
            'estados' => $this->repo->listTipoSeccionEstadosMap($empresaId, $tipoId),
        ];
    }

    /**
     * @param array<int|string, string> $postEstados seccion_id => estado
     * @return array{success: bool, message: string}
     */
    public function save(int $tipoId, array $postEstados, array $query): array
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

        if (($tipo['modo'] ?? '') !== 'maestro') {
            return ['success' => false, 'message' => 'El perfil de secciones solo aplica a tipos en modo maestro.'];
        }

        $map = [];
        foreach ($postEstados as $sid => $estado) {
            $map[(int)$sid] = (string)$estado;
        }

        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $this->saveProfileMap($empresaId, $tipoId, $map, $userId);

        return ['success' => true, 'message' => 'Perfil de secciones actualizado.'];
    }

    /**
     * @param array<int, string> $map
     */
    public function saveProfileMap(int $empresaId, int $tipoId, array $map, ?int $userId = null): void
    {
        $this->repo->replaceTipoDocumentalSecciones($empresaId, $tipoId, $map, $userId);
    }
}
