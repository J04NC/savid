<?php
/** @var array $scope */
/** @var bool $configurado */
/** @var ?array $comparacion */
/** @var ?array $archivoInfo */
/** @var string $codiAno */
/** @var string $codiMes */
?>

<div class="module-container auditoria-page">

    <div class="crud-toolbar auditoria-toolbar">
        <div class="auditoria-toolbar-title">
            <h2 class="auditoria-title">SIHOS — Planilla Integrada vs SIHOS</h2>
            <p class="auditoria-subtitle">
                Carga la Planilla Integrada de Liquidación de Aportes (el archivo YA liquidado que entrega el operador
                al terminar el cargue completo) y la compara de solo lectura contra SIHOS — por empleado y agregado
                por administradora. Esta pantalla no escribe nada en SIHOS.
            </p>
        </div>
    </div>

    <?php $filterUrl = 'sihos/nominaPlanillaIntegrada'; require BASE_PATH . '/app/views/sgd/_empresa_filter.php'; ?>

    <?php
    /* _empresa_filter.php también usa $empresaId internamente; se recalcula
       aquí después del require para no quedarse con el valor pisado. */
    $empresaId = $scope['empresaId'] ?? null;
    ?>

    <?php if ($empresaId === null): ?>
        <p class="field-note sgd-page-empty">Seleccione la empresa en el filtro superior para usar esta pantalla.</p>
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

        <form method="post" enctype="multipart/form-data" class="crud-form auditoria-filters-grid" style="margin-bottom:20px;">
            <input type="hidden" name="url" value="sihos/nominaPlanillaIntegrada">
            <input type="hidden" name="empresa_id" value="<?= (int)$empresaId ?>">
            <div class="form-group">
                <label for="piCodiAno">Año a comparar en SIHOS</label>
                <select id="piCodiAno" name="codi_ano" class="form-input" required>
                    <?php for ($anoOpcion = $anoActual; $anoOpcion >= $anoActual - 5; $anoOpcion--): ?>
                        <option value="<?= $anoOpcion ?>" <?= $anoOpcion === $anoSeleccionado ? 'selected' : '' ?>><?= $anoOpcion ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="piCodiMes">Mes a comparar en SIHOS</label>
                <select id="piCodiMes" name="codi_mes" class="form-input" required>
                    <?php foreach ($mesesNombre as $numeroMes => $nombreMes): ?>
                        <option value="<?= $numeroMes ?>" <?= $numeroMes === $mesSeleccionado ? 'selected' : '' ?>><?= $nombreMes ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="piCsv">Planilla Integrada (CSV)</label>
                <input type="file" id="piCsv" name="csv_planilla" accept=".csv,text/csv" class="form-input" required>
            </div>
            <div class="form-group" style="align-self:flex-end;">
                <button type="submit" class="auditoria-btn-primary">📤 Cargar y comparar</button>
            </div>
        </form>

        <?php if ($comparacion === null): ?>
            <p class="auditoria-subtitle">Elija el período de SIHOS a comparar y cargue la Planilla Integrada.</p>
        <?php elseif (!$comparacion['ok']): ?>
            <p class="modal-form-alert">⚠️ <?= htmlspecialchars($comparacion['error']) ?></p>
        <?php else: ?>

            <?php if ($archivoInfo !== null): ?>
                <p class="field-note">
                    Archivo: <?= htmlspecialchars($archivoInfo['empresa']['razon_social']) ?> (NIT <?= htmlspecialchars($archivoInfo['empresa']['nit']) ?>)
                    — período cotizado en el archivo: <?= htmlspecialchars($archivoInfo['periodo_archivo']['cotizado']) ?>,
                    pago: <?= htmlspecialchars($archivoInfo['periodo_archivo']['pago']) ?>.
                    <?php if ($archivoInfo['periodo_archivo']['cotizado'] !== '' && $archivoInfo['periodo_archivo']['cotizado'] !== sprintf('%04d-%02d', $anoSeleccionado, $mesSeleccionado)): ?>
                        <br><span style="color:#e67e22;">⚠ El período del archivo no coincide con el período de SIHOS elegido arriba — verifique que sean el mismo antes de interpretar las diferencias.</span>
                    <?php endif; ?>
                </p>
            <?php endif; ?>

            <?php
            $empleados = $comparacion['empleados'];
            $administradoras = $comparacion['administradoras'];

            $totalConceptosEmpleado = 0;
            $totalDiferenciasEmpleado = 0;
            foreach ($empleados as $emp) {
                foreach ($emp['conceptos'] as $c) {
                    $totalConceptosEmpleado++;
                    if ($c['estado'] === 'diferencia') {
                        $totalDiferenciasEmpleado++;
                    }
                }
            }

            $etiquetasEstado = [
                'coincide' => ['texto' => 'Coincide', 'color' => '#7f8c8d'],
                'diferencia' => ['texto' => 'Diferencia', 'color' => '#e67e22'],
                'no_encontrado' => ['texto' => 'No se encontró en SIHOS', 'color' => '#e74c3c'],
                'no_en_archivo' => ['texto' => 'SIHOS tiene esta administradora pero el archivo no', 'color' => '#e74c3c'],
            ];

            $nombreConcepto = [
                'pension' => 'Pensión', 'salud' => 'Salud', 'ccf' => 'CCF', 'arl' => 'ARL', 'sena' => 'SENA', 'icbf' => 'ICBF',
                'fondo_solidaridad' => 'Fondo Solidaridad Pensional',
            ];
            ?>

            <p class="field-note">
                <?= count($empleados) ?> empleado(s), <?= $totalConceptosEmpleado ?> comparaciones de concepto —
                <?= $totalDiferenciasEmpleado ?> con diferencia frente a SIHOS.
                Las diferencias de unos $100 por concepto son el margen de redondeo normal entre PILA y SIHOS ya
                documentado; diferencias mayores, o marcadas como inconsistencia interna del archivo, merecen revisión
                aparte.
            </p>

            <div class="crud-toolbar auditoria-toolbar" style="margin-top:8px;margin-bottom:0;">
                <div class="auditoria-toolbar-title">
                    <h3 class="auditoria-title" style="font-size:15px;">Totales por administradora</h3>
                    <p class="auditoria-subtitle">Suma de toda la nómina del período, agrupada por administradora — chequeo agregado independiente del detalle por empleado.</p>
                </div>
            </div>
            <div style="overflow:auto;">
                <table class="seguridad-table no-datatable" style="white-space:nowrap;width:100%;">
                    <thead>
                        <tr>
                            <th>Concepto</th>
                            <th>Administradora</th>
                            <th>Afiliados (archivo)</th>
                            <th>Valor archivo</th>
                            <th>Valor SIHOS</th>
                            <th>Diferencia</th>
                            <th>Consistencia interna del archivo</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($administradoras as $a): ?>
                            <?php $et = $etiquetasEstado[$a['estado']]; ?>
                            <tr>
                                <td><?= htmlspecialchars($a['nombre_concepto']) ?></td>
                                <td><?= htmlspecialchars($a['nombre'] ?? '—') ?><?= $a['nit'] !== '' ? ' (' . htmlspecialchars($a['nit']) . ')' : '' ?></td>
                                <td><?= $a['afiliados'] ?? '—' ?></td>
                                <td>$<?= number_format($a['valor_archivo'], 0, ',', '.') ?></td>
                                <td><?= $a['valor_sihos'] !== null ? '$' . number_format($a['valor_sihos'], 0, ',', '.') : '—' ?></td>
                                <td><?= $a['diferencia'] !== null ? '$' . number_format($a['diferencia'], 0, ',', '.') : '—' ?></td>
                                <td>
                                    <?php if ($a['inconsistente_internamente']): ?>
                                        <span style="color:#e74c3c;">⚠ El resumen del archivo ($<?= number_format($a['valor_archivo'], 0, ',', '.') ?>) no coincide
                                        con la suma de sus propios empleados ($<?= number_format($a['suma_detalle_archivo'], 0, ',', '.') ?>,
                                        diferencia $<?= number_format($a['diferencia_interna'], 0, ',', '.') ?>) — revisar el archivo, no necesariamente SIHOS.</span>
                                    <?php elseif ($a['suma_detalle_archivo'] !== null): ?>
                                        <span class="field-note">OK (cuadra con su propio detalle)</span>
                                    <?php else: ?>
                                        <span class="field-note">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><span style="color:<?= $et['color'] ?>;"><?= $et['texto'] ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="crud-toolbar auditoria-toolbar" style="margin-top:18px;margin-bottom:0;">
                <div class="auditoria-toolbar-title">
                    <h3 class="auditoria-title" style="font-size:15px;">Detalle por empleado</h3>
                </div>
            </div>

            <?php foreach ($empleados as $emp): ?>
                <div class="crud-toolbar auditoria-toolbar" style="margin-top:14px;margin-bottom:0;padding-bottom:6px;border-bottom:1px solid var(--border-color, #444);">
                    <div class="auditoria-toolbar-title">
                        <h4 class="auditoria-title" style="font-size:14px;">
                            <?= htmlspecialchars($emp['tipo_docu']) ?> <?= htmlspecialchars($emp['no_id']) ?>
                            <?php if ($emp['nombre']): ?> — <?= htmlspecialchars($emp['nombre']) ?><?php endif; ?>
                        </h4>
                    </div>
                </div>
                <div style="overflow:auto;">
                    <table class="seguridad-table no-datatable" style="white-space:nowrap;width:100%;">
                        <tbody>
                            <?php foreach ($emp['conceptos'] as $c): ?>
                                <?php $et = $etiquetasEstado[$c['estado']]; ?>
                                <tr>
                                    <td style="width:90px;"><strong><?= htmlspecialchars($nombreConcepto[$c['concepto']] ?? $c['concepto']) ?></strong></td>
                                    <td>archivo $<?= number_format($c['suma_archivo'], 0, ',', '.') ?></td>
                                    <td><?= $c['suma_sihos'] !== null ? 'SIHOS $' . number_format($c['suma_sihos'], 0, ',', '.') : 'sin registro en SIHOS' ?></td>
                                    <td><?= $c['diferencia'] !== null ? 'diferencia $' . number_format($c['diferencia'], 0, ',', '.') : '' ?></td>
                                    <td class="sihos-correccion-estado">
                                        <span style="color:<?= $et['color'] ?>;"><?= $et['texto'] ?></span>
                                        <?php if ($c['lineas_sihos'] !== []): ?>
                                            <details style="display:inline;">
                                                <summary style="display:inline;cursor:pointer;color:var(--link-color,#5aa9ff);">Ver registros en SIHOS</summary>
                                                <div style="margin-top:6px;padding:8px;background:rgba(255,255,255,0.03);border-radius:6px;">
                                                    <ul style="margin:0;padding:0 0 0 18px;">
                                                        <?php foreach ($c['lineas_sihos'] as $linea): ?>
                                                            <li>
                                                                <?= htmlspecialchars($linea['nombre_docu']) ?> (<?= htmlspecialchars($linea['codi_docu']) ?>-<?= htmlspecialchars($linea['nume_docu']) ?>)
                                                                — <?= $linea['causado'] ? 'CONFIRMADA' : 'sin confirmar' ?>
                                                                — $<?= number_format($linea['total'], 0, ',', '.') ?>
                                                            </li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                </div>
                                            </details>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>

        <?php endif; ?>

    <?php endif; ?>

</div>
