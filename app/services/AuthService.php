<?php

class AuthService
{
    /** Coincide con comprobaciones existentes (ej. RolController). */
    private const SUPER_ADMIN_ROL_ID = 1;

    private UserRepository $userRepository;
    private CompanyRepository $companyRepository;
    private BranchRepository $branchRepository;
    private SubscriptionRepository $subscriptionRepository;

    public function __construct()
    {
        $database = new Database();
        $pdo = $database->connect();

        $this->userRepository = new UserRepository($pdo);
        $this->companyRepository = new CompanyRepository($pdo);
        $this->branchRepository = new BranchRepository($pdo);
        $this->subscriptionRepository = new SubscriptionRepository($pdo);
    }

    /**
     * @return array{success:bool, error?:string, redirect?:string}
     */
    public function authenticate($username, $password)
    {
        if ($username === '' || $password === '') {
            return ['success' => false, 'error' => 'Todos los campos son obligatorios'];
        }

        $user = $this->userRepository->findActiveByUsername($username);

        if (!$user || !password_verify($password, $user['password'])) {
            return ['success' => false, 'error' => 'Usuario o contraseña incorrectos'];
        }

        $userId = (int)$user['id'];

        if (!empty($user['two_factor_enabled'])) {
            $twoFactor = new TwoFactorService();
            if (!$twoFactor->hasTrustedDeviceCookie($userId)) {
                $_SESSION['tfa_pending_user_id'] = $userId;
                $twoFactor->sendCode(
                    $userId,
                    (string)($user['email'] ?? ''),
                    (string)($user['nombre'] ?? $user['username']),
                    $_SERVER['REMOTE_ADDR'] ?? null
                );

                return ['success' => true, 'needs_2fa' => true, 'redirect' => '?url=login/verificar2fa'];
            }
        }

        return $this->completeLogin($user);
    }

    /**
     * Retoma el login de un usuario que ya validó su código de doble factor.
     *
     * @return array{success:bool, error?:string, redirect?:string}
     */
    public function completeLoginForUserId(int $userId): array
    {
        $user = $this->userRepository->findActiveById($userId);
        if (!$user) {
            return ['success' => false, 'error' => 'No se pudo completar el inicio de sesión.'];
        }

        return $this->completeLogin($user);
    }

    /**
     * @return array{success:bool, error?:string, redirect?:string}
     */
    private function completeLogin(array $user): array
    {
        $userId = (int)$user['id'];

        unset($_SESSION['tfa_pending_user_id']);

        $this->clearStaleContextKeys();
        $this->setUserSession($user);

        if ($this->userRepository->hasRole($userId, self::SUPER_ADMIN_ROL_ID)) {
            $_SESSION['es_super_admin'] = true;
            $this->applySuperAdminSessionDefaults();

            return ['success' => true, 'redirect' => '?url=dashboard'];
        }

        $_SESSION['es_super_admin'] = false;

        if (!PermisoService::userHasAssignedGrants($userId)) {
            $this->clearAuthState();

            return [
                'success' => false,
                'error' => 'No tiene permisos asignados en el sistema. Comuníquese con el administrador.',
            ];
        }

        $ctx = $this->resolveOperationalContext($userId);

        if (!$ctx['success']) {
            $this->clearAuthState();

            return ['success' => false, 'error' => $ctx['error']];
        }

        return ['success' => true, 'redirect' => $ctx['redirect']];
    }

    private function clearStaleContextKeys(): void
    {
        unset(
            $_SESSION['empresa_id'],
            $_SESSION['empresa'],
            $_SESSION['empresas'],
            $_SESSION['sede_id'],
            $_SESSION['sede'],
            $_SESSION['sedes'],
            $_SESSION['es_super_admin'],
            $_SESSION['plan_id'],
            $_SESSION['plan_nombre'],
            $_SESSION['fecha_fin'],
            $_SESSION['sesion_idle_minutos']
        );
    }

    private function clearAuthState(): void
    {
        unset(
            $_SESSION['user_id'],
            $_SESSION['nombre'],
            $_SESSION['rol_id'],
            $_SESSION['rol_nombre'],
            $_SESSION['foto_ruta'],
            $_SESSION['es_super_admin'],
            $_SESSION['empresa_id'],
            $_SESSION['empresa'],
            $_SESSION['empresas'],
            $_SESSION['sede_id'],
            $_SESSION['sede'],
            $_SESSION['sedes'],
            $_SESSION['sesion_idle_minutos'],
            $_SESSION['plan_id'],
            $_SESSION['plan_nombre'],
            $_SESSION['fecha_fin']
        );
    }

    private function applySuperAdminSessionDefaults(): void
    {
        $_SESSION['empresa_id'] = null;
        $_SESSION['sede_id'] = null;
        $_SESSION['empresa'] = '';
        $_SESSION['sede'] = '';
        unset($_SESSION['empresas'], $_SESSION['sedes']);
        unset($_SESSION['plan_id'], $_SESSION['plan_nombre'], $_SESSION['fecha_fin']);
    }

    /**
     * Usuario operativo (no Super Admin): empresa + sede obligatorias salvo flujo de selección.
     *
     * @return array{success:bool, error?:string, redirect?:string}
     */
    private function resolveOperationalContext(int $userId): array
    {
        $empresas = $this->companyRepository->findActiveByUserId($userId);

        if (count($empresas) === 0) {
            return [
                'success' => false,
                'error' => "No tiene ninguna empresa asociada.\nComuníquese con el administrador del sistema.",
            ];
        }

        if (count($empresas) > 1) {
            $_SESSION['empresas'] = $empresas;
            $_SESSION['empresa_id'] = null;
            $_SESSION['empresa'] = 'Seleccione empresa';
            $_SESSION['sedes'] = null;
            $_SESSION['sede_id'] = null;
            $_SESSION['sede'] = 'Seleccione sede';

            return ['success' => true, 'redirect' => '?url=context/cambiarSede'];
        }

        $empresa = $empresas[0];
        $_SESSION['empresa_id'] = $empresa['id'];
        $_SESSION['empresa'] = $empresa['razon_social'];
        $_SESSION['empresas'] = $empresas;

        $subscriptionValidation = $this->validateAndSetSubscription((int)$empresa['id'], $empresa['razon_social'] ?? '');
        if (!$subscriptionValidation['success']) {
            return $subscriptionValidation;
        }

        $sedes = $this->branchRepository->findActiveByUserIdAndEmpresaId($userId, (int)$empresa['id']);

        if (count($sedes) === 0) {
            return [
                'success' => false,
                'error' => "No tiene ninguna sede asignada.\nComuníquese con el administrador del sistema.",
            ];
        }

        if (count($sedes) === 1) {
            $_SESSION['sede_id'] = $sedes[0]['id'];
            $_SESSION['sede'] = $sedes[0]['nombre'];
            $_SESSION['sedes'] = $sedes;

            return ['success' => true, 'redirect' => '?url=dashboard'];
        }

        $_SESSION['sedes'] = $sedes;
        $_SESSION['sede_id'] = null;
        $_SESSION['sede'] = 'Seleccione sede';

        return ['success' => true, 'redirect' => '?url=context/cambiarSede'];
    }

    private function setUserSession($user)
    {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['nombre'] = $user['nombre'];
        $_SESSION['rol_id'] = $user['rol_id'];
        $_SESSION['rol_nombre'] = $user['rol_nombre'] ?? '';
        $_SESSION['foto_ruta'] = $user['foto_ruta'] ?? null;

        $idle = $user['sesion_idle_minutos'] ?? null;
        if ($idle !== null && $idle !== '' && (int)$idle > 0) {
            $_SESSION['sesion_idle_minutos'] = (int)$idle;
        } else {
            unset($_SESSION['sesion_idle_minutos']);
        }
    }

    /**
     * @return array{success:bool, error?:string}
     */
    private function validateAndSetSubscription($empresaId, $empresaNombre)
    {
        $suscripcion = $this->subscriptionRepository->findActiveLatestByEmpresaId($empresaId);

        if (!$suscripcion) {
            return ['success' => false, 'error' => "La empresa '{$empresaNombre}' no tiene plan activo"];
        }

        $fechaFin = date('Y-m-d', strtotime($suscripcion['fecha_fin']));
        $hoy = date('Y-m-d');

        if ($fechaFin < $hoy) {
            return [
                'success' => false,
                'error' => "El plan de '{$empresaNombre}' venció el " . date('d/m/Y', strtotime($suscripcion['fecha_fin'])),
            ];
        }

        $_SESSION['plan_id'] = $suscripcion['plan_id'];
        $_SESSION['plan_nombre'] = $suscripcion['plan_nombre'];
        $_SESSION['fecha_fin'] = $suscripcion['fecha_fin'];

        return ['success' => true];
    }

    public static function verifyPasswordForUserId(int $userId, string $password): bool
    {
        if ($userId <= 0 || $password === '') {
            return false;
        }

        $database = new Database();
        $repo = new UserAccessRepository($database->connect());
        $user = $repo->findActiveUserById($userId);

        return is_array($user)
            && !empty($user['password'])
            && password_verify($password, (string)$user['password']);
    }
}
