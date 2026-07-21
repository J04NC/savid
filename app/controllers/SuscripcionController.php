<?php
/**
 * CRUD base (index) delega en el motor genérico; aquí solo vive la acción
 * especial "Renovar" (accion_codigo: renovar_suscripcion).
 */

class SuscripcionController
{
    public function index()
    {
        require BASE_PATH . '/app/controllers/ModuleController.php';

        $module = new ModuleController();
        $module->index();
    }

    /**
     * GET ?url=suscripcion/renovar/{id} — modal de confirmación (abierto vía openModalGod).
     */
    public function renovar($suscripcionId = null)
    {
        if (!PermisoService::isSuperAdminSession()) {
            http_response_code(403);
            exit('Acceso denegado. Solo superadministrador.');
        }

        $service = new SuscripcionService();
        $preview = $service->getRenewalPreviewForSuscripcion((int)$suscripcionId);

        require BASE_PATH . '/app/views/suscripcion/renovar.php';
    }

    /**
     * POST ?url=suscripcion/renovarConfirmar/{id} — crea la nueva suscripción.
     */
    public function renovarConfirmar($suscripcionId = null)
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!PermisoService::isSuperAdminSession()) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Solo superadministrador'], JSON_UNESCAPED_UNICODE);

            return;
        }

        $service = new SuscripcionService();
        $result = $service->renewFromSuscripcion((int)$suscripcionId);

        if (!$result['success']) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $result['error']], JSON_UNESCAPED_UNICODE);

            return;
        }

        echo json_encode(['ok' => true, 'message' => 'Suscripción renovada correctamente.'], JSON_UNESCAPED_UNICODE);
    }
}
