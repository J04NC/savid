<?php

/**
 * Guardia de aislamiento por tenant en tiempo de ejecución.
 *
 * MySQL no tiene Row-Level Security nativo: el filtro por empresa_id depende
 * de que cada Repository se acuerde de escribirlo. .githooks/pre-commit ya
 * avisa sobre eso, pero solo mira el diff de un commit — no ve SQL que ya
 * existía antes del hook, que se modificó sin tocar la línea del FROM/JOIN,
 * ni SQL construido dinámicamente. Esta clase es la misma red, pero corriendo
 * sobre cada consulta real que pasa por AuditingPDOStatement::execute(), sin
 * importar de dónde vino el SQL ni cuándo se escribió.
 *
 * No es RLS real (sigue siendo una revisión de texto, no una política del
 * motor), pero es lo más cerca que se puede llegar sin migrar a PostgreSQL:
 * se ejecuta en cada request, no solo en el momento de escribir código.
 */
class TenantIsolationGuard
{
    /** @var list<string>|null */
    private static ?array $tablasMultiTenant = null;

    private static int $suppressDepth = 0;

    /**
     * Para consultas legítimamente cruzadas entre empresas (reportes de
     * superadmin, scripts administrativos explícitos). Siempre restaura el
     * estado anterior, incluso si $fn lanza una excepción.
     */
    public static function allowCrossTenant(callable $fn): mixed
    {
        self::$suppressDepth++;

        try {
            return $fn();
        } finally {
            self::$suppressDepth--;
        }
    }

    public static function check(AuditingPDO $connection, string $sql): void
    {
        if (PHP_SAPI === 'cli') {
            // Scripts/migraciones/cron: sin contexto de tenant en sesión, y el
            // acceso cruzado ahí suele ser intencional (import, batch, admin).
            return;
        }

        if (self::$suppressDepth > 0) {
            return;
        }

        $modo = self::modo();
        if ($modo === 'off') {
            return;
        }

        $tablas = self::tablasMultiTenant($connection);
        if ($tablas === []) {
            return;
        }

        if (stripos($sql, 'empresa_id') !== false) {
            return;
        }

        $patron = '/\b(FROM|INTO|UPDATE|JOIN)\s+`?(' . implode('|', array_map(
            static fn (string $t) => preg_quote($t, '/'),
            $tablas
        )) . ')`?\b/i';

        if (!preg_match($patron, $sql, $coincidencia)) {
            return;
        }

        $mensaje = sprintf(
            'TENANT_GUARD: consulta contra "%s" sin empresa_id en el texto SQL | sql=%s',
            $coincidencia[2],
            self::sqlCorto($sql)
        );

        if ($modo === 'enforce') {
            throw new RuntimeException($mensaje);
        }

        error_log($mensaje);
    }

    private static function modo(): string
    {
        $valor = (string)(getenv('TENANT_GUARD_MODE') ?: 'log');

        if ($valor === 'enforce' || $valor === 'off') {
            return $valor;
        }

        return 'log';
    }

    /**
     * Tablas con columna empresa_id en el esquema real (no en los archivos de
     * migración: así refleja lo que de verdad existe en esta base de datos).
     * Se cachea en una propiedad estática — una sola consulta por request
     * (los estáticos se reinician en cada petición PHP-FPM), no por cada
     * statement individual que pase por el guard.
     *
     * @return list<string>
     */
    private static function tablasMultiTenant(AuditingPDO $connection): array
    {
        if (self::$tablasMultiTenant !== null) {
            return self::$tablasMultiTenant;
        }

        try {
            // prepareNative (no prepare): evita volver a entrar a
            // AuditingPDOStatement::execute() con esta misma consulta.
            $stmt = $connection->prepareNative(
                "SELECT DISTINCT TABLE_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'empresa_id'"
            );

            if ($stmt === false) {
                self::$tablasMultiTenant = [];
                return self::$tablasMultiTenant;
            }

            $stmt->execute();
            self::$tablasMultiTenant = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable $e) {
            // Nunca romper una petición real por un fallo de la propia guardia.
            self::$tablasMultiTenant = [];
        }

        return self::$tablasMultiTenant;
    }

    private static function sqlCorto(string $sql): string
    {
        $plano = (string)preg_replace('/\s+/', ' ', trim($sql));

        return mb_substr($plano, 0, 300);
    }
}
