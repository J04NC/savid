<?php

/**
 * Rutas bajo `?url=item/...`: CRUD automático en index; modales y APIs en el resto.
 */
class ItemController
{
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

        $database = new Database();
        $pdo = $database->connect();

        $itemNotDeleted = SoftDeleteService::sqlAndNotDeleted($pdo, 'item', 'i');

        $stmt = $pdo->prepare(
            "SELECT i.id, i.nombre, i.ruta, i.modulo_id, m.nombre AS modulo_nombre
             FROM item i
             LEFT JOIN modulo m ON m.id = i.modulo_id
             WHERE i.id = ?
             {$itemNotDeleted}
             LIMIT 1"
        );
        $stmt->execute([$iid]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            $this->renderModalError('Ítem no encontrado.');
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->handleAccionesPost($pdo, $iid);
            return;
        }

        $acciones = $this->fetchItemAcciones($pdo, $iid);

        require BASE_PATH . '/app/views/item/acciones_modal.php';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchItemAcciones(PDO $pdo, int $itemId): array
    {
        $stmt = $pdo->prepare(
            'SELECT a.id AS accion_id, a.nombre, a.codigo, a.accion_codigo, a.icono, a.descripcion, a.orden,
                    ia.id AS item_accion_id,
                    ia.estado_id AS link_estado_id
               FROM accion a
               LEFT JOIN item_accion ia ON ia.accion_id = a.id AND ia.item_id = ?
              WHERE a.estado_id = 1
              ORDER BY a.orden ASC, a.nombre ASC, a.id ASC'
        );
        $stmt->execute([$itemId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function handleAccionesPost(PDO $pdo, int $itemId): void
    {
        if (class_exists('PermisoService') && !PermisoService::can('item', 'guardar')) {
            $this->jsonResponse(['success' => false, 'message' => 'Sin permiso para modificar acciones del ítem.']);
            return;
        }

        $action = (string)($_POST['_action'] ?? '');

        if ($action === 'link') {
            $this->handleAccionLink($pdo, $itemId);
            return;
        }

        if ($action === 'unlink') {
            $this->handleAccionUnlink($pdo, $itemId);
            return;
        }

        if ($action === 'toggle') {
            $this->handleAccionToggle($pdo, $itemId);
            return;
        }

        $this->jsonResponse(['success' => false, 'message' => 'Acción inválida.']);
    }

    private function handleAccionLink(PDO $pdo, int $itemId): void
    {
        $accionId = (int)($_POST['accion_id'] ?? 0);
        if ($accionId <= 0) {
            $this->jsonResponse(['success' => false, 'message' => 'Acción no especificada.']);
            return;
        }

        $st = $pdo->prepare('SELECT 1 FROM accion WHERE id = ? AND estado_id = 1 LIMIT 1');
        $st->execute([$accionId]);
        if (!$st->fetchColumn()) {
            $this->jsonResponse(['success' => false, 'message' => 'La acción no existe o está inactiva.']);
            return;
        }

        try {
            $st = $pdo->prepare(
                'INSERT INTO item_accion (item_id, accion_id, estado_id)
                 VALUES (?, ?, 1)
                 ON DUPLICATE KEY UPDATE estado_id = 1'
            );
            $st->execute([$itemId, $accionId]);
        } catch (Throwable $e) {
            $this->jsonResponse(['success' => false, 'message' => 'No se pudo vincular: ' . $e->getMessage()]);
            return;
        }

        $this->jsonResponse([
            'success' => true,
            'message' => 'Acción vinculada al ítem.',
            'acciones' => $this->fetchItemAcciones($pdo, $itemId),
        ]);
    }

    private function handleAccionUnlink(PDO $pdo, int $itemId): void
    {
        if (!$this->isSuperAdmin()) {
            $this->jsonResponse([
                'success' => false,
                'message' => 'Solo un superadministrador puede quitar vínculos de acción.',
            ]);
            return;
        }

        $accionId = (int)($_POST['accion_id'] ?? 0);
        if ($accionId <= 0) {
            $this->jsonResponse(['success' => false, 'message' => 'Acción no especificada.']);
            return;
        }

        $st = $pdo->prepare('SELECT id FROM item_accion WHERE item_id = ? AND accion_id = ? LIMIT 1');
        $st->execute([$itemId, $accionId]);
        if (!$st->fetchColumn()) {
            $this->jsonResponse(['success' => false, 'message' => 'La acción no está vinculada a este ítem.']);
            return;
        }

        try {
            $st = $pdo->prepare('DELETE FROM item_accion WHERE item_id = ? AND accion_id = ?');
            $st->execute([$itemId, $accionId]);
        } catch (Throwable $e) {
            $this->jsonResponse(['success' => false, 'message' => 'No se pudo quitar: ' . $e->getMessage()]);
            return;
        }

        $this->jsonResponse([
            'success' => true,
            'message' => 'Vínculo eliminado.',
            'acciones' => $this->fetchItemAcciones($pdo, $itemId),
        ]);
    }

    private function handleAccionToggle(PDO $pdo, int $itemId): void
    {
        $accionId = (int)($_POST['accion_id'] ?? 0);
        if ($accionId <= 0) {
            $this->jsonResponse(['success' => false, 'message' => 'Acción no especificada.']);
            return;
        }

        $st = $pdo->prepare(
            'SELECT id, estado_id FROM item_accion WHERE item_id = ? AND accion_id = ? LIMIT 1'
        );
        $st->execute([$itemId, $accionId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $this->jsonResponse(['success' => false, 'message' => 'La acción no está vinculada a este ítem.']);
            return;
        }

        $new = ((int)$row['estado_id'] === 1) ? 2 : 1;

        try {
            $st = $pdo->prepare('UPDATE item_accion SET estado_id = ? WHERE id = ?');
            $st->execute([$new, (int)$row['id']]);
        } catch (Throwable $e) {
            $this->jsonResponse(['success' => false, 'message' => 'No se pudo cambiar el estado: ' . $e->getMessage()]);
            return;
        }

        $this->jsonResponse([
            'success' => true,
            'message' => $new === 1 ? 'Vínculo activado.' : 'Vínculo inactivado.',
            'acciones' => $this->fetchItemAcciones($pdo, $itemId),
        ]);
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
