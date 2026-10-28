<?php

/**
 * Autoservicio: cambio de la propia contraseña por un usuario ya autenticado.
 * Distinto del flujo de PasswordResetService (olvidó la contraseña, no puede
 * entrar) — este exige conocer la contraseña actual, así una sesión abierta
 * sin vigilancia no basta para tomar la cuenta.
 */
class CuentaPasswordService
{
    private UserRepository $userRepository;
    private UsuarioFormValidationService $passwordValidator;
    private ResendService $resend;

    public function __construct()
    {
        $database = new Database();
        $pdo = $database->connect();

        $this->userRepository = new UserRepository($pdo);
        $this->passwordValidator = new UsuarioFormValidationService($pdo);
        $this->resend = new ResendService();
    }

    /**
     * @return array{success: bool, error?: string}
     */
    public function changeOwnPassword(int $usuarioId, string $currentPassword, string $newPassword, string $newPasswordConfirm): array
    {
        $user = $this->userRepository->findActiveById($usuarioId);
        if (!$user || empty($user['password'])) {
            return ['success' => false, 'error' => 'No se pudo verificar su usuario.'];
        }

        if (!password_verify($currentPassword, (string)$user['password'])) {
            return ['success' => false, 'error' => 'La contraseña actual no es correcta.'];
        }

        if ($newPassword !== $newPasswordConfirm) {
            return ['success' => false, 'error' => 'Las contraseñas nuevas no coinciden.'];
        }

        if ($newPassword === $currentPassword) {
            return ['success' => false, 'error' => 'La nueva contraseña debe ser diferente a la actual.'];
        }

        try {
            $this->passwordValidator->validatePasswordPolicy(['password' => $newPassword], $usuarioId);
        } catch (Exception $e) {
            $decoded = json_decode($e->getMessage(), true);
            $mensaje = is_array($decoded) ? implode(' ', $decoded) : $e->getMessage();

            return ['success' => false, 'error' => $mensaje];
        }

        $this->userRepository->updatePassword($usuarioId, password_hash($newPassword, PASSWORD_DEFAULT));

        $this->sendConfirmationEmail($user);

        return ['success' => true];
    }

    /**
     * @param array<string, mixed> $user
     */
    private function sendConfirmationEmail(array $user): void
    {
        $email = trim((string)($user['email'] ?? ''));
        if ($email === '') {
            return;
        }

        $nombre = (string)($user['nombre'] ?? $user['username']);
        $nombreSeguro = htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8');
        $fecha = (new DateTime())->format('d/m/Y H:i');

        $html = "
            <p>Hola {$nombreSeguro},</p>
            <p>Tu contraseña en SAVID fue cambiada el {$fecha}.</p>
            <p>Si no fuiste tú quien hizo este cambio, contacta de inmediato al administrador del sistema.</p>
        ";

        try {
            $this->resend->send($email, $nombre, 'Tu contraseña fue cambiada - SAVID', $html);
        } catch (Throwable $e) {
            error_log('CuentaPasswordService::sendConfirmationEmail: ' . $e->getMessage());
        }
    }
}
