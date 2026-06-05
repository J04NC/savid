<?php

/**
 * Modos de guardado del CRUD usuario (spec VALIDACIONES_CRUD_USUARIO §2).
 */
final class UsuarioSaveMode
{
    /** Alta nueva: crear persona y cuenta. */
    public const ALTA = 'alta';

    /** Edición: usuario existente gestionable en sesión. */
    public const EDICION = 'edicion';

    /** Solo vínculo empresa/sede; no modifica datos del usuario. */
    public const LINK_ONLY = 'link_only';
}
