<?php

/**
 * Token CSRF de sesión (patrón "synchronizer token"): un solo token por sesión,
 * expuesto a formularios nativos vía hidden input y a JS vía window.CSRF_TOKEN
 * (public/js/csrf.js lo inyecta automáticamente en fetch/XHR same-origin).
 * Verificado centralmente en Router::middleware() para toda request POST.
 */
class CsrfService
{
    private const SESSION_KEY = 'csrf_token';
    private const HEADER_NAME = 'HTTP_X_CSRF_TOKEN';

    public static function token(): string
    {
        if (empty($_SESSION[self::SESSION_KEY]) || !is_string($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    public static function verify(?string $token): bool
    {
        $expected = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_string($expected) || $expected === '' || !is_string($token) || $token === '') {
            return false;
        }

        return hash_equals($expected, $token);
    }

    /**
     * Lee el token de $_POST['csrf_token'] (forms nativos) o del header
     * X-CSRF-Token (fetch/XHR) y lo valida contra el de la sesión.
     */
    public static function verifyRequest(): bool
    {
        $token = $_POST['csrf_token'] ?? ($_SERVER[self::HEADER_NAME] ?? null);

        return self::verify(is_string($token) ? $token : null);
    }
}
