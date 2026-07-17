<?php

class PasswordResetService
{
    private const TOKEN_TTL_MINUTES = 30;

    private PDO $pdo;
    private UserRepository $userRepository;
    private PasswordResetRepository $resetRepository;
    private MailerService $mailer;
    private UsuarioFormValidationService $passwordValidator;

    public function __construct()
    {
        $database = new Database();
        $this->pdo = $database->connect();

        $this->userRepository = new UserRepository($this->pdo);
        $this->resetRepository = new PasswordResetRepository($this->pdo);
        $this->mailer = new MailerService();
        $this->passwordValidator = new UsuarioFormValidationService($this->pdo);
    }

    /**
     * Busca el usuario por username o correo y, si existe, le envía un enlace de recuperación.
     * Nunca informa al llamador si el usuario existía o no (anti-enumeración): el controller
     * debe mostrar siempre el mismo mensaje genérico independientemente del valor devuelto.
     */
    public function requestReset(string $identificador, ?string $ip): void
    {
        $identificador = trim($identificador);
        if ($identificador === '') {
            return;
        }

        $user = $this->userRepository->findActiveByUsernameOrEmail($identificador);
        if (!$user || empty($user['email'])) {
            return;
        }

        $usuarioId = (int)$user['id'];
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expiresAt = (new DateTime('+' . self::TOKEN_TTL_MINUTES . ' minutes'))->format('Y-m-d H:i:s.v');

        $this->resetRepository->invalidateAllForUser($usuarioId);
        $this->resetRepository->create($usuarioId, $tokenHash, $expiresAt, $ip);

        $link = MailerService::baseUrl() . '/?url=login/resetPassword&token=' . $token;
        $nombre = htmlspecialchars((string)($user['nombre'] ?? $user['username']), ENT_QUOTES, 'UTF-8');

        $html = "
            <p>Hola {$nombre},</p>
            <p>Recibimos una solicitud para restablecer tu contraseña en SAVID.</p>
            <p><a href=\"{$link}\">Haz clic aquí para crear una nueva contraseña</a></p>
            <p>Este enlace vence en " . self::TOKEN_TTL_MINUTES . " minutos y solo puede usarse una vez.</p>
            <p>Si no solicitaste este cambio, puedes ignorar este correo.</p>
        ";

        $this->mailer->send((string)$user['email'], (string)($user['nombre'] ?? $user['username']), 'Recuperar contraseña - SAVID', $html);
    }

    /**
     * @return array{valid: bool, error?: string}
     */
    public function validateToken(string $token): array
    {
        if ($token === '') {
            return ['valid' => false, 'error' => 'Enlace inválido.'];
        }

        $row = $this->resetRepository->findValidByTokenHash(hash('sha256', $token));
        if (!$row) {
            return ['valid' => false, 'error' => 'El enlace es inválido o ya venció. Solicita uno nuevo.'];
        }

        return ['valid' => true];
    }

    /**
     * @return array{success: bool, error?: string}
     */
    public function resetPassword(string $token, string $newPassword, string $newPasswordConfirm): array
    {
        $row = $this->resetRepository->findValidByTokenHash(hash('sha256', $token));
        if (!$row) {
            return ['success' => false, 'error' => 'El enlace es inválido o ya venció. Solicita uno nuevo.'];
        }

        if ($newPassword !== $newPasswordConfirm) {
            return ['success' => false, 'error' => 'Las contraseñas no coinciden.'];
        }

        try {
            $this->passwordValidator->validatePasswordPolicy(['password' => $newPassword], (int)$row['usuario_id']);
        } catch (Exception $e) {
            $decoded = json_decode($e->getMessage(), true);
            $mensaje = is_array($decoded) ? implode(' ', $decoded) : $e->getMessage();

            return ['success' => false, 'error' => $mensaje];
        }

        $this->userRepository->updatePassword((int)$row['usuario_id'], password_hash($newPassword, PASSWORD_DEFAULT));
        $this->resetRepository->markUsed((int)$row['id']);
        $this->resetRepository->invalidateAllForUser((int)$row['usuario_id']);

        return ['success' => true];
    }
}
