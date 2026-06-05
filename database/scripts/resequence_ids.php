#!/usr/bin/env php
<?php
/**
 * Reasigna IDs consecutivos desde 1 en tablas con columna `id` (PK),
 * actualizando todas las FKs registradas en INFORMATION_SCHEMA.
 *
 * Uso:
 *   php database/scripts/resequence_ids.php           # simulación
 *   php database/scripts/resequence_ids.php --execute # aplicar cambios
 */
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__, 2));

require BASE_PATH . '/config/Database.php';

$execute = in_array('--execute', $argv ?? [], true);

$pdo = (new Database())->connect();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/**
 * @return list<string>
 */
function tablesWithIdColumn(PDO $pdo): array
{
    $out = [];
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        $col = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE 'id'")->fetch(PDO::FETCH_ASSOC);
        if (!$col) {
            continue;
        }
        $key = $pdo->query("SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'")->fetch(PDO::FETCH_ASSOC);
        if (!$key || ($key['Column_name'] ?? '') !== 'id') {
            continue;
        }
        $out[] = $table;
    }

    sort($out);

    return $out;
}

/**
 * @return list<array{table: string, column: string, ref_table: string, ref_column: string}>
 */
function foreignKeys(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT TABLE_NAME AS `table`, COLUMN_NAME AS `column`,
               REFERENCED_TABLE_NAME AS ref_table, REFERENCED_COLUMN_NAME AS ref_column
        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE()
          AND REFERENCED_TABLE_NAME IS NOT NULL
          AND REFERENCED_COLUMN_NAME = 'id'
    ");

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Orden: tablas referenciadas antes que las que dependen de ellas.
 *
 * @param list<string> $tables
 * @param list<array{table: string, column: string, ref_table: string, ref_column: string}> $fks
 * @return list<string>
 */
function sortTablesForResequence(array $tables, array $fks): array
{
    $set = array_fill_keys($tables, true);
    /** @var array<string, list<string>> $dependents */
    $dependents = [];
    /** @var array<string, int> $inDegree */
    $inDegree = array_fill_keys($tables, 0);

    foreach ($tables as $t) {
        $dependents[$t] = [];
    }

    foreach ($fks as $fk) {
        $parent = $fk['ref_table'];
        $child = $fk['table'];
        if (!isset($set[$parent]) || !isset($set[$child]) || $parent === $child) {
            continue;
        }
        $dependents[$parent][] = $child;
        $inDegree[$child]++;
    }

    $queue = [];
    foreach ($inDegree as $t => $deg) {
        if ($deg === 0) {
            $queue[] = $t;
        }
    }

    $sorted = [];
    while ($queue !== []) {
        $t = array_shift($queue);
        $sorted[] = $t;
        foreach ($dependents[$t] as $child) {
            $inDegree[$child]--;
            if ($inDegree[$child] === 0) {
                $queue[] = $child;
            }
        }
    }

    if (count($sorted) !== count($tables)) {
        foreach ($tables as $t) {
            if (!in_array($t, $sorted, true)) {
                $sorted[] = $t;
            }
        }
    }

    return $sorted;
}

/**
 * @return array{skip: bool, rows: int, gaps: bool, max: int, min: int}|null
 */
function tableStats(PDO $pdo, string $table): ?array
{
    $row = $pdo->query("SELECT COUNT(*) AS c, MIN(id) AS mi, MAX(id) AS ma FROM `{$table}`")->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int)$row['c'] === 0) {
        return null;
    }

    $count = (int)$row['c'];
    $min = (int)$row['mi'];
    $max = (int)$row['ma'];
    $gaps = ($min !== 1 || $max !== $count);

    return [
        'skip' => !$gaps,
        'rows' => $count,
        'gaps' => $gaps,
        'max' => $max,
        'min' => $min,
    ];
}

/**
 * @return array<int, int> old_id => new_id
 */
function buildIdMap(PDO $pdo, string $table): array
{
    $ids = $pdo->query("SELECT id FROM `{$table}` ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
    $map = [];
    $n = 1;
    foreach ($ids as $id) {
        $map[(int)$id] = $n++;
    }

    return $map;
}

/**
 * @param array<int, int> $map
 * @param list<array{table: string, column: string, ref_table: string, ref_column: string}> $fks
 */
function resequenceOneTable(PDO $pdo, string $table, array $map, array $fks, bool $execute): void
{
    $pdo->exec('DROP TEMPORARY TABLE IF EXISTS `_reseq_map`');
    $pdo->exec('CREATE TEMPORARY TABLE `_reseq_map` (`old_id` BIGINT NOT NULL PRIMARY KEY, `new_id` BIGINT NOT NULL)');

    $ins = $pdo->prepare('INSERT INTO `_reseq_map` (`old_id`, `new_id`) VALUES (?, ?)');
    foreach ($map as $old => $new) {
        $ins->execute([$old, $new]);
    }

    $incoming = array_filter($fks, static fn ($fk) => $fk['ref_table'] === $table);

    foreach ($incoming as $fk) {
        $sql = "UPDATE `{$fk['table']}` c
                INNER JOIN `_reseq_map` m ON c.`{$fk['column']}` = m.old_id
                SET c.`{$fk['column']}` = m.new_id
                WHERE c.`{$fk['column']}` IS NOT NULL";
        if ($execute) {
            $pdo->exec($sql);
        }
    }

    if ($execute) {
        $maxOld = (int)max(array_keys($map));
        $offset = $maxOld + 1000000;
        $pdo->exec("UPDATE `{$table}` SET id = id + {$offset}");
        $pdo->exec("UPDATE `{$table}` t INNER JOIN `_reseq_map` m ON t.id = m.old_id + {$offset} SET t.id = m.new_id");
        $next = count($map) + 1;
        $pdo->exec("ALTER TABLE `{$table}` AUTO_INCREMENT = {$next}");
    }

    $pdo->exec('DROP TEMPORARY TABLE IF EXISTS `_reseq_map`');
}

// --- main ---

$tables = tablesWithIdColumn($pdo);
$fks = foreignKeys($pdo);
$order = sortTablesForResequence($tables, $fks);

echo $execute ? "MODO: EJECUCIÓN\n" : "MODO: SIMULACIÓN (use --execute para aplicar)\n";
echo 'Tablas con id: ' . count($tables) . "\n\n";

$toProcess = [];
foreach ($order as $table) {
    $stats = tableStats($pdo, $table);
    if ($stats === null) {
        continue;
    }
    if ($stats['skip']) {
        echo "[OK] {$table}: ya consecutivo 1..{$stats['rows']}\n";
        continue;
    }
    $toProcess[] = $table;
    echo "[PLAN] {$table}: {$stats['rows']} filas, id {$stats['min']}..{$stats['max']} -> 1..{$stats['rows']}\n";
}

if ($toProcess === []) {
    echo "\nNada que reordenar.\n";
    exit(0);
}

if (!$execute) {
    echo "\n" . count($toProcess) . ' tablas pendientes. Ejecute con --execute.' . "\n";
    exit(0);
}

echo "\nAplicando cambios...\n";
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

try {
    foreach ($order as $table) {
        $stats = tableStats($pdo, $table);
        if ($stats === null || $stats['skip']) {
            continue;
        }

        $map = buildIdMap($pdo, $table);
        resequenceOneTable($pdo, $table, $map, $fks, true);
        echo "[DONE] {$table}\n";
    }

    echo "\nCompletado.\n";
} catch (Throwable $e) {
    echo "\nERROR: " . $e->getMessage() . "\n";
    exit(1);
} finally {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}
