<?php

class SuscripcionService
{
    private const RENEWAL_MONTHS = 12;

    private PDO $pdo;
    private CompanyRepository $companyRepository;
    private SubscriptionRepository $subscriptionRepository;

    public function __construct()
    {
        $database = new Database();
        $this->pdo = $database->connect();

        $this->companyRepository = new CompanyRepository($this->pdo);
        $this->subscriptionRepository = new SubscriptionRepository($this->pdo);
    }

    /**
     * Datos para el modal de confirmación: empresa, plan actual y la nueva vigencia calculada.
     *
     * @return array{success:bool, error?:string, empresa_nombre?:string, plan_id?:int, plan_nombre?:string, fecha_inicio?:string, fecha_fin?:string}
     */
    public function getRenewalPreview(int $empresaId): array
    {
        $empresa = $this->companyRepository->findActiveById($empresaId);
        if (!$empresa) {
            return ['success' => false, 'error' => 'Empresa no encontrada o inactiva.'];
        }

        $ultima = $this->subscriptionRepository->findLatestByEmpresaId($empresaId);
        if (!$ultima) {
            return ['success' => false, 'error' => 'Esta empresa no tiene ninguna suscripción registrada aún. Cree la primera manualmente.'];
        }

        $fechaInicio = new DateTime('today');
        $fechaFin = (clone $fechaInicio)->modify('+' . self::RENEWAL_MONTHS . ' months');

        return [
            'success' => true,
            'empresa_nombre' => (string)($empresa['razon_social'] ?? ('Empresa #' . $empresaId)),
            'plan_id' => (int)$ultima['plan_id'],
            'plan_nombre' => (string)($ultima['plan_nombre'] ?? ''),
            'fecha_inicio' => $fechaInicio->format('Y-m-d'),
            'fecha_fin' => $fechaFin->format('Y-m-d'),
        ];
    }

    /**
     * @return array{success:bool, error?:string, id?:int}
     */
    public function renew(int $empresaId): array
    {
        $preview = $this->getRenewalPreview($empresaId);
        if (!$preview['success']) {
            return $preview;
        }

        $id = $this->subscriptionRepository->createRenewal(
            $empresaId,
            $preview['plan_id'],
            $preview['fecha_inicio'],
            $preview['fecha_fin']
        );

        return ['success' => true, 'id' => $id];
    }

    /**
     * @return array{success:bool, error?:string, empresa_nombre?:string, plan_id?:int, plan_nombre?:string, fecha_inicio?:string, fecha_fin?:string}
     */
    public function getRenewalPreviewForSuscripcion(int $suscripcionId): array
    {
        $empresaId = $this->subscriptionRepository->findEmpresaIdById($suscripcionId);
        if (!$empresaId) {
            return ['success' => false, 'error' => 'Suscripción no encontrada.'];
        }

        return $this->getRenewalPreview($empresaId);
    }

    /**
     * @return array{success:bool, error?:string, id?:int}
     */
    public function renewFromSuscripcion(int $suscripcionId): array
    {
        $empresaId = $this->subscriptionRepository->findEmpresaIdById($suscripcionId);
        if (!$empresaId) {
            return ['success' => false, 'error' => 'Suscripción no encontrada.'];
        }

        return $this->renew($empresaId);
    }
}
