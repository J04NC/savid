<?php

/**
 * Verificación server-side de Cloudflare Turnstile (antibot del login).
 *
 * La Site Key (pública) va en el HTML del widget; la Secret Key (privada,
 * en config/.env) se usa aquí para confirmar contra Cloudflare que el token
 * que mandó el navegador es real, antes de intentar autenticar. Sin esta
 * verificación server-side, el widget del cliente sería solo decorativo —
 * cualquiera podría mandar el POST directo sin pasar por Turnstile.
 */
class TurnstileService
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /** Si no hay Secret Key configurada, el login sigue funcionando sin antibot. */
    public static function habilitado(): bool
    {
        return trim((string)(getenv('TURNSTILE_SECRET_KEY') ?: '')) !== '';
    }

    public static function siteKey(): string
    {
        return trim((string)(getenv('TURNSTILE_SITE_KEY') ?: ''));
    }

    /**
     * @param string $token Valor de cf-turnstile-response enviado por el widget.
     * @param string $remoteIp IP del visitante (opcional, Cloudflare la usa para su score).
     */
    public static function verify(string $token, string $remoteIp = ''): bool
    {
        $secret = trim((string)(getenv('TURNSTILE_SECRET_KEY') ?: ''));

        if ($secret === '' || trim($token) === '') {
            return false;
        }

        $payload = ['secret' => $secret, 'response' => $token];
        if ($remoteIp !== '') {
            $payload['remoteip'] = $remoteIp;
        }

        $ch = curl_init(self::VERIFY_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            // Cloudflare inalcanzable: se falla ABIERTO (deja seguir) y se
            // registra. Un antibot no puede convertirse en un punto único de
            // fallo que tumbe el acceso a todo el sistema si un servicio de
            // terceros tiene una caída — el costo de eso (nadie entra a
            // SAVID) es mucho mayor que el de perder la protección antibot
            // por el rato que dure la caída. Un token vacío o que Cloudflare
            // rechace explícitamente SÍ se sigue bloqueando (ver abajo).
            error_log('TurnstileService::verify curl error, se permite continuar: ' . $error);

            return true;
        }

        $data = json_decode($body, true);

        return is_array($data) && !empty($data['success']);
    }
}
