<?php

declare(strict_types=1);

require __DIR__ . '/../config/Database.php';

$empresaId = isset($argv[1]) ? (int)$argv[1] : 1;

$pdo = (new Database())->connect();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function normalizeCode(string $raw): string
{
    return strtoupper(str_replace(' ', '', trim($raw)));
}

function parseTaCode(string $code): ?array
{
    $code = normalizeCode($code);
    if (!str_starts_with($code, 'PN-TA')) {
        return null;
    }

    if (preg_match('/^PN-TA([A-Z]{1,4})-?(\d+)-F(\d+)$/', $code, $m)) {
        return [
            'is_child_f' => true,
            'seccion' => $m[1],
            'consecutivo' => $m[2],
            'f_consecutivo' => $m[3],
            'raiz_codigo' => 'PN-TA' . $m[1] . $m[2],
        ];
    }

    if (preg_match('/^PN-TA([A-Z]{1,4})-?(\d+)$/', $code, $m)) {
        return [
            'is_child_f' => false,
            'seccion' => $m[1],
            'consecutivo' => $m[2],
            'f_consecutivo' => null,
            'raiz_codigo' => $code,
        ];
    }

    return null;
}

$pdo->beginTransaction();
try {
    $stmtTa = $pdo->prepare("
        SELECT id
        FROM sgd_tipo_documental
        WHERE empresa_id = ? AND UPPER(TRIM(codigo)) = 'TA' AND deleted_at IS NULL
        LIMIT 1
    ");
    $stmtTa->execute([$empresaId]);
    $taTipoId = (int)$stmtTa->fetchColumn();
    if ($taTipoId <= 0) {
        throw new RuntimeException('No existe tipo documental TA para la empresa ' . $empresaId);
    }

    $stmtF = $pdo->prepare("
        SELECT id
        FROM sgd_tipo_documental
        WHERE empresa_id = ? AND UPPER(TRIM(codigo)) = 'F' AND deleted_at IS NULL
        LIMIT 1
    ");
    $stmtF->execute([$empresaId]);
    $fTipoId = (int)$stmtF->fetchColumn();
    if ($fTipoId <= 0) {
        throw new RuntimeException('No existe tipo documental F para la empresa ' . $empresaId);
    }

    $secRows = $pdo->prepare("
        SELECT id, UPPER(TRIM(codigo)) AS codigo
        FROM sgd_linea_documental
        WHERE empresa_id = ? AND deleted_at IS NULL
    ");
    $secRows->execute([$empresaId]);
    $secByCode = [];
    foreach ($secRows->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $secByCode[$r['codigo']] = (int)$r['id'];
    }

    $rowsStmt = $pdo->prepare("
        SELECT id, proceso_id, documento_id, consecutivo
        FROM sgd_documento
        WHERE empresa_id = ?
          AND deleted_at IS NULL
          AND UPPER(REPLACE(consecutivo, ' ', '')) LIKE 'PN-TA%'
        ORDER BY id
    ");
    $rowsStmt->execute([$empresaId]);
    $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

    $procesoPnStmt = $pdo->prepare("
        SELECT id
        FROM sgd_proceso
        WHERE empresa_id = ? AND UPPER(TRIM(codigo)) = 'PN' AND deleted_at IS NULL
        LIMIT 1
    ");
    $procesoPnStmt->execute([$empresaId]);
    $pnProcesoId = (int)$procesoPnStmt->fetchColumn();
    if ($pnProcesoId <= 0) {
        throw new RuntimeException('No existe proceso PN para empresa ' . $empresaId);
    }

    $rootIdByCode = [];
    $updatedRoots = 0;
    $updatedChildren = 0;
    $skipped = 0;

    $updateRootStmt = $pdo->prepare("
        UPDATE sgd_documento
        SET proceso_id = ?, tipo_documental_id = ?, linea_documental_id = ?, documento_id = NULL, consecutivo = ?, updated_at = NOW(3)
        WHERE id = ?
    ");
    $insertSecStmt = $pdo->prepare("
        INSERT INTO sgd_linea_documental (empresa_id, codigo, nombre, orden, estado_id, created_at)
        VALUES (?, ?, ?, 950, 1, NOW(3))
    ");
    $updateChildStmt = $pdo->prepare("
        UPDATE sgd_documento
        SET proceso_id = ?, tipo_documental_id = ?, documento_id = ?, consecutivo = ?, updated_at = NOW(3)
        WHERE id = ?
    ");

    foreach ($rows as $row) {
        $parsed = parseTaCode((string)$row['consecutivo']);
        if ($parsed === null || $parsed['is_child_f']) {
            continue;
        }
        $secCode = strtoupper((string)$parsed['seccion']);
        if (!isset($secByCode[$secCode])) {
            $insertSecStmt->execute([
                $empresaId,
                $secCode,
                'Linea TA importada ' . $secCode,
            ]);
            $secByCode[$secCode] = (int)$pdo->lastInsertId();
        }

        $updateRootStmt->execute([
            $pnProcesoId,
            $taTipoId,
            $secByCode[$secCode],
            (string)$parsed['consecutivo'],
            (int)$row['id'],
        ]);
        $updatedRoots++;
        $rootIdByCode[$parsed['raiz_codigo']] = (int)$row['id'];
    }

    foreach ($rows as $row) {
        $parsed = parseTaCode((string)$row['consecutivo']);
        if ($parsed === null || !$parsed['is_child_f']) {
            continue;
        }
        $parentId = $rootIdByCode[$parsed['raiz_codigo']] ?? null;
        if (!$parentId) {
            $skipped++;
            continue;
        }

        $updateChildStmt->execute([
            $pnProcesoId,
            $fTipoId,
            $parentId,
            'F' . $parsed['f_consecutivo'],
            (int)$row['id'],
        ]);
        $updatedChildren++;
    }

    $pdo->commit();

    echo json_encode([
        'empresa_id' => $empresaId,
        'total_ta_detectados' => count($rows),
        'raices_actualizadas' => $updatedRoots,
        'hijos_f_actualizados' => $updatedChildren,
        'omitidos' => $skipped,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
