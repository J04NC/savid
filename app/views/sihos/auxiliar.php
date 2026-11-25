<?php
/** @var array $scope */
/** @var bool $configurado */
/** @var array<string,mixed> $form parámetros GET actuales */
/** @var array<string,mixed> $catalogos sede/centro/recurso/documento (código => nombre) y sedePrincipal */
/** @var string $tokenJob */
/** @var ?string $error */
/** @var ?array $trabajo Generación en curso: token, estado, fase, lineas, segundos */
/** @var ?array $reporte Resumen del trabajo terminado */

$h = static fn ($s): string => htmlspecialchars((string)$s, ENT_QUOTES);
$mon = static fn (float $v): string => number_format($v, 2, ',', '.');
$enviado = isset($form['contabilidad']);
$val = static fn (string $k, string $def = ''): string => trim((string)($form[$k] ?? $def));
$marcado = static fn (string $k, bool $def = false): string => ($enviado ? !empty($form[$k]) : $def) ? ' checked' : '';
$radio = static fn (string $k, string $v, string $def): string => $val($k, $def) === $v ? ' checked' : '';
$listas = ['sede', 'centro', 'recurso', 'documento'];
?>

<div class="module-container auditoria-page">

    <div class="crud-toolbar auditoria-toolbar">
        <div class="auditoria-toolbar-title">
            <h2 class="auditoria-title">SIHOS — Informe auxiliar</h2>
            <p class="auditoria-subtitle">Saldo anterior, débitos, créditos y nuevo saldo por cuenta y tercero, con el detalle de cada movimiento, de ColGAAP o NIIF. Resumen por cuenta en pantalla y Excel completo. Reporte de solo lectura.</p>
        </div>
    </div>

    <?php $filterUrl = 'sihos/auxiliar'; require BASE_PATH . '/app/views/sgd/_empresa_filter.php'; ?>
    <?php $empresaId = $scope['empresaId'] ?? null; /* _empresa_filter.php pisa $empresaId */ ?>

    <?php if ($empresaId === null): ?>
        <p class="field-note sgd-page-empty">Seleccione la empresa en el filtro superior para ver este reporte.</p>
    <?php elseif (!$configurado): ?>
        <p class="modal-form-alert">⚠️ Esta empresa no tiene conexión a SIHOS configurada. Ve a <a href="?url=sihos&empresa_id=<?= (int)$empresaId ?>">Conexión SIHOS</a>.</p>
    <?php else: ?>

        <form method="get" id="sihosAuxForm" class="sihos-aux-form">
            <input type="hidden" name="url" value="sihos/auxiliar">
            <input type="hidden" name="empresa_id" value="<?= (int)$empresaId ?>">

            <fieldset class="sihos-aux-grupo"><legend>Contabilidad</legend>
                <?php foreach (SihosAuxiliarFiltros::CONTABILIDADES as $cod => $nombre): ?>
                    <label><input type="radio" name="contabilidad" value="<?= $h($cod) ?>"<?= $radio('contabilidad', $cod, 'colgaap') ?>> <?= $h($nombre) ?></label>
                <?php endforeach; ?>
            </fieldset>

            <fieldset class="sihos-aux-grupo"><legend>Filtros</legend>
                <?php foreach (SihosAuxiliarFiltros::FILTROS as $clave => [$etiqueta, $modos]):
                    $porDefecto = $clave === 'sede' && !$enviado && ($catalogos['sedePrincipal'] ?? '') !== '';
                    $modo = $val($clave . '_modo', $porDefecto ? 'igual' : 'todos');
                    $valores = $val($clave . '_valores', $porDefecto ? (string)$catalogos['sedePrincipal'] : ''); ?>
                    <div class="sihos-aux-filtro" data-filtro="<?= $h($clave) ?>">
                        <label for="aux_<?= $h($clave) ?>_modo"><?= $h($clave === 'cuenta' ? 'Cuenta (ColGAAP) / Partida (NIIF)' : $etiqueta) ?></label>
                        <select id="aux_<?= $h($clave) ?>_modo" name="<?= $h($clave) ?>_modo" class="form-input aux-modo">
                            <?php foreach ($modos as $m): ?>
                                <option value="<?= $h($m) ?>"<?= $modo === $m ? ' selected' : '' ?>><?= $h(SihosAuxiliarFiltros::MODOS[$m]) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="<?= $h($clave) ?>_valores" class="form-input aux-valores" value="<?= $h($valores) ?>" placeholder="Código(s) separados por coma"<?= in_array($clave, $listas, true) ? ' list="aux_dl_' . $h($clave) . '"' : '' ?>>
                        <input type="text" name="<?= $h($clave) ?>_hasta" class="form-input aux-hasta" value="<?= $h($val($clave . '_hasta')) ?>" placeholder="Hasta"<?= in_array($clave, $listas, true) ? ' list="aux_dl_' . $h($clave) . '"' : '' ?>>
                    </div>
                <?php endforeach; ?>
                <?php foreach ($listas as $l): ?>
                    <datalist id="aux_dl_<?= $h($l) ?>"><?php foreach ((array)($catalogos[$l] ?? []) as $cod => $nombre): ?><option value="<?= $h($cod) ?>"><?= $h($nombre) ?></option><?php endforeach; ?></datalist>
                <?php endforeach; ?>
            </fieldset>

            <fieldset class="sihos-aux-grupo"><legend>Periodo e información</legend>
                <label>Inicial <input type="date" name="desde" class="form-input" required value="<?= $h($val('desde', date('Y-m-01'))) ?>"></label>
                <label>Final <input type="date" name="hasta" class="form-input" required value="<?= $h($val('hasta', date('Y-m-d'))) ?>"></label>
                <label><input type="radio" name="info" value="resumen"<?= $radio('info', 'resumen', 'detallado') ?>> Resumen</label>
                <label><input type="radio" name="info" value="detallado"<?= $radio('info', 'detallado', 'detallado') ?>> Detallado</label>
                <span class="sihos-aux-sep">Reporte:</span>
                <label><input type="radio" name="diseno" value="vertical"<?= $radio('diseno', 'vertical', 'vertical') ?>> Vertical</label>
                <label><input type="radio" name="diseno" value="horizontal"<?= $radio('diseno', 'horizontal', 'vertical') ?>> Horizontal</label>
            </fieldset>

            <fieldset class="sihos-aux-grupo"><legend>Mostrar</legend>
                <?php foreach (SihosAuxiliarFiltros::OPCIONES as $op => $nombre): ?>
                    <label><input type="checkbox" name="<?= $h($op) ?>" value="1" id="aux_<?= $h($op) ?>"<?= $marcado($op, $op === 'solo_movimientos') ?>> <?= $h($nombre) ?></label>
                <?php endforeach; ?>
            </fieldset>

            <div style="margin-top:12px;">
                <button type="submit" name="buscar" value="1" class="auditoria-btn-primary">📊 Generar</button>
                <a class="auditoria-btn-secondary" href="?url=sihos/auxiliar&empresa_id=<?= (int)$empresaId ?>">Restablecer</a>
            </div>
        </form>
        <p class="auditoria-hint">Igual/Diferente admiten varios códigos separados por coma. «Entre» de cuenta incluye las subcuentas del código final (entre 5 y 8 = clases 5, 6, 7 y 8). El saldo anterior es el total de la cuenta (no aplica los demás filtros), como en SIHOS, e incluye los movimientos del 1 del mes hasta el día anterior a la fecha inicial. «Resumen» y «Saldo x cuenta» muestran solo totales por cuenta; «Solo terceros» (vertical) omite el detalle de documentos.</p>

        <?php if ($trabajo !== null): ?>
            <div class="field-note" style="margin:16px 0;">⏳ <?= $h($trabajo['fase']) ?>… Esta página se actualiza sola al terminar.</div>
        <?php elseif ($error !== null): ?>
            <p class="modal-form-alert">⚠️ <?= $h($error) ?></p>
        <?php elseif ($reporte !== null): $conSaldos = (bool)$reporte['conSaldos']; $t = $reporte['totales']; ?>
            <p class="field-note">
                <strong><?= $h($reporte['contabilidad']) ?></strong> del <?= $h($reporte['desde']) ?> al <?= $h($reporte['hasta']) ?> · <?= $h($reporte['filtros']) ?><br>
                <strong><?= count($reporte['cuentas']) ?></strong> cuenta(s)<?= $reporte['lineas'] > 0 ? ', ' . number_format((int)$reporte['lineas'], 0, ',', '.') . ' movimiento(s) en el detalle' : '' ?><?= $reporte['hojas'] > 1 ? ' (el Excel trae ' . (int)$reporte['hojas'] . ' hojas)' : '' ?>.
                <a class="auditoria-btn-primary" href="?url=sihos/auxiliarDescargar&empresa_id=<?= (int)$empresaId ?>&job=<?= rawurlencode($tokenJob) ?>">⬇ Descargar Excel</a>
            </p>
            <?php if ($reporte['cuentas'] !== []): ?>
                <table class="seguridad-table" data-dt-page-length="50" data-dt-scroll-wrap-class="sihos-auditoria-glosa-scroll" data-dt-order="[[0,&quot;asc&quot;]]">
                    <thead><tr><th>Código</th><th>Nombre</th><?php if ($conSaldos): ?><th>Saldo anterior</th><?php endif; ?><th>Débito</th><th>Crédito</th><?php if ($conSaldos): ?><th>Nuevo saldo</th><?php endif; ?></tr></thead>
                    <tbody>
                        <?php foreach ($reporte['cuentas'] as [$cod, $nombre, $ant, $db, $cr, $nue]): ?>
                            <tr><td><?= $h($cod) ?></td><td><?= $h($nombre) ?></td>
                                <?php foreach ($conSaldos ? [$ant, $db, $cr, $nue] : [$db, $cr] as $v): ?><td style="text-align:right;" data-order="<?= $h($v) ?>"><?= $mon((float)$v) ?></td><?php endforeach; ?></tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot><tr><th></th><th>TOTAL</th><?php foreach ($conSaldos ? $t : [$t[1], $t[2]] as $v): ?><th style="text-align:right;"><?= $mon((float)$v) ?></th><?php endforeach; ?></tr></tfoot>
                </table>
            <?php else: ?>
                <p class="field-note">No hay movimientos con esos filtros.</p>
            <?php endif; ?>
        <?php endif; ?>

    <?php endif; ?>
</div>

<style nonce="<?= CspNonceService::value() ?>">
/* Formulario en bloque (no .crud-form, que lo vuelve grilla de columnas y monta un recuadro sobre otro). */
.sihos-aux-form{display:block;max-width:1100px;margin-bottom:20px}
/* min-width:0: un fieldset por defecto no se encoge por debajo de su contenido y se desborda sobre el vecino. */
.sihos-aux-grupo{display:flex;flex-wrap:wrap;gap:8px 18px;align-items:center;width:100%;min-width:0;box-sizing:border-box;border:1px solid #d6dbe4;border-radius:6px;padding:8px 12px;margin:0 0 12px}
.sihos-aux-grupo legend{font-weight:600;padding:0 4px}
.sihos-aux-grupo label{display:inline-flex;align-items:center;gap:6px;margin:0}
.sihos-aux-grupo input[type=date]{width:auto}
.sihos-aux-filtro{display:grid;grid-template-columns:minmax(140px,220px) 150px minmax(160px,1fr) minmax(120px,180px);gap:6px 10px;align-items:center;width:100%;min-width:0}
.sihos-aux-filtro > *{min-width:0}
.sihos-aux-filtro label{display:block}
.sihos-aux-sep{margin-left:12px;font-weight:600}
@media (max-width:720px){.sihos-aux-filtro{grid-template-columns:1fr 1fr}}
</style>

<script nonce="<?= CspNonceService::value() ?>">
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('sihosAuxForm');
    if (!form) { return; }
    form.querySelectorAll('.sihos-aux-filtro').forEach(function (fila) {
        var modo = fila.querySelector('.aux-modo'), valores = fila.querySelector('.aux-valores'), hasta = fila.querySelector('.aux-hasta');
        function ajustar() {
            valores.style.visibility = modo.value === 'todos' ? 'hidden' : '';
            hasta.style.visibility = modo.value === 'entre' ? '' : 'hidden';
            valores.placeholder = modo.value === 'entre' ? 'Desde' : (modo.value === 'inicia' ? 'Código' : 'Código(s) separados por coma');
        }
        modo.addEventListener('change', ajustar);
        ajustar();
    });
    var soloTerceros = document.getElementById('aux_solo_terceros');
    function diseno() {
        var horizontal = form.querySelector('input[name=diseno][value=horizontal]').checked;
        soloTerceros.disabled = horizontal;
        if (horizontal) { soloTerceros.checked = false; }
    }
    form.querySelectorAll('input[name=diseno]').forEach(function (r) { r.addEventListener('change', diseno); });
    diseno();
    if (typeof savidMostrarCargando === 'function') {
        form.addEventListener('submit', function () { savidMostrarCargando('Iniciando el informe auxiliar…', true); });
    }
});
<?php if (!empty($trabajo)): ?>
document.addEventListener('DOMContentLoaded', function () {
    var url = '?url=sihos/auxiliarEstado&empresa_id=<?= (int)$empresaId ?>&job=<?= $h($trabajo['token']) ?>';
    var inicio = Date.now() - <?= (int)$trabajo['segundos'] ?> * 1000, fase = <?= json_encode($trabajo['fase'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>, filas = <?= (int)$trabajo['lineas'] ?>, terminado = false;
    function mmss(s) { var m = Math.floor(s / 60); s = s % 60; return (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s; }
    function pintar() {
        var overlay = document.getElementById('savidCargandoOverlay');
        if (!overlay || !overlay.classList.contains('activo')) { savidMostrarCargando('Generando informe…', true); }
        var texto = document.querySelector('#savidCargandoOverlay .savid-cargando-texto');
        if (texto) { texto.textContent = fase + '… ' + mmss(Math.floor((Date.now() - inicio) / 1000)) + (filas > 0 ? ' · ' + filas.toLocaleString('es-CO') + ' movimientos escritos' : ''); }
    }
    function consultar() {
        fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (e) {
                if (e.estado === 'pendiente' || e.estado === 'ejecutando') {
                    fase = e.fase || fase; filas = e.lineas; inicio = Date.now() - e.segundos * 1000; setTimeout(consultar, 3000);
                } else { terminado = true; window.location.reload(); }
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
