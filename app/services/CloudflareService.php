<?php

/**
 * Automatiza el alta de subdominios de cliente: crea el registro DNS
 * (CNAME apuntando al túnel de Cloudflare) vía la API REST de Cloudflare.
 * Requiere que el ingress del túnel ya acepte "*.APP_BASE_DOMAIN"
 * (configurado una sola vez, fuera de la app — ver plan de subdominio
 * por empresa) para que el DNS nuevo funcione sin tocar el servidor.
 *
 * Variables de entorno soportadas (config/.env, igual que Database):
 * CLOUDFLARE_API_TOKEN    — token con permiso "Edit DNS" ÚNICAMENTE
 *   sobre la zona de APP_BASE_DOMAIN (nunca "Full access" a la cuenta).
 * CLOUDFLARE_ZONE_ID      — el ID de esa zona (dashboard de Cloudflare,
 *   en la página del dominio, columna derecha "API" -> "Zone ID").
 * CLOUDFLARE_TUNNEL_TARGET — hostname del túnel al que apunta el CNAME
 *   (formato "<tunnel-id>.cfargotunnel.com").
 */
class CloudflareService
{
    private const API_BASE = 'https://api.cloudflare.com/client/v4';

    private ?string $apiToken;
    private string $zoneId;
    private string $tunnelTarget;
    private EmpresaSubdominioService $subdominioService;

    public function __construct()
    {
        Database::bootstrapEnv();

        $this->apiToken = trim((string)(getenv('CLOUDFLARE_API_TOKEN') ?: '')) ?: null;
        $this->zoneId = trim((string)(getenv('CLOUDFLARE_ZONE_ID') ?: ''));
        $this->tunnelTarget = trim((string)(getenv('CLOUDFLARE_TUNNEL_TARGET') ?: ''));
        $this->subdominioService = new EmpresaSubdominioService();
    }

    public static function isConfigured(): bool
    {
        Database::bootstrapEnv();

        return trim((string)(getenv('CLOUDFLARE_API_TOKEN') ?: '')) !== ''
            && trim((string)(getenv('CLOUDFLARE_ZONE_ID') ?: '')) !== ''
            && trim((string)(getenv('CLOUDFLARE_TUNNEL_TARGET') ?: '')) !== '';
    }

    /**
     * Valida el formato del subdominio (sin tocar la red): letras
     * minúsculas, números y guiones, sin empezar/terminar en guión, y
     * nunca "app" (reservado para el dominio genérico de siempre).
     */
    public function validarFormato(string $subdominio): ?string
    {
        $subdominio = strtolower(trim($subdominio));

        if ($subdominio === '') {
            return 'Indique un subdominio.';
        }

        if (!preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $subdominio)) {
            return 'Subdominio inválido: solo minúsculas, números y guiones, sin empezar ni terminar en guión.';
        }

        if ($subdominio === 'app') {
            return '"app" está reservado para el dominio genérico (app.' . $this->subdominioService->baseDomain() . ').';
        }

        return null;
    }

    /**
     * Crea el registro DNS (CNAME, proxied) para el subdominio dado.
     * Nunca lanza: si algo falla, devuelve success=false con el motivo —
     * el llamador debe guardar el subdominio en empresa.subdominio SOLO
     * si esto tiene éxito, para no dejar un subdominio "fantasma" que no
     * resuelve en DNS.
     *
     * @return array{success: bool, error?: string}
     */
    public function crearSubdominio(string $subdominio): array
    {
        $errorFormato = $this->validarFormato($subdominio);
        if ($errorFormato !== null) {
            return ['success' => false, 'error' => $errorFormato];
        }

        if ($this->apiToken === null || $this->zoneId === '' || $this->tunnelTarget === '') {
            return [
                'success' => false,
                'error' => 'Integración con Cloudflare no configurada (CLOUDFLARE_API_TOKEN / CLOUDFLARE_ZONE_ID / CLOUDFLARE_TUNNEL_TARGET).',
            ];
        }

        $subdominio = strtolower(trim($subdominio));
        $baseDomain = $this->subdominioService->baseDomain();
        $fqdn = $baseDomain !== '' ? $subdominio . '.' . $baseDomain : $subdominio;

        $client = new \GuzzleHttp\Client();

        try {
            // http_errors=false: Cloudflare siempre devuelve un body JSON
            // con success/errors, incluso en 4xx — se parsea igual en
            // todos los casos, sin depender de que Guzzle lance excepción.
            $response = $client->post(self::API_BASE . "/zones/{$this->zoneId}/dns_records", [
                'http_errors' => false,
                'timeout' => 15,
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'type' => 'CNAME',
                    'name' => $fqdn,
                    'content' => $this->tunnelTarget,
                    'proxied' => true,
                    'comment' => 'SAVID: subdominio exclusivo de empresa (creado automáticamente)',
                ],
            ]);

            $body = json_decode((string)$response->getBody(), true);

            if (empty($body['success'])) {
                $error = $body['errors'][0]['message'] ?? ('Error desconocido de Cloudflare (HTTP ' . $response->getStatusCode() . ').');
                error_log('CloudflareService: fallo al crear DNS para ' . $fqdn . ': ' . $error);

                return ['success' => false, 'error' => $error];
            }

            error_log('CloudflareService: DNS creado para ' . $fqdn);

            return ['success' => true];
        } catch (\Throwable $e) {
            error_log('CloudflareService: excepción al crear DNS para ' . $fqdn . ': ' . $e->getMessage());

            return ['success' => false, 'error' => 'No se pudo contactar a Cloudflare: ' . $e->getMessage()];
        }
    }
}
