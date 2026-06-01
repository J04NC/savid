<?php

declare(strict_types=1);

/**
 * Añade rel:tercero|label:razon_social a todas las columnas empresa_id (FK → empresa).
 */
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/config/Database.php';

$pdo = (new Database())->connect();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$comment = 'label:Empresa|rel:tercero|label:razon_social|title:Razón social de la empresa (tercero vinculado)';

$stmt = $pdo->query("
    SELECT c.TABLE_NAME, c.COLUMN_NAME, c.COLUMN_TYPE, c.IS_NULLABLE, c.COLUMN_DEFAULT, c.EXTRA, c.COLUMN_COMMENT
    FROM information_schema.COLUMNS c
    INNER JOIN information_schema.KEY_COLUMN_USAGE k
        ON k.TABLE_SCHEMA = c.TABLE_SCHEMA
        AND k.TABLE_NAME = c.TABLE_NAME
        AND k.COLUMN_NAME = c.COLUMN_NAME
        AND k.REFERENCED_TABLE_NAME = 'empresa'
    WHERE c.TABLE_SCHEMA = DATABASE()
      AND c.COLUMN_NAME = 'empresa_id'
    ORDER BY c.TABLE_NAME
");

$updated = 0;
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $current = trim((string)($row['COLUMN_COMMENT'] ?? ''));
    if (str_contains($current, 'rel:tercero') && str_contains($current, 'label:razon_social')) {
        continue;
    }

    $newComment = $comment;
    if ($current !== '' && !str_contains($current, 'rel:tercero')) {
        $newComment = 'rel:tercero|label:razon_social|' . $current;
    }

    $table = $row['TABLE_NAME'];
    $col = $row['COLUMN_NAME'];
    $type = $row['COLUMN_TYPE'];
    $nullable = $row['IS_NULLABLE'] === 'YES' ? ' NULL' : ' NOT NULL';
    $default = '';
    if ($row['COLUMN_DEFAULT'] !== null) {
        $def = (string)$row['COLUMN_DEFAULT'];
        if (strtoupper($def) === 'CURRENT_TIMESTAMP' || str_starts_with(strtoupper($def), 'CURRENT_TIMESTAMP(')) {
            $default = ' DEFAULT ' . $def;
        } elseif (preg_match('/^(tiny|small|medium|big)?int|decimal|float|double/i', $type)) {
            $default = ' DEFAULT ' . $def;
        } else {
            $default = " DEFAULT '" . str_replace("'", "''", $def) . "'";
        }
    } elseif ($row['IS_NULLABLE'] === 'YES') {
        $default = ' DEFAULT NULL';
    }
    $extra = trim((string)($row['EXTRA'] ?? ''));
    $extraSql = $extra !== '' ? ' ' . $extra : '';

    $sql = sprintf(
        'ALTER TABLE `%s` MODIFY COLUMN `%s` %s%s%s%s COMMENT %s',
        str_replace('`', '``', $table),
        str_replace('`', '``', $col),
        $type,
        $nullable,
        $default,
        $extraSql,
        $pdo->quote($newComment)
    );
    $pdo->exec($sql);
    $updated++;
    echo "OK {$table}.{$col}\n";
}

echo "Columnas actualizadas: {$updated}\n";
