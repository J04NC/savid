<?php

/**
 * Resuelve la IP real del cliente detrás de un proxy/túnel (ngrok, etc.).
 *
 * ngrok reescribe X-Forwarded-For con la IP pública real del visitante antes de
 * reenviar la petición a este servidor (verificado: no se puede falsificar pasando
 * por la URL pública de ngrok). Si esta cabecera llega directo al servidor sin pasar
 * por un proxy de confianza (p. ej. alguien conectando directo por la IP de LAN),
 * sí podría venir falsificada — por eso solo se usa si es una IP válida, con
 * REMOTE_ADDR como respaldo.
 */
class RequestIpService
{
    public static function current(): ?string
    {
        $forwarded = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
        if ($forwarded !== '') {
            $first = trim(explode(',', $forwarded)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }

        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;

        return $remoteAddr !== null ? (string)$remoteAddr : null;
    }
}
