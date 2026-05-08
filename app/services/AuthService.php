<?php

class AuthService
{
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

    public function authenticate($username, $password)
    {
        if ($username === '' || $password === '') {
            return ['success' => false, 'error' => 'Todos los campos son obligatorios'];
        }

        $user = $this->userRepository->findActiveByUsername($username);

        if (!$user || !password_verify($password, $user['password'])) {
            return ['success' => false, 'error' => 'Usuario o contraseña incorrectos'];
        }

        $this->setUserSession($user);
        $this->setEmpresaSession($user['id']);

        if (!empty($_SESSION['empresa_id'])) {
            $subscriptionValidation = $this->validateAndSetSubscription($_SESSION['empresa_id'], $_SESSION['empresa'] ?? '');
            if (!$subscriptionValidation['success']) {
                return $subscriptionValidation;
            }
        }

        $this->setSedeSession($user['id']);

        return ['success' => true];
    }

    private function setUserSession($user)
    {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['nombre'] = $user['nombre'];
        $_SESSION['rol_id'] = $user['rol_id'];
        $_SESSION['rol_nombre'] = $user['rol_nombre'] ?? '';
    }

    private function setEmpresaSession($userId)
    {
        $empresas = $this->companyRepository->findActiveByUserId($userId);

        if (count($empresas) === 1) {
            $_SESSION['empresa_id'] = $empresas[0]['id'];
            $_SESSION['empresa'] = $empresas[0]['razon_social'];
        } elseif (count($empresas) > 1) {
            $_SESSION['empresas'] = $empresas;
            $_SESSION['empresa_id'] = null;
            $_SESSION['empresa'] = 'Seleccione empresa';
        } else {
            $_SESSION['empresa_id'] = null;
            $_SESSION['empresa'] = 'Sin empresa';
        }
    }

    private function setSedeSession($userId)
    {
        $sedes = $this->branchRepository->findActiveByUserId($userId);

        if (count($sedes) === 1) {
            $_SESSION['sede_id'] = $sedes[0]['id'];
            $_SESSION['sede'] = $sedes[0]['nombre'];
        } elseif (count($sedes) > 1) {
            $_SESSION['sedes'] = $sedes;
            $_SESSION['sede_id'] = $sedes[0]['id'] ?? null;
            $_SESSION['sede'] = $sedes[0]['nombre'] ?? 'Seleccione sede';
        } else {
            $_SESSION['sede_id'] = null;
            $_SESSION['sede'] = 'Sin sede';
        }
    }

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
}
