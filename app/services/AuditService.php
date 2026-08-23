<?php

class AuditService
{
    private const SKIP_TABLES = ['auditoria', 'auditoria_archivo', 'usuario_sesion'];

    private const SENSITIVE_FIELDS = [
        'password', 'password_hash', 'clave', 'token', 'secret', 'api_key',
    ];

    private static int $disableDepth = 0;

    /** @var array<string, bool> */
    private static array $tableHasDeletedAt = [];

    public static function isEnabled(): bool
    {
        return self::$disableDepth === 0;
    }

    public static function withoutAuditing(callable $fn): mixed
    {
        self::$disableDepth++;

        try {
            return $fn();
        } finally {
            self::$disableDepth--;
        }
    }

    public static function isMutatingSql(string $sql): bool
    {
        $sql = ltrim($sql);

        return (bool) preg_match('/^(INSERT|UPDATE|DELETE|REPLACE)\b/i', $sql);
    }

    /**
     * @param array<int|string, mixed>|null $params
     * @return array{rows: list<array<string, mixed>>, table: string, action: string}|null
     */
    public static function captureBeforeMutation(PDO $pdo, string $sql, ?array $params): ?array
    {
        $parsed = self::parseSql($sql);

        if ($parsed === null || in_array($parsed['table'], self::SKIP_TABLES, true)) {
            return null;
        }

        $action = $parsed['action'];

        if ($action === 'INSERT' || $action === 'REPLACE') {
            return ['rows' => [], 'table' => $parsed['table'], 'action' => $action];
        }

        $selectSql = self::sqlToSelectBefore($sql, $action, $parsed['table']);

        if ($selectSql === null) {
            return ['rows' => [], 'table' => $parsed['table'], 'action' => $action];
        }

        try {
            $whereParams = self::paramsForWhereClause($sql, $params);
            $stmt = $pdo->prepare($selectSql);
            $stmt->execute($whereParams);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $rows = [];
        }

        return ['rows' => $rows, 'table' => $parsed['table'], 'action' => $action];
    }

    /**
     * @param array<int|string, mixed>|null $params
     * @param array{rows: list<array<string, mixed>>, table: string, action: string}|null $snapshot
     */
    public static function recordAfterMutation(PDO $pdo, string $sql, ?array $params, ?array $snapshot): void
    {
        if ($snapshot === null) {
            return;
        }

        $parsed = self::parseSql($sql);

        if ($parsed === null || in_array($parsed['table'], self::SKIP_TABLES, true)) {
            return;
        }

        $action = $snapshot['action'];
        $table = $snapshot['table'];
        $beforeRows = $snapshot['rows'];

        $afterRows = [];

        if ($action === 'INSERT' || $action === 'REPLACE') {
            $newId = $pdo->lastInsertId();
            if ($newId !== '' && $newId !== '0') {
                $afterRows = self::fetchRowById($pdo, $table, $newId);
            }
        } elseif ($action === 'UPDATE') {
            $selectSql = self::sqlToSelectBefore($sql, 'UPDATE', $table);
            if ($selectSql !== null) {
                try {
                    $whereParams = self::paramsForWhereClause($sql, $params);
                    $stmt = $pdo->prepare($selectSql);
                    $stmt->execute($whereParams);
                    $afterRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                } catch (Throwable $e) {
                    $afterRows = [];
                }
            }
        }

        if ($action === 'DELETE') {
            foreach ($beforeRows as $row) {
                self::persistLog($pdo, 'DELETE', $table, $row, null, $sql);
            }

            if ($beforeRows === [] && $params !== null) {
                self::persistLog($pdo, 'DELETE', $table, null, null, $sql, $params);
            }

            return;
        }

        if ($action === 'INSERT' || $action === 'REPLACE') {
            if ($afterRows !== []) {
                foreach ($afterRows as $row) {
                    self::persistLog($pdo, 'INSERT', $table, null, $row, $sql);
                }
            } else {
                self::persistLog($pdo, 'INSERT', $table, null, ['_params' => self::normalizeParams($params)], $sql);
            }

            return;
        }

        if ($action === 'UPDATE') {
            $pairs = self::pairBeforeAfter($beforeRows, $afterRows);

            foreach ($pairs as ['before' => $b, 'after' => $a]) {
                self::persistLog($pdo, 'UPDATE', $table, $b, $a, $sql);
            }

            if ($pairs === [] && $afterRows !== []) {
                foreach ($afterRows as $row) {
                    self::persistLog($pdo, 'UPDATE', $table, null, $row, $sql);
                }
            }

            if ($pairs === [] && $afterRows === [] && $beforeRows === []) {
                $whereParams = self::paramsForWhereClause($sql, $params);
                self::persistLog(
                    $pdo,
                    'UPDATE',
                    $table,
                    null,
                    ['_where_params' => $whereParams],
                    $sql,
                    $whereParams
                );
            }
        }
    }

    public static function recordBeforeExec(PDO $pdo, string $sql): void
    {
        // Reservado para exec() masivo; el snapshot se toma en recordAfterExec si aplica.
    }

    public static function recordAfterExec(PDO $pdo, string $sql): void
    {
        $parsed = self::parseSql($sql);

        if ($parsed === null || in_array($parsed['table'], self::SKIP_TABLES, true)) {
            return;
        }

        self::persistLog($pdo, $parsed['action'], $parsed['table'], null, null, $sql);
    }

    public static function tableSupportsSoftDelete(PDO $pdo, string $table): bool
    {
        return SoftDeleteService::supports($pdo, $table);
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     * @param array<int|string, mixed>|null $extra
     */
    private static function persistLog(
        PDO $pdo,
        string $action,
        string $table,
        ?array $before,
        ?array $after,
        string $sql,
        ?array $extra = null
    ): void {
        $before = self::sanitizeRow($before);
        $after = self::sanitizeRow($after);
        // Diff campo a campo solo tiene sentido cuando existen ambos lados (UPDATE);
        // en INSERT/DELETE, un lado es null y "todo cambió" no aporta nada sobre datos_anteriores/datos_nuevos.
        $changed = ($before !== null && $after !== null) ? self::diffFields($before, $after) : null;
        $registroId = self::extractRegistroId($before ?? $after ?? $extra);

        $ctx = self::requestContext();

        self::withoutAuditing(function () use ($pdo, $action, $table, $registroId, $before, $after, $changed, $sql, $ctx) {
            $stmt = $pdo->prepare('
                INSERT INTO auditoria (
                    accion, tabla, registro_id,
                    datos_anteriores, datos_nuevos, campos_cambiados,
                    sql_resumen, usuario_id, empresa_id, sede_id,
                    ip, user_agent, request_url
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');

            $stmt->execute([
                $action,
                $table,
                $registroId,
                $before !== null ? json_encode($before, JSON_UNESCAPED_UNICODE) : null,
                $after !== null ? json_encode($after, JSON_UNESCAPED_UNICODE) : null,
                $changed !== null ? json_encode($changed, JSON_UNESCAPED_UNICODE) : null,
                mb_substr(preg_replace('/\s+/', ' ', trim($sql)), 0, 500),
                $ctx['usuario_id'],
                $ctx['empresa_id'],
                $ctx['sede_id'],
                $ctx['ip'],
                $ctx['user_agent'],
                $ctx['request_url'],
            ]);
        });
    }

    /**
     * @return array{usuario_id: ?int, empresa_id: ?int, sede_id: ?int, ip: ?string, user_agent: ?string, request_url: ?string}
     */
    private static function requestContext(): array
    {
        $uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $eid = isset($_SESSION['empresa_id']) ? (int)$_SESSION['empresa_id'] : null;
        $sid = isset($_SESSION['sede_id']) ? (int)$_SESSION['sede_id'] : null;

        if ($eid !== null && $eid <= 0) {
            $eid = null;
        }
        if ($sid !== null && $sid <= 0) {
            $sid = null;
        }

        return [
            'usuario_id' => $uid > 0 ? $uid : null,
            'empresa_id' => $eid,
            'sede_id' => $sid,
            'ip' => RequestIpService::current(),
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT'])
                ? mb_substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 255)
                : null,
            'request_url' => isset($_SERVER['REQUEST_URI'])
                ? mb_substr((string)$_SERVER['REQUEST_URI'], 0, 500)
                : null,
        ];
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

        if (preg_match('/^DELETE\s+FROM\s+`?([a-zA-Z0-9_]+)`?/i', $sql, $m)) {
            return ['action' => 'DELETE', 'table' => strtolower($m[1])];
        }

        return null;
    }

    private static function sqlToSelectBefore(string $sql, string $action, string $table): ?string
    {
        // Los repositorios escriben el SQL en heredocs/cadenas multilínea, así que
        // casi siempre empieza con salto de línea y sangría. Sin este ltrim los
        // patrones anclados en ^ no casaban y el DELETE se devolvía intacto: la
        // captura previa entonces preparaba y EJECUTABA ese DELETE, cuyo execute
        // volvía a pedir la captura previa, en recursión infinita hasta agotar la
        // memoria del proceso.
        $sql = ltrim($sql);

        if ($action === 'DELETE') {
            $replaced = preg_replace('/^DELETE\s+FROM/i', 'SELECT * FROM', $sql, 1);

            return self::asSelectOrNull($replaced);
        }

        if ($action === 'UPDATE') {
            $replaced = preg_replace(
                '/^UPDATE\s+`?' . preg_quote($table, '/') . '`?\s+SET\s+.+?\s+(WHERE\s+.+)$/is',
                'SELECT * FROM `' . $table . '` $1',
                $sql,
                1
            );

            return self::asSelectOrNull($replaced);
        }

        return null;
    }

    /**
     * Red de seguridad: la captura previa solo puede ejecutar sentencias de
     * lectura. Si la reescritura no produjo un SELECT se descarta el snapshot
     * (se pierde detalle de auditoría) antes que ejecutar una sentencia que
     * mutaría datos y se auditaría a sí misma en bucle.
     */
    private static function asSelectOrNull(?string $sql): ?string
    {
        if (!is_string($sql)) {
            return null;
        }

        return stripos(ltrim($sql), 'SELECT') === 0 ? $sql : null;
    }

    /**
     * En UPDATE/DELETE los placeholders del SET van antes que los del WHERE;
     * el SELECT previo/posterior solo debe recibir los parámetros del WHERE.
     *
     * @param array<int|string, mixed>|null $params
     * @return list<mixed>
     */
    private static function paramsForWhereClause(string $sql, ?array $params): array
    {
        $params = self::normalizeParams($params);

        if ($params === [] || !preg_match('/\bWHERE\b(.+)$/is', $sql, $m)) {
            return $params;
        }

        $whereMarks = substr_count($m[1], '?');

        if ($whereMarks <= 0) {
            return $params;
        }

        if (count($params) < $whereMarks) {
            return $params;
        }

        return array_slice($params, -$whereMarks);
    }

    /**
     * @param array<int|string, mixed>|null $params
     * @return list<mixed>
     */
    private static function normalizeParams(?array $params): array
    {
        if ($params === null || $params === []) {
            return [];
        }

        if (array_is_list($params)) {
            return $params;
        }

        return array_values($params);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function fetchRowById(PDO $pdo, string $table, string $id): array
    {
        $table = preg_replace('/[^A-Za-z0-9_]/', '', $table) ?? '';

        if ($table === '') {
            return [];
        }

        try {
            $stmt = $pdo->prepare("SELECT * FROM `{$table}` WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return $row ? [$row] : [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * @param list<array<string, mixed>> $beforeRows
     * @param list<array<string, mixed>> $afterRows
     * @return list<array{before: ?array<string, mixed>, after: ?array<string, mixed>}>
     */
    private static function pairBeforeAfter(array $beforeRows, array $afterRows): array
    {
        $out = [];
        $afterById = [];

        foreach ($afterRows as $a) {
            $id = $a['id'] ?? null;
            if ($id !== null) {
                $afterById[(string)$id] = $a;
            }
        }

        foreach ($beforeRows as $b) {
            $id = isset($b['id']) ? (string)$b['id'] : null;
            $out[] = [
                'before' => $b,
                'after' => $id !== null && isset($afterById[$id]) ? $afterById[$id] : null,
            ];
        }

        if ($beforeRows === [] && $afterRows !== []) {
            foreach ($afterRows as $a) {
                $out[] = ['before' => null, 'after' => $a];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed>|null $row
     * @return array<string, mixed>|null
     */
    private static function sanitizeRow(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }

        foreach ($row as $k => $v) {
            $lk = strtolower((string)$k);
            foreach (self::SENSITIVE_FIELDS as $sensitive) {
                if (str_contains($lk, $sensitive)) {
                    $row[$k] = '[REDACTED]';
                    break;
                }
            }
        }

        return $row;
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     * @return array<string, array{antes: mixed, despues: mixed}>|null
     */
    private static function diffFields(?array $before, ?array $after): ?array
    {
        if ($before === null && $after === null) {
            return null;
        }

        $keys = array_unique(array_merge(
            array_keys($before ?? []),
            array_keys($after ?? [])
        ));

        $diff = [];

        foreach ($keys as $key) {
            $b = $before[$key] ?? null;
            $a = $after[$key] ?? null;
            if (!self::auditValuesEqual($b, $a)) {
                $diff[$key] = ['antes' => $b, 'despues' => $a];
            }
        }

        return $diff === [] ? null : $diff;
    }

    private static function auditValuesEqual(mixed $before, mixed $after): bool
    {
        if ($before === $after) {
            return true;
        }

        if (is_array($before) || is_array($after) || is_object($before) || is_object($after)) {
            return json_encode($before, JSON_UNESCAPED_UNICODE) === json_encode($after, JSON_UNESCAPED_UNICODE);
        }

        if ($before === null || $after === null) {
            return false;
        }

        return (string)$before === (string)$after;
    }

    /**
     * @param array<string, mixed>|null $row
     * @param array<int|string, mixed>|null $fallback
     */
    private static function extractRegistroId(?array $row, ?array $fallback = null): ?string
    {
        if ($row !== null && isset($row['id'])) {
            return (string)$row['id'];
        }

        if ($fallback !== null && isset($fallback[0])) {
            return (string)$fallback[0];
        }

        return null;
    }
}
