<?php

/**
 * Resuelve la IP real del cliente detrás de un proxy/túnel (ngrok, etc.).
 *
 * ngrok reescribe X-Forwarded-For con la IP pública real del visitante antes de
 * reenviar la petición a este servidor. Esa cabecera solo es confiable si la
 * conexión realmente pasó por el proxy de confianza — si alguien conecta directo
 * (p. ej. por la IP de LAN del servidor) puede enviar cualquier valor falso.
 * Por eso solo se acepta X-Forwarded-For cuando REMOTE_ADDR coincide con la lista
 * de proxies de confianza (por defecto, loopback — configurable vía
 * TRUSTED_PROXY_IPS en el .env, coma-separado, para topologías distintas).
 *
 * Requisito de infraestructura: el proxy/túnel debe conectar al backend por
 * loopback (127.0.0.1), no por la IP de LAN — si conecta por LAN, REMOTE_ADDR es
 * indistinguible entre tráfico real del túnel y tráfico directo por LAN, y este
 * allowlist no puede proteger nada (ver ngrok.service: apuntar a 127.0.0.1:80).
 */
class RequestIpService
{
    private const DEFAULT_TRUSTED_PROXIES = ['127.0.0.1', '::1'];

    public static function current(): ?string
    {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;

        if (self::remoteAddrIsTrustedProxy($remoteAddr)) {
            $forwarded = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
            if ($forwarded !== '') {
                $first = trim(explode(',', $forwarded)[0]);
                if (filter_var($first, FILTER_VALIDATE_IP)) {
                    return $first;
                }
            }
        }

        return $remoteAddr !== null ? (string)$remoteAddr : null;
    }

    /**
     * Código de país ISO 3166-1 alpha-2 (ej. CO, US) que Cloudflare agrega
     * automáticamente en CF-IPCountry a cada petición que pasa por su proxy
     * (confirmado: app.savid.com.co y subdominios están "proxied" en la zona).
     * Null si la petición no vino por Cloudflare (ej. túnel de ngrok) o el
     * valor no es un código de país reconocible.
     */
    public static function currentCountry(): ?string
    {
        $raw = strtoupper(trim((string)($_SERVER['HTTP_CF_IPCOUNTRY'] ?? '')));

        // "XX" = Cloudflare no pudo determinar el país; "T1" = salida de Tor.
        if (!preg_match('/^[A-Z]{2}$/', $raw) || $raw === 'XX') {
            return null;
        }

        return $raw;
    }

    private static function remoteAddrIsTrustedProxy(?string $remoteAddr): bool
    {
        if ($remoteAddr === null || $remoteAddr === '') {
            return false;
        }

        $configured = trim((string)(getenv('TRUSTED_PROXY_IPS') ?: ''));
        $list = $configured !== ''
            ? array_map('trim', explode(',', $configured))
            : self::DEFAULT_TRUSTED_PROXIES;

        return in_array($remoteAddr, $list, true);
    }
}
