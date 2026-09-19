<?php

/**
 * Envío de correo transaccional vía Resend (API HTTP): reemplaza a
 * MailerService (SMTP/PHPMailer) para TODO el correo saliente de la app —
 * código de acceso/2FA, recuperación de contraseña, alertas de seguridad y
 * el correo de prueba de ?url=sistema/correoProbar. MailerService::baseUrl()
 * (URL base para enlaces en los correos) se sigue usando tal cual, no
 * depende del mecanismo de envío.
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

    public static function isConfigured(): bool
    {
        Database::bootstrapEnv();

        return trim((string)(getenv('RESEND_API_KEY') ?: '')) !== '';
    }

    /**
     * Envía el código de acceso (2FA) al correo del usuario.
     */
    public function sendAccessCode(string $email, string $codigo, string $nombreUsuario): bool
    {
        $nombreSeguro = htmlspecialchars($nombreUsuario, ENT_QUOTES, 'UTF-8');
        $codigoSeguro = htmlspecialchars($codigo, ENT_QUOTES, 'UTF-8');
        $html = "
            <p>Hola {$nombreSeguro},</p>
            <p>Tu código de verificación para iniciar sesión en SAVID es:</p>
            <p style=\"font-size:28px;font-weight:bold;letter-spacing:4px;\">{$codigoSeguro}</p>
            <p>Vence en pocos minutos y solo puede usarse una vez.</p>
            <p>Si no intentaste iniciar sesión, ignora este correo y considera cambiar tu contraseña.</p>
        ";

        return $this->send($email, $nombreUsuario, 'Código de verificación - SAVID', $html);
    }

    /**
     * Envío genérico — mismo rol que tenía MailerService::send() (misma
     * firma, incluido el $error por referencia que usa SistemaController)
     * para los demás casos: recuperación de contraseña, alertas de
     * seguridad, correo de prueba.
     *
     * Nunca lanza: si falla (o si RESEND_API_KEY no está configurada),
     * queda registrado vía error_log y el llamador decide qué hacer — no
     * debe tumbar el flujo (login, request de reset, etc.) por un fallo
     * de envío.
     */
    public function send(string $toEmail, string $toName, string $subject, string $htmlBody, ?string &$error = null): bool
    {
        if ($this->client === null) {
            $error = 'RESEND_API_KEY no configurada';
            error_log('ResendService: RESEND_API_KEY no configurada; correo no enviado a ' . $toEmail);

            return false;
        }

        $destinatario = $toName !== '' ? "{$toName} <{$toEmail}>" : $toEmail;

        try {
            $this->client->emails->send([
                'from' => self::FROM,
                'to' => [$destinatario],
                'subject' => $subject,
                'html' => $htmlBody,
            ]);

            error_log('ResendService: correo enviado a ' . $toEmail . ' — asunto: ' . $subject);

            return true;
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            error_log('ResendService: fallo al enviar correo a ' . $toEmail . ' (' . $subject . '): ' . $e->getMessage());

            return false;
        }
    }
}
