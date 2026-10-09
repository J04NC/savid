<?php
/** @var array $scope */
/** @var bool $configurado */
/** @var ?array $comparacion */
/** @var ?array $archivoInfo */
/** @var string $codiAno */
/** @var string $codiMes */
/** @var bool $puedeGuardar */

$assetSihosPlanillaIntegrada = BASE_PATH . '/public/js/sihos-nomina-planilla-integrada-correccion.js';
$sihosPlanillaIntegradaJsV = is_readable($assetSihosPlanillaIntegrada) ? (int)filemtime($assetSihosPlanillaIntegrada) : time();
?>

<div class="module-container auditoria-page" data-sihos-planilla-integrada data-puede-guardar="<?= $puedeGuardar ? '1' : '0' ?>">

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

        <form method="post" enctype="multipart/form-data" id="sihosNominaPlanillaIntegradaForm" class="crud-form auditoria-filters-grid" style="margin-bottom:20px;">
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
            $totalAplicablesValor = 0;
            $totalAplicablesTercero = 0;
            foreach ($empleados as $emp) {
                foreach ($emp['conceptos'] as $c) {
                    $totalConceptosEmpleado++;
                    if ($c['estado'] === 'diferencia') {
                        $totalDiferenciasEmpleado++;
                    }
                    $hayLineaAbierta = count(array_filter($c['lineas_sihos'], static fn (array $l): bool => !$l['causado'])) > 0;
                    if ($c['concepto'] === 'arl' && $c['estado'] === 'diferencia' && $hayLineaAbierta) {
                        $totalAplicablesValor++;
                    }
                    if ($c['tercero_estado'] === 'diferencia' && $c['tercero_archivo_nit'] !== null && $hayLineaAbierta) {
                        $totalAplicablesTercero++;
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
                <?= $totalDiferenciasEmpleado ?> con diferencia de valor frente a SIHOS
                (<?= $totalAplicablesValor ?> de ARL se pueden corregir directamente aquí)
                y <?= $totalAplicablesTercero ?> con diferencia de tercero (AFP/EPS/CCF/ARL) corregibles aquí.
                Las diferencias de unos $100 por concepto son el margen de redondeo normal entre PILA y SIHOS ya
                documentado; diferencias mayores, o marcadas como inconsistencia interna del archivo, merecen revisión
                aparte. Las correcciones de valor de ARL y de tercero son las dos únicas que esta pantalla aplica
                en SIHOS — pensión/salud/CCF/SENA/ICBF por valor se corrigen desde
                <a href="?url=sihos/nominaPilaCorreccion&empresa_id=<?= (int)$empresaId ?>">Corrección Nómina (PILA)</a>.
                Solo se pueden corregir nóminas que aún estén preliminares (sin confirmar/causar) en SIHOS; cuando hay
                más de una línea sin confirmar para el mismo concepto, se elige automáticamente la de mayor valor.
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

            <form id="sihosPlanillaIntegradaCorreccionForm" data-codi-ano="<?= htmlspecialchars($codiAno) ?>" data-codi-mes="<?= htmlspecialchars($codiMes) ?>" data-empresa-id="<?= (int)$empresaId ?>">

                <?php if ($puedeGuardar && ($totalAplicablesValor > 0 || $totalAplicablesTercero > 0)): ?>
                    <div style="margin-bottom:16px;display:flex;gap:12px;align-items:center;">
                        <label class="field-note"><input type="checkbox" class="sihos-pi-marcar-todas"> Marcar todas las corregibles</label>
                        <button type="button" class="auditoria-btn-primary sihos-pi-aplicar-btn">✅ Aplicar seleccionadas en SIHOS</button>
                        <span class="field-note sihos-pi-status"></span>
                    </div>
                <?php endif; ?>

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
                                    <?php
                                    $et = $etiquetasEstado[$c['estado']];
                                    $lineasAbiertas = array_values(array_filter($c['lineas_sihos'], static fn (array $l): bool => !$l['causado']));
                                    $hayLineaAbierta = $lineasAbiertas !== [];
                                    $aplicableValor = $puedeGuardar && $c['concepto'] === 'arl' && $c['estado'] === 'diferencia' && $hayLineaAbierta;
                                    $aplicableTercero = $puedeGuardar && $c['tercero_estado'] === 'diferencia' && $c['tercero_archivo_nit'] !== null && $hayLineaAbierta;

                                    // Mismo criterio de "elegir la de mayor valor" que usa
                                    // aplicarCorrecciones() cuando hay varias líneas abiertas —
                                    // se muestra aquí cuál es, para que quede claro a qué
                                    // nómina pertenece el registro que se actualizaría. Si NO
                                    // hay ninguna abierta (todas ya causadas/confirmadas en
                                    // SIHOS), se muestra igual cuál sería — a pedido del
                                    // usuario, 2026-10-09 — para que sepa cuál nómina debe
                                    // REVERSAR en SIHOS antes de poder correr el ajuste aquí.
                                    $esDiferenciaCorregibleAqui = ($c['concepto'] === 'arl' && $c['estado'] === 'diferencia') || $c['tercero_estado'] === 'diferencia';
                                    $lineaElegida = null;
                                    if ($esDiferenciaCorregibleAqui && $c['lineas_sihos'] !== []) {
                                        $candidatas = $hayLineaAbierta ? $lineasAbiertas : $c['lineas_sihos'];
                                        $ordenadas = $candidatas;
                                        usort($ordenadas, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);
                                        $lineaElegida = $ordenadas[0];
                                    }
                                    ?>
                                    <tr class="sihos-pi-correccion-fila">
                                        <td style="width:90px;"><strong><?= htmlspecialchars($nombreConcepto[$c['concepto']] ?? $c['concepto']) ?></strong></td>
                                        <td>archivo $<?= number_format($c['suma_archivo'], 0, ',', '.') ?></td>
                                        <td><?= $c['suma_sihos'] !== null ? 'SIHOS $' . number_format($c['suma_sihos'], 0, ',', '.') : 'sin registro en SIHOS' ?></td>
                                        <td><?= $c['diferencia'] !== null ? 'diferencia $' . number_format($c['diferencia'], 0, ',', '.') : '' ?></td>
                                        <td class="sihos-correccion-estado">
                                            <span style="color:<?= $et['color'] ?>;"><?= $et['texto'] ?></span>
                                            <?php if ($lineaElegida !== null): ?>
                                                <?php if (!$lineaElegida['causado']): ?>
                                                    <div class="field-note" style="margin-top:2px;">
                                                        Nómina a actualizar: <?= htmlspecialchars($lineaElegida['nombre_docu']) ?>
                                                        (<?= htmlspecialchars($lineaElegida['codi_docu']) ?>-<?= htmlspecialchars($lineaElegida['nume_docu']) ?>)
                                                        <?php if (count($lineasAbiertas) > 1): ?>
                                                            <span title="Había <?= count($lineasAbiertas) ?> líneas sin confirmar para este concepto — se eligió automáticamente la de mayor valor. Verifique antes de aplicar." style="color:#e67e22;cursor:help;">⚠</span>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <div style="margin-top:2px;">
                                                        <span style="color:#e74c3c;">
                                                            🔒 Nómina a reversar: <?= htmlspecialchars($lineaElegida['nombre_docu']) ?>
                                                            (<?= htmlspecialchars($lineaElegida['codi_docu']) ?>-<?= htmlspecialchars($lineaElegida['nume_docu']) ?>)
                                                            — ya está CONFIRMADA en SIHOS, no se puede corregir aquí mientras siga así.
                                                            Reverse esta nómina en SIHOS y vuelva a comparar para poder aplicar el ajuste.
                                                        </span>
                                                    </div>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            <?php if ($aplicableValor): ?>
                                                <label class="field-note" style="margin-left:8px;">
                                                    <input type="checkbox" class="sihos-pi-check"
                                                        data-tipo-docu="<?= htmlspecialchars($emp['tipo_docu'], ENT_QUOTES, 'UTF-8') ?>"
                                                        data-no-id="<?= htmlspecialchars($emp['no_id'], ENT_QUOTES, 'UTF-8') ?>"
                                                        data-concepto="<?= htmlspecialchars($c['concepto'], ENT_QUOTES, 'UTF-8') ?>"
                                                        data-tipo-correccion="valor"
                                                        data-suma-esperada="<?= htmlspecialchars((string)$c['suma_archivo'], ENT_QUOTES, 'UTF-8') ?>">
                                                    corregir valor
                                                </label>
                                            <?php endif; ?>
                                            <?php if ($c['lineas_sihos'] !== []): ?>
                                                <details style="display:inline;">
                                                    <summary style="display:inline;cursor:pointer;color:var(--link-color,#5aa9ff);">Ver registros en SIHOS</summary>
                                                    <div style="margin-top:6px;padding:8px;background:rgba(255,255,255,0.03);border-radius:6px;">
                                                        <ul style="margin:0;padding:0 0 0 18px;">
                                                            <?php foreach ($c['lineas_sihos'] as $linea): ?>
                                                                <?php $esLaElegida = $lineaElegida !== null && !$linea['causado'] && $linea['codi_docu'] === $lineaElegida['codi_docu'] && $linea['nume_docu'] === $lineaElegida['nume_docu']; ?>
                                                                <li<?= $esLaElegida ? ' style="font-weight:bold;"' : '' ?>>
                                                                    <?= htmlspecialchars($linea['nombre_docu']) ?> (<?= htmlspecialchars($linea['codi_docu']) ?>-<?= htmlspecialchars($linea['nume_docu']) ?>)
                                                                    — <?= $linea['causado'] ? 'CONFIRMADA' : 'sin confirmar' ?>
                                                                    — $<?= number_format($linea['total'], 0, ',', '.') ?>
                                                                    <?= $esLaElegida ? ' ← se ajustará esta' : '' ?>
                                                                </li>
                                                            <?php endforeach; ?>
                                                        </ul>
                                                    </div>
                                                </details>
                                            <?php endif; ?>
                                            <?php if ($c['tercero_estado'] !== 'no_aplica'): ?>
                                                <div style="margin-top:4px;">
                                                    <?php if ($c['tercero_estado'] === 'diferencia'): ?>
                                                        <span style="color:#e67e22;">⚠ Tercero archivo: <?= htmlspecialchars((string)$c['tercero_archivo_nombre']) ?> (<?= htmlspecialchars((string)$c['tercero_archivo_nit']) ?>)
                                                        — SIHOS: <?= htmlspecialchars((string)$c['tercero_sihos_nit']) ?></span>
                                                        <?php if ($aplicableTercero): ?>
                                                            <label class="field-note" style="margin-left:8px;">
                                                                <input type="checkbox" class="sihos-pi-check"
                                                                    data-tipo-docu="<?= htmlspecialchars($emp['tipo_docu'], ENT_QUOTES, 'UTF-8') ?>"
                                                                    data-no-id="<?= htmlspecialchars($emp['no_id'], ENT_QUOTES, 'UTF-8') ?>"
                                                                    data-concepto="<?= htmlspecialchars($c['concepto'], ENT_QUOTES, 'UTF-8') ?>"
                                                                    data-tipo-correccion="tercero"
                                                                    data-tercero-nit="<?= htmlspecialchars((string)$c['tercero_archivo_nit'], ENT_QUOTES, 'UTF-8') ?>">
                                                                corregir tercero
                                                            </label>
                                                        <?php endif; ?>
                                                    <?php elseif ($c['tercero_estado'] === 'no_encontrado'): ?>
                                                        <span class="field-note">Tercero: sin dato suficiente para comparar.</span>
                                                    <?php else: ?>
                                                        <span class="field-note">Tercero coincide (<?= htmlspecialchars((string)$c['tercero_archivo_nit']) ?>).</span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endforeach; ?>

                <?php if ($puedeGuardar && ($totalAplicablesValor > 0 || $totalAplicablesTercero > 0)): ?>
                    <div style="margin-top:16px;display:flex;gap:12px;align-items:center;">
                        <label class="field-note"><input type="checkbox" class="sihos-pi-marcar-todas"> Marcar todas las corregibles</label>
                        <button type="button" class="auditoria-btn-primary sihos-pi-aplicar-btn">✅ Aplicar seleccionadas en SIHOS</button>
                        <span class="field-note sihos-pi-status"></span>
                    </div>
                <?php endif; ?>
            </form>

        <?php endif; ?>

    <?php endif; ?>

</div>

<script src="/js/sihos-nomina-planilla-integrada-correccion.js?v=<?= (int)$sihosPlanillaIntegradaJsV ?>"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Formulario de navegación completa (submit normal, no fetch): sube el
    // CSV y compara contra SIHOS empleado por empleado — puede tardar
    // varios segundos con nóminas grandes. Se muestra el overlay antes de
    // dejar navegar; desaparece solo cuando la página nueva reemplaza esta
    // (ver savidMostrarCargando en app.js, mismo patrón que sihos/cruce.php
    // y sihos/nominaPilaCorreccion).
    var form = document.getElementById('sihosNominaPlanillaIntegradaForm');
    if (form && typeof savidMostrarCargando === 'function') {
        form.addEventListener('submit', function () {
            savidMostrarCargando('Comparando contra SIHOS…', true);
        });
    }
});
</script>
