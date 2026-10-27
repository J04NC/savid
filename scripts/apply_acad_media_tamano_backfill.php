<?php
/**
 * Rellena tamano_bytes en acad_exercise_reference_audio y acad_attempt para
 * las filas existentes (creadas antes de que esa columna existiera),
 * leyendo el tamaño real del archivo vía StorageService. Uso único, tras
 * aplicar database/migrations/20261023_acad_media_tamano_bytes.sql.
 *
 * Uso: php scripts/apply_acad_media_tamano_backfill.php
 */

require_once dirname(__DIR__) . '/core/AppBootstrap.php';
AppBootstrap::initCore();

$database = new Database();
$pdo = $database->connect();
$storage = StorageService::instance();

function backfillTabla(PDO $pdo, StorageService $storage, string $tabla, string $columnaRuta): void
{
    $stmt = $pdo->query("SELECT id, {$columnaRuta} AS ruta FROM {$tabla} WHERE tamano_bytes IS NULL AND {$columnaRuta} IS NOT NULL");
    $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $actualizadas = 0;
    $sinArchivo = 0;

    foreach ($filas as $fila) {
        $localPath = $storage->localPath($fila['ruta']);
        if ($localPath === null || !is_file($localPath)) {
            $sinArchivo++;
            continue;
        }

        $tamano = filesize($localPath);
        if ($tamano === false) {
            $sinArchivo++;
            continue;
        }

        // Prepared statement nuevo en cada vuelta (no reusado): AuditingPDOStatement
        // reescribe el SQL para inyectar updated_at/updated_by la primera vez que
        // se ejecuta, y deja esa versión mutada en el objeto — reusar el mismo
        // statement en un loop hace fallar la 2a ejecución en adelante con
        // "Invalid parameter number" (2 valores atados contra 3 placeholders).
        $update = $pdo->prepare("UPDATE {$tabla} SET tamano_bytes = ? WHERE id = ?");
        $update->execute([$tamano, $fila['id']]);
        $actualizadas++;
    }

    echo "{$tabla}: {$actualizadas} actualizadas, {$sinArchivo} sin archivo en disco (de " . count($filas) . " candidatas)\n";
}

backfillTabla($pdo, $storage, 'acad_exercise_reference_audio', 'ruta');
backfillTabla($pdo, $storage, 'acad_attempt', 'audio_ruta');

echo "Listo.\n";
