<?php

/**
 * Arma los datos de las 3 pestañas de consulta de SIHOS > Procesos >
 * Interfaz Laboratorio (Homologación, Solicitudes, Resultados), usando la
 * MISMA conexión de solo lectura por empresa que el resto del módulo SIHOS
 * (`sihos_empresa_config` / `SihosExternalRepository`'s config shape). No
 * escribe nada — ver SihosInterlabProcesarService para la escritura.
 */
class SihosInterlabService
{
    private SihosEmpresaConfigRepository $configRepository;

    public function __construct()
    {
        $this->configRepository = new SihosEmpresaConfigRepository();
    }

    /**
     * @return array{host:string,port:string,database:string,username:string,password:string,charset:string,codiInst:string}|null null si la empresa no tiene conexión configurada
     */
    private function buildConexionLectura(int $empresaId): ?array
    {
        $row = $this->configRepository->findByEmpresaId($empresaId);
        if ($row === null || $row['host'] === '' || $row['base_datos'] === '' || $row['usuario'] === '') {
            return null;
        }

        return [
            'host' => $row['host'],
            'port' => (string)$row['puerto'],
            'database' => $row['base_datos'],
            'username' => $row['usuario'],
            'password' => SihosCredentialCipher::decrypt($row['password_cifrado'] ?? null),
            'charset' => $row['charset'],
            'codiInst' => (string)($row['codi_inst'] ?? ''),
        ];
    }

    private function repositorio(int $empresaId): ?SihosInterlabRepository
    {
        $config = $this->buildConexionLectura($empresaId);

        return $config === null ? null : new SihosInterlabRepository($config);
    }

    /**
     * @param int[] $estados
     * @return array{ok:bool, error?:string, filas?:list<array<string,mixed>>}
     */
    public function buildResultados(int $empresaId, string $fechaIni, string $fechaFin, array $estados = []): array
    {
        $repo = $this->repositorio($empresaId);
        if ($repo === null) {
            return ['ok' => false, 'error' => 'Esta empresa no tiene conexión a SIHOS configurada.'];
        }

        try {
            return ['ok' => true, 'filas' => $repo->fetchResultados($fechaIni, $fechaFin, $estados)];
        } catch (PDOException $e) {
            return ['ok' => false, 'error' => 'No se pudo consultar SIHOS: ' . $e->getMessage()];
        }
    }

    /**
     * @return array{ok:bool, error?:string, registradas?:list<array<string,mixed>>, candidatas?:list<array<string,mixed>>}
     */
    public function buildSolicitudes(int $empresaId, string $fechaIni, string $fechaFin): array
    {
        $repo = $this->repositorio($empresaId);
        if ($repo === null) {
            return ['ok' => false, 'error' => 'Esta empresa no tiene conexión a SIHOS configurada.'];
        }

        try {
            return [
                'ok' => true,
                'registradas' => $repo->fetchSolicitudesRegistradas($fechaIni, $fechaFin),
                'candidatas' => $repo->fetchSolicitudesCandidatas(),
            ];
        } catch (PDOException $e) {
            return ['ok' => false, 'error' => 'No se pudo consultar SIHOS: ' . $e->getMessage()];
        }
    }

    /**
     * @return array{ok:bool, error?:string, filas?:list<array{CodiCups:string,CodiPrue:string,Analito:string}>}
     */
    public function buildHomologacion(int $empresaId, string $codiCups = ''): array
    {
        $repo = $this->repositorio($empresaId);
        if ($repo === null) {
            return ['ok' => false, 'error' => 'Esta empresa no tiene conexión a SIHOS configurada.'];
        }

        try {
            return ['ok' => true, 'filas' => $repo->fetchHomologacion($codiCups)];
        } catch (PDOException $e) {
            return ['ok' => false, 'error' => 'No se pudo consultar SIHOS: ' . $e->getMessage()];
        }
    }

    /**
     * IDs de Interfaz_resultados_Roche pendientes de procesar — usado por el
     * script CLI de cron (scripts/sihos_interlab_procesar.php), no por la
     * vista web (que usa buildResultados(), con rango de fecha).
     *
     * @return array{ok:bool, error?:string, ids?:int[]}
     */
    public function buildIdsResultadosPendientes(int $empresaId): array
    {
        $repo = $this->repositorio($empresaId);
        if ($repo === null) {
            return ['ok' => false, 'error' => 'Esta empresa no tiene conexión a SIHOS configurada.'];
        }

        try {
            return ['ok' => true, 'ids' => $repo->fetchIdsResultadosPendientes()];
        } catch (PDOException $e) {
            return ['ok' => false, 'error' => 'No se pudo consultar SIHOS: ' . $e->getMessage()];
        }
    }

    /**
     * Candidatas a solicitud pendientes — mismo repositorio que usa la
     * pestaña Solicitudes de la vista web, expuesto aquí para el script CLI.
     *
     * @return array{ok:bool, error?:string, candidatas?:list<array<string,mixed>>}
     */
    public function buildSolicitudesCandidatas(int $empresaId): array
    {
        $repo = $this->repositorio($empresaId);
        if ($repo === null) {
            return ['ok' => false, 'error' => 'Esta empresa no tiene conexión a SIHOS configurada.'];
        }

        try {
            return ['ok' => true, 'candidatas' => $repo->fetchSolicitudesCandidatas()];
        } catch (PDOException $e) {
            return ['ok' => false, 'error' => 'No se pudo consultar SIHOS: ' . $e->getMessage()];
        }
    }
}
