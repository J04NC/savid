<?php

/**
 * Limpieza de textos de SIHOS antes de escribirlos en una celda de Excel
 * (OpenSpout) o en pantalla: UTF-8 válido, sin doble codificación, sin
 * caracteres de control (XML no los admite y los saltos de línea rompían los
 * CSV), en una sola línea y dentro del límite de 32.767 caracteres por celda.
 */
class SihosExcelTexto
{
    private const MAX_CELDA = 32767;

    public static function limpiar(?string $s): string
    {
        $s = self::reparar((string)$s);
        $s = trim(preg_replace('/[\x00-\x20\x7F]+/u', ' ', $s) ?? '');

        return self::recortar($s);
    }

    /**
     * Repara textos de SIHOS guardados con doble codificación (UTF-8 leído como
     * Windows-1252: "REPRESENTACIÃ“N" en vez de "REPRESENTACIÓN"). Solo
     * actúa si aparece el patrón típico (Ã o Â seguido de un carácter de ese
     * rango) y si la reconversión da UTF-8 válido; si no, deja el texto igual.
     */
    public static function reparar(string $s): string
    {
        if (!mb_check_encoding($s, 'UTF-8')) {
            return mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
        }
        if (preg_match('/[ÃÂ][\x{0080}-\x{00BF}\x{0152}\x{0153}\x{0160}\x{0161}\x{0178}\x{017D}\x{017E}\x{0192}\x{02C6}\x{02DC}\x{2013}-\x{203A}\x{20AC}\x{2122}]/u', $s) !== 1) {
            return $s;
        }
        $bytes = mb_convert_encoding($s, 'Windows-1252', 'UTF-8');

        return mb_check_encoding($bytes, 'UTF-8') ? $bytes : $s;
    }

    /** Recorta al límite de una celda sin tocar los saltos de línea puestos a propósito. */
    public static function recortar(string $s): string
    {
        return mb_strlen($s) > self::MAX_CELDA ? mb_substr($s, 0, self::MAX_CELDA) : $s;
    }

    /** Recorte de SIHOS (Recorte($texto, $n, 1)): primeros $n caracteres + "..." si es más largo. */
    public static function recorteSihos(string $s, int $n): string
    {
        return mb_strlen($s) > $n ? mb_substr($s, 0, $n) . '...' : $s;
    }
}
