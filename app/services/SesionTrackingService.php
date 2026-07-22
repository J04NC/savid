<?php

/**
 * Registro de inicios/cierres de sesión en usuario_sesion.
 */
class SesionTrackingService
{
    private UsuarioSesionRepository $repo;

    public function __construct()
    {
        $database = new Database();
        $this->repo = new UsuarioSesionRepository($database->connect());
    }

    public function openSessionForCurrentUser(): void
    {
        if (!isset($_SESSION['user_id'])) {
            return;
        }

        $phpSid = session_id();
        if ($phpSid !== '') {
            $this->repo->closeOpenSessionsByPhpSessionId($phpSid, 'relogin');
        }

        $empresaId = isset($_SESSION['empresa_id']) ? (int)$_SESSION['empresa_id'] : null;
        $sedeId = isset($_SESSION['sede_id']) ? (int)$_SESSION['sede_id'] : null;

        $newId = $this->repo->insertOpenSession(
            (int)$_SESSION['user_id'],
            $phpSid !== '' ? $phpSid : null,
            $empresaId > 0 ? $empresaId : null,
            $sedeId > 0 ? $sedeId : null,
            RequestIpService::current(),
            isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 255) : null
        );

        $_SESSION['usuario_sesion_id'] = $newId;
    }

    public function touchCurrentSession(): void
    {
        $sesionId = (int)($_SESSION['usuario_sesion_id'] ?? 0);
        if ($sesionId <= 0) {
            return;
        }

        $empresaId = isset($_SESSION['empresa_id']) ? (int)$_SESSION['empresa_id'] : null;
        $sedeId = isset($_SESSION['sede_id']) ? (int)$_SESSION['sede_id'] : null;

        $this->repo->touchSession(
            $sesionId,
            $empresaId > 0 ? $empresaId : null,
            $sedeId > 0 ? $sedeId : null
        );
    }

    /**
     * true si un administrador cerró esta sesión desde el reporte de sesiones
     * (la sesión PHP del usuario sigue viva; se detecta en el siguiente ping()).
     */
    public function wasCurrentSessionClosedByAdmin(): bool
    {
        $sesionId = (int)($_SESSION['usuario_sesion_id'] ?? 0);
        if ($sesionId <= 0) {
            return false;
        }

        $row = $this->repo->findById($sesionId);

        return $row !== null
            && $row['logout_at'] !== null
            && ($row['logout_motivo'] ?? '') === 'admin';
    }

    public function closeCurrentSession(string $motivo = 'logout'): void
    {
        $sesionId = (int)($_SESSION['usuario_sesion_id'] ?? 0);
        if ($sesionId > 0) {
            $this->repo->closeSession($sesionId, $motivo);
        }

        $phpSid = session_id();
        if ($phpSid !== '') {
            $this->repo->closeOpenSessionsByPhpSessionId($phpSid, $motivo);
        }

        unset($_SESSION['usuario_sesion_id']);
    }
}
