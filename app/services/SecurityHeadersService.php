<?php

/**
 * Cabeceras de seguridad que no dependen de un valor por request (a
 * diferencia de CspNonceService, que sí necesita el nonce fresco en cada
 * petición). Separadas en su propia clase porque su ciclo de vida es
 * distinto: estas son siempre el mismo valor fijo.
 */
class SecurityHeadersService
{
    public static function apply(): void
    {
        if (headers_sent()) {
            return;
        }

        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: ' . self::permissionsPolicy());

        if (self::isHttps()) {
            self::applyHsts();
        }
    }

    /**
     * camera/microphone en (self): captura de foto vía getUserMedia en
     * crud.js (foto de tercero/usuario) y grabación de audio en el módulo
     * Acad (ejercicios de pronunciación). El resto no tiene uso conocido en
     * SAVID, así que se bloquea explícitamente en vez de dejarlo abierto
     * por omisión.
     */
    private static function permissionsPolicy(): string
    {
        $directivas = [
            'camera=(self)',
            'microphone=(self)',
            'geolocation=()',
            'payment=()',
            'usb=()',
            'magnetometer=()',
            'gyroscope=()',
            'accelerometer=()',
        ];

        return implode(', ', $directivas);
    }

    private static function isHttps(): bool
    {
        return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    }

    /**
     * Sin includeSubDomains ni preload: hay subdominios por empresa (ver
     * SessionManager, SESSION_COOKIE_DOMAIN) cuyo soporte HTTPS no está
     * garantizado todavía — forzarlo ahí podría dejarlos inaccesibles en vez
     * de solo advertir. 180 días: suficiente para el efecto de HSTS sin
     * quedar atado a una decisión difícil de revertir rápido si algo falla.
     */
    private static function applyHsts(): void
    {
        header('Strict-Transport-Security: max-age=15552000');
    }
}
