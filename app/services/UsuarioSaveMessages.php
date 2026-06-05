<?php

/**
 * Catálogo de mensajes del guardado usuario (spec §7).
 */
final class UsuarioSaveMessages
{
    public const PERSONA_NOT_FOUND = 'No se pudo asociar la persona. Pulse Limpiar, verifique tipo y número de documento, y guarde de nuevo.';

    public const PERSONA_INSERT_FAILED = 'No se pudo registrar la persona en el sistema. Intente guardar de nuevo.';

    public const PERSONA_STALE_CLEARED = 'El formulario tenía datos de una sesión anterior; se limpiaron las referencias internas. Complete y guarde.';

    public static function linkOnlyNotice(): string
    {
        return 'El usuario ya existía en el sistema; solo se actualizó el vínculo con su empresa/sede (no se modificaron sus datos).';
    }

    public static function usernameLinkNotice(): string
    {
        return 'Ya existía una cuenta con ese usuario para la misma identificación; se vinculó a su empresa/sede (no se creó un usuario nuevo).';
    }

    /**
     * @param array<string, string> $errors
     */
    public static function throwJson(array $errors): void
    {
        throw new Exception(json_encode($errors, JSON_UNESCAPED_UNICODE));
    }
}
