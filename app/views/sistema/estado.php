<?php
/** @var array $health */
/** @var list<array{nombre: string, fecha: int, tamanoMb: float}> $backups */

$checkLabels = [
    'app' => 'Aplicación',
    'database' => 'Base de datos (MySQL)',
    'storage' => 'Almacenamiento de archivos',
    'session' => 'Sesiones',
    'redis' => 'Redis',
];
?>

<div class="module-container estado-sistema-page">

    <div class="crud-toolbar seguridad-toolbar">
        <div class="seguridad-toolbar-title">
            <h2 class="seguridad-title">Estado del sistema</h2>
            <p class="seguridad-subtitle">Verificación en vivo de los componentes críticos y de los backups de base de datos.</p>
        </div>
    </div>

    <div class="seguridad-stats-grid">
        <?php foreach ($health['checks'] as $key => $check): ?>
            <div class="seguridad-stat-card <?= empty($check['ok']) ? 'seguridad-stat-card--alerta' : '' ?>">
                <div class="seguridad-stat-value"><?= !empty($check['ok']) ? '✅' : '⚠️' ?></div>
                <div class="seguridad-stat-label"><?= htmlspecialchars($checkLabels[$key] ?? $key, ENT_QUOTES, 'UTF-8') ?></div>
                <div class="seguridad-stat-detail">
                    <?= htmlspecialchars((string)($check['message'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                    <?php if (!empty($check['driver'])): ?>
                        (<?= htmlspecialchars((string)$check['driver'], ENT_QUOTES, 'UTF-8') ?>)
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="seguridad-panels">
        <div class="seguridad-panel seguridad-panel-wide">
            <div class="estado-backup-header">
                <h3 class="seguridad-panel-title">Backups de base de datos</h3>
                <button type="button" id="btnGenerarBackup" class="auditoria-btn-secondary" title="Genera un backup ahora mismo (útil si no hay cron configurado en este servidor)">
                    💾 Generar backup ahora
                </button>
            </div>
            <p id="estadoBackupStatus" class="estado-backup-status" aria-live="polite"></p>

            <?php if (empty($backups)): ?>
                <p class="seguridad-panel-empty" id="estadoBackupEmpty">⚠️ Nunca se ha generado un backup en este servidor.</p>
            <?php else: ?>
                <table class="seguridad-table no-datatable" id="estadoBackupTable">
                    <thead>
                        <tr>
                            <th>Archivo</th>
                            <th>Fecha</th>
                            <th>Tamaño</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($backups as $b): ?>
                            <tr>
                                <td><code><?= htmlspecialchars($b['nombre'], ENT_QUOTES, 'UTF-8') ?></code></td>
                                <td><?= htmlspecialchars(date('d/m/Y H:i', $b['fecha']), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars(number_format($b['tamanoMb'], 2), ENT_QUOTES, 'UTF-8') ?>&nbsp;MB</td>
                                <td>
                                    <a href="?url=sistema/backupDescargar&archivo=<?= urlencode($b['nombre']) ?>" class="auditoria-btn-secondary">⬇️ Descargar</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

</div>

<script>
(function () {
    const btn = document.getElementById('btnGenerarBackup');
    const status = document.getElementById('estadoBackupStatus');
    if (!btn) return;

    btn.addEventListener('click', function () {
        btn.disabled = true;
        const original = btn.innerHTML;
        btn.innerHTML = '⏳ Generando… puede tardar unos segundos';
        status.textContent = '';

        fetch('?url=sistema/backupGenerar', { method: 'POST', credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                btn.disabled = false;
                btn.innerHTML = original;
                if (data.ok) {
                    status.textContent = '✅ ' + data.message;
                    location.reload();
                } else {
                    status.textContent = '❌ ' + (data.error || 'No se pudo generar el backup.');
                }
            })
            .catch(function () {
                btn.disabled = false;
                btn.innerHTML = original;
                status.textContent = '❌ Error de conexión.';
            });
    });
})();
</script>
