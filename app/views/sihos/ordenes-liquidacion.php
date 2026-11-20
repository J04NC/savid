<?php
/** @var array $scope */
/** @var bool $configurado */
/** @var string $desde */
/** @var string $hasta */
/** @var string $tokenJob */
/** @var list<string> $actividadesSel */
/** @var list<string> $epsSel */
/** @var array<string,string> $administradoras CodiAdmi => NombAdmi */
/** @var ?array $reporte */
/** @var ?array $trabajo Cálculo en segundo plano en curso: token, estado, lineas, segundos */

$fmt = static fn (float $v): string => SihosCartera2193Service::formatoMoneda($v);
$num = static fn (float $v): string => number_format($v, 0, ',', '.');
$estados = SihosOrdenLiquidacionCruce::ESTADOS;
$h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
// Celda de la matriz: cantidad de ítems y, si hay valor liquidado, el valor.
$celda = static function (?array $v) use ($num, $fmt): string {
    if ($v === null) {
        return '<td style="text-align:right;color:#999;">—</td>';
    }

    return '<td style="text-align:right;">' . $num((float)$v[0]) . ($v[1] > 0 ? '<br><small>' . $fmt((float)$v[1]) . '</small>' : '') . '</td>';
};
?>

<div class="module-container auditoria-page">

    <div class="crud-toolbar auditoria-toolbar">
        <div class="auditoria-toolbar-title">
            <h2 class="auditoria-title">SIHOS — Órdenes vs liquidación</h2>
            <p class="auditoria-subtitle">Compara los ítems de orden médica del rango con lo liquidado en la misma admisión: qué se ordenó y se liquidó, qué quedó sin liquidar y qué se liquidó sin orden. Reporte de solo lectura; no incluye datos del paciente.</p>
        </div>
    </div>

    <?php $filterUrl = 'sihos/ordenesLiquidacion'; require BASE_PATH . '/app/views/sgd/_empresa_filter.php'; ?>
    <?php $empresaId = $scope['empresaId'] ?? null; /* _empresa_filter.php pisa $empresaId */ ?>

    <?php if ($empresaId === null): ?>
        <p class="field-note sgd-page-empty">Seleccione la empresa en el filtro superior para ver este reporte.</p>
    <?php elseif (!$configurado): ?>
        <p class="modal-form-alert">⚠️ Esta empresa no tiene conexión a SIHOS configurada. Ve a <a href="?url=sihos&empresa_id=<?= (int)$empresaId ?>">Conexión SIHOS</a>.</p>
    <?php else: ?>

        <form method="get" id="sihosOrdenesForm" class="crud-form auditoria-filters-grid" style="margin-bottom:20px;">
            <input type="hidden" name="url" value="sihos/ordenesLiquidacion">
            <input type="hidden" name="empresa_id" value="<?= (int)$empresaId ?>">
            <div class="form-group">
                <label for="sihosOrdenesDesde">Fecha de la orden — desde</label>
                <input type="date" id="sihosOrdenesDesde" name="desde" class="form-input" required value="<?= $h($desde) ?>">
            </div>
            <div class="form-group">
                <label for="sihosOrdenesHasta">Hasta</label>
                <input type="date" id="sihosOrdenesHasta" name="hasta" class="form-input" required value="<?= $h($hasta) ?>">
            </div>
            <div class="form-group">
                <label for="sihosOrdenesActividad">Actividad (ninguna = todas)</label>
                <select id="sihosOrdenesActividad" name="actividad[]" class="form-input" multiple size="6">
                    <?php foreach (SihosLiquidacionClasificador::ACTIVIDADES as $a): ?>
                        <option value="<?= $h($a) ?>"<?= in_array($a, $actividadesSel, true) ? ' selected' : '' ?>><?= $h($a) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="sihosOrdenesEps">EPS / administradora (ninguna = todas)</label>
                <select id="sihosOrdenesEps" name="eps[]" class="form-input" multiple size="6">
                    <?php foreach ($administradoras as $cod => $nombre): ?>
                        <option value="<?= $h((string)$cod) ?>"<?= in_array((string)$cod, $epsSel, true) ? ' selected' : '' ?>><?= $h($nombre !== '' ? $nombre : (string)$cod) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="align-self:flex-end;">
                <button type="submit" name="buscar" value="1" class="auditoria-btn-primary">🔍 Consultar</button>
            </div>
        </form>
        <p class="auditoria-hint">Mantenga Ctrl (o Cmd) para elegir varias actividades o EPS. Fecha de la orden: EncaOrde.Fecha; lo "liquidado sin orden" se toma por fecha de la liquidación en el mismo rango.</p>

        <?php if ($trabajo !== null): ?>
            <div class="field-note" style="margin:16px 0;">
                ⏳ Generando el reporte en segundo plano… <strong><?= $num((float)$trabajo['lineas']) ?></strong> fila(s) procesadas
                · <?= $h($trabajo['desde']) ?> a <?= $h($trabajo['hasta']) ?>. Esta página se actualiza sola al terminar.
            </div>
        <?php elseif ($reporte === null): ?>
            <p class="auditoria-subtitle">Elija el rango de fechas y presione Consultar.</p>
        <?php elseif (!$reporte['ok']): ?>
            <p class="modal-form-alert">⚠️ <?= $h($reporte['error']) ?></p>
        <?php elseif ($reporte['lineas'] === 0): ?>
            <p class="field-note">No hay órdenes ni liquidaciones del <?= $h($reporte['desde']) ?> al <?= $h($reporte['hasta']) ?> con esos filtros.</p>
        <?php else: ?>
            <p class="field-note">
                <strong><?= $num((float)$reporte['lineas']) ?></strong> fila(s) del <?= $h($reporte['desde']) ?> al <?= $h($reporte['hasta']) ?>.
                <a class="auditoria-btn-primary" href="?url=sihos/ordenesLiquidacionDescargar&empresa_id=<?= (int)$empresaId ?>&job=<?= rawurlencode($tokenJob) ?>">⬇ Descargar detalle (CSV)</a>
            </p>

            <h3 class="auditoria-title" style="font-size:1.1em; margin-top:24px;">Resumen por estado</h3>
            <table class="seguridad-table savid-dt-fit">
                <thead><tr><th>Estado</th><th>Ítems / líneas</th><th>Valor liquidado</th></tr></thead>
                <tbody>
                    <?php foreach ($estados as $e): $v = $reporte['porEstado'][$e] ?? [0, 0.0]; ?>
                        <tr><td><?= $h($e) ?></td><td style="text-align:right;"><?= $num((float)$v[0]) ?></td><td style="text-align:right;"><?= $v[1] > 0 ? $fmt((float)$v[1]) : '—' ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="auditoria-hint">
                «Con evidencia de atención» = la orden tiene cantidad realizada mayor a 0 pero no se liquidó (la fuga probable).
                «Sin evidencia» no prueba que no se atendió: la cantidad realizada puede quedar en 0 si no se carga desde la liquidación.
                «Inferido» = sin enlace entre orden y liquidación, pero la misma admisión liquidó ese mismo CUPS.
            </p>

            <h3 class="auditoria-title" style="font-size:1.1em; margin-top:28px;">Por actividad</h3>
            <table class="seguridad-table savid-dt-fit">
                <thead><tr><th>Actividad</th><?php foreach ($estados as $e): ?><th><?= $h($e) ?></th><?php endforeach; ?></tr></thead>
                <tbody>
                    <?php foreach ($reporte['porActividad'] as $actividad => $porEstado): ?>
                        <tr><td><?= $h((string)$actividad) ?></td><?php foreach ($estados as $e) { echo $celda($porEstado[$e] ?? null); } ?></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <h3 class="auditoria-title" style="font-size:1.1em; margin-top:28px;">Por EPS / administradora</h3>
            <table class="seguridad-table savid-dt-fit">
                <thead><tr><th>Administradora</th><?php foreach ($estados as $e): ?><th><?= $h($e) ?></th><?php endforeach; ?></tr></thead>
                <tbody>
                    <?php foreach ($reporte['porEps'] as $clave => $porEstado): [$cod, $nombre] = array_pad(explode('|', (string)$clave, 2), 2, ''); ?>
                        <tr><td><?= $h($nombre !== '' ? $nombre : ($cod !== '' ? $cod : 'SIN ADMINISTRADORA')) ?></td><?php foreach ($estados as $e) { echo $celda($porEstado[$e] ?? null); } ?></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

    <?php endif; ?>

</div>

<script nonce="<?= CspNonceService::value() ?>">
// La vista se inserta en main.php ANTES de cargar app.js y jQuery: se espera a DOMContentLoaded para usar savidMostrarCargando.
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('sihosOrdenesForm');
    if (form && typeof savidMostrarCargando === 'function') {
        form.addEventListener('submit', function () { savidMostrarCargando('Consultando órdenes y liquidación…', true); });
    }
});

<?php if (!empty($trabajo)): ?>
document.addEventListener('DOMContentLoaded', function () {
    var url = '?url=sihos/ordenesLiquidacionEstado&empresa_id=<?= (int)$empresaId ?>&job=<?= $h($trabajo['token']) ?>';
    var inicio = Date.now() - <?= (int)$trabajo['segundos'] ?> * 1000;
    var filas = <?= (int)$trabajo['lineas'] ?>;
    var terminado = false;

    function mmss(seg) {
        var m = Math.floor(seg / 60), s = seg % 60;
        return (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
    }
    function pintar() {
        // app.js cierra el overlay en el "pageshow" de toda carga: si se cerró, se reabre (el trabajo sigue en curso).
        var overlay = document.getElementById('savidCargandoOverlay');
        if (!overlay || !overlay.classList.contains('activo')) {
            savidMostrarCargando('Generando reporte…', true);
        }
        var texto = document.querySelector('#savidCargandoOverlay .savid-cargando-texto');
        if (texto) {
            texto.textContent = 'Generando reporte… ' + mmss(Math.floor((Date.now() - inicio) / 1000)) + ' · ' + filas.toLocaleString('es-CO') + ' filas procesadas';
        }
    }
    function consultar() {
        fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (e) {
                if (e.estado === 'pendiente' || e.estado === 'ejecutando') {
                    filas = e.lineas;
                    inicio = Date.now() - e.segundos * 1000;
                    setTimeout(consultar, 3000);
                } else {
                    terminado = true;
                    window.location.reload();
                }
            })
            .catch(function () { setTimeout(consultar, 5000); });
    }

    if (typeof savidMostrarCargando === 'function') {
        pintar();
        window.addEventListener('pageshow', function () { setTimeout(pintar, 0); });
        setInterval(function () { if (!terminado) { pintar(); } }, 1000);
    }
    setTimeout(consultar, 2000);
});
<?php endif; ?>
</script>
