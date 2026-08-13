<?php

/**
 * Cifrado reversible (AES-256-GCM) para la clave de conexión a SIHOS
 * guardada en `sihos_empresa_config`. No es un hash: hay que poder
 * recuperar el texto plano para abrir la conexión real a SIHOS.
 *
 * Clave: SIHOS_CONFIG_KEY en config/.env, 32 bytes en base64
 * (generar con: openssl rand -base64 32).
 */
class SihosCredentialCipher
{
    private const CIPHER = 'aes-256-gcm';

    public static function encrypt(string $plaintext): string
    {
        $key = self::key();
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);

        if ($ciphertext === false) {
            throw new RuntimeException('No se pudo cifrar la contraseña de SIHOS.');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    public static function decrypt(?string $stored): string
    {
        if ($stored === null || trim($stored) === '') {
            return '';
        }

        $key = self::key();
        $raw = base64_decode($stored, true);

        if ($raw === false || strlen($raw) < 28) {
            return '';
        }

        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ciphertext = substr($raw, 28);

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);

        return $plaintext === false ? '' : $plaintext;
    }

    private static function key(): string
    {
        Database::bootstrapEnv();

        $raw = (string)(getenv('SIHOS_CONFIG_KEY') ?: '');
        if ($raw === '') {
            throw new RuntimeException('SIHOS_CONFIG_KEY no está configurada en config/.env.');
        }

        $key = base64_decode($raw, true);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('SIHOS_CONFIG_KEY debe ser una clave base64 de 32 bytes (openssl rand -base64 32).');
        }

        return $key;
    }
}
