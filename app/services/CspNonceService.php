<?php

/**
 * Nonce de Content-Security-Policy: uno por request (los properties
 * estáticos se reinician en cada petición PHP-FPM, así que no hay riesgo de
 * reutilizarlo entre peticiones distintas). Las vistas lo leen con
 * CspNonceService::value() para marcar cada <script> inline como permitido
 * por la CSP sin necesitar 'unsafe-inline' en script-src.
 */
class CspNonceService
{
    private static ?string $nonce = null;

    public static function value(): string
    {
        if (self::$nonce === null) {
            self::$nonce = bin2hex(random_bytes(16));
        }

        return self::$nonce;
    }

    /**
     * Cabecera completa para esta respuesta. object-src/base-uri/form-action
     * acotados porque ninguna vista de SAVID los necesita abiertos (ver
     * README.md, sección CSP, para el detalle de cada dominio permitido).
     */
    public static function header(): string
    {
        $nonce = self::value();

        $directivas = [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}' https://cdn.jsdelivr.net https://challenges.cloudflare.com",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data:",
            "font-src 'self'",
            "connect-src 'self'",
            "frame-src https://challenges.cloudflare.com",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
        ];

        return 'Content-Security-Policy: ' . implode('; ', $directivas);
    }
}
