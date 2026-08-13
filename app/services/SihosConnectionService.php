<?php

/**
 * Orquesta la conexión SIHOS por empresa: resolución de alcance
 * (empresa_id + permiso vía SihosScopeService), qué mostrar (sin exponer
 * la contraseña), guardar credenciales (cifradas) y probar la conexión
 * contra la BD externa de la empresa activa.
 */
class SihosConnectionService
{
    private SihosScopeService $scope;
    private SihosEmpresaConfigRepository $configRepository;

    public function __construct()
    {
        $this->scope = new SihosScopeService();
        $this->configRepository = new SihosEmpresaConfigRepository();
    }

    public function buildScope(array $query = []): array
    {
        return $this->scope->buildScope($query);
    }

    public function canAccessEmpresa(int $empresaId, array $query = []): bool
    {
        return $this->scope->canAccessEmpresa($empresaId, $query);
    }

    public function buildConfigView(int $empresaId): array
    {
        $row = $this->configRepository->findByEmpresaId($empresaId);

        return [
            'host' => $row['host'] ?? '',
            'puerto' => $row !== null ? (int)$row['puerto'] : 3306,
            'base_datos' => $row['base_datos'] ?? '',
            'usuario' => $row['usuario'] ?? '',
            'codi_inst' => $row['codi_inst'] ?? '',
            'charset' => $row['charset'] ?? 'utf8mb4',
            'password_configurada' => !empty($row['password_cifrado'] ?? null),
            'usuario_escritura' => $row['usuario_escritura'] ?? '',
            'password_escritura_configurada' => !empty($row['password_escritura_cifrado'] ?? null),
            'configurado' => $row !== null,
        ];
    }

    public function saveConfig(int $empresaId, array $post): array
    {
        $host = trim((string)($post['host'] ?? ''));
        $puerto = (int)($post['puerto'] ?? 0) ?: 3306;
        $baseDatos = trim((string)($post['base_datos'] ?? ''));
        $usuario = trim((string)($post['usuario'] ?? ''));
        $codiInst = trim((string)($post['codi_inst'] ?? ''));
        $password = (string)($post['password'] ?? '');
        $charset = trim((string)($post['charset'] ?? '')) ?: 'utf8mb4';
        $usuarioEscritura = trim((string)($post['usuario_escritura'] ?? ''));
        $passwordEscritura = (string)($post['password_escritura'] ?? '');

        if ($host === '' || $baseDatos === '' || $usuario === '' || $codiInst === '') {
            return ['success' => false, 'message' => 'Host, base de datos, usuario y CodiInst son obligatorios.'];
        }

        $passwordCifrado = $password !== '' ? SihosCredentialCipher::encrypt($password) : null;
        $passwordEscrituraCifrado = $passwordEscritura !== '' ? SihosCredentialCipher::encrypt($passwordEscritura) : null;

        $this->configRepository->upsert($empresaId, [
            'host' => $host,
            'puerto' => $puerto,
            'base_datos' => $baseDatos,
            'usuario' => $usuario,
            'codi_inst' => $codiInst,
            'password_cifrado' => $passwordCifrado,
            'charset' => $charset,
            'usuario_escritura' => $usuarioEscritura,
            'password_escritura_cifrado' => $passwordEscrituraCifrado,
        ]);

        return ['success' => true, 'message' => 'Conexión de SIHOS guardada.'];
    }

    public function testConnection(int $empresaId): array
    {
        $row = $this->configRepository->findByEmpresaId($empresaId);

        if ($row === null || $row['host'] === '' || $row['base_datos'] === '' || $row['usuario'] === '') {
            return ['ok' => false, 'message' => 'No hay conexión configurada para esta empresa.'];
        }

        $repository = new SihosExternalRepository([
            'host' => $row['host'],
            'port' => (string)$row['puerto'],
            'database' => $row['base_datos'],
            'username' => $row['usuario'],
            'codiInst' => (string)($row['codi_inst'] ?? ''),
            'password' => SihosCredentialCipher::decrypt($row['password_cifrado'] ?? null),
            'charset' => $row['charset'],
        ]);

        try {
            $version = $repository->fetchServerVersion();

            return [
                'ok' => true,
                'message' => 'Conexión exitosa. Versión del servidor: ' . $version . '.',
            ];
        } catch (PDOException $e) {
            return [
                'ok' => false,
                'message' => 'No se pudo conectar: ' . $e->getMessage(),
            ];
        }
    }
}
