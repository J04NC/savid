<?php

/**
 * Limpieza de textos de SIHOS antes de escribirlos en una celda de Excel
 * (OpenSpout): UTF-8 válido, sin caracteres de control (XML no los admite y
 * los saltos de línea rompían los CSV), en una sola línea y dentro del límite
 * de 32.767 caracteres por celda.
 */
class SihosExcelTexto
{
    private const MAX_CELDA = 32767;

    public static function limpiar(?string $s): string
    {
        $s = (string)$s;
        if (!mb_check_encoding($s, 'UTF-8')) {
            $s = mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
        }
        $s = trim(preg_replace('/[\x00-\x20\x7F]+/u', ' ', $s) ?? '');

        return self::recortar($s);
    }

    /** Recorta al límite de una celda sin tocar los saltos de línea puestos a propósito. */
    public static function recortar(string $s): string
    {
        return mb_strlen($s) > self::MAX_CELDA ? mb_substr($s, 0, self::MAX_CELDA) : $s;
    }
}
