<?php

class ContextService
{
    private CompanyRepository $companyRepository;
    private BranchRepository $branchRepository;

    public function __construct()
    {
        $database = new Database();
        $pdo = $database->connect();

        $this->companyRepository = new CompanyRepository($pdo);
        $this->branchRepository = new BranchRepository($pdo);
    }

    public function getSedesByEmpresa($empresaId)
    {
        return $this->branchRepository->findActiveByEmpresaId($empresaId);
    }

    public function changeContext($empresaId, $sedeId)
    {
        $empresa = $this->companyRepository->findActiveById($empresaId);

        if (!$empresa) {
            return ['success' => false];
        }

        $_SESSION['empresa_id'] = $empresa['id'];
        $_SESSION['empresa'] = $empresa['razon_social'];

        if ($sedeId) {
            $sede = $this->branchRepository->findActiveByIdAndEmpresaId($sedeId, $empresaId);

            if ($sede) {
                $_SESSION['sede_id'] = $sede['id'];
                $_SESSION['sede'] = $sede['nombre'];
            } else {
                $_SESSION['sede_id'] = null;
                $_SESSION['sede'] = 'Sin sede';
            }
        } else {
            $_SESSION['sede_id'] = null;
            $_SESSION['sede'] = 'Sin sede';
        }

        return [
            'success' => true,
            'empresa' => $_SESSION['empresa'],
            'sede' => $_SESSION['sede'],
        ];
    }

    public function getEmpresasByUsuario($usuarioId)
    {
        return $this->companyRepository->findActiveByUserId($usuarioId);
    }
}
