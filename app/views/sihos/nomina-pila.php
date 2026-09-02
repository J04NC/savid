<?php
/** @var array $scope */
/** @var bool $configurado */
/** @var ?array $resultado */
/** @var string $codiAno */
/** @var string $codiMes */
?>

<div class="module-container auditoria-page">

    <div class="crud-toolbar auditoria-toolbar">
        <div class="auditoria-toolbar-title">
            <h2 class="auditoria-title">SIHOS — Nómina: Aportes en línea (PILA)</h2>
            <p class="auditoria-subtitle">Genera el archivo de nómina del período elegido, en el mismo orden de columnas que la plantilla de cargue de aportes en línea.</p>
        </div>
        <?php if (class_exists('PermisoService') && PermisoService::can('sihos/nominaPilaCorreccion', 'ver')): ?>
            <a href="?url=sihos/nominaPilaCorreccion" class="auditoria-btn-primary" style="text-decoration:none;">
                🛠️ ¿El portal pidió correcciones? Cárgalas aquí
            </a>
        <?php endif; ?>
    </div>

    <?php $filterUrl = 'sihos/nominaPila'; require BASE_PATH . '/app/views/sgd/_empresa_filter.php'; ?>

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

        <?php
        $mesesNombre = [
            1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
            7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
        ];
        $anoActual = (int)date('Y');
        $anoSeleccionado = (int)$codiAno;
        $mesSeleccionado = (int)ltrim($codiMes, '0');
        ?>
        <form method="get" id="sihosNominaPilaForm" class="crud-form auditoria-filters-grid" style="margin-bottom:20px;">
            <input type="hidden" name="url" value="sihos/nominaPila">
            <input type="hidden" name="empresa_id" value="<?= (int)$empresaId ?>">
            <div class="form-group">
                <label for="sihosCodiAno">Año</label>
                <select id="sihosCodiAno" name="codi_ano" class="form-input" required>
                    <?php for ($anoOpcion = $anoActual; $anoOpcion >= $anoActual - 5; $anoOpcion--): ?>
                        <option value="<?= $anoOpcion ?>" <?= $anoOpcion === $anoSeleccionado ? 'selected' : '' ?>><?= $anoOpcion ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="sihosCodiMes">Mes</label>
                <select id="sihosCodiMes" name="codi_mes" class="form-input" required>
                    <?php foreach ($mesesNombre as $numeroMes => $nombreMes): ?>
                        <option value="<?= $numeroMes ?>" <?= $numeroMes === $mesSeleccionado ? 'selected' : '' ?>><?= $nombreMes ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="align-self:flex-end;">
                <button type="submit" name="buscar" value="1" class="auditoria-btn-primary">🔍 Consultar</button>
            </div>
        </form>

        <?php if ($resultado === null): ?>
            <p class="auditoria-subtitle">Elija el período (año y mes) a reportar.</p>
        <?php elseif (!$resultado['ok']): ?>
            <p class="modal-form-alert">⚠️ <?= htmlspecialchars($resultado['error']) ?></p>
        <?php else: ?>

            <p class="field-note">
                <?= count($resultado['filas']) ?> fila(s) encontradas para el período <?= htmlspecialchars($codiAno) ?>-<?= htmlspecialchars(str_pad(ltrim($codiMes, '0'), 2, '0', STR_PAD_LEFT)) ?>
                (un empleado puede aparecer más de una fila si tuvo vacaciones, incapacidad, licencia no remunerada o licencia de maternidad en el período).
            </p>

            <?php if (count($resultado['filas']) > 0): ?>
                <form method="get" action="" class="crud-form auditoria-filters-grid" style="margin-bottom:20px;">
                    <input type="hidden" name="url" value="sihos/nominaPilaExportar">
                    <input type="hidden" name="empresa_id" value="<?= (int)$empresaId ?>">
                    <input type="hidden" name="codi_ano" value="<?= htmlspecialchars($codiAno) ?>">
                    <input type="hidden" name="codi_mes" value="<?= htmlspecialchars($codiMes) ?>">
                    <div class="form-group">
                        <label for="sihosSucursalCodigo">Código de sucursal</label>
                        <input type="text" id="sihosSucursalCodigo" name="sucursal_codigo" class="form-input">
                    </div>
                    <div class="form-group" style="align-self:flex-end;">
                        <button type="submit" class="auditoria-btn-primary">⬇️ Descargar Excel (.xlsx)</button>
                    </div>
                </form>

                <div class="crud-toolbar auditoria-toolbar">
                    <div class="auditoria-toolbar-title">
                        <h3 class="auditoria-title" style="font-size:16px;">Vista previa (<?= count($resultado['filas']) ?>)</h3>
                        <p class="auditoria-subtitle">Mismas columnas y mismo orden que el archivo exportado — para revisar antes de descargar.</p>
                    </div>
                </div>

                <div style="overflow-x:auto;">
                    <table class="seguridad-table" style="white-space:nowrap;">
                        <thead>
                            <tr>
                                <th>No.</th>
                                <?php foreach (SihosNominaPilaService::ENCABEZADOS as $encabezado): ?>
                                    <th><?= htmlspecialchars($encabezado) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $numeroFila = 1; ?>
                            <?php foreach ($resultado['filas'] as $fila): ?>
                                <tr>
                                    <td><?= $numeroFila++ ?></td>
                                    <?php foreach ($fila as $valor): ?>
                                        <td><?= htmlspecialchars((string)($valor ?? '')) ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

        <?php endif; ?>
    <?php endif; ?>

</div>
