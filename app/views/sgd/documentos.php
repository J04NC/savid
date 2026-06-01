<?php
/** @var array $scope */
/** @var int|null $empresaId */
/** @var list<array<string, mixed>> $documentos */
/** @var int $total */
/** @var string $search */
/** @var array<string, mixed>|null $edit */
/** @var array<string, list<array<string, mixed>>> $catalogos */
/** @var string $catalogosJson */
/** @var int $selectedGridId */
/** @var bool $canGuardar */
/** @var bool $canEliminar */

$sgdDocJs = BASE_PATH . '/public/js/sgd-documentos.js';
$sgdDocJsV = is_readable($sgdDocJs) ? (int)filemtime($sgdDocJs) : time();

$form = $edit ?? [
    'id' => '',
    'proceso_id' => '',
    'documento_id' => '',
    'tipo_documental_id' => '',
    'linea_documental_id' => '',
    'consecutivo' => '',
    'nombre' => '',
    'modo' => '',
    'version_actual' => '',
    'fecha_primera_aprobacion' => '',
    'fecha_ultima_aprobacion' => '',
    'estado_id' => (string)SgdRepository::ESTADO_DOC_VIGENTE,
    'codigo_display' => '',
];

$estadosDocumentales = $catalogos['estadosDocumentales'] ?? [];

$empresaQuery = $empresaId ? '&empresa_id=' . (int)$empresaId : '';
$listUrl = '?url=sgd/documentos' . $empresaQuery;
$formAction = $listUrl;

$formatOption = static function (string $codigo, string $nombre): string {
    return htmlspecialchars($codigo . ' — ' . $nombre, ENT_QUOTES, 'UTF-8');
};
?>
<div class="module-container sgd-documentos-page">
    <?php $filterUrl = 'sgd/documentos'; require BASE_PATH . '/app/views/sgd/_empresa_filter.php'; ?>

    <?php if (empty($empresaId)): ?>
        <p class="field-note sgd-page-empty">Seleccione la empresa en el filtro superior para administrar el listado maestro.</p>
    <?php else: ?>

        <div class="crud-toolbar sgd-doc-toolbar">
            <div class="sgd-doc-toolbar-actions">
                <a href="<?= htmlspecialchars($listUrl, ENT_QUOTES, 'UTF-8') ?>" id="sgd-doc-btn-nuevo" class="sgd-doc-btn" title="Nuevo documento">➕ Nuevo</a>
                <?php if ($canGuardar): ?>
                    <button type="submit" form="sgd-doc-form" class="sgd-doc-btn sgd-doc-btn-primary" title="Guardar">💾 Guardar</button>
                <?php endif; ?>
                <?php if ($canEliminar && !empty($form['id'])): ?>
                    <button type="submit" form="sgd-doc-delete-form" class="sgd-doc-btn sgd-doc-btn-danger" title="Eliminar">🗑 Eliminar</button>
                <?php endif; ?>
            </div>
        </div>

        <div class="sgd-doc-layout">
            <section class="sgd-panel sgd-doc-list-panel">
                <header class="sgd-panel-head">
                    <h3 class="sgd-panel-title">Listado maestro</h3>
                    <span class="sgd-doc-count"><?= (int)$total ?> documento(s)</span>
                </header>
                <div class="sgd-doc-table-wrap">
                    <table class="sgd-doc-table savid-datatable" data-dt-page-length="50">
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Nombre</th>
                                <th>Tipo</th>
                                <th>Versión</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($documentos === []): ?>
                                <tr>
                                    <td colspan="4" class="sgd-doc-empty">No hay documentos para mostrar.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($documentos as $doc): ?>
                                    <?php
                                    $rowUrl = $listUrl . '&id=' . (int)$doc['id'];
                                    if ($search !== '') {
                                        $rowUrl .= '&q=' . urlencode($search);
                                    }
                                    $isActive = (int)($form['id'] ?? 0) === (int)$doc['id'];
                                    $isGridSelected = $selectedGridId > 0 && $selectedGridId === (int)$doc['id'];
                                    ?>
                                    <tr
                                        class="sgd-doc-row<?= $isActive || $isGridSelected ? ' is-active' : '' ?>"
                                        data-doc-id="<?= (int)$doc['id'] ?>"
                                        data-proceso-id="<?= (int)($doc['proceso_id'] ?? 0) ?>"
                                        data-tipo-id="<?= (int)($doc['tipo_documental_id'] ?? 0) ?>"
                                        tabindex="0"
                                        role="button"
                                        aria-label="Seleccionar como documento padre"
                                    >
                                        <td>
                                            <a href="<?= htmlspecialchars($rowUrl, ENT_QUOTES, 'UTF-8') ?>" class="sgd-doc-code-link">
                                                <?= htmlspecialchars((string)($doc['codigo_display'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                            </a>
                                        </td>
                                        <td><?= htmlspecialchars((string)$doc['nombre'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td>
                                            <?php if (!empty($doc['tipo_codigo'])): ?>
                                                <span class="sgd-doc-badge"><?= htmlspecialchars((string)$doc['tipo_codigo'], ENT_QUOTES, 'UTF-8') ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars((string)($doc['version_actual'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="sgd-panel sgd-doc-form-panel">
                <header class="sgd-panel-head">
                    <h3 class="sgd-panel-title"><?= !empty($form['id']) ? 'Editar documento' : 'Nuevo documento' ?></h3>
                </header>

                <div class="sgd-doc-code-preview" id="sgd-doc-code-preview">
                    <span class="sgd-doc-code-preview-label">Código resultante</span>
                    <strong class="sgd-doc-code-preview-value" id="sgd-doc-code-value">
                        <?= htmlspecialchars((string)($form['codigo_display'] ?? '—'), ENT_QUOTES, 'UTF-8') ?>
                    </strong>
                    <p class="field-note sgd-doc-code-note" id="sgd-doc-code-note">
                        Se compone con proceso, tipo, línea documental (opcional), documento padre y consecutivo.
                    </p>
                </div>

                <form id="sgd-doc-form" method="post" action="<?= htmlspecialchars($formAction, ENT_QUOTES, 'UTF-8') ?>" class="crud-form sgd-doc-form">
                    <fieldset <?= $canGuardar ? '' : 'disabled' ?> class="sgd-doc-fieldset">
                    <input type="hidden" name="_action" value="save">
                    <input type="hidden" name="id" id="sgd_doc_id" value="<?= htmlspecialchars((string)($form['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">

                    <div class="form-group">
                        <label for="sgd_doc_proceso_id">Proceso</label>
                        <select name="proceso_id" id="sgd_doc_proceso_id" class="form-input" required data-sgd-doc-field>
                            <option value="">— Seleccione —</option>
                            <?php foreach ($catalogos['procesos'] as $p): ?>
                                <option
                                    value="<?= (int)$p['id'] ?>"
                                    data-codigo="<?= htmlspecialchars((string)$p['codigo'], ENT_QUOTES, 'UTF-8') ?>"
                                    <?= (int)($form['proceso_id'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>
                                ><?= $formatOption((string)$p['codigo'], (string)$p['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="sgd_doc_tipo_id">Tipo documental</label>
                        <select name="tipo_documental_id" id="sgd_doc_tipo_id" class="form-input" required data-sgd-doc-field>
                            <option value="">— Seleccione —</option>
                            <?php foreach ($catalogos['tipos'] as $t): ?>
                                <option
                                    value="<?= (int)$t['id'] ?>"
                                    data-codigo="<?= htmlspecialchars((string)$t['codigo'], ENT_QUOTES, 'UTF-8') ?>"
                                    <?= (int)($form['tipo_documental_id'] ?? 0) === (int)$t['id'] ? 'selected' : '' ?>
                                ><?= $formatOption((string)$t['codigo'], (string)$t['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group" id="sgd-doc-linea-group">
                        <label for="sgd_doc_linea_id">Línea documental</label>
                        <select name="linea_documental_id" id="sgd_doc_linea_id" class="form-input" data-sgd-doc-field>
                            <option value="">— Seleccione —</option>
                            <?php foreach ($catalogos['lineas'] as $l): ?>
                                <option
                                    value="<?= (int)$l['id'] ?>"
                                    data-codigo="<?= htmlspecialchars((string)$l['codigo'], ENT_QUOTES, 'UTF-8') ?>"
                                    <?= (int)($form['linea_documental_id'] ?? 0) === (int)$l['id'] ? 'selected' : '' ?>
                                ><?= $formatOption((string)$l['codigo'], (string)$l['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="field-note">Opcional. Clasificador definido por la empresa (área, disciplina, familia documental, etc.). Solo en documentos raíz; los hijos heredan el código del padre.</p>
                    </div>

                    <div class="form-group">
                        <label for="sgd_doc_padre_id">Documento padre</label>
                        <select name="documento_id" id="sgd_doc_padre_id" class="form-input" data-sgd-doc-field>
                            <option value="">— Ninguno (documento raíz) —</option>
                            <?php foreach ($catalogos['padres'] as $padre): ?>
                                <?php if (!empty($form['id']) && (int)$form['id'] === (int)$padre['id']) continue; ?>
                                <option
                                    value="<?= (int)$padre['id'] ?>"
                                    data-tipo-documental-id="<?= (int)($padre['tipo_documental_id'] ?? 0) ?>"
                                    data-proceso-id="<?= (int)($padre['proceso_id'] ?? 0) ?>"
                                    data-codigo-display="<?= htmlspecialchars((string)($padre['codigo_display'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                    data-proceso-codigo="<?= htmlspecialchars((string)($padre['proceso_codigo'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                    <?= (int)($form['documento_id'] ?? 0) === (int)$padre['id'] ? 'selected' : '' ?>
                                ><?= htmlspecialchars((string)($padre['codigo_display'] ?? ''), ENT_QUOTES, 'UTF-8') ?> — <?= htmlspecialchars((string)$padre['nombre'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="field-note" id="sgd-doc-padre-note">Solo documentos del mismo proceso y con tipo padre permitido (Tipos documentales → Padres permitidos).</p>
                    </div>

                    <div class="form-group">
                        <label for="sgd_doc_consecutivo">Consecutivo</label>
                        <input
                            type="text"
                            name="consecutivo"
                            id="sgd_doc_consecutivo"
                            class="form-input"
                            required
                            maxlength="64"
                            value="<?= htmlspecialchars((string)($form['consecutivo'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                            placeholder="Automático: último + 1"
                            data-sgd-doc-field
                            data-sgd-doc-consecutivo
                        >
                        <p class="field-note">Se asigna el siguiente número en el mismo proceso, padre y tipo (si no hay registros, empieza en <code>1</code>). En hijos F suele ser <code>1</code> (se muestra como F1).</p>
                    </div>

                    <div class="form-group crud-form-field-full">
                        <label for="sgd_doc_nombre">Nombre</label>
                        <input
                            type="text"
                            name="nombre"
                            id="sgd_doc_nombre"
                            class="form-input"
                            required
                            maxlength="500"
                            value="<?= htmlspecialchars((string)($form['nombre'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                        >
                    </div>

                    <div class="form-group">
                        <label for="sgd_doc_modo">Modo (opcional)</label>
                        <select name="modo" id="sgd_doc_modo" class="form-input">
                            <option value="">— Heredar del tipo —</option>
                            <?php foreach (['maestro' => 'Maestro', 'dinamico' => 'Dinámico', 'hibrido' => 'Híbrido'] as $val => $label): ?>
                                <option value="<?= $val ?>" <?= ($form['modo'] ?? '') === $val ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="sgd_doc_version">Versión actual</label>
                        <input type="text" name="version_actual" id="sgd_doc_version" class="form-input" maxlength="32"
                               value="<?= htmlspecialchars((string)($form['version_actual'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    </div>

                    <div class="form-group">
                        <label for="sgd_doc_estado_id">Estado documental</label>
                        <select name="estado_id" id="sgd_doc_estado_id" class="form-input" required>
                            <?php foreach ($estadosDocumentales as $est): ?>
                                <?php
                                $eid = (int)$est['id'];
                                $selected = (int)($form['estado_id'] ?? SgdRepository::ESTADO_DOC_VIGENTE) === $eid;
                                ?>
                                <option value="<?= $eid ?>" <?= $selected ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string)$est['nombre'], ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="sgd_doc_fecha_primera">Primera aprobación</label>
                        <input type="date" name="fecha_primera_aprobacion" id="sgd_doc_fecha_primera" class="form-input"
                               value="<?= htmlspecialchars((string)($form['fecha_primera_aprobacion'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    </div>

                    <div class="form-group">
                        <label for="sgd_doc_fecha_ultima">Última aprobación</label>
                        <input type="date" name="fecha_ultima_aprobacion" id="sgd_doc_fecha_ultima" class="form-input"
                               value="<?= htmlspecialchars((string)($form['fecha_ultima_aprobacion'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    </fieldset>
                </form>

                <?php if ($canEliminar && !empty($form['id'])): ?>
                    <form id="sgd-doc-delete-form" method="post" action="<?= htmlspecialchars($formAction, ENT_QUOTES, 'UTF-8') ?>"
                          onsubmit="return confirm('¿Eliminar este documento?');">
                        <input type="hidden" name="_action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$form['id'] ?>">
                    </form>
                <?php endif; ?>
            </section>
        </div>

        <script type="application/json" id="sgd-doc-catalogos"><?= $catalogosJson ?></script>
        <script src="/js/sgd-documentos.js?v=<?= (int)$sgdDocJsV ?>"></script>
    <?php endif; ?>
</div>
