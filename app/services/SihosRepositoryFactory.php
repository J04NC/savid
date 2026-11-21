<?php

/**
 * Construye los repositorios de lectura/escritura contra SIHOS a partir de
 * la fila de config de la empresa (SihosEmpresaConfigRepository). Antes de
 * este factory, el mismo array de 7 claves (host/port/database/username/
 * codiInst/password descifrada/charset) estaba copiado en ~34 sitios del
 * módulo; la validación de qué campos son obligatorios sigue en cada
 * llamador (varía: solo lectura exige menos que escritura), este factory
 * solo construye el objeto una vez que el llamador ya validó.
 */
class SihosRepositoryFactory
{
    /** @param array<string,mixed> $configFila fila de sihos_empresa_config */
    public static function buildRead(array $configFila, string $codiInst): SihosExternalRepository
    {
        return new SihosExternalRepository([
            'host' => $configFila['host'],
            'port' => (string)$configFila['puerto'],
            'database' => $configFila['base_datos'],
            'username' => $configFila['usuario'],
            'codiInst' => $codiInst,
            'password' => SihosCredentialCipher::decrypt($configFila['password_cifrado'] ?? null),
            'charset' => $configFila['charset'],
        ]);
    }

    /**
     * @param array<string,mixed> $configFila fila de sihos_empresa_config
     * @param string $usuarioEscritura ya resuelto por el llamador (algunos lo
     *               pasan recortado/validado antes de llegar aquí)
     */
    public static function buildWrite(array $configFila, string $codiInst, string $usuarioEscritura): SihosExternalWriteRepository
    {
        return new SihosExternalWriteRepository([
            'host' => $configFila['host'],
            'port' => (string)$configFila['puerto'],
            'database' => $configFila['base_datos'],
            'username' => $usuarioEscritura,
            'codiInst' => $codiInst,
            'password' => SihosCredentialCipher::decrypt($configFila['password_escritura_cifrado'] ?? null),
            'charset' => $configFila['charset'],
        ]);
    }
}
