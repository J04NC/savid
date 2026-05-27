<?php

/**
 * Rellena created_at/by y updated_at/by en INSERT/UPDATE vía PDO.
 */
class TrackableColumnsService
{
    private const SKIP_TABLES = ['auditoria', 'auditoria_archivo'];

    /** @var array<string, array{created_at: bool, created_by: bool, updated_at: bool, updated_by: bool}> */
    private static array $metaCache = [];

    public static function isMutatingSql(string $sql): bool
    {
        return AuditService::isMutatingSql($sql);
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array{0: string, 1: array<int|string, mixed>}
     */
    public static function apply(PDO $pdo, string $sql, array $params): array
    {
        $parsed = self::parseSql($sql);

        if ($parsed === null || in_array($parsed['table'], self::SKIP_TABLES, true)) {
            return [$sql, $params];
        }

        $meta = self::getMeta($pdo, $parsed['table']);

        if ($parsed['action'] === 'INSERT' || $parsed['action'] === 'REPLACE') {
            return self::stampInsert($sql, $params, $meta);
        }

        if ($parsed['action'] === 'UPDATE') {
            return self::stampUpdate($sql, $params, $meta);
        }

        return [$sql, $params];
    }

    public static function currentUserId(): ?int
    {
        $id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

        return $id > 0 ? $id : null;
    }

    /**
     * @return array{created_at: bool, created_by: bool, updated_at: bool, updated_by: bool}
     */
    public static function getMeta(PDO $pdo, string $table): array
    {
        $table = SoftDeleteService::sanitizeTable($table);

        if ($table === '') {
            return self::emptyMeta();
        }

        if (isset(self::$metaCache[$table])) {
            return self::$metaCache[$table];
        }

        $stmt = $pdo->prepare('
            SELECT COLUMN_NAME
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME IN ("created_at", "created_by", "updated_at", "updated_by")
        ');
        $stmt->execute([$table]);
        $cols = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        self::$metaCache[$table] = [
            'created_at' => in_array('created_at', $cols, true),
            'created_by' => in_array('created_by', $cols, true),
            'updated_at' => in_array('updated_at', $cols, true),
            'updated_by' => in_array('updated_by', $cols, true),
        ];

        return self::$metaCache[$table];
    }

    /**
     * @return array{action: string, table: string}|null
     */
    private static function parseSql(string $sql): ?array
    {
        $sql = trim(preg_replace('/\s+/', ' ', $sql) ?? '');

        if (preg_match('/^INSERT\s+(?:IGNORE\s+)?INTO\s+`?([a-zA-Z0-9_]+)`?/i', $sql, $m)) {
            return ['action' => 'INSERT', 'table' => strtolower($m[1])];
        }

        if (preg_match('/^REPLACE\s+INTO\s+`?([a-zA-Z0-9_]+)`?/i', $sql, $m)) {
            return ['action' => 'REPLACE', 'table' => strtolower($m[1])];
        }

        if (preg_match('/^UPDATE\s+`?([a-zA-Z0-9_]+)`?/i', $sql, $m)) {
            return ['action' => 'UPDATE', 'table' => strtolower($m[1])];
        }

        return null;
    }

    /**
     * @param array{created_at: bool, created_by: bool, updated_at: bool, updated_by: bool} $meta
     * @param array<int|string, mixed> $params
     * @return array{0: string, 1: array<int|string, mixed>}
     */
    private static function stampInsert(string $sql, array $params, array $meta): array
    {
        if (!preg_match(
            '/^(INSERT\s+(?:IGNORE\s+)?INTO|REPLACE\s+INTO)\s+`?([a-zA-Z0-9_]+)`?\s*\(([^)]+)\)\s*VALUES\s*\(([^)]+)\)/is',
            $sql,
            $m
        )) {
            return [$sql, $params];
        }

        $prefix = $m[1];
        $table = $m[2];
        $colList = $m[3];
        $valList = $m[4];

        $cols = array_map(
            static fn($c) => strtolower(str_replace('`', '', trim($c))),
            explode(',', $colList)
        );

        $extraCols = [];
        $extraVals = [];
        $extraParams = [];
        $uid = self::currentUserId();

        if ($meta['created_at'] && !in_array('created_at', $cols, true)) {
            $extraCols[] = 'created_at';
            $extraVals[] = 'NOW(3)';
        }
        if ($meta['created_by'] && !in_array('created_by', $cols, true)) {
            $extraCols[] = 'created_by';
            $extraVals[] = '?';
            $extraParams[] = $uid;
        }
        if ($meta['updated_at'] && !in_array('updated_at', $cols, true)) {
            $extraCols[] = 'updated_at';
            $extraVals[] = 'NOW(3)';
        }
        if ($meta['updated_by'] && !in_array('updated_by', $cols, true)) {
            $extraCols[] = 'updated_by';
            $extraVals[] = '?';
            $extraParams[] = $uid;
        }

        if ($extraCols === []) {
            return [$sql, $params];
        }

        $newSql = sprintf(
            '%s `%s` (%s,%s) VALUES (%s,%s)',
            $prefix,
            $table,
            $colList,
            implode(',', $extraCols),
            $valList,
            implode(',', $extraVals)
        );

        return [$newSql, array_merge($params, $extraParams)];
    }

    /**
     * @param array{created_at: bool, created_by: bool, updated_at: bool, updated_by: bool} $meta
     * @param array<int|string, mixed> $params
     * @return array{0: string, 1: array<int|string, mixed>}
     */
    private static function stampUpdate(string $sql, array $params, array $meta): array
    {
        if (!preg_match('/\bWHERE\b/i', $sql)) {
            return [$sql, $params];
        }

        $setParts = [];

        if ($meta['updated_at'] && !preg_match('/\bupdated_at\s*=/i', $sql)) {
            $setParts[] = 'updated_at=NOW(3)';
        }
        if ($meta['updated_by'] && !preg_match('/\bupdated_by\s*=/i', $sql)) {
            $setParts[] = 'updated_by=?';
        }

        if ($setParts === []) {
            return [$sql, $params];
        }

        $newSql = preg_replace(
            '/\s+WHERE\s+/i',
            ', ' . implode(', ', $setParts) . ' WHERE ',
            $sql,
            1
        );

        if (!is_string($newSql)) {
            return [$sql, $params];
        }

        if (!$meta['updated_by'] || preg_match('/\bupdated_by\s*=/i', $sql)) {
            return [$newSql, $params];
        }

        $whereCount = self::countWherePlaceholders($sql);
        if ($whereCount <= 0 || count($params) < $whereCount) {
            return [$newSql, array_merge($params, [self::currentUserId()])];
        }

        $insertAt = count($params) - $whereCount;
        $newParams = array_merge(
            array_slice($params, 0, $insertAt),
            [self::currentUserId()],
            array_slice($params, $insertAt)
        );

        return [$newSql, $newParams];
    }

    private static function countWherePlaceholders(string $sql): int
    {
        if (!preg_match('/\bWHERE\b(.+)$/is', $sql, $m)) {
            return 0;
        }

        return substr_count($m[1], '?');
    }

    /**
     * @return array{created_at: bool, created_by: bool, updated_at: bool, updated_by: bool}
     */
    private static function emptyMeta(): array
    {
        return [
            'created_at' => false,
            'created_by' => false,
            'updated_at' => false,
            'updated_by' => false,
        ];
    }
}
