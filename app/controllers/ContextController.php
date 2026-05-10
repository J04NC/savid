<?php

class ContextController
{
    private ContextService $contextService;

    public function __construct()
    {
        $this->contextService = new ContextService();
    }

    public function cambiarSede()
    {
        if (!isset($_SESSION['user_id'])) {
            header('Location: ?url=login');
            exit;
        }

        // AJAX: sedes autorizadas para la empresa (y usuario actual)
        if (isset($_GET['empresa_id'])) {
            $empresaId = $_GET['empresa_id'];
            echo json_encode($this->contextService->getSedesForContextSelection($empresaId));
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $empresaId = $_POST['empresa_id'] ?? null;
            $sedeId = $_POST['sede_id'] ?? null;
            echo json_encode($this->contextService->changeContext($empresaId, $sedeId));
            exit;
        }

        $empresas = $this->contextService->getEmpresasForContextSelection((int)$_SESSION['user_id']);
        $soloEmpresa = count($empresas) === 1;
        $empresaFijaId = $soloEmpresa ? (int)$empresas[0]['id'] : null;

        $partial = isset($_GET['partial']) && $_GET['partial'] === '1';

        if ($partial) {
            require BASE_PATH . '/app/views/select_context.php';
            exit;
        }

        require BASE_PATH . '/app/views/context_select_page.php';
    }
}
