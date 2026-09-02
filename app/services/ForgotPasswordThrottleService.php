<?php

/**
 * Protección de abuso en "olvidé mi contraseña": bloquea temporalmente una
 * IP tras varias solicitudes seguidas, dentro de una ventana deslizante.
 * A diferencia de LoginThrottleService, aquí no hay éxito/fallo que
 * distinguir — login/forgotSend siempre responde el mismo mensaje genérico
 * (anti-enumeración de usuarios), así que el throttle es solo por volumen
 * de solicitudes desde la misma IP, sin importar el identificador usado.
 */
class ForgotPasswordThrottleService
{
    private const MAX_INTENTOS = 5;
    private const VENTANA_MINUTOS = 15;

    private ForgotPasswordIntentoRepository $repo;

    public function __construct()
    {
        $database = new Database();
        $this->repo = new ForgotPasswordIntentoRepository($database->connect());
    }

    /**
     * @return array{blocked: bool, retryAfterMinutes?: int}
     */
    public function checkBlocked(string $ip): array
    {
        $ip = trim($ip);
        if ($ip === '') {
            return ['blocked' => false];
        }

        $recientes = $this->repo->countRecent($ip, self::VENTANA_MINUTOS);
        if ($recientes < self::MAX_INTENTOS) {
            return ['blocked' => false];
        }

        $oldest = $this->repo->oldestInWindow($ip, self::VENTANA_MINUTOS);
        $retryAfterMinutes = self::VENTANA_MINUTOS;
        if ($oldest !== null) {
            $elapsedMinutes = (int)floor((time() - strtotime($oldest)) / 60);
            $retryAfterMinutes = max(1, self::VENTANA_MINUTOS - $elapsedMinutes);
        }

        return ['blocked' => true, 'retryAfterMinutes' => $retryAfterMinutes];
    }

    public function recordAttempt(string $ip, string $identificador): void
    {
        $ip = trim($ip);
        if ($ip === '') {
            return;
        }
        $this->repo->record($ip, trim($identificador));
    }
}
