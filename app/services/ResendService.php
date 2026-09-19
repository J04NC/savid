<?php

/**
 * Envío de correo transaccional vía Resend (API HTTP) para el código de
 * acceso de login/2FA. Reemplaza a MailerService (SMTP) SOLO para ese
 * flujo — MailerService se mantiene para los demás usos (recuperación de
 * contraseña en PasswordResetService, alertas en SecurityAlertService,
 * el chequeo de salud en SistemaController).
 *
 * Variable de entorno soportada (config/.env, igual que Database):
 * RESEND_API_KEY
 */
class ResendService
{
    private const FROM = 'SAVID <codigos@notificaciones.savid.com.co>';

    private ?\Resend\Client $client;

    public function __construct()
    {
        Database::bootstrapEnv();

        $apiKey = trim((string)(getenv('RESEND_API_KEY') ?: ''));
        $this->client = $apiKey !== '' ? \Resend::client($apiKey) : null;
    }

    /**
     * Envía el código de acceso (2FA) al correo del usuario. Nunca lanza:
     * si falla (o si RESEND_API_KEY no está configurada), queda registrado
     * vía error_log y el llamador decide qué hacer — no debe tumbar el
     * flujo de login por un fallo de envío.
     */
    public function sendAccessCode(string $email, string $codigo, string $nombreUsuario): bool
    {
        if ($this->client === null) {
            error_log('ResendService: RESEND_API_KEY no configurada; código de acceso no enviado a ' . $email);

            return false;
        }

        $nombreSeguro = htmlspecialchars($nombreUsuario, ENT_QUOTES, 'UTF-8');
        $codigoSeguro = htmlspecialchars($codigo, ENT_QUOTES, 'UTF-8');
        $html = "
            <p>Hola {$nombreSeguro},</p>
            <p>Tu código de verificación para iniciar sesión en SAVID es:</p>
            <p style=\"font-size:28px;font-weight:bold;letter-spacing:4px;\">{$codigoSeguro}</p>
            <p>Vence en pocos minutos y solo puede usarse una vez.</p>
            <p>Si no intentaste iniciar sesión, ignora este correo y considera cambiar tu contraseña.</p>
        ";

        try {
            $this->client->emails->send([
                'from' => self::FROM,
                'to' => [$email],
                'subject' => 'Código de verificación - SAVID',
                'html' => $html,
            ]);

            error_log('ResendService: código de acceso enviado a ' . $email);

            return true;
        } catch (\Throwable $e) {
            error_log('ResendService: fallo al enviar código de acceso a ' . $email . ': ' . $e->getMessage());

            return false;
        }
    }
}
