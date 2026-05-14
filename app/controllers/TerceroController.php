<?php

/**
 * Rutas bajo `?url=tercero/...` sin sustituir el CRUD automático del ítem:
 * `index` delega en ModuleController; las demás acciones son modales / especiales.
 */
class TerceroController
{
    /**
     * CRUD estándar del ítem `tercero` (misma lógica que antes de existir este controlador).
     */
    public function index(): void
    {
        require_once BASE_PATH . '/app/controllers/ModuleController.php';
        (new ModuleController())->index();
    }

    /**
     * Modal: listado de identificaciones del tercero seleccionado en el CRUD.
     * Ruta: ?url=tercero/identificaciones/{id}
     */
    public function identificaciones($terceroId = null): void
    {
        $tid = $terceroId !== null && $terceroId !== '' ? (int)$terceroId : 0;
        if ($tid <= 0) {
            echo '<div class="modal-inner" style="padding:16px;"><p>Seleccione un tercero en la tabla y vuelva a abrir la acción.</p></div>';
            exit;
        }

        $database = new Database();
        $pdo = $database->connect();

        $st = $pdo->prepare('SELECT id FROM tercero WHERE id = ? LIMIT 1');
        $st->execute([$tid]);
        if (!$st->fetchColumn()) {
            echo '<div class="modal-inner" style="padding:16px;"><p>Tercero no encontrado.</p></div>';
            exit;
        }

        $filas = [];
        $tablaExiste = true;
        try {
            $q = $pdo->prepare(
                'SELECT ti.*, td.nombre AS tipo_documento_nombre
                 FROM terceroidentificacion ti
                 LEFT JOIN tipodocumento td ON td.id = ti.tipodocumento_id
                 WHERE ti.tercero_id = ?
                 ORDER BY ti.principal DESC, ti.id ASC'
            );
            $q->execute([$tid]);
            $filas = $q->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $tablaExiste = false;
            $filas = [];
        }

        require BASE_PATH . '/app/views/tercero/identificaciones_modal.php';
    }
}
