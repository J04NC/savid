<?php
/**
 * Gestor global de sesiones - SOLUCIONA EL ERROR
 */
class SessionManager {
    private static $started = false;
    
    public static function start() {
        if (session_status() === PHP_SESSION_NONE) {
            require_once dirname(__FILE__) . '/SessionConfigurator.php';
            SessionConfigurator::apply();

            $cookieLifetime = max(60, (int)(getenv('SESSION_LIFETIME') ?: 86400));
            $secure = in_array(strtolower((string)(getenv('SESSION_COOKIE_SECURE'))), ['1', 'true', 'yes'], true);

            $options = [
                'cookie_lifetime' => $cookieLifetime,
                'cookie_httponly' => true,
                'cookie_secure' => $secure,
                'use_strict_mode' => true
            ];

            // Vacío por defecto (cookie atada al host exacto, comportamiento
            // de siempre) — solo se fija en producción para que la sesión
            // sobreviva al saltar entre subdominios de cliente (ver plan de
            // acceso exclusivo por empresa). Con un dominio como
            // ".savid.com.co" en localhost/desarrollo el navegador rechaza
            // la cookie entera, por eso queda detrás de una env var.
            // Solo se aplica si el host actual cuelga de ese dominio; por IP
            // (192.168.x.x) el navegador descartaría la cookie y el login fallaría.
            $cookieDomain = trim((string)(getenv('SESSION_COOKIE_DOMAIN') ?: ''));
            $host = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
            $baseDomain = strtolower(ltrim($cookieDomain, '.'));
            if ($baseDomain !== '' && ($host === $baseDomain || str_ends_with($host, '.' . $baseDomain))) {
                $options['cookie_domain'] = $cookieDomain;
            }

            session_start($options);
            self::$started = true;
        }
    }
    
    public static function isActive() {
        return self::$started || session_status() === PHP_SESSION_ACTIVE;
    }
    
    public static function destroy() {
        if (self::isActive()) {
            $_SESSION = [];
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000,
                    $params["path"], $params["domain"],
                    $params["secure"], $params["httponly"]
                );
            }
            session_destroy();
            self::$started = false;
        }
    }
    
    public static function regenerate() {
        if (self::isActive()) {
            session_regenerate_id(true);
        }
    }
    
    public static function userLogged() {
        return isset($_SESSION['user_id']);
    }
    
    public static function requireLogin() {
        if (!self::userLogged()) {
            header("Location: ?url=login");
            exit;
        }
    }
}