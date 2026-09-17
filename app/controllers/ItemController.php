<?php

/**
 * Rutas bajo `?url=item/...`: CRUD automático en index; modales y APIs en el resto.
 */
class ItemController
{
    private ItemAccionesService $accionesService;

    public function __construct()
    {
        $this->accionesService = new ItemAccionesService();
    }

    private function isSuperAdmin(): bool
    {
        return !empty($_SESSION['es_super_admin'])
            || (int)($_SESSION['rol_id'] ?? 0) === 1;
    }

    public function index(): void
    {
        require_once BASE_PATH . '/app/controllers/ModuleController.php';
        (new ModuleController())->index();
    }

    /**
     * Modal: acciones vinculadas al ítem seleccionado en el CRUD.
     * Ruta: ?url=item/acciones/{itemId}
     * POST _action: link | unlink | toggle
     */
    public function acciones($itemId = null): void
    {
        SessionManager::requireLogin();

        if (!$this->isSuperAdmin()) {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $this->jsonResponse([
                    'success' => false,
                    'message' => 'Solo un superadministrador puede gestionar acciones de ítems.',
                ]);
            }
            $this->renderModalError('Solo un superadministrador puede acceder al catálogo de ítems.');
            return;
        }

        $iid = $itemId !== null && $itemId !== '' ? (int)$itemId : 0;
        if ($iid <= 0) {
            $this->renderModalError('Seleccione un ítem en la tabla y vuelva a abrir la acción.');
            return;
        }

        $item = $this->accionesService->findItem($iid);
        if (!$item) {
            $this->renderModalError('Ítem no encontrado.');
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->handleAccionesPost($iid);
            return;
        }

        $acciones = $this->accionesService->listarAcciones($iid);

        require BASE_PATH . '/app/views/item/acciones_modal.php';
    }

    private function handleAccionesPost(int $itemId): void
    {
        if (class_exists('PermisoService') && !PermisoService::can('item', 'guardar')) {
            $this->jsonResponse(['success' => false, 'message' => 'Sin permiso para modificar acciones del ítem.']);
            return;
        }

        $action = (string)($_POST['_action'] ?? '');
        $accionId = (int)($_POST['accion_id'] ?? 0);

        if ($action === 'link') {
            $this->jsonResponse($this->accionesService->link($itemId, $accionId));
            return;
        }

        if ($action === 'unlink') {
            if (!$this->isSuperAdmin()) {
                $this->jsonResponse([
                    'success' => false,
                    'message' => 'Solo un superadministrador puede quitar vínculos de acción.',
                ]);
                return;
            }
            $this->jsonResponse($this->accionesService->unlink($itemId, $accionId));
            return;
        }

        if ($action === 'toggle') {
            $this->jsonResponse($this->accionesService->toggle($itemId, $accionId));
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

    private function renderModalError(
        string $message,
        string $title = 'Acciones del ítem'
    ): void {
        echo '<div class="item-acciones-modal modal-inner">'
            . '<header class="modal-form-head"><h3 class="modal-form-title">'
            . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h3></header>'
            . '<p class="modal-form-alert">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<footer class="item-acciones-footer">'
            . '<button type="button" class="btn-cancel" onclick="closeModalGod()">Cerrar</button>'
            . '</footer></div>';
        exit;
    }
}
