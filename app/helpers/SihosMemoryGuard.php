<?php

/**
 * Colchón local de memory_limit para operaciones puntuales pesadas sobre
 * datos SIHOS (reportes con decenas de miles de filas) — solo SUBE el
 * límite, nunca lo baja por debajo del original. Centraliza un patrón que
 * antes estaba duplicado en SihosCruceReconocimientoService y
 * SihosAuditoriaGlosaService.
 *
 * Importante en PHP-FPM (a diferencia de un script CLI de una sola
 * ejecución): un worker atiende muchos requests a lo largo de su vida, así
 * que si se sube el límite hay que restaurarlo — pero solo DESPUÉS de que
 * el array pesado deje de usarse, nunca a mitad de camino. Por eso el
 * llamador debe envolver TODO el trabajo (no solo el decode/fetch) dentro
 * de $trabajo — restaurar antes de tiempo no libera nada (los datos siguen
 * vivos hasta que $trabajo termina) y deja al worker con el límite alto
 * para el resto de su vida si el intento de restaurar falla en silencio
 * por seguir habiendo memoria en uso por encima del límite previo.
 */
class SihosMemoryGuard
{
    public static function ejecutar(int $megabytesMinimos, callable $trabajo): mixed
    {
        $limitePrevio = ini_get('memory_limit');
        $bytesPrevios = self::aBytes($limitePrevio);
        $bytesDeseados = $megabytesMinimos * 1024 * 1024;

        if ($bytesPrevios !== -1 && $bytesPrevios < $bytesDeseados) {
            ini_set('memory_limit', $megabytesMinimos . 'M');
        }

        try {
            return $trabajo();
        } finally {
            if ($bytesPrevios === -1 || memory_get_usage(true) < $bytesPrevios) {
                ini_set('memory_limit', $limitePrevio);
            }
        }
    }

    /** "128M" / "1G" / "-1" → bytes. -1 = sin límite. */
    private static function aBytes(string $valor): int
    {
        $valor = trim($valor);
        if ($valor === '-1') {
            return -1;
        }

        $unidad = strtolower(substr($valor, -1));
        $numero = (int)$valor;

        return match ($unidad) {
            'g' => $numero * 1024 * 1024 * 1024,
            'm' => $numero * 1024 * 1024,
            'k' => $numero * 1024,
            default => (int)$valor,
        };
    }
}
