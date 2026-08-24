<?php

/**
 * Otra petición está ejecutando la misma operación de escritura sobre SIHOS.
 *
 * La lanza SihosExternalWriteRepository cuando no consigue el bloqueo nombrado
 * que serializa cada operación (ver conBloqueo()). No es un error del sistema:
 * significa que la acción ya está en curso — normalmente un doble clic — y que
 * repetirla ahora duplicaría un movimiento contable.
 */
class SihosOperacionEnCursoException extends RuntimeException
{
}
