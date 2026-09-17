<?php

/**
 * Aviso legal — pública, sin sesión (ver core/Router.php: se agrega a las
 * excepciones de login/permiso junto a LoginController y PrivacidadController).
 * Debe ser visible antes de iniciar sesión (enlazada desde login.php).
 */
class AvisolegalController
{
    public function index()
    {
        require BASE_PATH . '/app/views/avisolegal.php';
    }
}
