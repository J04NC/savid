<?php

/**
 * Protección de fuerza bruta en el login: bloquea la combinación usuario+IP
 * tras varios intentos fallidos seguidos, dentro de una ventana deslizante.
 *
 * Además, bloquea por IP sola (sin importar el usuario) con un umbral más alto:
 * el throttle por usuario+IP no detiene credential stuffing (muchas cuentas distintas,
 * 1-2 intentos cada una, misma IP) porque ninguna cuenta individual llega a MAX_INTENTOS.
 * MAX_INTENTOS_IP queda por encima de lo que genera una oficina real compartiendo IP
 * (varios empleados tecleando mal la contraseña la misma mañana) pero por debajo de lo
 * que necesita un script probando credenciales filtradas.
 */
class LoginThrottleService
{
    private const MAX_INTENTOS = 5;
    private const MAX_INTENTOS_IP = 20;
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

    /**
     * @return array{blocked: bool, retryAfterMinutes?: int}
     */
    public function checkIpBlocked(string $ip): array
    {
        $ip = trim($ip);
        if ($ip === '') {
            return ['blocked' => false];
        }

        $fails = $this->repo->countRecentFailuresByIp($ip, self::VENTANA_MINUTOS);
        if ($fails < self::MAX_INTENTOS_IP) {
            return ['blocked' => false];
        }

        $oldest = $this->repo->oldestFailureByIpInWindow($ip, self::VENTANA_MINUTOS);
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

        $ipFails = $this->repo->countRecentFailuresByIp($ip, self::VENTANA_MINUTOS);
        if ($ipFails === self::MAX_INTENTOS_IP) {
            try {
                $usuariosDistintos = $this->repo->countDistinctUsernamesFailedByIp($ip, self::VENTANA_MINUTOS);
                (new SecurityAlertService())->notificarBloqueoCredentialStuffing($ip, $usuariosDistintos);
            } catch (Throwable $e) {
                error_log('SecurityAlertService::notificarBloqueoCredentialStuffing: ' . $e->getMessage());
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
