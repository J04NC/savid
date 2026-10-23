<?php
/** @var array $scope */
/** @var bool $configurado */
/** @var string $fechaCorte */
/** @var ?array $reporte */
/** @var ?string $tokenDatos */

$fmt = static fn (float $v): string => SihosDeterioroService::formatoMoneda($v);
?>

<div class="module-container auditoria-page">

    <div class="crud-toolbar auditoria-toolbar">
        <div class="auditoria-toolbar-title">
            <h2 class="auditoria-title">SIHOS — Cartera: Deterioro</h2>
            <p class="auditoria-subtitle">Valor aplicado por los documentos de deterioro de cartera (<code class="auditoria-code">DocuApli=108</code>) sobre cada factura, hasta la fecha de corte. Reporte de solo lectura.</p>
        </div>
    </div>

    <?php $filterUrl = 'sihos/deterioro'; require BASE_PATH . '/app/views/sgd/_empresa_filter.php'; ?>

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

        <form method="get" id="sihosDeterioroForm" class="crud-form auditoria-filters-grid" style="margin-bottom:20px;">
            <input type="hidden" name="url" value="sihos/deterioro">
            <input type="hidden" name="empresa_id" value="<?= (int)$empresaId ?>">
            <div class="form-group">
                <label for="sihosDeterioroFechaCorte">Fecha de corte</label>
                <input type="date" id="sihosDeterioroFechaCorte" name="fecha_corte" class="form-input" required value="<?= htmlspecialchars($fechaCorte) ?>">
            </div>
            <div class="form-group" style="align-self:flex-end;">
                <button type="submit" name="buscar" value="1" class="auditoria-btn-primary">🔍 Consultar</button>
            </div>
        </form>

        <?php if ($reporte === null): ?>
            <p class="auditoria-subtitle">Elija la fecha de corte y presione Consultar.</p>
        <?php elseif (!$reporte['ok']): ?>
            <p class="modal-form-alert">⚠️ <?= htmlspecialchars($reporte['error']) ?></p>
        <?php elseif ($reporte['totalFilas'] === 0): ?>
            <p class="field-note">No hay deterioro de cartera con saldo hasta el <?= htmlspecialchars($fechaCorte) ?>.</p>
        <?php else: ?>

            <p class="field-note">
                Deterioro total a <strong><?= htmlspecialchars($fechaCorte) ?></strong>: <strong><?= $fmt($reporte['total']) ?></strong>
                · <?= (int)$reporte['totalFilas'] ?> factura(s) · <?= count($reporte['porEntidad']) ?> entidad(es).
                Haga clic en una fila de las dos primeras tablas para filtrar el detalle.
            </p>

            <h3 class="auditoria-title" style="font-size:1.1em;">1. Por tipo de usuario</h3>
            <table class="savid-datatable seguridad-table" id="sihosDeterioroPorTipo"
                   data-dt-order="[[2,&quot;desc&quot;]]"
                   data-dt-scroll-wrap-class="sihos-auditoria-glosa-scroll">
                <thead>
                    <tr>
                        <th>Tipo de usuario</th>
                        <th style="text-align:right;">Facturas</th>
                        <th style="text-align:right;">Valor</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reporte['porTipoUsuario'] as $g): ?>
                        <tr class="sihos-deterioro-filtro" style="cursor:pointer;" title="Filtrar el detalle"
                            data-col="0" data-valor="<?= htmlspecialchars($g['NombTipo']) ?>">
                            <td><?= htmlspecialchars($g['NombTipo']) ?></td>
                            <td style="text-align:right;"><?= (int)$g['facturas'] ?></td>
                            <td style="text-align:right;" data-order="<?= $g['Valor'] ?>"><?= $fmt($g['Valor']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <h3 class="auditoria-title" style="font-size:1.1em; margin-top:28px;">2. Por entidad (tercero)</h3>
            <table class="savid-datatable seguridad-table" id="sihosDeterioroPorEntidad"
                   data-dt-order="[[4,&quot;desc&quot;]]"
                   data-dt-scroll-wrap-class="sihos-auditoria-glosa-scroll">
                <thead>
                    <tr>
                        <th>Tipo doc.</th>
                        <th>Documento</th>
                        <th>Entidad</th>
                        <th style="text-align:right;">Facturas</th>
                        <th style="text-align:right;">Valor</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reporte['porEntidad'] as $g): ?>
                        <tr class="sihos-deterioro-filtro" style="cursor:pointer;" title="Filtrar el detalle"
                            data-col="5" data-valor="<?= htmlspecialchars($g['NuDoTerc']) ?>">
                            <td><?= htmlspecialchars($g['TiDoTerc']) ?></td>
                            <td><?= htmlspecialchars($g['NuDoTerc']) ?></td>
                            <td><?= htmlspecialchars($g['NombTerc']) ?></td>
                            <td style="text-align:right;"><?= (int)$g['facturas'] ?></td>
                            <td style="text-align:right;" data-order="<?= $g['Valor'] ?>"><?= $fmt($g['Valor']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <h3 class="auditoria-title" style="font-size:1.1em; margin-top:28px;" id="sihosDeterioroDetalleTitulo">3. Detalle</h3>
            <?php if ($tokenDatos !== null): ?>
                <?php
                $urlExportar = '?url=sihos/deterioroExportar&empresa_id=' . (int)$empresaId . '&token=' . rawurlencode($tokenDatos);
                $urlDatos = '?url=sihos/deterioroDatos&empresa_id=' . (int)$empresaId . '&token=' . rawurlencode($tokenDatos);
                ?>
                <p class="field-note">
                    <a href="<?= htmlspecialchars($urlExportar) ?>" class="auditoria-btn-primary" style="text-decoration:none; display:inline-block;">📥 Exportar CSV (las <?= (int)$reporte['totalFilas'] ?> filas)</a>
                    — los botones Excel/CSV de la tabla solo exportan la página visible.
                    <span id="sihosDeterioroFiltroActivo" hidden>
                        · Filtrando por <strong id="sihosDeterioroFiltroTexto"></strong>
                        <button type="button" id="sihosDeterioroQuitarFiltro" class="auditoria-btn-primary" style="margin-left:6px;">✕ Quitar filtro</button>
                    </span>
                </p>
                <table class="seguridad-table"
                       id="sihosDeterioroDetalle"
                       data-dt-server="1"
                       data-dt-server-url="<?= htmlspecialchars($urlDatos) ?>"
                       data-dt-column-search="1"
                       data-dt-scroll-wrap-class="sihos-auditoria-glosa-scroll"
                       data-dt-order="[[1,&quot;desc&quot;]]">
                    <thead>
                        <tr>
                            <th>Tipo de usuario</th>
                            <th>Fecha</th>
                            <th>Documento</th>
                            <th>Factura</th>
                            <th>Entidad</th>
                            <th>Documento entidad</th>
                            <th style="text-align:right;">Valor</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            <?php endif; ?>

        <?php endif; ?>

    <?php endif; ?>

</div>

<script>
(function () {
    // Overlay de "cargando": la consulta a SIHOS tarda varios segundos.
    var form = document.getElementById('sihosDeterioroForm');
    if (form && typeof savidMostrarCargando === 'function') {
        form.addEventListener('submit', function () {
            savidMostrarCargando('Consultando deterioro de cartera…', true);
        });
    }

    var detalle = document.getElementById('sihosDeterioroDetalle');
    if (!detalle || typeof jQuery === 'undefined') {
        return;
    }

    var aviso = document.getElementById('sihosDeterioroFiltroActivo');
    var textoAviso = document.getElementById('sihosDeterioroFiltroTexto');

    function api() {
        return jQuery(detalle).DataTable();
    }

    // Deja el filtro de columna (coincidencia exacta con prefijo "=", ver
    // SihosDeterioroService::paginarParaDataTable) y refleja el valor en el
    // input de filtro del encabezado para que sea visible y editable.
    function aplicarFiltro(columna, valor, etiqueta) {
        var tabla = api();
        var inputs = detalle.closest('.savid-dt-shell, .dt-container')
            ? detalle.closest('.savid-dt-shell, .dt-container').querySelectorAll('thead tr.savid-dt-filters input')
            : [];
        tabla.columns().every(function (i) {
            this.search(i === columna && valor !== '' ? '=' + valor : '');
            if (inputs[i]) {
                inputs[i].value = (i === columna && valor !== '') ? '=' + valor : '';
            }
        });
        tabla.draw();

        aviso.hidden = valor === '';
        textoAviso.textContent = etiqueta || '';
    }

    // Delegado en document: DataTables reordena/repagina las filas de los resúmenes.
    document.addEventListener('click', function (ev) {
        var fila = ev.target.closest ? ev.target.closest('tr.sihos-deterioro-filtro') : null;
        if (!fila) {
            return;
        }
        var etiqueta = fila.dataset.col === '0'
            ? fila.dataset.valor
            : fila.cells[2].textContent.trim() + ' (' + fila.dataset.valor + ')';
        aplicarFiltro(parseInt(fila.dataset.col, 10), fila.dataset.valor, etiqueta);
        document.getElementById('sihosDeterioroDetalleTitulo').scrollIntoView({ behavior: 'smooth', block: 'start' });
    });

    document.getElementById('sihosDeterioroQuitarFiltro').addEventListener('click', function () {
        aplicarFiltro(-1, '', '');
    });
})();
</script>
