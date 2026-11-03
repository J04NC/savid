<?php
/**
 * Uso único: mueve las grabaciones de voz de estudiantes que quedaron en la
 * carpeta pública (/uploads/acad/...) a la zona privada acad_attempts y
 * actualiza acad_attempt.audio_ruta. Después de esto solo se escuchan vía
 * ?url=acad/audioIntento/ID (con permiso).
 *
 * Los archivos originales pertenecen al usuario del servidor web (nginx,
 * modo 0600), así que hay que correrlo con ese usuario:
 *
 *   sudo -u nginx php scripts/migrate_acad_attempt_audio_private.php            (simulación)
 *   sudo -u nginx php scripts/migrate_acad_attempt_audio_private.php --aplicar  (mueve de verdad)
 *
 * Seguro de repetir: solo toca filas cuya ruta aún empieza por /uploads/acad/.
 * El original se borra únicamente después de comprobar que la copia privada
 * tiene el mismo contenido y que la BD quedó actualizada.
 */

require_once dirname(__DIR__) . '/core/AppBootstrap.php';
AppBootstrap::initCore();

$aplicar = in_array('--aplicar', $argv ?? [], true);
$pdo = (new Database())->connect();
$storage = StorageService::instance();

$filas = $pdo->query(
    "SELECT id, empresa_id, exercise_id, usuario_id, audio_ruta
     FROM acad_attempt
     WHERE audio_ruta LIKE '/uploads/acad/%'"
)->fetchAll(PDO::FETCH_ASSOC);

echo ($aplicar ? 'APLICANDO' : 'SIMULACIÓN (usa --aplicar para mover)') . ' — ' . count($filas) . " grabación(es) pública(s)\n";

$upd = $pdo->prepare('UPDATE acad_attempt SET audio_ruta = ? WHERE id = ?');
$movidas = 0;
$fallos = 0;

foreach ($filas as $f) {
    $id = (int)$f['id'];
    $contenido = $storage->get((string)$f['audio_ruta']);
    if ($contenido === null) {
        echo "  intento #{$id}: no se pudo leer el archivo (¿permisos? ¿falta?) — se omite\n";
        $fallos++;
        continue;
    }

    $nombre = basename((string)$f['audio_ruta']);
    $key = $storage->key(StorageService::ZONE_ACAD_ATTEMPTS, $nombre, (int)$f['empresa_id'], (int)$f['exercise_id'], (int)$f['usuario_id']);

    if (!$aplicar) {
        echo "  intento #{$id}: " . strlen($contenido) . " bytes → {$key}\n";
        continue;
    }

    $ref = $storage->putContents($key, $contenido);
    if ($storage->get($key) !== $contenido) {
        echo "  intento #{$id}: la copia privada no coincide — se conserva el original\n";
        $fallos++;
        continue;
    }

    $upd->execute([$ref, $id]);
    $storage->delete((string)$f['audio_ruta']);
    echo "  intento #{$id}: movido a {$key}\n";
    $movidas++;
}

echo "Listo. Movidas: {$movidas}. Con problema: {$fallos}.\n";
exit($fallos > 0 ? 1 : 0);
