<?php

/**
 * Resuelve el valor a usar en `UsuaDigi`/`UsuaModi` de SIHOS (varchar(12))
 * para las escrituras que SAVID hace contra SIHOS: si el usuario de SAVID
 * autenticado en la sesión tiene, por el mismo tipo+número de documento, un
 * usuario activo en SIHOS, usa su `Login` real — igual que si esa persona
 * hubiera digitado el registro desde la propia pantalla de SIHOS. Si no hay
 * match (el caso más común — la mayoría de usuarios de SAVID no tienen
 * cuenta en SIHOS) o falla cualquier consulta, cae al literal fijo
 * `'SAVID'`: la resolución de identidad es un enriquecimiento, nunca debe
 * bloquear la escritura real. La trazabilidad real de qué usuario de SAVID
 * lo hizo vive siempre en `auditoria.usuario_id`, sea cual sea el resultado
 * de esta resolución.
 */
class SihosUsuaDigiResolver
{
    private const FALLBACK = 'SAVID';

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? (new Database())->connect();
    }

    public function resolver(int $usuarioId, SihosExternalRepository $repositorioLectura): string
    {
        if ($usuarioId <= 0) {
            return self::FALLBACK;
        }

        try {
            $stmt = $this->pdo->prepare('
                SELECT td.codigo, ti.numero
                FROM usuario u
                INNER JOIN terceroidentificacion ti ON ti.id = u.terceroidentificacion_id
                INNER JOIN tipodocumento td ON td.id = ti.tipodocumento_id
                WHERE u.id = ?
                LIMIT 1
            ');
            $stmt->execute([$usuarioId]);
            $documento = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return self::FALLBACK;
        }

        $codigo = trim((string)($documento['codigo'] ?? ''));
        $numero = trim((string)($documento['numero'] ?? ''));

        if ($codigo === '' || $numero === '') {
            return self::FALLBACK;
        }

        try {
            $login = $repositorioLectura->resolveLoginUsuarioPorDocumento($codigo, $numero);
        } catch (PDOException $e) {
            return self::FALLBACK;
        }

        return $login ?? self::FALLBACK;
    }
}
