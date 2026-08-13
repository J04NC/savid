<?php
/** @var array $scope */
/** @var bool $configurado */
/** @var ?array $reporte */
/** @var string $fechaInicio */
/** @var string $fechaFin */
/** @var bool $puedeEliminarDetaPlan */

function sihosFormatoMoneda($valor): string
{
    return '$' . number_format((float)$valor, 0, ',', '.');
}

$assetSihosCruce = BASE_PATH . '/public/js/sihos-cruce.js';
$sihosCruceJsV = is_readable($assetSihosCruce) ? (int)filemtime($assetSihosCruce) : time();
$assetSihosRubros = BASE_PATH . '/public/js/sihos-rubros.js';
$sihosRubrosJsV = is_readable($assetSihosRubros) ? (int)filemtime($assetSihosRubros) : time();
?>

<div class="module-container auditoria-page">

    <div class="crud-toolbar auditoria-toolbar">
        <div class="auditoria-toolbar-title">
            <h2 class="auditoria-title">SIHOS — Cruce reconocimiento de ingresos</h2>
            <p class="auditoria-subtitle">Cruza facturación, presupuesto (<code class="auditoria-code">DetaPlan</code>) y contabilidad (<code class="auditoria-code">DetaCont</code>) para el rango de fechas elegido.</p>
        </div>
    </div>

    <?php $filterUrl = 'sihos/cruce'; require BASE_PATH . '/app/views/sgd/_empresa_filter.php'; ?>

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

        <form method="get" class="crud-form auditoria-filters-grid" style="margin-bottom:20px;">
            <input type="hidden" name="url" value="sihos/cruce">
            <input type="hidden" name="empresa_id" value="<?= (int)$empresaId ?>">
            <div class="form-group">
                <label for="sihosFechaInicio">Desde</label>
                <input type="date" id="sihosFechaInicio" name="fecha_inicio" class="form-input" value="<?= htmlspecialchars($fechaInicio) ?>" required>
            </div>
            <div class="form-group">
                <label for="sihosFechaFin">Hasta</label>
                <input type="date" id="sihosFechaFin" name="fecha_fin" class="form-input" value="<?= htmlspecialchars($fechaFin) ?>" required>
            </div>
            <div class="form-group" style="align-self:flex-end;">
                <button type="submit" class="auditoria-btn-primary">🔍 Ejecutar cruce</button>
            </div>
        </form>

        <?php if ($reporte === null): ?>
            <p class="auditoria-subtitle">Elija un rango de fechas para ejecutar el cruce.</p>
        <?php elseif (!$reporte['ok']): ?>
            <p class="modal-form-alert">⚠️ <?= htmlspecialchars($reporte['error']) ?></p>
        <?php else: ?>

            <p class="field-note">
                Documentos considerados — Facturas: <code><?= htmlspecialchars(implode(', ', $reporte['codigosFactura'])) ?></code>
                · Glosas: <code><?= htmlspecialchars(implode(', ', $reporte['codigosGlosa'])) ?></code>
                · Notas: <code><?= htmlspecialchars(implode(', ', $reporte['codigosNota'])) ?></code>
            </p>

            <?php
            $secciones = [
                [
                    'titulo' => '1. Facturas sin reconocimiento presupuestal',
                    'subtitulo' => 'Facturas (FE/FV/FVC) sin ninguna línea en DetaPlan.',
                    'filas' => $reporte['facturasSinPresupuesto'],
                    'tipo' => 'factura',
                ],
                [
                    'titulo' => '2. Facturas sin cuenta de ingreso',
                    'subtitulo' => 'Facturas sin ninguna línea de cuenta que empiece por 4 (ni capita configurada).',
                    'filas' => $reporte['facturasSinCuentaIngreso'],
                    'tipo' => 'factura',
                ],
                [
                    'titulo' => '3. Notas de vigencia actual incompletas',
                    'subtitulo' => 'Notas (NCC) sobre facturas de la misma vigencia, sin DetaPlan o sin cuenta esperada (empieza por 4, o espejo de la cuenta que usó la factura — p. ej. anulación de capita sin distribuir).',
                    'filas' => $reporte['notasIncompletas'],
                    'tipo' => 'nota',
                ],
                [
                    'titulo' => '4. Glosas de vigencia actual incompletas',
                    'subtitulo' => 'Glosas aceptadas (GLA) sobre facturas de la misma vigencia, sin DetaPlan o sin cuenta esperada (empieza por 4, o espejo de la cuenta que usó la factura).',
                    'filas' => $reporte['glosasIncompletas'],
                    'tipo' => 'nota',
                ],
                [
                    'titulo' => '5a. Facturas con cuenta fuera de lo esperado',
                    'subtitulo' => 'Líneas contables con cuenta que no es cartera, ingreso ni capita configurada.',
                    'filas' => $reporte['cuentasInesperadasFacturas'],
                    'tipo' => 'cuenta',
                ],
                [
                    'titulo' => '5b. Notas con cuenta fuera de lo esperado',
                    'subtitulo' => 'Notas (NCC) de vigencia actual con cuenta que no es cartera, reversión ni gasto configurados.',
                    'filas' => $reporte['cuentasInesperadasNotas'],
                    'tipo' => 'cuenta-nota',
                ],
                [
                    'titulo' => '5c. Glosas con cuenta fuera de lo esperado',
                    'subtitulo' => 'Glosas (GLA) de vigencia actual con cuenta que no es cartera, reversión ni gasto configurados.',
                    'filas' => $reporte['cuentasInesperadasGlosas'],
                    'tipo' => 'cuenta-nota',
                ],
            ];
            ?>

            <?php foreach ($secciones as $seccion): ?>
                <div class="crud-toolbar auditoria-toolbar" style="margin-top:24px;">
                    <div class="auditoria-toolbar-title">
                        <h3 class="auditoria-title" style="font-size:16px;"><?= htmlspecialchars($seccion['titulo']) ?> (<?= count($seccion['filas']) ?>)</h3>
                        <p class="auditoria-subtitle"><?= htmlspecialchars($seccion['subtitulo']) ?></p>
                    </div>
                </div>

                <?php if ($seccion['filas'] === []): ?>
                    <p class="field-note">Sin hallazgos en este rango.</p>
                <?php else: ?>
                    <div style="overflow-x:auto;">
                        <table class="seguridad-table">
                            <?php if ($seccion['tipo'] === 'factura'): ?>
                                <thead><tr><th>Documento</th><th>Fecha</th><th>Valor total</th><th>Tercero</th><th>Centro de costo</th></tr></thead>
                                <tbody>
                                <?php foreach ($seccion['filas'] as $f): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($f['CodiDocu'] . '-' . $f['NumeDocu']) ?></td>
                                        <td><?= htmlspecialchars($f['FechDocu']) ?></td>
                                        <td><?= sihosFormatoMoneda($f['ValoTota']) ?></td>
                                        <td><?= htmlspecialchars(trim(($f['TiDoTerc'] ?? '') . ' ' . ($f['NuDoTerc'] ?? ''))) ?></td>
                                        <td><?= htmlspecialchars((string)($f['CentCost'] ?? '')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            <?php elseif ($seccion['tipo'] === 'nota'): ?>
                                <thead><tr><th>Documento</th><th>Fecha</th><th>Valor</th><th>Factura</th><th>Fecha factura</th><th>Tiene DetaPlan</th><th>Tiene cuenta esperada</th></tr></thead>
                                <tbody>
                                <?php foreach ($seccion['filas'] as $f): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($f['CodiDocu'] . '-' . $f['NumeDocu']) ?></td>
                                        <td><?= htmlspecialchars($f['FechDocu']) ?></td>
                                        <td><?= sihosFormatoMoneda($f['ValoTota']) ?></td>
                                        <td><?= htmlspecialchars($f['FacturaCodiDocu'] . '-' . $f['FacturaNumeDocu']) ?></td>
                                        <td><?= htmlspecialchars($f['FacturaFecha']) ?></td>
                                        <td><?= ((int)$f['TieneDetaPlan'] > 0) ? 'Sí' : '❌ No' ?></td>
                                        <td><?= ((int)$f['TieneCuenta4'] > 0) ? 'Sí' : '❌ No' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            <?php elseif ($seccion['tipo'] === 'cuenta'): ?>
                                <thead><tr><th>Documento</th><th>Fecha</th><th>Cuenta</th><th>Valor</th><th>Centro de costo</th></tr></thead>
                                <tbody>
                                <?php foreach ($seccion['filas'] as $f): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($f['CodiDocu'] . '-' . $f['NumeDocu']) ?></td>
                                        <td><?= htmlspecialchars($f['FechDocu']) ?></td>
                                        <td><?= htmlspecialchars($f['CodiCont']) ?></td>
                                        <td><?= sihosFormatoMoneda($f['Valor']) ?></td>
                                        <td><?= htmlspecialchars((string)($f['CentCost'] ?? '')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            <?php else: ?>
                                <thead><tr><th>Documento</th><th>Fecha</th><th>Cuenta</th><th>Valor</th><th>Factura</th></tr></thead>
                                <tbody>
                                <?php foreach ($seccion['filas'] as $f): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($f['CodiDocu'] . '-' . $f['NumeDocu']) ?></td>
                                        <td><?= htmlspecialchars($f['FechDocu']) ?></td>
                                        <td><?= htmlspecialchars($f['CodiCont']) ?></td>
                                        <td><?= sihosFormatoMoneda($f['Valor']) ?></td>
                                        <td><?= htmlspecialchars($f['FacturaCodiDocu'] . '-' . $f['FacturaNumeDocu']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            <?php endif; ?>
                        </table>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>

            <?php $dif = $reporte['diferenciasPresupuestoContabilidad']; ?>

            <div class="crud-toolbar auditoria-toolbar" style="margin-top:24px;">
                <div class="auditoria-toolbar-title">
                    <h3 class="auditoria-title" style="font-size:16px;">6. Diferencia presupuesto vs. contabilidad (cuenta 4312)</h3>
                    <p class="auditoria-subtitle">Por documento: presupuesto reconocido (<code class="auditoria-code">DetaPlan</code>) contra saldo contable de la familia <code class="auditoria-code">4312</code> (<code class="auditoria-code">DetaCont</code>).</p>
                </div>
            </div>

            <div class="crud-form auditoria-filters-grid" style="margin-bottom:16px;">
                <div class="form-group">
                    <label>Total presupuesto reconocido</label>
                    <input type="text" class="form-input" value="<?= sihosFormatoMoneda($dif['totalPresupuesto']) ?>" readonly>
                </div>
                <div class="form-group">
                    <label>Total saldo contable 4312</label>
                    <input type="text" class="form-input" value="<?= sihosFormatoMoneda($dif['totalContabilidad']) ?>" readonly>
                </div>
                <div class="form-group">
                    <label>Diferencia a revisar</label>
                    <input type="text" class="form-input" value="<?= sihosFormatoMoneda($dif['totalDiferenciaOperativa']) ?>" readonly style="font-weight:bold;">
                </div>
            </div>

            <p class="field-note">
                Diferencia por "Reconocimiento" de tesorería (rendimientos financieros, saldos de vigencia anterior, disponibilidad inicial): <strong><?= sihosFormatoMoneda($dif['totalDiferenciaReconocimientoTesoreria']) ?></strong> —
                esperada, nunca tiene contrapartida en 4312 porque no es ingreso por facturación de pacientes. Se muestra aparte, abajo, para no tapar las diferencias reales.
            </p>

            <h4 class="auditoria-title" style="font-size:14px; margin-top:16px;">Por tipo de usuario</h4>
            <?php if ($dif['porTipoUsua'] === []): ?>
                <p class="field-note">Sin datos en este rango.</p>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table class="seguridad-table">
                        <thead><tr><th>Tipo de usuario</th><th>Presupuesto</th><th>Contabilidad</th><th>Diferencia</th></tr></thead>
                        <tbody>
                        <?php foreach ($dif['porTipoUsua'] as $tipo => $vals): ?>
                            <tr>
                                <td><?= htmlspecialchars((string)$tipo) ?></td>
                                <td><?= sihosFormatoMoneda($vals['presupuesto']) ?></td>
                                <td><?= sihosFormatoMoneda($vals['contabilidad']) ?></td>
                                <td><?= sihosFormatoMoneda($vals['diferencia']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <h4 class="auditoria-title" style="font-size:14px; margin-top:16px;">Detalle de diferencias a revisar (<?= count($dif['detalle']) ?>)</h4>
            <?php if ($dif['detalle'] === []): ?>
                <p class="field-note">Sin diferencias en este rango.</p>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table class="seguridad-table">
                        <thead><tr><th>Documento</th><th>Fecha</th><th>Tipo de usuario</th><th>Presupuesto</th><th>Contabilidad</th><th>Diferencia</th><th>Documento relacionado</th><th>Fecha relacionado</th><th>Cuenta real (si no es 4312)</th><th>Opciones</th></tr></thead>
                        <tbody>
                        <?php foreach ($dif['detalle'] as $f): ?>
                            <?php $tieneRelacionado = $f['RelacionadoCodiDocu'] !== null; ?>
                            <tr>
                                <td><?= htmlspecialchars($f['CodiDocu'] . '-' . $f['NumeDocu']) ?></td>
                                <td><?= htmlspecialchars($f['FechDocu']) ?></td>
                                <td><?= htmlspecialchars($f['TipoUsua']) ?></td>
                                <td><?= sihosFormatoMoneda($f['presupuesto']) ?></td>
                                <td><?= sihosFormatoMoneda($f['contabilidad']) ?></td>
                                <td><?= sihosFormatoMoneda($f['diferencia']) ?></td>
                                <td><?= $tieneRelacionado ? htmlspecialchars($f['RelacionadoCodiDocu'] . '-' . $f['RelacionadoNumeDocu']) : '—' ?></td>
                                <td><?= $tieneRelacionado ? htmlspecialchars((string)$f['RelacionadoFecha']) : '—' ?></td>
                                <td><?= $f['CuentaReal'] !== null ? htmlspecialchars($f['CuentaReal']) : '—' ?></td>
                                <td>
                                    <?php if ($puedeEliminarDetaPlan && $f['puedeEliminarDetaPlan']): ?>
                                        <button type="button" class="auditoria-btn-primary btnSihosEliminarDetaPlan"
                                                data-empresa-id="<?= (int)$empresaId ?>"
                                                data-codi-docu="<?= htmlspecialchars($f['CodiDocu']) ?>"
                                                data-nume-docu="<?= htmlspecialchars($f['NumeDocu']) ?>"
                                                style="background:#c0392b;">🗑️ Eliminar DetaPlan</button>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <h4 class="auditoria-title" style="font-size:14px; margin-top:16px;">Reconocimiento de tesorería (informativo, no requiere acción) (<?= count($dif['detalleReconocimientoTesoreria']) ?>)</h4>
            <?php if ($dif['detalleReconocimientoTesoreria'] === []): ?>
                <p class="field-note">Sin documentos de este tipo en el rango.</p>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table class="seguridad-table">
                        <thead><tr><th>Documento</th><th>Fecha</th><th>Presupuesto</th><th>Contabilidad</th><th>Diferencia</th><th>Rubros</th></tr></thead>
                        <tbody>
                        <?php foreach ($dif['detalleReconocimientoTesoreria'] as $f): ?>
                            <?php $documentoRec = $f['CodiDocu'] . '-' . $f['NumeDocu']; ?>
                            <tr>
                                <td><?= htmlspecialchars($documentoRec) ?></td>
                                <td><?= htmlspecialchars($f['FechDocu']) ?></td>
                                <td><?= sihosFormatoMoneda($f['presupuesto']) ?></td>
                                <td><?= sihosFormatoMoneda($f['contabilidad']) ?></td>
                                <td><?= sihosFormatoMoneda($f['diferencia']) ?></td>
                                <td>
                                    <?php if ($f['rubros'] !== []): ?>
                                        <button type="button" class="auditoria-btn-primary btnSihosVerRubros"
                                                data-documento="<?= htmlspecialchars($documentoRec) ?>"
                                                data-rubros="<?= htmlspecialchars(json_encode($f['rubros'], JSON_UNESCAPED_UNICODE)) ?>">
                                            🔍 Ver rubros (<?= count($f['rubros']) ?>)
                                        </button>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div id="sihosVerRubrosModal" class="modal hidden">
                    <div class="modal-content">
                        <div class="modal-header-bar">
                            <span>Rubros presupuestales afectados — <span id="sihosVerRubrosDocumento"></span></span>
                            <span class="close-modal" id="sihosVerRubrosCerrar" role="button" tabindex="0" aria-label="Cerrar">&times;</span>
                        </div>
                        <div style="padding:16px; overflow-x:auto;">
                            <table class="seguridad-table">
                                <thead><tr><th>Rubro</th><th>Nombre</th><th style="text-align:right;">Valor</th></tr></thead>
                                <tbody id="sihosVerRubrosBody"></tbody>
                                <tfoot>
                                    <tr>
                                        <td colspan="2" style="text-align:right; font-weight:bold;">Total</td>
                                        <td id="sihosVerRubrosTotal" style="text-align:right; font-weight:bold;"></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
                <script src="/js/sihos-rubros.js?v=<?= (int)$sihosRubrosJsV ?>"></script>
            <?php endif; ?>

        <?php endif; ?>

    <?php endif; ?>

    <?php if ($puedeEliminarDetaPlan): ?>
        <div id="sihosEliminarDetaPlanModal" class="modal hidden">
            <div class="modal-content">
                <div class="modal-header-bar">
                    <span>⚠️ Eliminar DetaPlan en SIHOS</span>
                    <span class="close-modal" id="sihosEliminarDetaPlanCerrar" role="button" tabindex="0" aria-label="Cerrar">&times;</span>
                </div>
                <div style="padding:16px;">
                    <p class="modal-form-alert">
                        Esta acción borra <strong>permanentemente</strong> el detalle presupuestal (<code>DetaPlan</code>) de
                        <strong id="sihosEliminarDetaPlanDocumento"></strong> en SIHOS. Es <strong>irreversible</strong>.
                        Después de confirmar, debe correr la reconstrucción presupuestal en SIHOS para el período correspondiente.
                    </p>
                    <div class="form-group">
                        <label for="sihosEliminarDetaPlanConfirmacion">Escriba <strong id="sihosEliminarDetaPlanDocumentoLabel"></strong> para confirmar</label>
                        <input type="text" id="sihosEliminarDetaPlanConfirmacion" class="form-input" autocomplete="off">
                    </div>
                    <div class="auditoria-filters-footer">
                        <button type="button" id="sihosEliminarDetaPlanConfirmar" class="auditoria-btn-primary" style="background:#c0392b;" disabled>🗑️ Eliminar definitivamente</button>
                    </div>
                    <p id="sihosEliminarDetaPlanStatus" class="usuario-perm-save-status" aria-live="polite"></p>
                </div>
            </div>
        </div>
        <script src="/js/sihos-cruce.js?v=<?= (int)$sihosCruceJsV ?>"></script>
    <?php endif; ?>

</div>
