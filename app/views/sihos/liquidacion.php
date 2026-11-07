<?php
/** @var array $scope */
/** @var bool $configurado */
/** @var string $desde */
/** @var string $hasta */
/** @var string $tokenJob */
/** @var ?array $reporte */
/** @var ?array $trabajo Cálculo en segundo plano en curso: token, estado, lineas, segundos */

$fmt = static fn (float $v): string => SihosCartera2193Service::formatoMoneda($v);
$num = static fn (float $v): string => number_format($v, 0, ',', '.');
$urlDescarga = static fn (string $archivo): string => '?url=sihos/liquidacionDescargar&empresa_id=' . (int)($scope['empresaId'] ?? 0)
    . '&job=' . rawurlencode($tokenJob) . '&archivo=' . $archivo;
?>

<div class="module-container auditoria-page">

    <div class="crud-toolbar auditoria-toolbar">
        <div class="auditoria-toolbar-title">
            <h2 class="auditoria-title">SIHOS — Liquidación</h2>
            <p class="auditoria-subtitle">Producción por actividad, especialidad y profesional de las liquidaciones confirmadas del rango elegido. Una fila por línea de liquidación (sin agrupar), con el tipo de servicio del CUPS. Reporte de solo lectura; no incluye datos del paciente.</p>
        </div>
    </div>

    <?php $filterUrl = 'sihos/liquidacion'; require BASE_PATH . '/app/views/sgd/_empresa_filter.php'; ?>

    <?php
    /* _empresa_filter.php también usa $empresaId internamente; se recalcula
       aquí después del require para no quedarse con el valor pisado. */
    $empresaId = $scope['empresaId'] ?? null;
    ?>

    <?php if ($empresaId === null): ?>
        <p class="field-note sgd-page-empty">Seleccione la empresa en el filtro superior para ver este reporte.</p>
    <?php elseif (!$configurado): ?>
        <p class="modal-form-alert">⚠️ Esta empresa no tiene conexión a SIHOS configurada. Ve a <a href="?url=sihos&empresa_id=<?= (int)$empresaId ?>">Conexión SIHOS</a>.</p>
    <?php else: ?>

        <form method="get" id="sihosLiquidacionForm" class="crud-form auditoria-filters-grid" style="margin-bottom:20px;">
            <input type="hidden" name="url" value="sihos/liquidacion">
            <input type="hidden" name="empresa_id" value="<?= (int)$empresaId ?>">
            <div class="form-group">
                <label for="sihosLiquidacionDesde">Fecha de liquidación — desde</label>
                <input type="date" id="sihosLiquidacionDesde" name="desde" class="form-input" required value="<?= htmlspecialchars($desde) ?>">
            </div>
            <div class="form-group">
                <label for="sihosLiquidacionHasta">Hasta</label>
                <input type="date" id="sihosLiquidacionHasta" name="hasta" class="form-input" required value="<?= htmlspecialchars($hasta) ?>">
            </div>
            <div class="form-group" style="align-self:flex-end;">
                <button type="submit" name="buscar" value="1" class="auditoria-btn-primary">🔍 Consultar</button>
            </div>
        </form>

        <?php if ($trabajo !== null): ?>
            <div class="field-note" style="margin:16px 0;">
                ⏳ Generando el reporte en segundo plano… <strong><?= $num((float)$trabajo['lineas']) ?></strong> línea(s) procesadas
                · <?= htmlspecialchars($trabajo['desde']) ?> a <?= htmlspecialchars($trabajo['hasta']) ?>.
                Puede tardar varios minutos en rangos largos; esta página se actualiza sola al terminar.
            </div>
        <?php elseif ($reporte === null): ?>
            <p class="auditoria-subtitle">Elija el rango de fechas de liquidación y presione Consultar.</p>
        <?php elseif (!$reporte['ok']): ?>
            <p class="modal-form-alert">⚠️ <?= htmlspecialchars($reporte['error']) ?></p>
        <?php elseif ($reporte['lineas'] === 0): ?>
            <p class="field-note">No hay liquidaciones confirmadas del <?= htmlspecialchars($reporte['desde']) ?> al <?= htmlspecialchars($reporte['hasta']) ?>.</p>
        <?php else: ?>
            <?php
            $t = $reporte['totales'];
            $meses = array_keys($reporte['porMesEspecialidad']);
            $matriz = [];
            foreach ($reporte['porMesEspecialidad'] as $mes => $porEsp) {
                foreach ($porEsp as $esp => $v) {
                    $matriz[$esp][$mes] = $v[4];
                }
            }
            uasort($matriz, static fn (array $a, array $b): int => array_sum($b) <=> array_sum($a));
            ?>
            <p class="field-note">
                <strong><?= $num((float)$reporte['lineas']) ?></strong> línea(s) del <?= htmlspecialchars($reporte['desde']) ?> al <?= htmlspecialchars($reporte['hasta']) ?>
                · EAPB <strong><?= $fmt($t[2]) ?></strong> · usuario <strong><?= $fmt($t[3]) ?></strong> · total <strong><?= $fmt($t[4]) ?></strong>.
            </p>
            <p>
                <a class="auditoria-btn-primary" href="<?= htmlspecialchars($urlDescarga('detalle')) ?>">⬇ Descargar detalle (CSV)</a>
                <a class="auditoria-btn-primary" href="<?= htmlspecialchars($urlDescarga('resumen')) ?>">⬇ Descargar resumen mensual (CSV)</a>
            </p>
            <?php if ($reporte['sinEspecialidad'] > 0 || $reporte['sinProfesional'] > 0 || $reporte['tiposOtros'] !== []): ?>
                <p class="modal-form-alert">
                    ⚠️ Control de calidad:
                    <?= $num((float)$reporte['sinProfesional']) ?> línea(s) sin profesional,
                    <?= $num((float)$reporte['sinEspecialidad']) ?> sin especialidad<?php if ($reporte['tiposOtros'] !== []): ?>,
                    actividad "Otros" por tipo de servicio no clasificado:
                    <?= htmlspecialchars(implode('; ', array_map(static fn ($n, $c) => "$n ($c)", array_keys($reporte['tiposOtros']), $reporte['tiposOtros']))) ?><?php endif; ?>.
                </p>
            <?php endif; ?>

            <h3 class="auditoria-title" style="font-size:1.1em; margin-top:28px;">Control por actividad</h3>
            <table class="seguridad-table savid-dt-fit" id="sihosLiquidacionActividad">
                <thead><tr><th>Actividad</th><th>Producción médica</th><th>Líneas</th><th>Cantidad</th><th>Valor EAPB</th><th>Valor usuario</th><th>Valor total</th></tr></thead>
                <tbody>
                    <?php foreach ($reporte['porActividad'] as $actividad => $porProd): ?>
                        <?php foreach ($porProd as $prod => $v): ?>
                            <tr>
                                <td><?= htmlspecialchars($actividad) ?></td>
                                <td><?= htmlspecialchars($prod) ?></td>
                                <td style="text-align:right;"><?= $num((float)$v[0]) ?></td>
                                <td style="text-align:right;"><?= $num($v[1]) ?></td>
                                <td style="text-align:right;"><?= $fmt($v[2]) ?></td>
                                <td style="text-align:right;"><?= $fmt($v[3]) ?></td>
                                <td style="text-align:right;"><?= $fmt($v[4]) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="2" style="text-align:right;">Total</th>
                        <th style="text-align:right;"><?= $num((float)$t[0]) ?></th>
                        <th style="text-align:right;"><?= $num($t[1]) ?></th>
                        <th style="text-align:right;"><?= $fmt($t[2]) ?></th>
                        <th style="text-align:right;"><?= $fmt($t[3]) ?></th>
                        <th style="text-align:right;"><?= $fmt($t[4]) ?></th>
                    </tr>
                </tfoot>
            </table>

            <h3 class="auditoria-title" style="font-size:1.1em; margin-top:28px;">Valor total de producción médica por especialidad y mes</h3>
            <table class="savid-datatable seguridad-table savid-dt-fit" id="sihosLiquidacionEspecialidad"
                   data-dt-order="[[<?= count($meses) + 1 ?>,&quot;desc&quot;]]"
                   data-dt-scroll-wrap-class="sihos-auditoria-glosa-scroll">
                <thead>
                    <tr>
                        <th>Especialidad</th>
                        <?php foreach ($meses as $mes): ?><th><?= htmlspecialchars($mes) ?></th><?php endforeach; ?>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($matriz as $esp => $porMes): ?>
                        <tr>
                            <td class="savid-dt-fit-text"><?= htmlspecialchars($esp) ?></td>
                            <?php foreach ($meses as $mes): ?>
                                <td style="text-align:right;" data-order="<?= (float)($porMes[$mes] ?? 0) ?>"><?= $fmt((float)($porMes[$mes] ?? 0)) ?></td>
                            <?php endforeach; ?>
                            <td style="text-align:right;" data-order="<?= array_sum($porMes) ?>"><strong><?= $fmt((float)array_sum($porMes)) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

        <?php endif; ?>

    <?php endif; ?>

</div>

<script>
// La vista se inserta en main.php ANTES de cargar app.js y jQuery: sin esperar
// a DOMContentLoaded, savidMostrarCargando aún no existe cuando corre este script.
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('sihosLiquidacionForm');
    if (form && typeof savidMostrarCargando === 'function') {
        form.addEventListener('submit', function () {
            savidMostrarCargando('Consultando liquidación…', true);
        });
    }
});

<?php if (!empty($trabajo)): ?>
document.addEventListener('DOMContentLoaded', function () {
    var url = '?url=sihos/liquidacionEstado&empresa_id=<?= (int)$empresaId ?>&job=<?= htmlspecialchars($trabajo['token'], ENT_QUOTES) ?>';
    var inicio = Date.now() - <?= (int)$trabajo['segundos'] ?> * 1000;
    var lineas = <?= (int)$trabajo['lineas'] ?>;
    var terminado = false;

    function mmss(seg) {
        var m = Math.floor(seg / 60), s = seg % 60;
        return (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
    }
    function pintar() {
        // app.js cierra el overlay en el "pageshow" de toda carga de página (savidOcultarCargandoForzado):
        // si se cerró, se vuelve a abrir; el trabajo sigue en curso y la pantalla debe seguir bloqueada.
        var overlay = document.getElementById('savidCargandoOverlay');
        if (!overlay || !overlay.classList.contains('activo')) {
            savidMostrarCargando('Generando liquidación…', true);
        }
        var texto = document.querySelector('#savidCargandoOverlay .savid-cargando-texto');
        if (texto) {
            texto.textContent = 'Generando liquidación… ' + mmss(Math.floor((Date.now() - inicio) / 1000))
                + ' · ' + lineas.toLocaleString('es-CO') + ' líneas procesadas';
        }
    }
    function consultar() {
        fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (e) {
                if (e.estado === 'pendiente' || e.estado === 'ejecutando') {
                    lineas = e.lineas;
                    inicio = Date.now() - e.segundos * 1000;
                    setTimeout(consultar, 3000);
                } else {
                    terminado = true;
                    window.location.reload();
                }
            })
            .catch(function () { setTimeout(consultar, 5000); });
    }

    // Bloquea la pantalla (sin botón de cerrar) y cuenta los segundos en el navegador cada 1 s.
    if (typeof savidMostrarCargando === 'function') {
        pintar();
        window.addEventListener('pageshow', function () { setTimeout(pintar, 0); });
        setInterval(function () { if (!terminado) { pintar(); } }, 1000);
    }
    setTimeout(consultar, 2000);
});
<?php endif; ?>
</script>
