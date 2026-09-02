<?php
/** @var array $scope */
/** @var bool $configurado */
/** @var ?array $vistaPrevia */
/** @var string $codiAno */
/** @var string $codiMes */
/** @var bool $puedeGuardar */

$assetSihosCorreccion = BASE_PATH . '/public/js/sihos-nomina-correccion.js';
$sihosCorreccionJsV = is_readable($assetSihosCorreccion) ? (int)filemtime($assetSihosCorreccion) : time();
?>

<div class="module-container auditoria-page" data-sihos-nomina-correccion data-puede-guardar="<?= $puedeGuardar ? '1' : '0' ?>">

    <div class="crud-toolbar auditoria-toolbar">
        <div class="auditoria-toolbar-title">
            <h2 class="auditoria-title">SIHOS — Corrección Nómina (PILA)</h2>
            <p class="auditoria-subtitle">
                Carga el reporte de validación (CSV) que entrega el portal de aportes en línea después de subir el archivo,
                y corrige en SIHOS el aporte patronal de las cotizaciones que aún se puedan ajustar antes de confirmar la nómina.
            </p>
        </div>
    </div>

    <?php $filterUrl = 'sihos/nominaPilaCorreccion'; require BASE_PATH . '/app/views/sgd/_empresa_filter.php'; ?>

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

        <form id="sihosNominaPilaCorreccionForm" method="post" enctype="multipart/form-data" class="crud-form auditoria-filters-grid" style="margin-bottom:20px;">
            <input type="hidden" name="url" value="sihos/nominaPilaCorreccion">
            <input type="hidden" name="empresa_id" value="<?= (int)$empresaId ?>">
            <div class="form-group">
                <label for="corrCodiAno">Año</label>
                <select id="corrCodiAno" name="codi_ano" class="form-input" required>
                    <?php for ($anoOpcion = $anoActual; $anoOpcion >= $anoActual - 5; $anoOpcion--): ?>
                        <option value="<?= $anoOpcion ?>" <?= $anoOpcion === $anoSeleccionado ? 'selected' : '' ?>><?= $anoOpcion ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="corrCodiMes">Mes</label>
                <select id="corrCodiMes" name="codi_mes" class="form-input" required>
                    <?php foreach ($mesesNombre as $numeroMes => $nombreMes): ?>
                        <option value="<?= $numeroMes ?>" <?= $numeroMes === $mesSeleccionado ? 'selected' : '' ?>><?= $nombreMes ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="corrCsv">CSV de correcciones del operador</label>
                <input type="file" id="corrCsv" name="csv_correcciones" accept=".csv,text/csv" class="form-input" required>
            </div>
            <div class="form-group" style="align-self:flex-end;">
                <button type="submit" class="auditoria-btn-primary">📤 Cargar y comparar</button>
            </div>
        </form>

        <?php if ($vistaPrevia === null): ?>
            <p class="auditoria-subtitle">Elija el período y cargue el CSV que descargó del portal de aportes en línea.</p>
        <?php elseif (!$vistaPrevia['ok']): ?>
            <p class="modal-form-alert">⚠️ <?= htmlspecialchars($vistaPrevia['error']) ?></p>
        <?php else: ?>

            <?php
            $empleados = $vistaPrevia['empleados'];
            $totalConceptos = 0;
            $totalAplicables = 0;
            foreach ($empleados as $emp) {
                $totalConceptos += count($emp['conceptos']);
                foreach ($emp['conceptos'] as $c) {
                    if ($c['aplicable']) {
                        $totalAplicables++;
                    }
                }
            }

            $etiquetasEstado = [
                'aplicable' => ['texto' => 'Corregible', 'color' => '#2ecc71'],
                'confirmada' => ['texto' => 'Nómina ya confirmada — no corregible aquí', 'color' => '#e67e22'],
                'ya_coincide' => ['texto' => 'Ya coincide — nada que corregir', 'color' => '#7f8c8d'],
                'ambiguo' => ['texto' => 'Varias líneas abiertas — revisar manualmente', 'color' => '#e74c3c'],
                'no_encontrado' => ['texto' => 'No se encontró en SIHOS', 'color' => '#7f8c8d'],
            ];

            $nombreConcepto = ['pension' => 'Pensión', 'salud' => 'Salud', 'ccf' => 'CCF', 'sena' => 'SENA', 'icbf' => 'ICBF', 'fondo_solidaridad' => 'FSP'];
            ?>

            <p class="field-note">
                <?= count($empleados) ?> empleado(s) con corrección de cotización obligatoria reportada por el operador para el período
                <?= htmlspecialchars($codiAno) ?>-<?= htmlspecialchars(str_pad(ltrim($codiMes, '0'), 2, '0', STR_PAD_LEFT)) ?>
                (<?= $totalConceptos ?> concepto(s) en total) — <?= $totalAplicables ?> se pueden corregir directamente aquí.
                Cuando un empleado tuvo vacaciones/incapacidad/licencia en el período, el archivo PILA calcula la cotización de
                cada porción del mes por separado — por eso puede haber más de un "valor esperado" del operador para el mismo
                concepto: son <strong>partes de un mismo total</strong>, se suman aquí y se comparan contra la suma real en
                SIHOS (que también puede vivir en más de un documento — nómina normal y de vacaciones). Cuando hay más de un
                registro sin confirmar para el mismo concepto, el sistema <strong>elige automáticamente el de mayor valor</strong>
                para aplicar el ajuste — es una suposición, no una certeza; esas filas se marcan con ⚠ y conviene revisarlas
                antes de aplicar. Los demás tipos de error del reporte (cantidad de empleados, IBC, clase de riesgo, nombre del afiliado, etc.)
                no se muestran aquí — quedan para revisión manual.
            </p>

            <?php if ($empleados === []): ?>
                <p class="auditoria-subtitle">No se encontró ninguna corrección de cotización obligatoria (pensión/salud/CCF/SENA/ICBF/FSP) en este archivo.</p>
            <?php else: ?>

                <form id="sihosCorreccionForm" data-codi-ano="<?= htmlspecialchars($codiAno) ?>" data-codi-mes="<?= htmlspecialchars($codiMes) ?>" data-empresa-id="<?= (int)$empresaId ?>">

                    <?php foreach ($empleados as $emp): ?>
                        <div class="crud-toolbar auditoria-toolbar" style="margin-top:18px;margin-bottom:0;padding-bottom:8px;border-bottom:1px solid var(--border-color, #444);">
                            <div class="auditoria-toolbar-title">
                                <h3 class="auditoria-title" style="font-size:15px;">
                                    <?= htmlspecialchars($emp['tipo_docu']) ?> <?= htmlspecialchars($emp['no_id']) ?>
                                    <?php if ($emp['nombre']): ?> — <?= htmlspecialchars($emp['nombre']) ?><?php endif; ?>
                                </h3>
                            </div>
                        </div>

                        <div style="overflow:auto;">
                            <table class="seguridad-table no-datatable" style="white-space:nowrap;width:100%;">
                                <tbody>
                                    <?php foreach ($emp['conceptos'] as $c): ?>
                                        <?php $et = $etiquetasEstado[$c['estado']]; ?>
                                        <tr class="sihos-correccion-fila" data-fila-aplicable="<?= $c['aplicable'] ? '1' : '0' ?>">
                                            <td style="width:24px;">
                                                <?php if ($c['aplicable']): ?>
                                                    <input type="checkbox" class="sihos-correccion-check"
                                                        data-tipo-docu="<?= htmlspecialchars($c['tipo_docu'], ENT_QUOTES, 'UTF-8') ?>"
                                                        data-no-id="<?= htmlspecialchars($c['no_id'], ENT_QUOTES, 'UTF-8') ?>"
                                                        data-concepto="<?= htmlspecialchars($c['concepto'], ENT_QUOTES, 'UTF-8') ?>"
                                                        data-suma-esperada="<?= htmlspecialchars((string)$c['suma_esperada'], ENT_QUOTES, 'UTF-8') ?>">
                                                <?php endif; ?>
                                            </td>
                                            <td style="width:90px;"><strong><?= htmlspecialchars($nombreConcepto[$c['concepto']] ?? $c['concepto']) ?></strong></td>
                                            <td>
                                                <?php if (count($c['lineas_sihos']) === 1): ?>
                                                    <?= htmlspecialchars($c['lineas_sihos'][0]['nombre_docu']) ?>
                                                    (<?= htmlspecialchars($c['lineas_sihos'][0]['codi_docu']) ?>-<?= htmlspecialchars($c['lineas_sihos'][0]['nume_docu']) ?>)
                                                <?php elseif ($c['lineas_sihos'] === []): ?>
                                                    —
                                                <?php else: ?>
                                                    <?= count($c['lineas_sihos']) ?> registro(s) en SIHOS
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($c['base_ibc_total'] !== null): ?>
                                                    base (I.B.C.) $<?= number_format((float)$c['base_ibc_total'], 0, ',', '.') ?>
                                                    <?php if ($c['tarifa_texto'] !== null): ?>
                                                        <span class="field-note">× <?= htmlspecialchars($c['tarifa_texto']) ?></span>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($c['suma_actual'] !== null): ?>
                                                    actual (suma) $<?= number_format((float)$c['suma_actual'], 0, ',', '.') ?>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                esperado (suma) $<?= number_format((float)$c['suma_esperada'], 0, ',', '.') ?>
                                                <?php if (count($c['valores_esperados']) > 1): ?>
                                                    <span class="field-note">(<?= count($c['valores_esperados']) ?> valores)</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($c['suma_actual'] !== null): ?>
                                                    <?php $dif = round($c['suma_esperada'] - $c['suma_actual'], 2); ?>
                                                    <span style="color:<?= abs($dif) < 0.01 ? '#7f8c8d' : '#e67e22' ?>;">
                                                        diferencia $<?= number_format($dif, 0, ',', '.') ?>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($c['ajusta_valo_empe']) && isset($c['valo_empe_actual'], $c['valo_empe_propuesto'])): ?>
                                                    empleado (línea abierta) $<?= number_format((float)$c['valo_empe_actual'], 0, ',', '.') ?> →
                                                    $<?= number_format((float)$c['valo_empe_propuesto'], 0, ',', '.') ?>
                                                    <span class="field-note">(FSP no tiene aporte patronal en SIHOS)</span>
                                                <?php elseif (isset($c['valo_patr_actual'], $c['valo_patr_propuesto'])): ?>
                                                    patronal (línea abierta) $<?= number_format((float)$c['valo_patr_actual'], 0, ',', '.') ?> →
                                                    $<?= number_format((float)$c['valo_patr_propuesto'], 0, ',', '.') ?>
                                                <?php endif; ?>
                                            </td>
                                            <td class="sihos-correccion-estado">
                                                <span style="color:<?= $et['color'] ?>;"><?= $et['texto'] ?></span>
                                                <?php if (!empty($c['elegida_entre_varias'])): ?>
                                                    <span title="Había <?= (int)$c['total_lineas_abiertas'] ?> líneas sin confirmar para este concepto — se eligió automáticamente la de mayor valor. Verifique antes de aplicar." style="color:#e67e22;cursor:help;">⚠</span>
                                                <?php endif; ?>
                                                <details style="display:inline;">
                                                    <summary style="display:inline;cursor:pointer;color:var(--link-color,#5aa9ff);">Ver detalle</summary>
                                                    <div style="margin-top:6px;padding:8px;background:rgba(255,255,255,0.03);border-radius:6px;">

                                                        <p style="margin:0 0 6px 0;">
                                                            <strong>Por línea del archivo (lo que exportamos vs. lo que pide el operador):</strong>
                                                        </p>
                                                        <?php $tarifaNumerica = $c['tarifa_texto'] !== null ? (float)rtrim($c['tarifa_texto'], '%') : null; ?>
                                                        <table style="width:100%;border-collapse:collapse;margin-bottom:8px;">
                                                            <thead>
                                                                <tr style="text-align:left;">
                                                                    <th style="padding:2px 8px 2px 0;">Línea</th>
                                                                    <th style="padding:2px 8px;">Exportamos</th>
                                                                    <th style="padding:2px 8px;">Base exportada</th>
                                                                    <th style="padding:2px 8px;">Operador pide</th>
                                                                    <th style="padding:2px 8px;">Base operador</th>
                                                                    <th style="padding:2px 0;">Diferencia</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                <?php foreach ($c['valores_esperados'] as $v): ?>
                                                                    <?php
                                                                    $difFila = $v['valor_original'] !== null ? round($v['valor'] - $v['valor_original'], 2) : null;
                                                                    $baseExportadaFila = ($v['valor_original'] !== null && $tarifaNumerica > 0) ? round($v['valor_original'] / ($tarifaNumerica / 100)) : null;
                                                                    $baseOperadorFila = $tarifaNumerica > 0 ? round($v['valor'] / ($tarifaNumerica / 100)) : null;
                                                                    ?>
                                                                    <tr>
                                                                        <td style="padding:2px 8px 2px 0;"><?= htmlspecialchars($v['linea_csv']) ?></td>
                                                                        <td style="padding:2px 8px;"><?= $v['valor_original'] !== null ? '$' . number_format($v['valor_original'], 0, ',', '.') : '—' ?></td>
                                                                        <td style="padding:2px 8px;" class="field-note"><?= $baseExportadaFila !== null ? '$' . number_format($baseExportadaFila, 0, ',', '.') : '—' ?></td>
                                                                        <td style="padding:2px 8px;">$<?= number_format($v['valor'], 0, ',', '.') ?></td>
                                                                        <td style="padding:2px 8px;" class="field-note"><?= $baseOperadorFila !== null ? '$' . number_format($baseOperadorFila, 0, ',', '.') : '—' ?></td>
                                                                        <td style="padding:2px 0;<?= $difFila !== null && abs($difFila) >= 0.01 ? ' color:#e67e22;' : '' ?>">
                                                                            <?= $difFila !== null ? '$' . number_format($difFila, 0, ',', '.') : '—' ?>
                                                                        </td>
                                                                    </tr>
                                                                <?php endforeach; ?>
                                                            </tbody>
                                                        </table>
                                                        <?php if ($tarifaNumerica > 0): ?>
                                                            <p class="field-note" style="margin:0 0 6px 0;">Base exportada/operador = valor ÷ tarifa (<?= htmlspecialchars($c['tarifa_texto']) ?>) — referencia, el redondeo real es por línea.</p>
                                                        <?php endif; ?>
                                                        <p style="margin:0 0 4px 0;">
                                                            Suma exportada (filas reportadas): <?= $c['suma_original'] !== null ? '$' . number_format($c['suma_original'], 0, ',', '.') : '—' ?>
                                                            — Suma pedida por el operador (filas reportadas): $<?= number_format($c['suma_esperada_reportada'], 0, ',', '.') ?>
                                                        </p>
                                                        <?php
                                                        $sumaOtrasPorciones = ($c['suma_exportada_total'] !== null && $c['suma_original'] !== null)
                                                            ? round($c['suma_exportada_total'] - $c['suma_original'], 2)
                                                            : null;
                                                        ?>
                                                        <?php if ($sumaOtrasPorciones !== null && abs($sumaOtrasPorciones) >= 0.01): ?>
                                                            <p style="margin:0 0 4px 0;">
                                                                + Otra(s) porción(es) exportada(s) sin error reportado (el operador las considera correctas): $<?= number_format($sumaOtrasPorciones, 0, ',', '.') ?>
                                                            </p>
                                                        <?php endif; ?>
                                                        <?php if ($c['base_ibc_total'] !== null): ?>
                                                            <p style="margin:0 0 4px 0;">
                                                                Base (I.B.C.) usada en el archivo exportado (suma de todas las porciones): $<?= number_format((float)$c['base_ibc_total'], 0, ',', '.') ?>
                                                                <?php if ($c['tarifa_texto'] !== null): ?>
                                                                    × <?= htmlspecialchars($c['tarifa_texto']) ?>
                                                                    = $<?= number_format(round((float)$c['base_ibc_total'] * (float)rtrim($c['tarifa_texto'], '%') / 100, 0), 0, ',', '.') ?>
                                                                    <span class="field-note">(referencia — el redondeo real es por porción, no sobre el total)</span>
                                                                <?php endif; ?>
                                                            </p>
                                                        <?php endif; ?>
                                                        <p style="margin:0 0 8px 0;">
                                                            <strong>Suma esperada total (usada para comparar contra SIHOS): $<?= number_format($c['suma_esperada'], 0, ',', '.') ?></strong>
                                                            <?php if ($c['suma_exportada_total'] === null || $c['suma_original'] === null): ?>
                                                                <br><span class="field-note">No se pudo reconstruir el total exportado completo — esta suma solo incluye las filas reportadas por el operador; puede haber otras porciones no visibles aquí.</span>
                                                            <?php endif; ?>
                                                            <?php if ($c['suma_exportada_total'] !== null && $c['suma_actual'] !== null && abs(round($c['suma_exportada_total'] - $c['suma_actual'], 2)) >= 0.01): ?>
                                                                <br><span style="color:#e74c3c;">⚠ Lo que exportamos en total (suma $<?= number_format($c['suma_exportada_total'], 0, ',', '.') ?>) no coincide con lo que SIHOS tiene ahora mismo (suma $<?= number_format($c['suma_actual'], 0, ',', '.') ?>) — algo cambió en SIHOS después de generar el archivo, o hay un error en el cálculo de este concepto en el reporte.</span>
                                                            <?php endif; ?>
                                                        </p>

                                                        <p style="margin:0 0 6px 0;"><strong>Encontrado en SIHOS ahora (líneas reales que se suman):</strong></p>
                                                        <?php if ($c['lineas_sihos'] === []): ?>
                                                            <p style="margin:0;">Ningún registro para este período.</p>
                                                        <?php else: ?>
                                                            <ul style="margin:0 0 8px 18px;padding:0;">
                                                                <?php foreach ($c['lineas_sihos'] as $linea): ?>
                                                                    <?php $esLaElegida = !$linea['causado'] && ($linea['codi_docu'] ?? null) === ($c['codi_docu'] ?? null) && ($linea['nume_docu'] ?? null) === ($c['nume_docu'] ?? null); ?>
                                                                    <li<?= $esLaElegida ? ' style="font-weight:bold;"' : '' ?>>
                                                                        <?= htmlspecialchars($linea['nombre_docu']) ?> (<?= htmlspecialchars($linea['codi_docu']) ?>-<?= htmlspecialchars($linea['nume_docu']) ?>)
                                                                        — <?= $linea['causado'] ? 'CONFIRMADA' : 'sin confirmar' ?>
                                                                        — $<?= number_format($linea['total'], 0, ',', '.') ?>
                                                                        <?= $esLaElegida ? ' ← se ajustará esta' : '' ?>
                                                                    </li>
                                                                <?php endforeach; ?>
                                                                <li><strong>Suma: $<?= number_format($c['suma_actual'], 0, ',', '.') ?></strong></li>
                                                            </ul>
                                                        <?php endif; ?>
                                                        <?php if (!empty($c['elegida_entre_varias'])): ?>
                                                            <p style="margin:0;color:#e67e22;">⚠ Había <?= (int)$c['total_lineas_abiertas'] ?> líneas sin confirmar para este concepto — se eligió automáticamente la de MAYOR valor (marcada arriba) para aplicar el ajuste completo. Es una suposición: la diferencia real podría corresponder a otra línea. Verifique antes de aplicar, o corríjalo a mano en SIHOS si no está seguro.</p>
                                                        <?php endif; ?>
                                                    </div>
                                                </details>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endforeach; ?>

                    <?php if ($puedeGuardar && $totalAplicables > 0): ?>
                        <div style="margin-top:16px;display:flex;gap:12px;align-items:center;">
                            <label class="field-note"><input type="checkbox" id="sihosCorreccionMarcarTodas"> Marcar todas las corregibles</label>
                            <button type="button" id="sihosCorreccionAplicarBtn" class="auditoria-btn-primary">✅ Aplicar seleccionadas en SIHOS</button>
                            <span id="sihosCorreccionStatus" class="field-note"></span>
                        </div>
                    <?php endif; ?>
                </form>

            <?php endif; ?>

        <?php endif; ?>
    <?php endif; ?>

</div>

<script src="/js/sihos-nomina-correccion.js?v=<?= (int)$sihosCorreccionJsV ?>"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Formulario de navegación completa (submit normal, no fetch): cruza el
    // CSV contra SIHOS empleado por empleado — puede tardar varios segundos
    // con nóminas grandes. Se muestra el overlay antes de dejar navegar;
    // desaparece solo cuando la página nueva reemplaza esta (ver
    // savidMostrarCargando en app.js, mismo patrón que sihos/cruce.php).
    var form = document.getElementById('sihosNominaPilaCorreccionForm');
    if (form && typeof savidMostrarCargando === 'function') {
        form.addEventListener('submit', function () {
            savidMostrarCargando('Comparando contra SIHOS…', true);
        });
    }
});
</script>
