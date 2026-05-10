<?php

/**
 * Contexto operativo: empresa + sede activas (usuarios no Super Admin).
 */
class ContextGateService
{
    public static function hasOperationalContext(): bool
    {
        if (!empty($_SESSION['es_super_admin'])) {
            return true;
        }

        $empresaId = $_SESSION['empresa_id'] ?? null;
        $sedeId = $_SESSION['sede_id'] ?? null;

        return $empresaId !== null && $empresaId !== ''
            && $sedeId !== null && $sedeId !== '';
    }

    /**
     * Si retorna true, el Router debe enviar a la selección de contexto.
     */
    public static function mustRedirectToContextSelection(string $controllerName, string $method): bool
    {
        if (!SessionManager::userLogged()) {
            return false;
        }

        if (self::hasOperationalContext()) {
            return false;
        }

        $allowed = [
            ['LoginController', 'index'],
            ['LoginController', 'authenticate'],
            ['LoginController', 'logout'],
            ['ContextController', 'cambiarSede'],
        ];

        foreach ($allowed as [$c, $m]) {
            if ($controllerName === $c && $method === $m) {
                return false;
            }
        }

        return true;
    }
}
