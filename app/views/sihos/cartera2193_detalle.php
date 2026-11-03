<?php
/** @var array<string,mixed> $detalle ok, filas, total, nombre, tipo | error */
/** @var float $celda valor que mostraba la celda del reporte */
/** @var string $columna */
/** @var string $nuDoTerc */

$fmt = static fn (float $v): string => SihosCartera2193Service::formatoMoneda($v);
$edades = ['0 - 60 días', '61 - 90 días', '91 - 180 días', '181 - 360 días', 'Más de 360 días'];
$titulosColumna = ['rad' => 'Total por cobrar con facturación radicada', 'pend' => 'Facturación pendiente de radicar', 'glosa' => 'Glosa inicial (objeciones pendientes)'];
foreach ($edades as $i => $edad) {
    $titulosColumna['e' . $i] = 'Radicada ' . $edad;
}
$fecha = static fn (string $f): string => $f === '' || str_starts_with($f, '0000') ? '—' : $f;
?>
<div class="modal-inner">
    <header class="modal-form-head">
        <h3 class="modal-form-title">Detalle de cartera 2193</h3>
        <?php if ($detalle['ok']): ?>
            <p class="modal-form-meta">
                <strong><?= htmlspecialchars($detalle['nombre'] !== '' ? $detalle['nombre'] : $nuDoTerc) ?></strong>
                · <?= htmlspecialchars($detalle['tipo']) ?> · <?= htmlspecialchars($titulosColumna[$columna] ?? $columna) ?>
            </p>
        <?php endif; ?>
    </header>

    <?php if (!$detalle['ok']): ?>
        <p class="modal-form-alert">⚠️ <?= htmlspecialchars($detalle['error']) ?></p>
    <?php else: ?>
        <p class="field-note">
            <?= count($detalle['filas']) ?> factura(s) · suma del detalle <strong><?= $fmt($detalle['total']) ?></strong>
            · valor en el reporte <strong><?= $fmt($celda) ?></strong>
            <?php if (abs($detalle['total'] - $celda) >= 0.01): ?>
                <br>⚠️ No coinciden: SIHOS pudo cambiar desde que se generó el reporte (documentos con fecha anterior al corte). Vuelva a consultar el reporte.
            <?php endif; ?>
        </p>

        <table class="savid-datatable seguridad-table savid-dt-fit" id="sihosCartera2193DetalleTabla" data-dt-order="[[9,&quot;desc&quot;]]" data-dt-page-length="50">
            <thead>
                <tr>
                    <th>Documento</th>
                    <th>Número</th>
                    <th>Fecha factura</th>
                    <th>Fecha radicación</th>
                    <th>Fecha para la edad</th>
                    <th>Edad</th>
                    <th>Estado</th>
                    <th>Saldo</th>
                    <th>Glosa en curso</th>
                    <th>Valor de la celda</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($detalle['filas'] as $f): ?>
                    <tr>
                        <td><?= htmlspecialchars($f['CodiDocu']) ?></td>
                        <td><?= htmlspecialchars($f['NumeDocu']) ?></td>
                        <td><?= htmlspecialchars($fecha($f['FechDocu'])) ?></td>
                        <td><?= htmlspecialchars($fecha($f['FechRadi'])) ?></td>
                        <td><?= htmlspecialchars($fecha($f['FechEdad'])) ?></td>
                        <td><?= htmlspecialchars($edades[$f['edad']] ?? '—') ?></td>
                        <td><?= $f['radicada'] ? 'Radicada' : 'Pendiente' ?></td>
                        <td style="text-align:right;" data-order="<?= $f['saldo'] ?>"><?= $fmt($f['saldo']) ?></td>
                        <td style="text-align:right;" data-order="<?= $f['glosa'] ?>"><?= $fmt($f['glosa']) ?></td>
                        <td style="text-align:right;" data-order="<?= $f['valor'] ?>"><?= $fmt($f['valor']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="9" style="text-align:right;">Suma</th>
                    <th style="text-align:right;"><?= $fmt($detalle['total']) ?></th>
                </tr>
            </tfoot>
        </table>
    <?php endif; ?>
</div>
