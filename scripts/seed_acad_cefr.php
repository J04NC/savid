<?php

declare(strict_types=1);

/**
 * Carga config.example/acad_cefr_a1_a2_seed.json en acad_level / acad_skill /
 * acad_cefr_descriptor para una empresa, y crea los 3 tipos de ejercicio por
 * defecto (uno por destreza). Idempotente (ON DUPLICATE KEY UPDATE).
 *
 * Uso: php scripts/seed_acad_cefr.php <empresa_id>
 */

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/config/Database.php';

$empresaId = (int)($argv[1] ?? 0);
if ($empresaId <= 0) {
    fwrite(STDERR, "Uso: php scripts/seed_acad_cefr.php <empresa_id>\n");
    exit(1);
}

$pdo = (new Database())->connect();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$stmt = $pdo->prepare('SELECT COUNT(*) FROM empresa WHERE id = ?');
$stmt->execute([$empresaId]);
if ((int)$stmt->fetchColumn() === 0) {
    fwrite(STDERR, "Empresa {$empresaId} no existe.\n");
    exit(1);
}

$seedPath = BASE_PATH . '/config.example/acad_cefr_a1_a2_seed.json';
$json = json_decode((string)file_get_contents($seedPath), true);
if (!is_array($json)) {
    fwrite(STDERR, "No se pudo leer/parsear {$seedPath}\n");
    exit(1);
}

$pdo->beginTransaction();

try {
    $insertLevel = $pdo->prepare('
        INSERT INTO acad_level (empresa_id, codigo, nombre_en, orden, estado_id, created_at)
        VALUES (:empresa_id, :codigo, :nombre_en, :orden, 1, NOW(3))
        ON DUPLICATE KEY UPDATE nombre_en = VALUES(nombre_en), orden = VALUES(orden)
    ');
    foreach ($json['levels'] ?? [] as $level) {
        $insertLevel->execute([
            'empresa_id' => $empresaId,
            'codigo' => strtoupper((string)$level['code']),
            'nombre_en' => (string)$level['name_en'],
            'orden' => (int)$level['order'],
        ]);
    }
    echo 'OK: acad_level (' . count($json['levels'] ?? []) . ")\n";

    $insertSkill = $pdo->prepare('
        INSERT INTO acad_skill (empresa_id, codigo, nombre_en, orden, estado_id, created_at)
        VALUES (:empresa_id, :codigo, :nombre_en, :orden, 1, NOW(3))
        ON DUPLICATE KEY UPDATE nombre_en = VALUES(nombre_en), orden = VALUES(orden)
    ');
    foreach ($json['skills'] ?? [] as $skill) {
        $insertSkill->execute([
            'empresa_id' => $empresaId,
            'codigo' => strtoupper((string)$skill['code']),
            'nombre_en' => (string)$skill['name_en'],
            'orden' => (int)$skill['order'],
        ]);
    }
    echo 'OK: acad_skill (' . count($json['skills'] ?? []) . ")\n";

    $levelIdByCode = [];
    $stmt = $pdo->prepare('SELECT id, codigo FROM acad_level WHERE empresa_id = ?');
    $stmt->execute([$empresaId]);
    foreach ($stmt->fetchAll() as $row) {
        $levelIdByCode[$row['codigo']] = (int)$row['id'];
    }

    $skillIdByCode = [];
    $stmt = $pdo->prepare('SELECT id, codigo FROM acad_skill WHERE empresa_id = ?');
    $stmt->execute([$empresaId]);
    foreach ($stmt->fetchAll() as $row) {
        $skillIdByCode[$row['codigo']] = (int)$row['id'];
    }

    $descriptors = $json['descriptors'] ?? [];
    if (is_array($descriptors) && $descriptors !== [] && isset($descriptors[0])) {
        $insertDescriptor = $pdo->prepare('
            INSERT INTO acad_cefr_descriptor
                (empresa_id, level_id, skill_id, codigo, descriptor_en, orden, estado_id, created_at)
            VALUES (:empresa_id, :level_id, :skill_id, :codigo, :descriptor_en, :orden, 1, NOW(3))
            ON DUPLICATE KEY UPDATE
                level_id = VALUES(level_id),
                skill_id = VALUES(skill_id),
                descriptor_en = VALUES(descriptor_en),
                orden = VALUES(orden)
        ');
        $count = 0;
        foreach ($descriptors as $descriptor) {
            $levelCode = strtoupper((string)($descriptor['level'] ?? ''));
            $skillCode = strtoupper((string)($descriptor['skill'] ?? ''));
            if (!isset($levelIdByCode[$levelCode], $skillIdByCode[$skillCode])) {
                fwrite(STDERR, "Descriptor {$descriptor['code']} ignorado: nivel/skill no encontrado.\n");
                continue;
            }
            $insertDescriptor->execute([
                'empresa_id' => $empresaId,
                'level_id' => $levelIdByCode[$levelCode],
                'skill_id' => $skillIdByCode[$skillCode],
                'codigo' => strtoupper((string)$descriptor['code']),
                'descriptor_en' => (string)$descriptor['descriptor_en'],
                'orden' => (int)$descriptor['order'],
            ]);
            $count++;
        }
        echo "OK: acad_cefr_descriptor ({$count})\n";
    } else {
        echo "Aviso: no hay 'descriptors' como lista en el seed JSON (revisar formato).\n";
    }

    $exerciseTypes = [
        ['codigo' => 'MULTIPLE_CHOICE', 'nombre_en' => 'Multiple choice', 'skill' => 'READING', 'orden' => 10],
        ['codigo' => 'OPEN_TEXT', 'nombre_en' => 'Open text answer', 'skill' => 'WRITING', 'orden' => 20],
        ['codigo' => 'AUDIO_RESPONSE', 'nombre_en' => 'Audio response', 'skill' => 'SPEAKING', 'orden' => 30],
    ];
    $insertType = $pdo->prepare('
        INSERT INTO acad_exercise_type (empresa_id, codigo, nombre_en, skill_id, orden, estado_id, created_at)
        VALUES (:empresa_id, :codigo, :nombre_en, :skill_id, :orden, 1, NOW(3))
        ON DUPLICATE KEY UPDATE nombre_en = VALUES(nombre_en), skill_id = VALUES(skill_id), orden = VALUES(orden)
    ');
    foreach ($exerciseTypes as $type) {
        $insertType->execute([
            'empresa_id' => $empresaId,
            'codigo' => $type['codigo'],
            'nombre_en' => $type['nombre_en'],
            'skill_id' => $skillIdByCode[$type['skill']] ?? null,
            'orden' => $type['orden'],
        ]);
    }
    echo 'OK: acad_exercise_type (' . count($exerciseTypes) . ")\n";

    $pdo->commit();
    echo "Seed CEFR A1/A2 completado para empresa {$empresaId}.\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
