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
            header("Location: ?url=login");
            exit;
        }

        // =========================================
        // 🔥 AJAX: CARGAR SEDES POR EMPRESA
        // =========================================
        if (isset($_GET['empresa_id'])) {
            $empresaId = $_GET['empresa_id'];
            echo json_encode($this->contextService->getSedesByEmpresa($empresaId));
            exit;
        }

        // =========================================
        // 🔥 GUARDAR SELECCIÓN (AJAX)
        // =========================================
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $empresaId = $_POST['empresa_id'] ?? null;
            $sedeId = $_POST['sede_id'] ?? null;
            echo json_encode($this->contextService->changeContext($empresaId, $sedeId));
            exit;
        }

        // =========================================
        // 🔥 OBTENER EMPRESAS (para vista)
        // =========================================
        $empresas = $this->contextService->getEmpresasByUsuario($_SESSION['user_id']);

        // =========================================
        // 🔥 CARGAR VISTA
        // =========================================
        require BASE_PATH . '/app/views/select_context.php';
    }
}