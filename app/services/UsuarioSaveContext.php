<?php

/**
 * Estado resuelto al preparar un guardado de usuario.
 */
final class UsuarioSaveContext
{
    public string $mode;

    public ?int $usuarioId;

    /** Si se descartaron id o FKs huérfanas del POST. */
    public bool $staleReferencesCleared = false;

    public function __construct(string $mode, ?int $usuarioId, bool $staleReferencesCleared = false)
    {
        $this->mode = $mode;
        $this->usuarioId = $usuarioId;
        $this->staleReferencesCleared = $staleReferencesCleared;
    }

    public function isAlta(): bool
    {
        return $this->mode === UsuarioSaveMode::ALTA;
    }

    public function isEdicion(): bool
    {
        return $this->mode === UsuarioSaveMode::EDICION;
    }

    public function isLinkOnly(): bool
    {
        return $this->mode === UsuarioSaveMode::LINK_ONLY;
    }
}
