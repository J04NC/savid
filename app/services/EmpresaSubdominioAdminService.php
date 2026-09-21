<?php

/**
 * Orquesta el alta de un subdominio de empresa (botón de superadmin en
 * el CRUD de empresa): crea el registro DNS en Cloudflare primero
 * (CloudflareService) y SOLO si tiene éxito lo guarda en
 * empresa.subdominio — así nunca queda un subdominio "fantasma" en la
 * BD que en realidad no resuelve en DNS si Cloudflare falla.
 *
 * Clase separada de EmpresaSubdominioService (que sigue siendo puro de
 * lectura/resolución) a propósito: CloudflareService ya depende de
 * EmpresaSubdominioService para leer baseDomain(), así que si este
 * servicio viviera ahí se formaría una dependencia circular entre
 * ambos constructores.
 */
class EmpresaSubdominioAdminService
{
    private PDO $pdo;
    private CloudflareService $cloudflare;
    private EmpresaSubdominioService $subdominioService;

    public function __construct()
    {
        $database = new Database();
        $this->pdo = $database->connect();
        $this->cloudflare = new CloudflareService();
        $this->subdominioService = new EmpresaSubdominioService();
    }

    /**
     * @return array{success: bool, message?: string, error?: string}
     */
    public function asignarSubdominio(int $empresaId, string $subdominio): array
    {
        $subdominio = strtolower(trim($subdominio));

        $stmt = $this->pdo->prepare('SELECT id, subdominio FROM empresa WHERE id = ? LIMIT 1');
        $stmt->execute([$empresaId]);
        $empresa = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$empresa) {
            return ['success' => false, 'error' => 'Empresa no encontrada.'];
        }

        if (!empty($empresa['subdominio'])) {
            return [
                'success' => false,
                'error' => 'Esta empresa ya tiene un subdominio asignado (' . $empresa['subdominio'] . '). No se puede reemplazar desde aquí.',
            ];
        }

        $errorFormato = $this->cloudflare->validarFormato($subdominio);
        if ($errorFormato !== null) {
            return ['success' => false, 'error' => $errorFormato];
        }

        $existe = $this->pdo->prepare('SELECT id FROM empresa WHERE subdominio = ? LIMIT 1');
        $existe->execute([$subdominio]);
        if ($existe->fetchColumn()) {
            return ['success' => false, 'error' => 'Ese subdominio ya está en uso por otra empresa.'];
        }

        $dns = $this->cloudflare->crearSubdominio($subdominio);
        if (!$dns['success']) {
            return ['success' => false, 'error' => $dns['error'] ?? 'No se pudo crear el registro DNS.'];
        }

        $update = $this->pdo->prepare('UPDATE empresa SET subdominio = ? WHERE id = ?');
        $update->execute([$subdominio, $empresaId]);

        $base = $this->subdominioService->baseDomain();
        $urlMostrada = $base !== '' ? $subdominio . '.' . $base : $subdominio;

        return [
            'success' => true,
            'message' => 'Subdominio creado: https://' . $urlMostrada,
        ];
    }
}
