<?php

/**
 * Filtros de baja lógica (deleted_at / deleted_by).
 */
class SoftDeleteService
{
    /** @var array<string, bool> */
    private static array $cache = [];

    public static function supports(PDO $pdo, string $table): bool
    {
        $table = self::sanitizeTable($table);

        if ($table === '') {
            return false;
        }

        if (array_key_exists($table, self::$cache)) {
            return self::$cache[$table];
        }

        $stmt = $pdo->prepare('
            SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = "deleted_at"
            LIMIT 1
        ');
        $stmt->execute([$table]);
        self::$cache[$table] = (bool) $stmt->fetchColumn();

        return self::$cache[$table];
    }

    /**
     * Fragmento SQL: AND `alias`.deleted_at IS NULL
     */
    public static function sqlAndNotDeleted(PDO $pdo, string $table, ?string $alias = null): string
    {
        if (!self::supports($pdo, $table)) {
            return '';
        }

        $a = self::sanitizeTable($alias ?? $table);

        return " AND `{$a}`.deleted_at IS NULL";
    }

    /**
     * Condición para cláusula WHERE (sin AND inicial).
     */
    public static function sqlWhereNotDeleted(PDO $pdo, string $table, ?string $alias = null): string
    {
        if (!self::supports($pdo, $table)) {
            return '';
        }

        $a = self::sanitizeTable($alias ?? $table);

        return "`{$a}`.deleted_at IS NULL";
    }

  /**
     * Añade filtro a un array de condiciones WHERE (para armado dinámico).
     *
     * @param list<string> $where
     */
    public static function pushWhereNotDeleted(PDO $pdo, string $table, array &$where, ?string $alias = null): void
    {
        $cond = self::sqlWhereNotDeleted($pdo, $table, $alias);

        if ($cond !== '') {
            $where[] = $cond;
        }
    }

    public static function sanitizeTable(string $table): string
    {
        return preg_replace('/[^A-Za-z0-9_]/', '', $table) ?? '';
    }
}
