<?php

class TwoFactorService
{
    private const CODE_TTL_MINUTES = 10;
    private const CODE_RESEND_COOLDOWN_SECONDS = 45;
    private const MAX_ATTEMPTS = 5;
    private const DEVICE_TTL_DAYS = 30;

    public const DEVICE_COOKIE_NAME = 'savid_2fa_device';

    private PDO $pdo;
    private TwoFactorRepository $repository;
    private MailerService $mailer;

    public function __construct()
    {
        $database = new Database();
        $this->pdo = $database->connect();

        $this->repository = new TwoFactorRepository($this->pdo);
        $this->mailer = new MailerService();
    }

    public function isEnabledForUser(array $user): bool
    {
        return !empty($user['two_factor_enabled']);
    }

    /**
     * Revisa la cookie "recordar dispositivo" del navegador actual contra el usuario dado.
     */
    public function hasTrustedDeviceCookie(int $usuarioId): bool
    {
        $token = (string)($_COOKIE[self::DEVICE_COOKIE_NAME] ?? '');
        if ($token === '') {
            return false;
        }

        $row = $this->repository->findValidTrustedDevice($usuarioId, hash('sha256', $token));

        return $row !== null;
    }

    /**
     * Genera y envía un código nuevo. Respeta un tiempo mínimo entre reenvíos.
     *
     * @return array{success: bool, error?: string, wait_seconds?: int}
     */
    public function sendCode(int $usuarioId, string $email, string $nombre, ?string $ip): array
    {
        $latest = $this->repository->findLatestValidCodeForUser($usuarioId);
        if ($latest) {
            $elapsed = time() - strtotime((string)$latest['created_at']);
            if ($elapsed < self::CODE_RESEND_COOLDOWN_SECONDS) {
                return [
                    'success' => false,
                    'error' => 'Espera unos segundos antes de solicitar otro código.',
                    'wait_seconds' => self::CODE_RESEND_COOLDOWN_SECONDS - $elapsed,
                ];
            }
        }

        if ($email === '') {
            return ['success' => false, 'error' => 'Su cuenta no tiene un correo asociado para recibir el código.'];
        }

        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $codeHash = hash('sha256', $code);
        $expiresAt = (new DateTime('+' . self::CODE_TTL_MINUTES . ' minutes'))->format('Y-m-d H:i:s.v');

        $this->repository->invalidateAllCodesForUser($usuarioId);
        $this->repository->createCode($usuarioId, $codeHash, $expiresAt, $ip);

        $nombreSeguro = htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8');
        $html = "
            <p>Hola {$nombreSeguro},</p>
            <p>Tu código de verificación para iniciar sesión en SAVID es:</p>
            <p style=\"font-size:28px;font-weight:bold;letter-spacing:4px;\">{$code}</p>
            <p>Vence en " . self::CODE_TTL_MINUTES . " minutos y solo puede usarse una vez.</p>
            <p>Si no intentaste iniciar sesión, ignora este correo y considera cambiar tu contraseña.</p>
        ";

        $this->mailer->send($email, $nombre, 'Código de verificación - SAVID', $html);

        return ['success' => true];
    }

    /**
     * @return array{success: bool, error?: string}
     */
    public function verifyCode(int $usuarioId, string $code): array
    {
        $row = $this->repository->findLatestValidCodeForUser($usuarioId);
        if (!$row) {
            return ['success' => false, 'error' => 'El código venció. Solicita uno nuevo.'];
        }

        if ((int)$row['intentos'] >= self::MAX_ATTEMPTS) {
            $this->repository->markCodeUsed((int)$row['id']);

            return ['success' => false, 'error' => 'Demasiados intentos fallidos. Solicita un código nuevo.'];
        }

        if (!hash_equals($row['code_hash'], hash('sha256', trim($code)))) {
            $this->repository->incrementAttempts((int)$row['id']);

            return ['success' => false, 'error' => 'Código incorrecto.'];
        }

        $this->repository->markCodeUsed((int)$row['id']);

        return ['success' => true];
    }

    /**
     * Crea un dispositivo confiable y devuelve el token en claro para setear la cookie.
     */
    public function registerTrustedDevice(int $usuarioId, ?string $ip, ?string $userAgent): string
    {
        $token = bin2hex(random_bytes(32));
        $expiresAt = (new DateTime('+' . self::DEVICE_TTL_DAYS . ' days'))->format('Y-m-d H:i:s.v');

        $this->repository->createTrustedDevice($usuarioId, hash('sha256', $token), $expiresAt, $ip, $userAgent);

        return $token;
    }

    public static function deviceCookieTtlSeconds(): int
    {
        return self::DEVICE_TTL_DAYS * 86400;
    }

    public function revokeAllTrustedDevices(int $usuarioId): void
    {
        $this->repository->revokeAllTrustedDevicesForUser($usuarioId);
    }
}
