<?php

/**
 * Política de privacidad — pública, sin sesión (ver core/Router.php: se
 * agrega a las excepciones de login/permiso junto a LoginController). Debe
 * ser visible antes de iniciar sesión (enlazada desde login.php) y también
 * para cualquier tercero/paciente que quiera consultarla sin ser usuario
 * del sistema.
 */
class PrivacidadController
{
    public function index()
    {
        require BASE_PATH . '/app/views/privacidad.php';
    }
}
