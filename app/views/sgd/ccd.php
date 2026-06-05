<?php
/** @var array $scope */
/** @var int|null $empresaId */
/** @var list<array<string, mixed>> $entradas */
/** @var int $total */
/** @var int $filterDependenciaId */
/** @var array<string, mixed>|null $edit */
/** @var array<string, mixed>|null $config */
/** @var array<string, list<array<string, mixed>>> $catalogos */
/** @var string $catalogosJson */
/** @var int $selectedGridId */
/** @var bool $canGuardar */
/** @var bool $canEliminar */
/** @var bool $canImportar */

$sgdCcdJs = BASE_PATH . '/public/js/sgd-ccd.js';
$sgdCcdJsV = is_readable($sgdCcdJs) ? (int)filemtime($sgdCcdJs) : time();
$sgdCatalogJs = BASE_PATH . '/public/js/sgd-catalog.js';
$sgdCatalogJsV = is_readable($sgdCatalogJs) ? (int)filemtime($sgdCatalogJs) : time();

$sgdPickLabel = static function (array $list, $id, bool $isDoc = false): string {
    if ($id === '' || $id === null || (int)$id <= 0) {
        return '';
    }
    foreach ($list as $item) {
        if ((int)$item['id'] === (int)$id) {
            if ($isDoc) {
                return trim((string)($item['codigo_display'] ?? '') . ' — ' . (string)($item['nombre'] ?? ''));
            }

            return trim((string)$item['codigo'] . ' — ' . (string)$item['nombre']);
        }
    }

    return '';
};

$form = $edit ?? [
    'id' => '',
    'dependencia_id' => '',
    'serie_id' => '',
    'subserie_id' => '',
    'documento_id' => '',
    'orden' => '0',
    'estado_id' => '1',
    'codigo_carpeta_display' => '',
    'ubicacion_label' => '',
];

$empresaQuery = $empresaId ? '&empresa_id=' . (int)$empresaId : '';
$listUrl = '?url=sgd/ccd' . $empresaQuery;
$formAction = $listUrl;
if ($filterDependenciaId > 0) {
    $listUrl .= '&dependencia_id=' . $filterDependenciaId;
    $formAction = $listUrl;
}

$vigenciaLabel = trim((string)($config['ccd_vigencia'] ?? ''));
$anioLabel = isset($config['ccd_anio']) && $config['ccd_anio'] !== '' ? (string)$config['ccd_anio'] : '';
?>
<div class="module-container sgd-documentos-page sgd-ccd-page">
    <?php $filterUrl = 'sgd/ccd'; require BASE_PATH . '/app/views/sgd/_empresa_filter.php'; ?>

    <?php if (empty($empresaId)): ?>
        <p class="field-note sgd-page-empty">Seleccione la empresa en el filtro superior para ver el cuadro de clasificación.</p>
    <?php else: ?>

        <p class="field-note sgd-page-lead sgd-ccd-lead">
            Mapa de <strong>ubicaciones archivísticas</strong>: área del organigrama + serie/subserie + vínculo opcional al listado maestro.
            <?php if ($vigenciaLabel !== '' || $anioLabel !== ''): ?>
                Vigencia activa:
                <strong><?= htmlspecialchars($vigenciaLabel !== '' ? $vigenciaLabel : '—', ENT_QUOTES, 'UTF-8') ?></strong>
                <?php if ($anioLabel !== ''): ?>
                    (<?= htmlspecialchars($anioLabel, ENT_QUOTES, 'UTF-8') ?>)
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($canImportar): ?>
                · <a href="?url=sgd/importar<?= $empresaQuery ?>">Importar desde Excel</a>
            <?php endif; ?>
        </p>

        <div class="crud-toolbar sgd-doc-toolbar">
            <div class="sgd-doc-toolbar-actions">
                <a href="<?= htmlspecialchars($listUrl, ENT_QUOTES, 'UTF-8') ?>" id="sgd-ccd-btn-nuevo" class="sgd-doc-btn" title="Nueva ubicación">➕ Nueva ubicación</a>
                <?php if ($canGuardar): ?>
                    <button type="submit" form="sgd-ccd-form" class="sgd-doc-btn sgd-doc-btn-primary" title="Guardar">💾 Guardar</button>
                <?php endif; ?>
                <?php if ($canEliminar && !empty($form['id'])): ?>
                    <button type="submit" form="sgd-ccd-delete-form" class="sgd-doc-btn sgd-doc-btn-danger" title="Eliminar">🗑 Eliminar</button>
                <?php endif; ?>
            </div>
        </div>

        <div class="sgd-doc-layout">
            <section class="sgd-panel sgd-doc-list-panel">
                <header class="sgd-panel-head">
                    <h3 class="sgd-panel-title">Ubicaciones CCD</h3>
                    <span class="sgd-doc-count"><?= (int)$total ?> registro(s)</span>
                </header>

                <form method="get" action="?url=sgd/ccd" class="sgd-ccd-filter-form">
                    <input type="hidden" name="url" value="sgd/ccd">
                    <input type="hidden" name="empresa_id" value="<?= (int)$empresaId ?>">
                    <label class="sgd-ccd-filter-label" for="sgd_ccd_filter_dep_search">Filtrar por área</label>
                    <?php
                    $name = 'dependencia_id';
                    $inputId = 'sgd_ccd_filter_dep';
                    $value = $filterDependenciaId > 0 ? $filterDependenciaId : '';
                    $displayValue = $filterDependenciaId > 0
                        ? $sgdPickLabel($catalogos['dependencias'], $filterDependenciaId)
                        : '';
                    $placeholder = 'Todas las áreas — buscar código o nombre…';
                    $required = false;
                    $catalogKey = 'dependencias';
                    $parentFieldsJson = '[]';
                    $allowEmpty = true;
                    $submitOnPick = true;
                    $extraAttrs = '';
                    require BASE_PATH . '/app/views/sgd/_catalog_field.php';
                    ?>
                </form>

                <div class="sgd-doc-table-wrap">
                    <table class="sgd-doc-table savid-datatable" data-dt-page-length="50">
                        <thead>
                            <tr>
                                <th>Código carpeta</th>
                                <th>Serie</th>
                                <th>Subserie</th>
                                <th>Área</th>
                                <th>Formato (calidad)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($entradas === []): ?>
                                <tr>
                                    <td colspan="5" class="sgd-doc-empty">No hay ubicaciones. Importe el CCD o cree una nueva.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($entradas as $row): ?>
                                    <?php
                                    $rowUrl = '?url=sgd/ccd' . $empresaQuery . '&id=' . (int)$row['id'];
                                    if ($filterDependenciaId > 0) {
                                        $rowUrl .= '&dependencia_id=' . $filterDependenciaId;
                                    }
                                    $isActive = (int)($form['id'] ?? 0) === (int)$row['id'];
                                    $isGridSelected = $selectedGridId > 0 && $selectedGridId === (int)$row['id'];
                                    ?>
                                    <tr class="sgd-doc-row<?= $isActive || $isGridSelected ? ' is-active' : '' ?>">
                                        <td>
                                            <a href="<?= htmlspecialchars($rowUrl, ENT_QUOTES, 'UTF-8') ?>" class="sgd-doc-code-link">
                                                <?= htmlspecialchars((string)($row['codigo_carpeta_display'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                            </a>
                                        </td>
                                        <td>
                                            <?php if (!empty($row['serie_codigo'])): ?>
                                                <span class="sgd-doc-badge"><?= htmlspecialchars((string)$row['serie_codigo'], ENT_QUOTES, 'UTF-8') ?></span>
                                                <?= htmlspecialchars((string)($row['serie_nombre'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                            <?php else: ?>
                                                <span class="field-note">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($row['subserie_nombre'])): ?>
                                                <?php if (!empty($row['subserie_codigo'])): ?>
                                                    <span class="sgd-doc-badge"><?= htmlspecialchars((string)$row['subserie_codigo'], ENT_QUOTES, 'UTF-8') ?></span>
                                                <?php endif; ?>
                                                <?= htmlspecialchars((string)$row['subserie_nombre'], ENT_QUOTES, 'UTF-8') ?>
                                            <?php else: ?>
                                                <span class="field-note">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="sgd-doc-badge"><?= htmlspecialchars((string)($row['dependencia_codigo'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                                            <?= htmlspecialchars((string)($row['dependencia_nombre'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($row['documento_codigo_display'])): ?>
                                                <span class="sgd-doc-code-link"><?= htmlspecialchars((string)$row['documento_codigo_display'], ENT_QUOTES, 'UTF-8') ?></span>
                                            <?php else: ?>
                                                <span class="field-note">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="sgd-panel sgd-doc-form-panel">
                <header class="sgd-panel-head">
                    <h3 class="sgd-panel-title"><?= !empty($form['id']) ? 'Editar ubicación' : 'Nueva ubicación' ?></h3>
                </header>

                <div class="sgd-doc-code-preview" id="sgd-ccd-code-preview">
                    <span class="sgd-doc-code-preview-label">Código de carpeta</span>
                    <strong class="sgd-doc-code-preview-value" id="sgd-ccd-code-value">
                        <?= htmlspecialchars((string)($form['codigo_carpeta_display'] ?? '—'), ENT_QUOTES, 'UTF-8') ?>
                    </strong>
                    <p class="field-note sgd-doc-code-note" id="sgd-ccd-code-note">
                        Patrón: <code>área.serie.subserie</code> (ej. 40.1.7). Define dónde se archivan las evidencias de ese asunto.
                    </p>
                </div>

                <form id="sgd-ccd-form" method="post" action="<?= htmlspecialchars($formAction, ENT_QUOTES, 'UTF-8') ?>" class="crud-form sgd-doc-form">
                    <fieldset <?= $canGuardar ? '' : 'disabled' ?> class="sgd-doc-fieldset">
                    <input type="hidden" name="_action" value="save">
                    <input type="hidden" name="id" id="sgd_ccd_id" value="<?= htmlspecialchars((string)($form['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">

                    <div class="form-group">
                        <label for="sgd_ccd_dependencia_search">Área (organigrama)</label>
                        <?php
                        $name = 'dependencia_id';
                        $inputId = 'sgd_ccd_dependencia_id';
                        $value = $form['dependencia_id'] ?? '';
                        $displayValue = $sgdPickLabel($catalogos['dependencias'], $value);
                        $placeholder = 'Buscar área por código o nombre…';
                        $required = true;
                        $catalogKey = 'dependencias';
                        $parentFieldsJson = '[]';
                        $allowEmpty = false;
                        $submitOnPick = false;
                        $extraAttrs = 'data-sgd-ccd-field';
                        require BASE_PATH . '/app/views/sgd/_catalog_field.php';
                        ?>
                    </div>

                    <div class="form-group">
                        <label for="sgd_ccd_serie_search">Serie documental</label>
                        <?php
                        $name = 'serie_id';
                        $inputId = 'sgd_ccd_serie_id';
                        $value = $form['serie_id'] ?? '';
                        $displayValue = $sgdPickLabel($catalogos['series'], $value);
                        $placeholder = 'Buscar serie…';
                        $required = true;
                        $catalogKey = 'series';
                        $parentFieldsJson = '[]';
                        $allowEmpty = false;
                        $submitOnPick = false;
                        $extraAttrs = 'data-sgd-ccd-field';
                        require BASE_PATH . '/app/views/sgd/_catalog_field.php';
                        ?>
                    </div>

                    <div class="form-group">
                        <label for="sgd_ccd_subserie_search">Subserie (opcional)</label>
                        <?php
                        $name = 'subserie_id';
                        $inputId = 'sgd_ccd_subserie_id';
                        $value = $form['subserie_id'] ?? '';
                        $displayValue = $sgdPickLabel($catalogos['subseries'], $value);
                        $placeholder = 'Buscar subserie (filtrada por serie)…';
                        $required = false;
                        $catalogKey = 'subseries';
                        $parentFieldsJson = '["serie_id"]';
                        $allowEmpty = true;
                        $submitOnPick = false;
                        $extraAttrs = 'data-sgd-ccd-field';
                        require BASE_PATH . '/app/views/sgd/_catalog_field.php';
                        ?>
                        <p class="field-note">Nombre del asunto archivístico (actas, historias laborales, etc.).</p>
                    </div>

                    <div class="form-group">
                        <label for="sgd_ccd_documento_search">Formato listado maestro (opcional)</label>
                        <?php
                        $name = 'documento_id';
                        $inputId = 'sgd_ccd_documento_id';
                        $value = $form['documento_id'] ?? '';
                        $displayValue = $sgdPickLabel($catalogos['documentos'], $value, true);
                        $placeholder = 'Buscar código SGC o nombre…';
                        $required = false;
                        $catalogKey = 'documentos';
                        $parentFieldsJson = '[]';
                        $allowEmpty = true;
                        $submitOnPick = false;
                        $extraAttrs = 'data-sgd-ccd-field';
                        require BASE_PATH . '/app/views/sgd/_catalog_field.php';
                        ?>
                        <p class="field-note">Mismo formato puede archivarse en varias áreas (ej. acta en Gerencia, TAC y Talento humano).</p>
                    </div>

                    <div class="form-group">
                        <label for="sgd_ccd_orden">Orden</label>
                        <input
                            type="number"
                            name="orden"
                            id="sgd_ccd_orden"
                            class="form-input"
                            min="0"
                            value="<?= htmlspecialchars((string)($form['orden'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>"
                        >
                    </div>

                    <div class="form-group">
                        <label for="sgd_ccd_estado_id">Estado</label>
                        <select name="estado_id" id="sgd_ccd_estado_id" class="form-input">
                            <option value="1" <?= (int)($form['estado_id'] ?? 1) === 1 ? 'selected' : '' ?>>Activo</option>
                            <option value="2" <?= (int)($form['estado_id'] ?? 1) === 2 ? 'selected' : '' ?>>Inactivo</option>
                        </select>
                    </div>
                    </fieldset>
                </form>

                <?php if ($canEliminar && !empty($form['id'])): ?>
                    <form id="sgd-ccd-delete-form" method="post" action="<?= htmlspecialchars($formAction, ENT_QUOTES, 'UTF-8') ?>"
                          onsubmit="return confirm('¿Eliminar esta ubicación del cuadro de clasificación?');">
                        <input type="hidden" name="_action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$form['id'] ?>">
                    </form>
                <?php endif; ?>
            </section>
        </div>

        <script type="application/json" id="sgd-ccd-catalogos"><?= $catalogosJson ?></script>
        <script src="/js/sgd-catalog.js?v=<?= (int)$sgdCatalogJsV ?>"></script>
        <script src="/js/sgd-ccd.js?v=<?= (int)$sgdCcdJsV ?>"></script>
    <?php endif; ?>
</div>
