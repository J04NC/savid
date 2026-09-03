<?php

declare(strict_types=1);

/**
 * Carga contenido de currículo Academic (acad_module/unit/lesson/exercise) desde
 * archivos JSON de staging generados manualmente por revisión humana (ver
 * docs/academic/staging/*.json). No es específico de ningún libro: cualquier
 * archivo de staging con el mismo formato puede cargarse (p.ej. un "Beginner 2"
 * futuro), por eso las rutas y datos vienen todos por parámetro/archivo, nunca
 * hardcodeados aquí.
 *
 * Idempotente: unidades/lecciones/ejercicios se resuelven por
 * (padre, titulo_en) — si ya existen se actualizan, si no se insertan. Las
 * opciones de un ejercicio MULTIPLE_CHOICE se reemplazan por completo en cada
 * corrida. El audio de referencia solo se sube si el ejercicio no tiene ya uno
 * (no se re-sube en corridas repetidas).
 *
 * Uso: php scripts/seed_acad_from_staging.php <audio_base_dir> <staging1.json> [<staging2.json> ...]
 *   audio_base_dir: carpeta que contiene las subcarpetas "Beginner 1 unit N mp3"
 *                    (resultado de descomprimir el zip de audios del libro).
 */

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/core/AppBootstrap.php';
AppBootstrap::initCore();

$audioBaseDir = rtrim((string)($argv[1] ?? ''), '/');
$stagingFiles = array_slice($argv, 2);

if ($audioBaseDir === '' || $stagingFiles === []) {
    fwrite(STDERR, "Uso: php scripts/seed_acad_from_staging.php <audio_base_dir> <staging1.json> [<staging2.json> ...]\n");
    exit(1);
}

$pdo = (new Database())->connect();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$repo = new AcadRepository($pdo);
$storage = StorageService::instance();

$report = [
    'modules_updated' => 0,
    'units_inserted' => 0, 'units_updated' => 0,
    'lessons_inserted' => 0, 'lessons_updated' => 0,
    'exercises_inserted' => 0, 'exercises_updated' => 0,
    'options_written' => 0,
    'audio_uploaded' => 0, 'audio_skipped_existing' => 0, 'audio_missing_source' => 0,
    'demo_exercises_archived' => 0,
];
$warnings = [];

function resolveCatalogId(PDO $pdo, string $table, int $empresaId, string $codigo): ?int
{
    $stmt = $pdo->prepare("SELECT id FROM `{$table}` WHERE empresa_id = ? AND codigo = ? AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([$empresaId, $codigo]);
    $id = $stmt->fetchColumn();

    return $id !== false ? (int)$id : null;
}

function upsertByTitle(PDO $pdo, string $table, string $parentCol, int $parentId, int $empresaId, string $titulo, array $extraCols): array
{
    $stmt = $pdo->prepare("SELECT id FROM `{$table}` WHERE empresa_id = ? AND `{$parentCol}` = ? AND titulo_en = ? AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([$empresaId, $parentId, $titulo]);
    $existingId = $stmt->fetchColumn();

    $cols = array_merge(['empresa_id' => $empresaId, $parentCol => $parentId, 'titulo_en' => $titulo], $extraCols);

    if ($existingId !== false) {
        $sets = [];
        $vals = [];
        foreach ($cols as $c => $v) {
            $sets[] = "`{$c}` = ?";
            $vals[] = $v;
        }
        $vals[] = (int)$existingId;
        $pdo->prepare("UPDATE `{$table}` SET " . implode(', ', $sets) . ", updated_at = NOW(3) WHERE id = ?")->execute($vals);

        return [(int)$existingId, 'updated'];
    }

    $colNames = array_keys($cols);
    $placeholders = implode(', ', array_fill(0, count($colNames), '?'));
    $pdo->prepare(
        "INSERT INTO `{$table}` (`" . implode('`, `', $colNames) . "`, created_at) VALUES ({$placeholders}, NOW(3))"
    )->execute(array_values($cols));

    return [(int)$pdo->lastInsertId(), 'inserted'];
}

foreach ($stagingFiles as $stagingFile) {
    if (!is_readable($stagingFile)) {
        fwrite(STDERR, "No se puede leer {$stagingFile}\n");
        exit(1);
    }
    $data = json_decode((string)file_get_contents($stagingFile), true);
    if (!is_array($data)) {
        fwrite(STDERR, "JSON invalido en {$stagingFile}\n");
        exit(1);
    }

    $empresaId = (int)$data['empresa_id'];
    $levelCodigo = (string)$data['level_codigo'];
    $levelId = resolveCatalogId($pdo, 'acad_level', $empresaId, $levelCodigo);
    if ($levelId === null) {
        fwrite(STDERR, "Nivel '{$levelCodigo}' no existe para empresa {$empresaId} (archivo {$stagingFile})\n");
        exit(1);
    }

    $pdo->beginTransaction();

    try {
        // --- Modulo (solo presente en el primer archivo de un libro) ---
        $moduleId = null;
        if (isset($data['module'])) {
            $m = $data['module'];
            if (($m['action'] ?? '') === 'rename_existing') {
                $moduleId = (int)$m['existing_id'];
                $pdo->prepare('UPDATE acad_module SET titulo_en = ?, level_id = ?, updated_at = NOW(3) WHERE id = ? AND empresa_id = ?')
                    ->execute([$m['titulo_en'], $levelId, $moduleId, $empresaId]);
                $report['modules_updated']++;
            }
        }
        if ($moduleId === null) {
            $stmt = $pdo->prepare('SELECT id FROM acad_module WHERE empresa_id = ? AND level_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 1');
            $stmt->execute([$empresaId, $levelId]);
            $found = $stmt->fetchColumn();
            if ($found === false) {
                throw new RuntimeException("No hay acad_module para empresa {$empresaId} / nivel {$levelCodigo}; el primer archivo de staging del libro debe traer la clave 'module'.");
            }
            $moduleId = (int)$found;
        }

        // --- Unidad: acepta "units":[...] (formato archivo 1) o "unit":{...} (formato archivos 2-4) ---
        $unitDef = $data['unit'] ?? ($data['units'][0] ?? null);
        if ($unitDef === null) {
            throw new RuntimeException("Archivo {$stagingFile} no trae 'unit' ni 'units'.");
        }

        if (($unitDef['action'] ?? '') === 'rename_existing') {
            $unitId = (int)$unitDef['existing_id'];
            $pdo->prepare('UPDATE acad_unit SET titulo_en = ?, module_id = ?, orden = ?, updated_at = NOW(3) WHERE id = ? AND empresa_id = ?')
                ->execute([$unitDef['titulo_en'], $moduleId, (int)$unitDef['orden'], $unitId, $empresaId]);
            $report['units_updated']++;
        } else {
            [$unitId, $op] = upsertByTitle($pdo, 'acad_unit', 'module_id', $moduleId, $empresaId, $unitDef['titulo_en'], [
                'orden' => (int)$unitDef['orden'],
                'estado_id' => 1,
            ]);
            $report['units_' . $op]++;
        }

        foreach ($unitDef['lessons'] as $lessonDef) {
            $notas = $lessonDef['notas'] ?? null;
            if ($notas !== null && mb_strlen($notas) > 500) {
                $notas = mb_substr($notas, 0, 497) . '...';
            }

            if (($lessonDef['action'] ?? '') === 'rename_existing') {
                $lessonId = (int)$lessonDef['existing_id'];
                $pdo->prepare('UPDATE acad_lesson SET titulo_en = ?, unit_id = ?, orden = ?, notas = ?, updated_at = NOW(3) WHERE id = ? AND empresa_id = ?')
                    ->execute([$lessonDef['titulo_en'], $unitId, (int)$lessonDef['orden'], $notas, $lessonId, $empresaId]);
                $report['lessons_updated']++;

                // Los ejercicios de ejemplo del registro demo no corresponden
                // al contenido real del libro: se archivan (soft delete) en
                // vez de mezclarlos con el contenido nuevo. Solo se archiva lo
                // que NO está en la lista de ejercicios que se va a (re)cargar
                // ahora, para que esto sea seguro de repetir en corridas futuras
                // (si no, cada corrida archivaría el contenido real recién
                // insertado y lo duplicaría).
                $incomingTitles = array_column($lessonDef['exercises'] ?? [], 'titulo_en');
                $existingEx = $pdo->prepare('SELECT id, titulo_en FROM acad_exercise WHERE lesson_id = ? AND empresa_id = ? AND deleted_at IS NULL');
                $existingEx->execute([$lessonId, $empresaId]);
                foreach ($existingEx->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    if (!in_array($row['titulo_en'], $incomingTitles, true)) {
                        $pdo->prepare('UPDATE acad_exercise SET deleted_at = NOW(3) WHERE id = ?')->execute([$row['id']]);
                        $report['demo_exercises_archived']++;
                    }
                }
            } else {
                [$lessonId, $op] = upsertByTitle($pdo, 'acad_lesson', 'unit_id', $unitId, $empresaId, $lessonDef['titulo_en'], [
                    'orden' => (int)$lessonDef['orden'],
                    'notas' => $notas,
                    'estado_id' => 1,
                ]);
                $report['lessons_' . $op]++;
            }

            foreach (($lessonDef['exercises'] ?? []) as $exDef) {
                $skillId = resolveCatalogId($pdo, 'acad_skill', $empresaId, $exDef['skill_codigo']);
                $typeId = resolveCatalogId($pdo, 'acad_exercise_type', $empresaId, $exDef['exercise_type_codigo']);
                if ($skillId === null || $typeId === null) {
                    throw new RuntimeException("Skill '{$exDef['skill_codigo']}' o tipo '{$exDef['exercise_type_codigo']}' no existe para empresa {$empresaId}.");
                }

                $stmt = $pdo->prepare('SELECT id, audio_referencia_ruta FROM acad_exercise WHERE empresa_id = ? AND lesson_id = ? AND titulo_en = ? AND deleted_at IS NULL LIMIT 1');
                $stmt->execute([$empresaId, $lessonId, $exDef['titulo_en']]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($existing) {
                    $exerciseId = (int)$existing['id'];
                    $pdo->prepare('
                        UPDATE acad_exercise
                        SET skill_id = ?, exercise_type_id = ?, prompt_en = ?, max_score = 100, orden = ?, updated_at = NOW(3)
                        WHERE id = ?
                    ')->execute([$skillId, $typeId, $exDef['prompt_en'], (int)$exDef['orden'], $exerciseId]);
                    $report['exercises_updated']++;
                    $currentAudio = $existing['audio_referencia_ruta'];
                } else {
                    $pdo->prepare('
                        INSERT INTO acad_exercise
                            (empresa_id, lesson_id, skill_id, exercise_type_id, titulo_en, prompt_en, max_score, orden, estado_id, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, 100, ?, 1, NOW(3))
                    ')->execute([$empresaId, $lessonId, $skillId, $typeId, $exDef['titulo_en'], $exDef['prompt_en'], (int)$exDef['orden']]);
                    $exerciseId = (int)$pdo->lastInsertId();
                    $report['exercises_inserted']++;
                    $currentAudio = null;
                }

                if (($exDef['exercise_type_codigo'] ?? '') === 'MULTIPLE_CHOICE' && !empty($exDef['options'])) {
                    $repo->deleteOptionsByExercise($exerciseId);
                    $optOrden = 10;
                    foreach ($exDef['options'] as $opt) {
                        $repo->insertOption($exerciseId, $opt['texto_en'], (bool)$opt['es_correcta'], $optOrden);
                        $optOrden += 10;
                        $report['options_written']++;
                    }
                }

                if (!empty($exDef['audio_file'])) {
                    if (!empty($currentAudio)) {
                        $report['audio_skipped_existing']++;
                    } else {
                        $srcPath = $audioBaseDir . '/' . $unitDef['audio_zip_folder'] . '/' . $exDef['audio_file'];
                        if (!is_readable($srcPath)) {
                            $report['audio_missing_source']++;
                            $warnings[] = "Audio no encontrado: {$srcPath} (ejercicio '{$exDef['titulo_en']}')";
                        } else {
                            $ext = pathinfo($srcPath, PATHINFO_EXTENSION) ?: 'mp3';
                            $storedName = 'ref_' . bin2hex(random_bytes(8)) . '.' . $ext;
                            $refPath = $storage->putLocalFile(
                                StorageService::ZONE_ACAD_MEDIA,
                                $storedName,
                                $srcPath,
                                false,
                                $empresaId,
                                $exerciseId
                            );
                            if ($refPath === null) {
                                $warnings[] = "No se pudo copiar audio para ejercicio '{$exDef['titulo_en']}' ({$srcPath})";
                            } else {
                                $repo->setExerciseAudioReferencia($empresaId, $exerciseId, $refPath);
                                $report['audio_uploaded']++;
                            }
                        }
                    }
                }
            }
        }

        $pdo->commit();
        fwrite(STDOUT, "OK: " . basename($stagingFile) . "\n");
    } catch (Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, "FALLO en {$stagingFile}: " . $e->getMessage() . "\n");
        exit(1);
    }
}

fwrite(STDOUT, "\n=== Reporte final ===\n");
foreach ($report as $k => $v) {
    fwrite(STDOUT, str_pad($k, 28) . ": {$v}\n");
}
if ($warnings !== []) {
    fwrite(STDOUT, "\n=== Advertencias (" . count($warnings) . ") ===\n");
    foreach ($warnings as $w) {
        fwrite(STDOUT, " - {$w}\n");
    }
}
