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

            session_start([
                'cookie_lifetime' => $cookieLifetime,
                'cookie_httponly' => true,
                'cookie_secure' => $secure,
                'use_strict_mode' => true
            ]);
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