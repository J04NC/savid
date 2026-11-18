<?php
/** @var array $scope */
/** @var bool $configurado */
/** @var string $desde */
/** @var string $hasta */
/** @var string[] $tiposElegidos */
/** @var string $nit */
/** @var array<string,string> $tiposUsuario CodiTipo => NombTipo */
/** @var ?array $reporte */
/** @var array<string,?string> $tokens token de cache por tabla */

$moneda = ['ValorCartera', 'ValoTota', 'ValoEven', 'ValorFactura'];
$secciones = [
    'facturas' => ['1. Facturación', 'factura(s)', 'No hay facturas con esos filtros.'],
    'notas' => ['2. Notas que afectan facturas', 'nota(s) por factura afectada', 'No hay notas que afecten facturas con esos filtros.'],
];
?>

<div class="module-container auditoria-page">

    <div class="crud-toolbar auditoria-toolbar">
        <div class="auditoria-toolbar-title">
            <h2 class="auditoria-title">SIHOS — Facturación</h2>
            <p class="auditoria-subtitle">Facturas y notas (notas contables de facturación y glosas aceptadas) que las afectan, por rango de fecha del documento, tipo de usuario y tercero, para cruzar contra la DIAN. Reporte de solo lectura.</p>
        </div>
    </div>

    <?php $filterUrl = 'sihos/facturacion'; require BASE_PATH . '/app/views/sgd/_empresa_filter.php'; ?>

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

        <form method="get" id="sihosFacturacionForm" class="crud-form auditoria-filters-grid" style="margin-bottom:20px;">
            <input type="hidden" name="url" value="sihos/facturacion">
            <input type="hidden" name="empresa_id" value="<?= (int)$empresaId ?>">
            <div class="form-group">
                <label for="sihosFacturacionDesde">Desde (fecha del documento)</label>
                <input type="date" id="sihosFacturacionDesde" name="desde" class="form-input" required value="<?= htmlspecialchars($desde) ?>">
            </div>
            <div class="form-group">
                <label for="sihosFacturacionHasta">Hasta</label>
                <input type="date" id="sihosFacturacionHasta" name="hasta" class="form-input" required value="<?= htmlspecialchars($hasta) ?>">
            </div>
            <div class="form-group">
                <label for="sihosFacturacionTipos">Tipo de usuario</label>
                <select id="sihosFacturacionTipos" name="tipos[]" class="form-input" multiple size="6">
                    <?php foreach ($tiposUsuario as $codigo => $nombre): ?>
                        <option value="<?= htmlspecialchars((string)$codigo) ?>" <?= in_array((string)$codigo, $tiposElegidos, true) ? 'selected' : '' ?>>
                            <?= htmlspecialchars(trim((string)$nombre)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="field-note">Sin selección = todos.</small>
            </div>
            <div class="form-group">
                <label for="sihosFacturacionNit">Tercero (documento)</label>
                <input type="text" id="sihosFacturacionNit" name="nit" class="form-input" placeholder="Todos los terceros" value="<?= htmlspecialchars($nit) ?>">
            </div>
            <div class="form-group" style="align-self:flex-end;">
                <button type="submit" name="buscar" value="1" class="auditoria-btn-primary">🔍 Consultar</button>
            </div>
        </form>

        <?php if ($reporte === null): ?>
            <p class="auditoria-subtitle">Elija el rango de fechas y presione Consultar.</p>
        <?php elseif (!$reporte['ok']): ?>
            <p class="modal-form-alert">⚠️ <?= htmlspecialchars($reporte['error']) ?></p>
        <?php else: ?>

            <?php foreach ($secciones as $tabla => [$titulo, $unidad, $vacio]): ?>
                <h3 class="auditoria-title" style="font-size:1.1em; margin-top:28px;"><?= htmlspecialchars($titulo) ?></h3>
                <?php if (empty($tokens[$tabla])): ?>
                    <p class="field-note"><?= htmlspecialchars($vacio) ?></p>
                <?php else: ?>
                    <?php
                    $columnas = SihosFacturacionTabla::COLUMNAS[$tabla];
                    $base = '?url=sihos/%s&empresa_id=' . (int)$empresaId . '&tabla=' . $tabla . '&token=' . rawurlencode($tokens[$tabla]);
                    ?>
                    <p class="field-note">
                        <?= (int)$reporte['total_' . $tabla] ?> <?= htmlspecialchars($unidad) ?> del <?= htmlspecialchars($desde) ?> al <?= htmlspecialchars($hasta) ?>.
                        <a href="<?= htmlspecialchars(sprintf($base, 'facturacionExportar')) ?>" class="auditoria-btn-primary" style="text-decoration:none; display:inline-block;">📥 Exportar CSV (todas las filas)</a>
                        — los botones Excel/CSV de la tabla solo exportan la página visible.
                    </p>
                    <table class="seguridad-table"
                           id="sihosFacturacion_<?= $tabla ?>"
                           data-dt-server="1"
                           data-dt-server-url="<?= htmlspecialchars(sprintf($base, 'facturacionDatos')) ?>"
                           data-dt-column-search="1"
                           data-dt-scroll-wrap-class="sihos-auditoria-glosa-scroll"
                           data-dt-order="[[1,&quot;asc&quot;]]">
                        <thead>
                            <tr>
                                <?php foreach ($columnas as $clave => $encabezado): ?>
                                    <th<?= in_array($clave, $moneda, true) ? ' style="text-align:right;"' : '' ?>><?= htmlspecialchars($encabezado) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                <?php endif; ?>
            <?php endforeach; ?>

        <?php endif; ?>

    <?php endif; ?>

</div>

<script nonce="<?= CspNonceService::value() ?>">
// La vista se inserta en main.php ANTES de cargar app.js: sin esperar a
// DOMContentLoaded, savidMostrarCargando aún no existe (ver sihos/cruce.php).
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('sihosFacturacionForm');
    if (form && typeof savidMostrarCargando === 'function') {
        form.addEventListener('submit', function () {
            savidMostrarCargando('Consultando facturación en SIHOS…', true);
        });
    }
});
</script>
