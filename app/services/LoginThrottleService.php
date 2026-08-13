<?php

/**
 * Protección de fuerza bruta en el login: bloquea la combinación usuario+IP
 * tras varios intentos fallidos seguidos, dentro de una ventana deslizante.
 */
class LoginThrottleService
{
    private const MAX_INTENTOS = 5;
    private const VENTANA_MINUTOS = 15;

    private LoginIntentoRepository $repo;

    public function __construct()
    {
        $database = new Database();
        $this->repo = new LoginIntentoRepository($database->connect());
    }

    /**
     * @return array{blocked: bool, retryAfterMinutes?: int}
     */
    public function checkBlocked(string $username, string $ip): array
    {
        $username = trim($username);
        $ip = trim($ip);
        if ($username === '' || $ip === '') {
            return ['blocked' => false];
        }

        $fails = $this->repo->countRecentFailuresSinceLastSuccess($username, $ip, self::VENTANA_MINUTOS);
        if ($fails < self::MAX_INTENTOS) {
            return ['blocked' => false];
        }

        $oldest = $this->repo->oldestFailureInCurrentStreak($username, $ip, self::VENTANA_MINUTOS);
        $retryAfterMinutes = self::VENTANA_MINUTOS;
        if ($oldest !== null) {
            $elapsedMinutes = (int)floor((time() - strtotime($oldest)) / 60);
            $retryAfterMinutes = max(1, self::VENTANA_MINUTOS - $elapsedMinutes);
        }

        return ['blocked' => true, 'retryAfterMinutes' => $retryAfterMinutes];
    }

    public function recordFailure(string $username, string $ip): void
    {
        $username = trim($username);
        $ip = trim($ip);
        if ($username === '' || $ip === '') {
            return;
        }
        $this->repo->record($username, $ip, false);

        // Notificar solo en el momento exacto en que se cruza el umbral (no en cada
        // intento posterior mientras ya está bloqueado: checkBlocked() corta el flujo
        // de autenticación antes de llegar aquí una vez bloqueado).
        $fails = $this->repo->countRecentFailuresSinceLastSuccess($username, $ip, self::VENTANA_MINUTOS);
        if ($fails === self::MAX_INTENTOS) {
            try {
                (new SecurityAlertService())->notificarBloqueoFuerzaBruta($username, $ip);
            } catch (Throwable $e) {
                error_log('SecurityAlertService::notificarBloqueoFuerzaBruta: ' . $e->getMessage());
            }
        }
    }

    public function recordSuccess(string $username, string $ip): void
    {
        $username = trim($username);
        $ip = trim($ip);
        if ($username === '' || $ip === '') {
            return;
        }
        $this->repo->record($username, $ip, true);
    }
}
