<?php

declare(strict_types=1);

/**
 * Añade show:none a comentarios de columnas de auditoría / soft delete.
 * Uso: php scripts/apply_trackable_column_comments.php [--dry-run]
 */

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/config/Database.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);

$trackable = [
    'deleted_at',
    'deleted_by',
    'created_at',
    'created_by',
    'updated_at',
    'updated_by',
];

function mergeShowNoneComment(?string $existing): string
{
    $existing = trim((string)$existing);
    if ($existing !== '' && stripos($existing, 'show:none') !== false) {
        return $existing;
    }
    if ($existing === '') {
        return 'show:none';
    }

    return 'show:none|' . $existing;
}

function buildDefaultAndExtraSql(?string $default, string $columnType, string $extra): string
{
    $sql = '';
    $extra = trim($extra);

    if ($default !== null && $default !== '') {
        $upper = strtoupper($default);
        if ($upper === 'CURRENT_TIMESTAMP' || str_starts_with($upper, 'CURRENT_TIMESTAMP(')) {
            $sql .= ' DEFAULT ' . $default;
        } elseif (preg_match('/^(tinyint|smallint|mediumint|int|bigint|decimal|float|double)/i', $columnType)) {
            $sql .= ' DEFAULT ' . $default;
        } else {
            $sql .= " DEFAULT '" . str_replace("'", "''", $default) . "'";
        }
    }

    if (preg_match('/\bon\s+update\s+CURRENT_TIMESTAMP(\(\d+\))?/i', $extra, $m)) {
        $sql .= ' ON UPDATE CURRENT_TIMESTAMP' . ($m[1] ?? '');
    }

    if (stripos($extra, 'auto_increment') !== false) {
        $sql .= ' AUTO_INCREMENT';
    }

    return $sql;
}

$pdo = (new Database())->connect();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$in = implode(',', array_fill(0, count($trackable), '?'));
$stmt = $pdo->prepare("
    SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLUMN_COMMENT
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND COLUMN_NAME IN ($in)
    ORDER BY TABLE_NAME, COLUMN_NAME
");
$stmt->execute($trackable);

$updated = 0;
$skipped = 0;

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $table = $row['TABLE_NAME'];
    $col = $row['COLUMN_NAME'];
    $type = $row['COLUMN_TYPE'];
    $nullable = $row['IS_NULLABLE'] === 'YES' ? ' NULL' : ' NOT NULL';
    $extra = trim((string)($row['EXTRA'] ?? ''));
    $comment = mergeShowNoneComment($row['COLUMN_COMMENT'] ?? '');

    if (trim((string)($row['COLUMN_COMMENT'] ?? '')) === $comment) {
        $skipped++;
        continue;
    }

    $defaultExtraSql = buildDefaultAndExtraSql(
        $row['COLUMN_DEFAULT'] !== null ? (string)$row['COLUMN_DEFAULT'] : null,
        $type,
        $extra
    );

    $sql = sprintf(
        'ALTER TABLE `%s` MODIFY COLUMN `%s` %s%s%s COMMENT %s',
        str_replace('`', '``', $table),
        str_replace('`', '``', $col),
        $type,
        $nullable,
        $defaultExtraSql,
        $pdo->quote($comment)
    );

    if ($dryRun) {
        echo $sql . ";\n";
    } else {
        $pdo->exec($sql);
    }
    $updated++;
}

echo ($dryRun ? '[dry-run] ' : '') . "Actualizadas: {$updated}, ya tenían show:none: {$skipped}\n";
