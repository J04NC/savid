<?php
/** @var array $scope */
/** @var bool $configurado */
/** @var string $fechaCorte */
/** @var string[] $tiposElegidos */
/** @var ?string $nit */
/** @var array<string,string> $tiposDisponibles CodiTipo => NombTipo */
/** @var ?array $reporte */
/** @var ?array $trabajo Cálculo en segundo plano en curso: token, estado, facturas, segundos */

$fmt = static fn (float $v): string => SihosCartera2193Service::formatoMoneda($v);
$edades = ['0 - 60 días', '61 - 90 días', '91 - 180 días', '181 - 360 días', 'Más de 360 días'];
?>

<div class="module-container auditoria-page">

    <div class="crud-toolbar auditoria-toolbar">
        <div class="auditoria-toolbar-title">
            <h2 class="auditoria-title">SIHOS — Cartera: 2193</h2>
            <p class="auditoria-subtitle">Cartera por edades por deudor y tipo de usuario (radicada, pendiente de radicar, glosa inicial y giro directo), a la fecha de corte. Reporte de solo lectura; replica el informe 2193 de SIHOS.</p>
        </div>
    </div>

    <?php $filterUrl = 'sihos/cartera2193'; require BASE_PATH . '/app/views/sgd/_empresa_filter.php'; ?>

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

        <form method="get" id="sihosCartera2193Form" class="crud-form auditoria-filters-grid" style="margin-bottom:20px;">
            <input type="hidden" name="url" value="sihos/cartera2193">
            <input type="hidden" name="empresa_id" value="<?= (int)$empresaId ?>">
            <div class="form-group">
                <label for="sihosCartera2193FechaCorte">Fecha de corte</label>
                <input type="date" id="sihosCartera2193FechaCorte" name="fecha_corte" class="form-input" required value="<?= htmlspecialchars($fechaCorte) ?>">
            </div>
            <div class="form-group">
                <label for="sihosCartera2193Tipos">Tipos de usuario <small>(Ctrl/Cmd para varios; sin elegir = todos)</small></label>
                <select id="sihosCartera2193Tipos" name="tipos[]" class="form-input" multiple size="6">
                    <?php foreach ($tiposDisponibles as $codigo => $nombre): ?>
                        <option value="<?= htmlspecialchars((string)$codigo) ?>" <?= in_array((string)$codigo, $tiposElegidos, true) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($nombre) ?> (<?= htmlspecialchars((string)$codigo) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="sihosCartera2193Nit">Entidad (NIT, opcional)</label>
                <input type="text" id="sihosCartera2193Nit" name="nit" class="form-input" placeholder="Todas las entidades" value="<?= htmlspecialchars((string)($nit ?? '')) ?>">
            </div>
            <div class="form-group" style="align-self:flex-end;">
                <button type="submit" name="buscar" value="1" class="auditoria-btn-primary">🔍 Consultar</button>
            </div>
        </form>

        <?php if ($trabajo !== null): ?>
            <div class="field-note" id="sihosCartera2193Progreso" style="margin:16px 0;">
                ⏳ Generando el reporte en segundo plano… <strong id="sihosCartera2193Facturas"><?= (int)$trabajo['facturas'] ?></strong> factura(s) procesadas
                · <span id="sihosCartera2193Segundos"><?= (int)$trabajo['segundos'] ?></span> s.
                Puede tardar varios minutos si son muchas entidades; esta página se actualiza sola al terminar.
            </div>
        <?php elseif ($reporte === null): ?>
            <p class="auditoria-subtitle">Elija la fecha de corte y presione Consultar.</p>
        <?php elseif (!$reporte['ok']): ?>
            <p class="modal-form-alert">⚠️ <?= htmlspecialchars($reporte['error']) ?></p>
        <?php elseif ($reporte['tipos'] === []): ?>
            <p class="field-note">No hay cartera con saldo hasta el <?= htmlspecialchars($fechaCorte) ?> para los filtros elegidos.</p>
        <?php else: ?>

            <p class="field-note">
                Cartera a <strong><?= htmlspecialchars($fechaCorte) ?></strong>:
                radicada <strong><?= $fmt($reporte['granTotal']['total']) ?></strong>
                · pendiente de radicar <strong><?= $fmt($reporte['granTotal']['pend']) ?></strong>
                · glosa inicial <strong><?= $fmt($reporte['granTotal']['glosa']) ?></strong>
                · <?= (int)$reporte['facturas'] ?> factura(s).
            </p>

            <?php foreach ($reporte['tipos'] as $i => $tipo): ?>
                <?php $t = $tipo['totales']; ?>
                <h3 class="auditoria-title" style="font-size:1.1em; margin-top:28px;"><?= htmlspecialchars($tipo['NombTipo']) ?></h3>
                <table class="savid-datatable seguridad-table savid-dt-fit" id="sihosCartera2193Tipo<?= (int)$i ?>"
                       data-dt-order="[[8,&quot;desc&quot;]]"
                       data-dt-scroll-wrap-class="sihos-auditoria-glosa-scroll">
                    <thead>
                        <tr>
                            <th>Tipo doc.</th>
                            <th>Documento</th>
                            <th>Entidad</th>
                            <?php foreach ($edades as $edad): ?><th><?= $edad ?></th><?php endforeach; ?>
                            <th>Total por cobrar con facturación radicada</th>
                            <th>Facturación pendiente de radicar</th>
                            <th>Glosa inicial (objeciones pendientes)</th>
                            <?php if ($tipo['conGiro']): ?><th>Giro directo</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tipo['filas'] as $fila): ?>
                            <tr>
                                <td><?= htmlspecialchars($fila['TiDoTerc']) ?></td>
                                <td><?= htmlspecialchars($fila['NuDoTerc']) ?></td>
                                <td class="savid-dt-fit-text" title="<?= htmlspecialchars($fila['NombTerc']) ?>"><?= htmlspecialchars($fila['NombTerc']) ?></td>
                                <?php foreach ($fila['v'] as $valor): ?>
                                    <td style="text-align:right;" data-order="<?= $valor ?>"><?= $fmt($valor) ?></td>
                                <?php endforeach; ?>
                                <td style="text-align:right;" data-order="<?= $fila['total'] ?>"><?= $fmt($fila['total']) ?></td>
                                <td style="text-align:right;" data-order="<?= $fila['pend'] ?>"><?= $fmt($fila['pend']) ?></td>
                                <td style="text-align:right;" data-order="<?= $fila['glosa'] ?>"><?= $fmt($fila['glosa']) ?></td>
                                <?php if ($tipo['conGiro']): ?><td style="text-align:right;" data-order="<?= $fila['giro'] ?>"><?= $fmt($fila['giro']) ?></td><?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th colspan="3" style="text-align:right;">Total <?= htmlspecialchars($tipo['NombTipo']) ?></th>
                            <?php foreach ($t['v'] as $valor): ?><th style="text-align:right;"><?= $fmt($valor) ?></th><?php endforeach; ?>
                            <th style="text-align:right;"><?= $fmt($t['total']) ?></th>
                            <th style="text-align:right;"><?= $fmt($t['pend']) ?></th>
                            <th style="text-align:right;"><?= $fmt($t['glosa']) ?></th>
                            <?php if ($tipo['conGiro']): ?><th style="text-align:right;"><?= $fmt($t['giro']) ?></th><?php endif; ?>
                        </tr>
                    </tfoot>
                </table>
            <?php endforeach; ?>

            <?php $g = $reporte['granTotal']; ?>
            <h3 class="auditoria-title" style="font-size:1.1em; margin-top:28px;">Total de cartera</h3>
            <table class="seguridad-table savid-dt-fit" id="sihosCartera2193Total">
                <thead>
                    <tr>
                        <?php foreach ($edades as $edad): ?><th><?= $edad ?></th><?php endforeach; ?>
                        <th>Total radicada</th>
                        <th>Pendiente de radicar</th>
                        <th>Glosa inicial</th>
                        <th>Giro directo</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <?php foreach ($g['v'] as $valor): ?><td style="text-align:right;"><?= $fmt($valor) ?></td><?php endforeach; ?>
                        <td style="text-align:right;"><?= $fmt($g['total']) ?></td>
                        <td style="text-align:right;"><?= $fmt($g['pend']) ?></td>
                        <td style="text-align:right;"><?= $fmt($g['glosa']) ?></td>
                        <td style="text-align:right;"><?= $fmt($g['giro']) ?></td>
                    </tr>
                </tbody>
            </table>

        <?php endif; ?>

    <?php endif; ?>

</div>

<script>
// La vista se inserta en main.php ANTES de cargar app.js y jQuery: sin esperar
// a DOMContentLoaded, savidMostrarCargando aún no existe cuando corre este script.
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('sihosCartera2193Form');
    if (form && typeof savidMostrarCargando === 'function') {
        form.addEventListener('submit', function () {
            savidMostrarCargando('Consultando cartera 2193… puede tardar si son muchas facturas.', true);
        });
    }
});

<?php if (!empty($trabajo)): ?>
document.addEventListener('DOMContentLoaded', function () {
    var url = '?url=sihos/cartera2193Estado&empresa_id=<?= (int)$empresaId ?>&job=<?= htmlspecialchars($trabajo['token'], ENT_QUOTES) ?>';
    var inicio = Date.now() - <?= (int)$trabajo['segundos'] ?> * 1000;
    var facturas = <?= (int)$trabajo['facturas'] ?>;
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
            savidMostrarCargando('Generando cartera 2193…', true);
        }
        var texto = document.querySelector('#savidCargandoOverlay .savid-cargando-texto');
        if (texto) {
            texto.textContent = 'Generando cartera 2193… ' + mmss(Math.floor((Date.now() - inicio) / 1000))
                + ' · ' + facturas.toLocaleString('es-CO') + ' facturas procesadas';
        }
    }
    function consultar() {
        fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (e) {
                if (e.estado === 'pendiente' || e.estado === 'ejecutando') {
                    facturas = e.facturas;
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
