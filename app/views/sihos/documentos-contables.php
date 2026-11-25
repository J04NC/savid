<?php
/** @var array $scope */
/** @var bool $configurado */
/** @var array<string,string> $filtros contabilidad, desde, hasta, modo_cuenta, cuenta, cuenta_hasta */
/** @var string $tokenJob */
/** @var ?string $error */
/** @var ?array $trabajo Generación en segundo plano en curso: token, estado, lineas, segundos */
/** @var ?array $listo Trabajo terminado: lineas, hojas, filtros */

$h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
$num = static fn (float $v): string => number_format($v, 0, ',', '.');
$modo = $filtros['modo_cuenta'] !== '' ? $filtros['modo_cuenta'] : 'todas';
?>

<div class="module-container auditoria-page">

    <div class="crud-toolbar auditoria-toolbar">
        <div class="auditoria-toolbar-title">
            <h2 class="auditoria-title">SIHOS — Documentos contables</h2>
            <p class="auditoria-subtitle">Todos los documentos contables causados y no anulados del rango, sumados por documento y cuenta, de la contabilidad elegida. Se descarga en Excel (.xlsx). Reporte de solo lectura.</p>
        </div>
    </div>

    <?php $filterUrl = 'sihos/documentosContables'; require BASE_PATH . '/app/views/sgd/_empresa_filter.php'; ?>
    <?php $empresaId = $scope['empresaId'] ?? null; /* _empresa_filter.php pisa $empresaId */ ?>

    <?php if ($empresaId === null): ?>
        <p class="field-note sgd-page-empty">Seleccione la empresa en el filtro superior para ver este reporte.</p>
    <?php elseif (!$configurado): ?>
        <p class="modal-form-alert">⚠️ Esta empresa no tiene conexión a SIHOS configurada. Ve a <a href="?url=sihos&empresa_id=<?= (int)$empresaId ?>">Conexión SIHOS</a>.</p>
    <?php else: ?>

        <form method="get" id="sihosDocContForm" class="crud-form auditoria-filters-grid" style="margin-bottom:20px;">
            <input type="hidden" name="url" value="sihos/documentosContables">
            <input type="hidden" name="empresa_id" value="<?= (int)$empresaId ?>">
            <div class="form-group">
                <label for="sihosDocContContabilidad">Contabilidad</label>
                <select id="sihosDocContContabilidad" name="contabilidad" class="form-input" required>
                    <option value="">— Seleccione —</option>
                    <?php foreach (SihosDocumentosContablesService::CONTABILIDADES as $cod => $nombre): ?>
                        <option value="<?= $h($cod) ?>"<?= $filtros['contabilidad'] === $cod ? ' selected' : '' ?>><?= $h($nombre) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="sihosDocContDesde">Fecha del documento — desde</label>
                <input type="date" id="sihosDocContDesde" name="desde" class="form-input" required value="<?= $h($filtros['desde']) ?>">
            </div>
            <div class="form-group">
                <label for="sihosDocContHasta">Hasta</label>
                <input type="date" id="sihosDocContHasta" name="hasta" class="form-input" required value="<?= $h($filtros['hasta']) ?>">
            </div>
            <div class="form-group">
                <label for="sihosDocContModo">Cuenta</label>
                <select id="sihosDocContModo" name="modo_cuenta" class="form-input">
                    <?php foreach (SihosDocumentosContablesService::MODOS_CUENTA as $cod => $nombre): ?>
                        <option value="<?= $h($cod) ?>"<?= $modo === $cod ? ' selected' : '' ?>><?= $h($nombre) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" id="sihosDocContCuentaGrupo">
                <label for="sihosDocContCuenta" id="sihosDocContCuentaLabel">Cuenta</label>
                <input type="text" id="sihosDocContCuenta" name="cuenta" class="form-input" inputmode="numeric" pattern="\d{1,16}" maxlength="16" placeholder="Ej. 4105" value="<?= $h($filtros['cuenta']) ?>">
            </div>
            <div class="form-group" id="sihosDocContCuentaHastaGrupo">
                <label for="sihosDocContCuentaHasta">Hasta la cuenta</label>
                <input type="text" id="sihosDocContCuentaHasta" name="cuenta_hasta" class="form-input" inputmode="numeric" pattern="\d{1,16}" maxlength="16" placeholder="Ej. 4199" value="<?= $h($filtros['cuenta_hasta']) ?>">
            </div>
            <div class="form-group" style="align-self:flex-end;">
                <button type="submit" name="buscar" value="1" class="auditoria-btn-primary">📊 Generar Excel</button>
            </div>
        </form>
        <p class="auditoria-hint">«Entre» incluye las subcuentas de la cuenta final (entre 4105 y 4199 incluye 41990101). Columnas: fecha, código y nombre del documento, número, concepto, cuenta, valor y usuario que causó.</p>

        <?php if ($trabajo !== null): ?>
            <div class="field-note" style="margin:16px 0;">
                ⏳ Generando el Excel en segundo plano… <strong><?= $num((float)$trabajo['lineas']) ?></strong> fila(s) escritas.
                Esta página se actualiza sola al terminar.
            </div>
        <?php elseif ($error !== null): ?>
            <p class="modal-form-alert">⚠️ <?= $h($error) ?></p>
        <?php elseif ($listo !== null): $f = $listo['filtros']; ?>
            <?php if ((int)$listo['lineas'] === 0): ?>
                <p class="field-note">No hay documentos <?= $h(SihosDocumentosContablesService::CONTABILIDADES[$f['contabilidad']] ?? '') ?> del <?= $h($f['desde']) ?> al <?= $h($f['hasta']) ?> con <?= $h(SihosDocumentosContablesService::describirCuenta($f)) ?>.</p>
            <?php else: ?>
                <p class="field-note">
                    <strong><?= $num((float)$listo['lineas']) ?></strong> fila(s) <?= $h(SihosDocumentosContablesService::CONTABILIDADES[$f['contabilidad']] ?? '') ?>
                    del <?= $h($f['desde']) ?> al <?= $h($f['hasta']) ?>, <?= $h(SihosDocumentosContablesService::describirCuenta($f)) ?>.
                    <?php if ((int)$listo['hojas'] > 1): ?>Excel admite 1.048.575 filas por hoja: el archivo trae <?= (int)$listo['hojas'] ?> hojas.<?php endif; ?>
                    <a class="auditoria-btn-primary" href="?url=sihos/documentosContablesDescargar&empresa_id=<?= (int)$empresaId ?>&job=<?= rawurlencode($tokenJob) ?>">⬇ Descargar Excel</a>
                </p>
            <?php endif; ?>
        <?php else: ?>
            <p class="auditoria-subtitle">Elija la contabilidad, el rango de fechas y, si desea, el filtro de cuenta; luego presione Generar Excel.</p>
        <?php endif; ?>

    <?php endif; ?>

</div>

<script nonce="<?= CspNonceService::value() ?>">
// La vista se inserta en main.php ANTES de cargar app.js y jQuery: se espera a DOMContentLoaded para usar savidMostrarCargando.
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('sihosDocContForm');
    if (!form) {
        return;
    }
    var modo = document.getElementById('sihosDocContModo');
    var grupoCuenta = document.getElementById('sihosDocContCuentaGrupo');
    var grupoHasta = document.getElementById('sihosDocContCuentaHastaGrupo');
    var cuenta = document.getElementById('sihosDocContCuenta');
    var hasta = document.getElementById('sihosDocContCuentaHasta');
    var etiqueta = document.getElementById('sihosDocContCuentaLabel');
    function ajustar() {
        var m = modo.value;
        grupoCuenta.style.display = m === 'todas' ? 'none' : '';
        grupoHasta.style.display = m === 'entre' ? '' : 'none';
        cuenta.required = m !== 'todas';
        hasta.required = m === 'entre';
        etiqueta.textContent = m === 'entre' ? 'Desde la cuenta' : (m === 'igual' ? 'Cuenta igual a' : 'Cuenta inicia por');
    }
    modo.addEventListener('change', ajustar);
    ajustar();

    if (typeof savidMostrarCargando === 'function') {
        form.addEventListener('submit', function () { savidMostrarCargando('Iniciando generación del Excel…', true); });
    }
});

<?php if (!empty($trabajo)): ?>
document.addEventListener('DOMContentLoaded', function () {
    var url = '?url=sihos/documentosContablesEstado&empresa_id=<?= (int)$empresaId ?>&job=<?= $h($trabajo['token']) ?>';
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
            savidMostrarCargando('Generando Excel…', true);
        }
        var texto = document.querySelector('#savidCargandoOverlay .savid-cargando-texto');
        if (texto) {
            texto.textContent = 'Generando Excel… ' + mmss(Math.floor((Date.now() - inicio) / 1000)) + ' · ' + filas.toLocaleString('es-CO') + ' filas escritas';
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
