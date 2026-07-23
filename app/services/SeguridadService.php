<?php

/**
 * Agrega, en una sola pantalla, señales de seguridad que hoy están dispersas
 * (2FA, sesiones activas, intentos de login fallidos, cuentas inactivas).
 * Exclusivo de superadmin: "intentos de login" es una tabla global sin empresa_id
 * y el desglose de sesiones por empresa solo tiene sentido con visión de todas ellas.
 */
class SeguridadService
{
    private const DIAS_INACTIVIDAD = 90;
    private const LIMITE_INTENTOS_FALLIDOS = 20;

    private SeguridadRepository $repo;
    private UsuarioSesionRepository $sesionRepo;
    private LoginIntentoRepository $intentoRepo;

    public function __construct()
    {
        $database = new Database();
        $pdo = $database->connect();
        $this->repo = new SeguridadRepository($pdo);
        $this->sesionRepo = new UsuarioSesionRepository($pdo);
        $this->intentoRepo = new LoginIntentoRepository($pdo);
    }

    public function canView(): bool
    {
        return isset($_SESSION['user_id']) && PermisoService::isSuperAdminSession();
    }

    /**
     * @return array{
     *   dosFactor: array{total: int, conDosFactor: int, porcentaje: float},
     *   sesionesPorEmpresa: list<array{empresa_id: ?int, empresa_nombre: string, activas: int}>,
     *   intentosFallidos: list<array{username: string, ip: string, created_at: string}>,
     *   totalFallidos24h: int,
     *   cuentasInactivas: list<array{id: int, username: string, ultimo_login: ?string, empresas: ?string}>,
     *   diasInactividadUmbral: int
     * }
     */
    public function buildDashboard(): array
    {
        $dosFactor = $this->repo->estadisticasDosFactor();
        $porcentaje = $dosFactor['total'] > 0
            ? round($dosFactor['conDosFactor'] / $dosFactor['total'] * 100, 1)
            : 0.0;

        return [
            'dosFactor' => [
                'total' => $dosFactor['total'],
                'conDosFactor' => $dosFactor['conDosFactor'],
                'porcentaje' => $porcentaje,
            ],
            'sesionesPorEmpresa' => $this->sesionRepo->countActivasPorEmpresa(),
            'intentosFallidos' => $this->intentoRepo->listRecentFailures(self::LIMITE_INTENTOS_FALLIDOS),
            'totalFallidos24h' => $this->intentoRepo->countFailuresSince(date('Y-m-d H:i:s', strtotime('-24 hours'))),
            'cuentasInactivas' => $this->repo->cuentasInactivas(self::DIAS_INACTIVIDAD),
            'diasInactividadUmbral' => self::DIAS_INACTIVIDAD,
        ];
    }
}
