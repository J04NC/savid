<?php

/**
 * Rutas legacy `action/*`. Preferir controladores dedicados (ej. usuario/empresa_sede).
 */
class ActionController
{
    public function usuario_sedes($usuarioId)
    {
        SessionManager::requireLogin();

        require_once BASE_PATH . '/app/controllers/UsuarioController.php';
        $usuarioController = new UsuarioController();
        $usuarioController->empresa_sede($usuarioId);
    }
}
