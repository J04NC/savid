<?php

/**
 * Resuelve el subdominio de la request actual (Host) contra la empresa que
 * lo tiene asignado — pieza central del plan de acceso exclusivo por
 * empresa: login (LoginController), redirección al cambiar de empresa
 * (ContextService) y el link de recuperación de contraseña
 * (PasswordResetService) reutilizan este mismo servicio para no duplicar
 * la lógica de extracción del subdominio.
 *
 * Variable de entorno soportada (config/.env, igual que Database):
 * APP_BASE_DOMAIN (ej. "savid.com.co" — sin protocolo, sin subdominio).
 * Si no está configurada, ningún host resuelve a nada: todo se comporta
 * como hoy (sin subdominios de cliente).
 */
class EmpresaSubdominioService
{
    private CompanyRepository $companyRepository;

    public function __construct()
    {
        $database = new Database();
        $this->companyRepository = new CompanyRepository($database->connect());
    }

    /**
     * Extrae el subdominio de un host, ej. "clientea.savid.com.co" -> "clientea".
     * Devuelve null si el host no cae bajo APP_BASE_DOMAIN, si no hay
     * subdominio (el host es exactamente el dominio base), o si el
     * "subdominio" resultante trae más de un nivel (b.clientea.savid.com.co)
     * — no soportado, se trata igual que "sin subdominio reconocido".
     */
    public function resolveSubdominioFromHost(string $host): ?string
    {
        Database::bootstrapEnv();

        $baseDomain = strtolower(trim((string)(getenv('APP_BASE_DOMAIN') ?: '')));
        if ($baseDomain === '') {
            return null;
        }

        $host = strtolower(trim($host));
        // Quita un posible puerto (ej. "app.savid.com.co:8080" en pruebas locales).
        $host = preg_replace('/:\d+$/', '', $host) ?? $host;

        $suffix = '.' . $baseDomain;
        if (!str_ends_with($host, $suffix)) {
            return null;
        }

        $subdominio = substr($host, 0, -strlen($suffix));
        if ($subdominio === '' || str_contains($subdominio, '.')) {
            return null;
        }

        return $subdominio;
    }

    /**
     * @return array{id:int, subdominio:string, razon_social:string}|null
     */
    public function resolveEmpresaFromHost(string $host): ?array
    {
        $subdominio = $this->resolveSubdominioFromHost($host);
        if ($subdominio === null) {
            return null;
        }

        return $this->companyRepository->findActiveBySubdominio($subdominio);
    }

    public function baseDomain(): string
    {
        Database::bootstrapEnv();

        return trim((string)(getenv('APP_BASE_DOMAIN') ?: ''));
    }

    /**
     * Usado en el LOGIN (no en el selector de cambiar empresa, que
     * deliberadamente muestra todas las empresas del usuario y redirige al
     * subdominio correcto): de la lista de empresas del usuario, cuáles son
     * válidas para autenticarse desde este host.
     * - Si el host es el subdominio exclusivo de una empresa, solo esa
     *   empresa es válida aquí (si el usuario pertenece a ella).
     * - Si el host es genérico (no resuelve a ninguna empresa), son
     *   válidas las empresas que NO tengan subdominio propio asignado.
     *
     * @param list<array<string, mixed>> $empresas cada una con al menos 'id' y 'subdominio'
     * @return list<array<string, mixed>>
     */
    public function filterEmpresasByHost(array $empresas, string $host): array
    {
        $empresaDelHost = $this->resolveEmpresaFromHost($host);

        if ($empresaDelHost !== null) {
            return array_values(array_filter(
                $empresas,
                fn ($e) => (int)$e['id'] === (int)$empresaDelHost['id']
            ));
        }

        return array_values(array_filter(
            $empresas,
            fn ($e) => empty($e['subdominio'])
        ));
    }

    /**
     * Mensaje para cuando filterEmpresasByHost() deja la lista vacía: el
     * usuario tiene empresas, pero ninguna es accesible desde este host.
     *
     * @param list<array<string, mixed>> $empresasTotales sin filtrar
     */
    public function mensajeSinAccesoDesdeEsteDominio(array $empresasTotales): string
    {
        $conSubdominio = array_values(array_filter(
            $empresasTotales,
            fn ($e) => !empty($e['subdominio'])
        ));

        if (count($conSubdominio) === 1) {
            $base = $this->baseDomain();
            if ($base !== '') {
                return 'Esta cuenta debe acceder desde https://' . $conSubdominio[0]['subdominio'] . '.' . $base;
            }
        }

        return 'Esta cuenta no tiene acceso desde este dominio. Use el enlace específico de su empresa.';
    }
}
