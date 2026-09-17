<?php
/** @var array $scope */
/** @var bool $configurado */
/** @var ?array $resultados */
/** @var ?array $solicitudes */
/** @var ?array $homologacion */
/** @var bool $puedeProcesar */
/** @var bool $puedeGuardarHomologacion */
/** @var bool $puedeEliminarHomologacion */

function sihosInterlabEstadoResultado($enviada, $correcion): string
{
    if ((int)$correcion === 1) {
        return '🔁 Corrección pendiente';
    }

    $etiquetas = [
        0 => '⏳ Pendiente',
        1 => '✅ Procesado',
        3 => '🧩 Homologado (9999, agrupado)',
        5 => '⚠️ No homologado',
        6 => '⚠️ No configurado',
        7 => '⚠️ Paciente/liquidación no relacionada',
    ];

    return $etiquetas[(int)$enviada] ?? ('Estado ' . (int)$enviada);
}

$assetSihosInterlab = BASE_PATH . '/public/js/sihos-interfaz-laboratorio.js';
$sihosInterlabJsV = is_readable($assetSihosInterlab) ? (int)filemtime($assetSihosInterlab) : time();
?>

<div class="module-container auditoria-page">

    <div class="crud-toolbar auditoria-toolbar">
        <div class="auditoria-toolbar-title">
            <h2 class="auditoria-title">SIHOS — Interfaz Laboratorio</h2>
            <p class="auditoria-subtitle">Homologación, solicitudes y resultados de la interfaz Roche &lt;-&gt; SIHOS, por empresa.</p>
        </div>
    </div>

    <?php $filterUrl = 'sihos/interfazLaboratorio'; require BASE_PATH . '/app/views/sgd/_empresa_filter.php'; ?>

    <?php
    /* _empresa_filter.php también usa $empresaId internamente; se recalcula
       aquí después del require para no quedarse con el valor pisado. */
    $empresaId = $scope['empresaId'] ?? null;
    ?>

    <?php if ($empresaId === null): ?>
        <p class="field-note sgd-page-empty">Seleccione la empresa en el filtro superior.</p>
    <?php elseif (!$configurado): ?>
        <p class="modal-form-alert">⚠️ Esta empresa no tiene conexión a SIHOS configurada. Ve a <a href="?url=sihos&empresa_id=<?= (int)$empresaId ?>">Conexión SIHOS</a>.</p>
    <?php else: ?>

        <div class="sihos-interlab-tabs" role="tablist">
            <button type="button" class="auditoria-btn-primary sihos-interlab-tab-btn" data-tab="homologacion" role="tab" aria-selected="true">🧩 Homologación</button>
            <button type="button" class="auditoria-btn-primary sihos-interlab-tab-btn" data-tab="solicitudes" role="tab" aria-selected="false">📤 Solicitudes</button>
            <button type="button" class="auditoria-btn-primary sihos-interlab-tab-btn" data-tab="resultados" role="tab" aria-selected="false">📥 Resultados</button>
        </div>

        <!-- ===================== HOMOLOGACIÓN ===================== -->
        <section id="sihosInterlabTabHomologacion" class="sihos-interlab-tab-panel" role="tabpanel">
            <form method="get" class="crud-form auditoria-filters-grid" style="margin:20px 0;">
                <input type="hidden" name="url" value="sihos/interfazLaboratorio">
                <input type="hidden" name="empresa_id" value="<?= (int)$empresaId ?>">
                <div class="form-group">
                    <label for="sihosHomoCodiCups">CUPS (opcional)</label>
                    <input type="text" id="sihosHomoCodiCups" name="codi_cups_h" class="form-input" value="<?= htmlspecialchars($_GET['codi_cups_h'] ?? '') ?>" placeholder="Filtrar por código CUPS">
                </div>
                <div class="form-group" style="align-self:flex-end;">
                    <button type="submit" class="auditoria-btn-primary">🔍 Filtrar</button>
                </div>
            </form>

            <?php if ($puedeGuardarHomologacion): ?>
                <div class="crud-toolbar" style="margin-bottom:12px;">
                    <button type="button" id="btnSihosHomoNueva" class="auditoria-btn-primary">➕ Nueva homologación</button>
                </div>
            <?php endif; ?>

            <?php if ($homologacion === null): ?>
                <p class="field-note">Cargando…</p>
            <?php elseif (!$homologacion['ok']): ?>
                <p class="modal-form-alert">⚠️ <?= htmlspecialchars($homologacion['error']) ?></p>
            <?php else: ?>
                <div class="crud-table">
                    <table class="savid-datatable">
                        <thead>
                            <tr>
                                <th>CUPS</th>
                                <th>Nombre CUPS</th>
                                <th>Analito</th>
                                <th>CodiPrue (interno)</th>
                                <th>Nombre prueba</th>
                                <?php if ($puedeGuardarHomologacion || $puedeEliminarHomologacion): ?><th>Acciones</th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($homologacion['filas'] === []): ?>
                                <tr><td colspan="6">Sin registros.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($homologacion['filas'] as $fila): ?>
                                <tr>
                                    <td><?= htmlspecialchars((string)$fila['CodiCups']) ?></td>
                                    <td><?= htmlspecialchars((string)($fila['NombreCups'] ?? '—')) ?></td>
                                    <td><?= htmlspecialchars((string)$fila['Analito']) ?></td>
                                    <td><?= htmlspecialchars((string)$fila['CodiPrue']) ?></td>
                                    <td><?= htmlspecialchars((string)($fila['NombrePrue'] ?? '—')) ?></td>
                                    <?php if ($puedeGuardarHomologacion || $puedeEliminarHomologacion): ?>
                                    <td>
                                        <?php if ($puedeGuardarHomologacion): ?>
                                            <button type="button" class="auditoria-btn-secondary sihosHomoEditar"
                                                data-codi-cups="<?= htmlspecialchars((string)$fila['CodiCups']) ?>"
                                                data-codi-prue="<?= htmlspecialchars((string)$fila['CodiPrue']) ?>"
                                                data-analito="<?= htmlspecialchars((string)$fila['Analito']) ?>">✏️ Editar</button>
                                        <?php endif; ?>
                                        <?php if ($puedeEliminarHomologacion): ?>
                                            <button type="button" class="auditoria-btn-secondary sihosHomoEliminar"
                                                data-codi-cups="<?= htmlspecialchars((string)$fila['CodiCups']) ?>"
                                                data-codi-prue="<?= htmlspecialchars((string)$fila['CodiPrue']) ?>"
                                                data-analito="<?= htmlspecialchars((string)$fila['Analito']) ?>">🗑️ Eliminar</button>
                                        <?php endif; ?>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <!-- Modal crear/editar homologación -->
        <div id="sihosHomoModal" class="sihos-interlab-modal" hidden>
            <div class="sihos-interlab-modal-caja">
                <h3 class="auditoria-title" style="font-size:16px;">Homologación</h3>
                <input type="hidden" id="sihosHomoCodiCupsAnterior">
                <input type="hidden" id="sihosHomoCodiPrueAnterior">
                <input type="hidden" id="sihosHomoAnalitoAnterior">
                <div class="form-group">
                    <label for="sihosHomoFormCups">CUPS</label>
                    <input type="text" id="sihosHomoFormCups" class="form-input" required>
                </div>
                <div class="form-group">
                    <label for="sihosHomoFormAnalito">Analito (código Roche)</label>
                    <input type="text" id="sihosHomoFormAnalito" class="form-input" required>
                </div>
                <div class="form-group">
                    <label for="sihosHomoFormPrue">CodiPrue interno (SIHOS)</label>
                    <input type="text" id="sihosHomoFormPrue" class="form-input" required>
                </div>
                <p id="sihosHomoFormError" class="modal-form-alert" hidden></p>
                <div class="auditoria-filters-footer">
                    <button type="button" id="btnSihosHomoCancelar" class="auditoria-btn-secondary">Cancelar</button>
                    <button type="button" id="btnSihosHomoGuardar" class="auditoria-btn-primary">💾 Guardar</button>
                </div>
            </div>
        </div>

        <!-- ===================== SOLICITUDES ===================== -->
        <section id="sihosInterlabTabSolicitudes" class="sihos-interlab-tab-panel" role="tabpanel" hidden>
            <form method="get" class="crud-form auditoria-filters-grid" style="margin:20px 0;">
                <input type="hidden" name="url" value="sihos/interfazLaboratorio">
                <input type="hidden" name="empresa_id" value="<?= (int)$empresaId ?>">
                <input type="hidden" name="buscar_s" value="1">
                <div class="form-group">
                    <label for="sihosSoliFechaInicio">Desde</label>
                    <input type="date" id="sihosSoliFechaInicio" name="fecha_inicio_s" class="form-input" value="<?= htmlspecialchars($_GET['fecha_inicio_s'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label for="sihosSoliFechaFin">Hasta</label>
                    <input type="date" id="sihosSoliFechaFin" name="fecha_fin_s" class="form-input" value="<?= htmlspecialchars($_GET['fecha_fin_s'] ?? '') ?>" required>
                </div>
                <div class="form-group" style="align-self:flex-end;">
                    <button type="submit" class="auditoria-btn-primary">🔍 Consultar</button>
                </div>
            </form>

            <?php if ($solicitudes === null): ?>
                <p class="auditoria-subtitle">Elija un rango de fechas para consultar.</p>
            <?php elseif (!$solicitudes['ok']): ?>
                <p class="modal-form-alert">⚠️ <?= htmlspecialchars($solicitudes['error']) ?></p>
            <?php else: ?>

                <h3 class="auditoria-title" style="font-size:15px;">Candidatas pendientes de enviar a Roche (<?= count($solicitudes['candidatas']) ?>)</h3>
                <div class="crud-table">
                    <table class="savid-datatable">
                        <thead>
                            <tr>
                                <th><input type="checkbox" id="sihosSoliSelectAll"></th>
                                <th>Fecha</th>
                                <th>ConsAdmi</th>
                                <th>ConsOrde</th>
                                <th>Item</th>
                                <th>CodiProc</th>
                                <th>Origen</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($solicitudes['candidatas'] === []): ?>
                                <tr><td colspan="7">Sin candidatas pendientes.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($solicitudes['candidatas'] as $fila): ?>
                                <tr>
                                    <td><input type="checkbox" class="sihosSoliCandidataCheckbox"
                                        data-codi-inst="<?= htmlspecialchars((string)$fila['CodiInst']) ?>"
                                        data-cons-admi="<?= htmlspecialchars((string)$fila['ConsAdmi']) ?>"
                                        data-cons-orde="<?= htmlspecialchars((string)$fila['ConsOrde']) ?>"
                                        data-item="<?= htmlspecialchars((string)$fila['Item']) ?>"
                                        data-codi-modu="<?= htmlspecialchars((string)$fila['CodiModu']) ?>"
                                        data-codi-proc="<?= htmlspecialchars((string)$fila['CodiProc']) ?>"
                                        data-obse-proc="<?= htmlspecialchars((string)$fila['ObseProc']) ?>"
                                        data-cons-de-fa="<?= htmlspecialchars((string)$fila['ConsDeFa']) ?>"
                                        data-fech-digi="<?= htmlspecialchars((string)$fila['FechDigi']) ?>"
                                        data-hora-digi="<?= htmlspecialchars((string)$fila['HoraDigi']) ?>"
                                        data-usua-digi="<?= htmlspecialchars((string)$fila['UsuaDigi']) ?>"
                                        data-tipo-docu="<?= htmlspecialchars((string)$fila['TipoDocu']) ?>"
                                        data-nume-usua="<?= htmlspecialchars((string)$fila['NumeUsua']) ?>"
                                        data-nume-liqu="<?= htmlspecialchars((string)$fila['NumeLiqu']) ?>"
                                        data-tipo-orde="<?= htmlspecialchars((string)$fila['TipoOrde']) ?>"
                                        data-tipo-interfaz="<?= htmlspecialchars((string)$fila['TipoInterfaz']) ?>"></td>
                                    <td><?= htmlspecialchars((string)$fila['FechDigi']) ?></td>
                                    <td><?= htmlspecialchars((string)$fila['ConsAdmi']) ?></td>
                                    <td><?= htmlspecialchars((string)$fila['ConsOrde']) ?></td>
                                    <td><?= htmlspecialchars((string)$fila['Item']) ?></td>
                                    <td><?= htmlspecialchars((string)$fila['CodiProc']) ?></td>
                                    <td><?= $fila['TipoInterfaz'] === '2' ? 'Liquidación' : 'Orden' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($puedeProcesar): ?>
                    <div class="auditoria-filters-footer">
                        <button type="button" id="btnSihosSoliMasivo" class="auditoria-btn-primary" disabled>▶️ Procesar seleccionadas (0)</button>
                    </div>
                    <div id="sihosSoliMasivoProgreso" class="sihos-interlab-progreso" hidden></div>
                <?php endif; ?>

                <h3 class="auditoria-title" style="font-size:15px; margin-top:24px;">Ya registradas en la interfaz</h3>
                <div class="crud-table">
                    <table class="savid-datatable">
                        <thead>
                            <tr>
                                <th>Fecha orden</th>
                                <th>ConsAdmi</th>
                                <th>ConsOrde</th>
                                <th>Item</th>
                                <th>Paciente</th>
                                <th>Examen</th>
                                <th>Centro</th>
                                <th>Cargada</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($solicitudes['registradas'] === []): ?>
                                <tr><td colspan="8">Sin registros en el rango.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($solicitudes['registradas'] as $fila): ?>
                                <tr>
                                    <td><?= htmlspecialchars((string)$fila['Fecha_Orden']) ?></td>
                                    <td><?= htmlspecialchars((string)$fila['ConsAdmi']) ?></td>
                                    <td><?= htmlspecialchars((string)$fila['ConsOrde']) ?></td>
                                    <td><?= htmlspecialchars((string)$fila['Item']) ?></td>
                                    <td><?= htmlspecialchars(trim($fila['Nombres'] . ' ' . $fila['Apellidos'])) ?></td>
                                    <td><?= htmlspecialchars((string)$fila['Descripcion_Examen']) ?></td>
                                    <td><?= htmlspecialchars((string)$fila['Descripcion_Centro_Produccion']) ?></td>
                                    <td><?= ((int)$fila['Cargada'] === 1) ? '✅' : '⏳' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <!-- ===================== RESULTADOS ===================== -->
        <section id="sihosInterlabTabResultados" class="sihos-interlab-tab-panel" role="tabpanel" hidden>
            <form method="get" class="crud-form auditoria-filters-grid" style="margin:20px 0;">
                <input type="hidden" name="url" value="sihos/interfazLaboratorio">
                <input type="hidden" name="empresa_id" value="<?= (int)$empresaId ?>">
                <input type="hidden" name="buscar_r" value="1">
                <div class="form-group">
                    <label for="sihosResuFechaInicio">Desde</label>
                    <input type="date" id="sihosResuFechaInicio" name="fecha_inicio_r" class="form-input" value="<?= htmlspecialchars($_GET['fecha_inicio_r'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label for="sihosResuFechaFin">Hasta</label>
                    <input type="date" id="sihosResuFechaFin" name="fecha_fin_r" class="form-input" value="<?= htmlspecialchars($_GET['fecha_fin_r'] ?? '') ?>" required>
                </div>
                <div class="form-group" style="align-self:flex-end;">
                    <button type="submit" class="auditoria-btn-primary">🔍 Consultar</button>
                </div>
            </form>

            <?php if ($resultados === null): ?>
                <p class="auditoria-subtitle">Elija un rango de fechas para consultar.</p>
            <?php elseif (!$resultados['ok']): ?>
                <p class="modal-form-alert">⚠️ <?= htmlspecialchars($resultados['error']) ?></p>
            <?php else: ?>
                <div class="crud-table">
                    <table class="savid-datatable">
                        <thead>
                            <tr>
                                <th><input type="checkbox" id="sihosResuSelectAll"></th>
                                <th>Fecha resultado</th>
                                <th>ConsAdmi</th>
                                <th>ConsOrde</th>
                                <th>Item</th>
                                <th>CodiPrue</th>
                                <th>Analito</th>
                                <th>Examen</th>
                                <th>Resultado</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($resultados['filas'] === []): ?>
                                <tr><td colspan="10">Sin resultados en el rango.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($resultados['filas'] as $fila): ?>
                                <tr>
                                    <td><input type="checkbox" class="sihosResuCheckbox" data-id="<?= (int)$fila['id'] ?>"></td>
                                    <td><?= htmlspecialchars((string)$fila['Fecha_Resultado']) ?></td>
                                    <td><?= htmlspecialchars((string)$fila['ConsAdmi']) ?></td>
                                    <td><?= htmlspecialchars((string)$fila['ConsOrde']) ?></td>
                                    <td><?= htmlspecialchars((string)$fila['Item']) ?></td>
                                    <td><?= htmlspecialchars((string)$fila['CodiPrue']) ?></td>
                                    <td><?= htmlspecialchars((string)$fila['CodAnalito']) ?></td>
                                    <td><?= htmlspecialchars((string)$fila['Descripcion_Examen']) ?></td>
                                    <td><?= htmlspecialchars((string)$fila['Resultado']) ?></td>
                                    <td><?= sihosInterlabEstadoResultado($fila['Enviada'], $fila['Correcion']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($puedeProcesar): ?>
                    <div class="auditoria-filters-footer">
                        <button type="button" id="btnSihosResuMasivo" class="auditoria-btn-primary" disabled>▶️ Procesar seleccionados (0)</button>
                    </div>
                    <div id="sihosResuMasivoProgreso" class="sihos-interlab-progreso" hidden></div>
                <?php endif; ?>
            <?php endif; ?>
        </section>

    <?php endif; ?>

</div>

<script src="/js/sihos-interfaz-laboratorio.js?v=<?= (int)$sihosInterlabJsV ?>"></script>
