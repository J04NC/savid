<?php
/** @var array $scope */
/** @var bool $configurado */
/** @var array<string,string> $manuales */
/** @var array<string,string> $planes */
/** @var ?array $grid */
/** @var ?array $vistaPrevia */
/** @var string $codiManu */
/** @var string $codiPlan */
/** @var bool $puedeConfirmar */

$assetTarifaProc = BASE_PATH . '/public/js/sihos-tarifa-procedimiento.js';
$tarifaProcJsV = is_readable($assetTarifaProc) ? (int)filemtime($assetTarifaProc) : time();
?>

<div class="module-container auditoria-page" data-sihos-tarifa-procedimiento data-puede-confirmar="<?= $puedeConfirmar ? '1' : '0' ?>">

    <div class="crud-toolbar auditoria-toolbar">
        <div class="auditoria-toolbar-title">
            <h2 class="auditoria-title">SIHOS — Tarifa Procedimientos</h2>
            <p class="auditoria-subtitle">
                Carga masiva de tarifas de <code>TariProc</code> a partir de un archivo Excel, con vista previa
                (nuevas / a actualizar / sin cambio / no encontradas) antes de escribir en SIHOS.
            </p>
        </div>
    </div>

    <?php $filterUrl = 'sihos/tarifaProcedimiento'; require BASE_PATH . '/app/views/sgd/_empresa_filter.php'; ?>

    <?php $empresaId = $scope['empresaId'] ?? null; ?>

    <?php if ($empresaId === null): ?>
        <p class="field-note sgd-page-empty">Seleccione la empresa en el filtro superior para usar esta pantalla.</p>
    <?php elseif (!$configurado): ?>
        <p class="modal-form-alert">⚠️ Esta empresa no tiene conexión a SIHOS configurada. Ve a <a href="?url=sihos&empresa_id=<?= (int)$empresaId ?>">Conexión SIHOS</a>.</p>
    <?php else: ?>

        <form method="get" class="crud-form auditoria-filters-grid" style="margin-bottom:20px;">
            <input type="hidden" name="url" value="sihos/tarifaProcedimiento">
            <input type="hidden" name="empresa_id" value="<?= (int)$empresaId ?>">
            <div class="form-group">
                <label for="tpCodiManu">Manual tarifario</label>
                <select id="tpCodiManu" name="codi_manu" class="form-input" required onchange="this.form.submit()">
                    <option value="">— Seleccione —</option>
                    <?php foreach ($manuales as $codigo => $nombre): ?>
                        <?php $codigo = (string)$codigo; /* claves numéricas ("28") las castea PHP a int en el array */ ?>
                        <option value="<?= htmlspecialchars($codigo) ?>" <?= $codigo === $codiManu ? 'selected' : '' ?>>
                            <?= htmlspecialchars($codigo) ?> — <?= htmlspecialchars($nombre) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="tpCodiPlan">Plan</label>
                <select id="tpCodiPlan" name="codi_plan" class="form-input" onchange="this.form.submit()">
                    <?php foreach ($planes as $codigo => $nombre): ?>
                        <?php $codigo = (string)$codigo; ?>
                        <option value="<?= htmlspecialchars($codigo) ?>" <?= $codigo === $codiPlan ? 'selected' : '' ?>>
                            <?= htmlspecialchars($codigo) ?> — <?= htmlspecialchars($nombre) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="align-self:flex-end;">
                <a href="?url=sihos/tarifaProcedimientoPlantilla" class="auditoria-btn-primary" style="text-decoration:none;display:inline-block;">⬇️ Descargar plantilla</a>
            </div>
        </form>

        <?php if ($codiManu === ''): ?>
            <p class="auditoria-subtitle">Elija un manual tarifario para ver sus tarifas actuales y cargar un archivo.</p>
        <?php else: ?>

            <?php if ($grid !== null && $grid['ok'] && $grid['filas'] !== []): ?>
                <details style="margin-bottom:20px;">
                    <summary style="cursor:pointer;font-weight:bold;">Tarifas actuales de este manual (<?= count($grid['filas']) ?>)</summary>
                    <div style="overflow:auto;margin-top:10px;">
                        <table class="seguridad-table">
                            <thead>
                                <tr>
                                    <th>CodiProc</th><th>Nombre</th><th>TipoTari</th><th>ValoUnit</th>
                                    <th>GrupQuir</th><th>UVR</th><th>UVRMax</th><th>IndiUVB</th><th>Modificado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($grid['filas'] as $f): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($f['codiProc']) ?></td>
                                        <td><?= htmlspecialchars($f['NombProc']) ?></td>
                                        <td><?= (int)$f['TipoTari'] ?></td>
                                        <td><?= $f['ValoUnit'] !== null ? '$' . number_format($f['ValoUnit'], 0, ',', '.') : '—' ?></td>
                                        <td><?= $f['GrupQuir'] !== null ? htmlspecialchars($f['GrupQuir']) : '—' ?></td>
                                        <td><?= $f['UVR'] !== null ? htmlspecialchars((string)$f['UVR']) : '—' ?></td>
                                        <td><?= $f['UVRMax'] !== null ? htmlspecialchars((string)$f['UVRMax']) : '—' ?></td>
                                        <td><?= $f['IndiUVB'] !== null ? htmlspecialchars((string)$f['IndiUVB']) : '—' ?></td>
                                        <td><?= htmlspecialchars($f['FechModi']) ?> <span class="field-note"><?= htmlspecialchars($f['UsuaModi']) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </details>
            <?php elseif ($grid !== null && !$grid['ok']): ?>
                <p class="modal-form-alert">⚠️ <?= htmlspecialchars($grid['error']) ?></p>
            <?php endif; ?>

            <form id="sihosTarifaProcForm" method="post" enctype="multipart/form-data" class="crud-form auditoria-filters-grid" style="margin-bottom:20px;">
                <input type="hidden" name="url" value="sihos/tarifaProcedimiento">
                <input type="hidden" name="empresa_id" value="<?= (int)$empresaId ?>">
                <input type="hidden" name="codi_manu" value="<?= htmlspecialchars($codiManu) ?>">
                <input type="hidden" name="codi_plan" value="<?= htmlspecialchars($codiPlan) ?>">
                <div class="form-group">
                    <label for="tpArchivo">Archivo de tarifas (.xlsx)</label>
                    <input type="file" id="tpArchivo" name="archivo_tarifas" accept=".xlsx" class="form-input" required>
                </div>
                <div class="form-group">
                    <label for="tpModo">Modo</label>
                    <select id="tpModo" name="modo" class="form-input" required>
                        <option value="insertar">Insertar (solo nuevas)</option>
                        <option value="actualizar">Actualizar (solo existentes)</option>
                        <option value="ambos">Insertar y Actualizar</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="tpValorUvb">Valor UVB de referencia (solo si hay filas TipoTari=5)</label>
                    <input type="number" step="0.01" id="tpValorUvb" name="valor_uvb_lote" class="form-input" placeholder="ej. 12110">
                </div>
                <div class="form-group" style="align-self:flex-end;">
                    <button type="submit" class="auditoria-btn-primary">📤 Cargar y comparar</button>
                </div>
            </form>

            <?php if ($vistaPrevia === null): ?>
                <p class="auditoria-subtitle">Cargue un archivo para ver la vista previa antes de confirmar.</p>
            <?php elseif (!$vistaPrevia['ok']): ?>
                <p class="modal-form-alert">⚠️ <?= htmlspecialchars($vistaPrevia['error']) ?></p>
            <?php else: ?>

                <?php $resumen = $vistaPrevia['resumen']; ?>
                <p class="field-note">
                    <?= $resumen['total'] ?> fila(s) en el archivo —
                    <strong style="color:#2ecc71;"><?= $resumen['nuevos'] ?> nueva(s)</strong>,
                    <strong style="color:#f1c40f;"><?= $resumen['actualizar'] ?> a actualizar</strong>,
                    <?= $resumen['sin_cambio'] ?> sin cambio,
                    <strong style="color:#e74c3c;"><?= $resumen['excluidos'] ?> excluida(s) por error</strong>.
                </p>

                <div style="overflow:auto;">
                    <table class="seguridad-table">
                        <thead>
                            <tr>
                                <th>Fila</th><th>CodiProc</th><th>Nombre</th><th>TipoTari</th>
                                <th>Valor actual</th><th>Valor nuevo</th><th>Diferencia</th><th>UVB</th><th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $colorEstado = [
                                'nuevo' => '#2ecc71', 'actualizar' => '#f1c40f', 'sin_cambio' => '#7f8c8d',
                                'ya_existe' => '#e67e22', 'no_existe' => '#e67e22', 'error' => '#e74c3c',
                            ];
                            $etiquetaEstado = [
                                'nuevo' => 'Nuevo', 'actualizar' => 'Actualizar', 'sin_cambio' => 'Sin cambio',
                                'ya_existe' => 'Ya existe', 'no_existe' => 'No existe', 'error' => 'Error',
                            ];
                            ?>
                            <?php foreach ($vistaPrevia['filas'] as $f): ?>
                                <tr>
                                    <td><?= (int)$f['fila'] ?></td>
                                    <td><?= htmlspecialchars($f['codiProc']) ?></td>
                                    <td><?= $f['nombProc'] !== null ? htmlspecialchars($f['nombProc']) : '—' ?></td>
                                    <td><?= (int)$f['tipoTari'] ?></td>
                                    <td><?= $f['valorActual'] !== null ? '$' . number_format($f['valorActual'], 0, ',', '.') : '—' ?></td>
                                    <td><?= $f['valorNuevo'] !== null ? '$' . number_format($f['valorNuevo'], 0, ',', '.') : '—' ?></td>
                                    <td><?= $f['diferencia'] !== null ? '$' . number_format($f['diferencia'], 0, ',', '.') : '—' ?></td>
                                    <td><?= $f['indiUVB'] !== null ? htmlspecialchars((string)$f['indiUVB']) : '—' ?></td>
                                    <td style="color:<?= $colorEstado[$f['estado']] ?? '#7f8c8d' ?>;">
                                        <?= $etiquetaEstado[$f['estado']] ?? $f['estado'] ?>
                                        <?php if ($f['motivo'] !== null): ?>
                                            <div class="field-note"><?= htmlspecialchars($f['motivo']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($puedeConfirmar && $vistaPrevia['token'] !== null): ?>
                    <div id="sihosTarifaProcConfirmar"
                        data-token="<?= htmlspecialchars($vistaPrevia['token']) ?>"
                        data-empresa-id="<?= (int)$empresaId ?>"
                        data-codi-manu="<?= htmlspecialchars($codiManu) ?>"
                        data-nombre-manu="<?= htmlspecialchars($manuales[$codiManu] ?? $manuales[(int)$codiManu] ?? $codiManu) ?>"
                        data-codi-plan="<?= htmlspecialchars($codiPlan) ?>"
                        data-nuevos="<?= (int)$resumen['nuevos'] ?>"
                        data-actualizar="<?= (int)$resumen['actualizar'] ?>"
                        style="margin-top:16px;display:flex;gap:12px;align-items:center;">
                        <button type="button" id="sihosTarifaProcConfirmarBtn" class="auditoria-btn-primary">✅ Confirmar carga en SIHOS</button>
                        <span id="sihosTarifaProcStatus" class="field-note"></span>
                    </div>
                <?php endif; ?>

            <?php endif; ?>

        <?php endif; ?>
    <?php endif; ?>

</div>

<script src="/js/sihos-tarifa-procedimiento.js?v=<?= (int)$tarifaProcJsV ?>"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('sihosTarifaProcForm');
    if (form && typeof savidMostrarCargando === 'function') {
        form.addEventListener('submit', function () {
            savidMostrarCargando('Comparando contra SIHOS…', true);
        });
    }
});
</script>
