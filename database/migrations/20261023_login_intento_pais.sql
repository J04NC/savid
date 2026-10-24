/* ============================================================
   login_intento: agrega columna `pais` (código ISO de 2 letras, ej. CO, US).

   Se llena a partir del header CF-IPCountry que Cloudflare agrega
   automáticamente a cada petición que pasa por su proxy (confirmado:
   app.savid.com.co y subdominios están "proxied" en la zona de Cloudflare).
   Queda NULL para tráfico que no pasa por Cloudflare (ej. ngrok).
============================================================ */

ALTER TABLE login_intento
    ADD COLUMN pais VARCHAR(2) COLLATE utf8mb4_unicode_ci NULL
        COMMENT 'label:País|order:35|show:table'
        AFTER ip;
