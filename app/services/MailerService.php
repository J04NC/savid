<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Envío de correo saliente vía SMTP (PHPMailer).
 *
 * Variables de entorno soportadas (config/.env, igual que Database):
 * SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASSWORD, SMTP_SECURE (tls|ssl|""),
 * SMTP_FROM_EMAIL, SMTP_FROM_NAME, APP_URL
 */
class MailerService
{
    public function send(string $toEmail, string $toName, string $subject, string $htmlBody): bool
    {
        Database::bootstrapEnv();

        $host = getenv('SMTP_HOST') ?: '';
        if ($host === '') {
            error_log('MailerService: SMTP_HOST no configurado; correo no enviado a ' . $toEmail);

            return false;
        }

        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host = $host;
            $mail->Port = (int)(getenv('SMTP_PORT') ?: 587);
            $mail->SMTPAuth = true;
            $mail->Username = getenv('SMTP_USER') ?: '';
            $mail->Password = getenv('SMTP_PASSWORD') ?: '';

            $secure = strtolower((string)(getenv('SMTP_SECURE') ?: 'tls'));
            if ($secure === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($secure === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPSecure = false;
                $mail->SMTPAutoTLS = false;
            }

            $fromEmail = getenv('SMTP_FROM_EMAIL') ?: $mail->Username;
            $fromName = getenv('SMTP_FROM_NAME') ?: 'SAVID';
            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($toEmail, $toName);

            $mail->CharSet = 'UTF-8';
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            $mail->AltBody = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody)));

            $mail->send();

            return true;
        } catch (PHPMailerException $e) {
            error_log('MailerService: fallo al enviar correo a ' . $toEmail . ': ' . $mail->ErrorInfo);

            return false;
        }
    }

    public static function baseUrl(): string
    {
        Database::bootstrapEnv();

        $configured = getenv('APP_URL');
        if ($configured !== false && trim((string)$configured) !== '') {
            return rtrim(trim($configured), '/');
        }

        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

        return $scheme . '://' . $host;
    }
}
