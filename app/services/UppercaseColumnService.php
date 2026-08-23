<?php

/**
 * Directiva `uppercase` de COLUMN_COMMENT: columnas que se editan y persisten
 * solo en mayúsculas (ver README.md, tabla de directivas).
 *
 * El motor CRUD genérico ya la aplica al guardar la tabla propia del ítem, pero
 * los formularios de `empresa` y `usuario` escriben además en `tercero` desde
 * sus propios resolvers, que corren antes de esa normalización. Esta clase es el
 * punto único que consultan todos, leyendo siempre el COLUMN_COMMENT real para
 * que la base de datos siga siendo la única fuente de verdad: marcar una columna
 * nueva con `uppercase` basta para que quede cubierta en todas las rutas.
 */
class UppercaseColumnService
{
    /** @var array<string, array<string, bool>> tabla => columna => exige mayúscula */
    private static array $cache = [];

    /**
     * ¿El comentario trae el trozo exacto `uppercase`?
     */
    public static function commentRequiresUppercase(string $comment): bool
    {
        foreach (explode('|', $comment) as $part) {
            if (trim($part) === 'uppercase') {
                return true;
            }
        }

        return false;
    }

    /**
     * Columnas de la tabla marcadas con `uppercase`.
     *
     * @return array<string, bool>
     */
    public static function columnsFor(PDO $pdo, string $table): array
    {
        $t = preg_replace('/[^a-zA-Z0-9_]/', '', $table) ?? '';

        if ($t === '') {
            return [];
        }

        if (isset(self::$cache[$t])) {
            return self::$cache[$t];
        }

        $stmt = $pdo->prepare('
            SELECT COLUMN_NAME, COLUMN_COMMENT
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
        ');
        $stmt->execute([$t]);

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            if (self::commentRequiresUppercase((string)($row['COLUMN_COMMENT'] ?? ''))) {
                $map[(string)$row['COLUMN_NAME']] = true;
            }
        }

        return self::$cache[$t] = $map;
    }

    /**
     * Pasa a mayúsculas un valor suelto destinado a esa columna.
     */
    public static function value(PDO $pdo, string $table, string $column, string $value): string
    {
        if ($value === '' || !isset(self::columnsFor($pdo, $table)[$column])) {
            return $value;
        }

        return self::toUpper($value);
    }

    /**
     * Pasa a mayúsculas los valores del mapa cuyas columnas lo exigen.
     * Respeta null y valores no string (FK, fechas) sin tocarlos.
     *
     * @param array<string, mixed> $map columna => valor
     * @return array<string, mixed>
     */
    public static function applyToMap(PDO $pdo, string $table, array $map): array
    {
        $upper = self::columnsFor($pdo, $table);

        if ($upper === []) {
            return $map;
        }

        foreach ($map as $column => $value) {
            if (!isset($upper[$column]) || !is_string($value) || $value === '') {
                continue;
            }

            $map[$column] = self::toUpper($value);
        }

        return $map;
    }

    private static function toUpper(string $value): string
    {
        return function_exists('mb_strtoupper')
            ? mb_strtoupper($value, 'UTF-8')
            : strtoupper($value);
    }
}
