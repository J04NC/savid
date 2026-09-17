<?php

/**
 * Rutas bajo `?url=tercero/...`: CRUD automático en index; modales y APIs en el resto.
 */
class TerceroController
{
    private TerceroIdentificacionService $identService;

    public function __construct()
    {
        $this->identService = new TerceroIdentificacionService();
    }

    private function isSuperAdmin(): bool
    {
        return class_exists('PermisoService')
            ? PermisoService::isSuperAdminSession()
            : (!empty($_SESSION['es_super_admin']) || (int)($_SESSION['rol_id'] ?? 0) === 1);
    }

    public function index(): void
    {
        require_once BASE_PATH . '/app/controllers/ModuleController.php';
        (new ModuleController())->index();
    }

    /**
     * Modal: identificaciones del tercero seleccionado en el CRUD.
     * Ruta: ?url=tercero/identificaciones/{terceroId}
     * POST _action: save | toggle | set_principal | delete (mutaciones solo superadmin)
     */
    public function identificaciones($terceroId = null): void
    {
        SessionManager::requireLogin();

        $tid = $terceroId !== null && $terceroId !== '' ? (int)$terceroId : 0;
        if ($tid <= 0) {
            $this->renderModalError('Seleccione un tercero en la tabla y vuelva a abrir la acción.');
            return;
        }

        if (class_exists('PermisoService') && !PermisoService::can('tercero', 'ver')) {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $this->jsonResponse(['success' => false, 'message' => 'Sin permiso para ver terceros.']);
            }
            $this->renderModalError('No tiene permiso para consultar identificaciones de terceros.');
            return;
        }

        $tercero = $this->identService->obtenerHeader($tid);
        if ($tercero === null) {
            $this->renderModalError('Tercero no encontrado.');
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->handleIdentificacionesPost($tid);
            return;
        }

        $tiposDocumento = $this->identService->obtenerTiposDocumento();
        $filas = $this->identService->obtenerIdentificaciones($tid);
        $esSuperAdmin = $this->isSuperAdmin();

        require BASE_PATH . '/app/views/tercero/identificaciones_modal.php';
    }

    private function handleIdentificacionesPost(int $terceroId): void
    {
        if (!$this->isSuperAdmin()) {
            $this->jsonResponse([
                'success' => false,
                'message' => 'Solo un superadministrador puede modificar identificaciones.',
            ]);
            return;
        }

        $action = (string)($_POST['_action'] ?? '');
        $identId = (int)($_POST['identificacion_id'] ?? 0);

        if ($action === 'save') {
            $this->jsonResponse($this->identService->guardar($terceroId, $_POST));
            return;
        }

        if ($action === 'toggle') {
            $this->jsonResponse($this->identService->alternarEstado($terceroId, $identId));
            return;
        }

        if ($action === 'set_principal') {
            $this->jsonResponse($this->identService->marcarPrincipal($terceroId, $identId));
            return;
        }

        if ($action === 'delete') {
            $this->jsonResponse($this->identService->eliminar($terceroId, $identId));
            return;
        }

        $this->jsonResponse(['success' => false, 'message' => 'Acción inválida.']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonResponse(array $payload): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function renderModalError(string $message, string $title = 'Identificaciones del tercero'): void
    {
        echo '<div class="tercero-ident-modal modal-inner">'
            . '<header class="modal-form-head"><h3 class="modal-form-title">'
            . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h3></header>'
            . '<p class="modal-form-alert">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<footer class="tercero-ident-footer">'
            . '<button type="button" class="btn-cancel" onclick="closeModalGod()">Cerrar</button>'
            . '</footer></div>';
        exit;
    }
}
