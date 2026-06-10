<?php
/** @var array $scope */
/** @var int|null $empresaId */
/** @var list<array<string, mixed>> $secciones */
/** @var array<string, mixed>|null $edit */
/** @var array<string, string> $clases */
/** @var bool $canGuardar */
/** @var bool $canEliminar */

$form = $edit ?? [
    'id' => '',
    'codigo' => '',
    'nombre' => '',
    'clase' => 'contenido',
    'orden' => 10,
    'ayuda' => '',
];

$empresaQuery = $empresaId ? '&empresa_id=' . (int)$empresaId : '';
$listUrl = '?url=sgd/secciones' . $empresaQuery;
$selectedId = (int)($form['id'] ?? 0);
?>
<div class="module-container sgd-secciones-page">
    <?php $filterUrl = 'sgd/secciones'; require BASE_PATH . '/app/views/sgd/_empresa_filter.php'; ?>

    <?php if (empty($empresaId)): ?>
        <p class="field-note sgd-page-empty">Seleccione la empresa en el filtro superior.</p>
    <?php else: ?>

        <div class="crud-toolbar sgd-doc-toolbar">
            <div class="sgd-doc-toolbar-actions">
                <a href="<?= htmlspecialchars($listUrl, ENT_QUOTES, 'UTF-8') ?>" class="sgd-doc-btn">➕ Nueva</a>
                <?php if ($canGuardar): ?>
                    <button type="submit" form="sgd-seccion-form" class="sgd-doc-btn sgd-doc-btn-primary">💾 Guardar</button>
                <?php endif; ?>
                <?php if ($canEliminar && $selectedId > 0): ?>
                    <button type="submit" form="sgd-seccion-delete-form" class="sgd-doc-btn sgd-doc-btn-danger"
                            onclick="return confirm('¿Eliminar esta sección?');">🗑 Eliminar</button>
                <?php endif; ?>
            </div>
        </div>

        <div class="sgd-doc-layout">
            <section class="sgd-panel sgd-doc-list-panel">
                <header class="sgd-panel-head">
                    <h3 class="sgd-panel-title">Catálogo de secciones</h3>
                    <span class="sgd-doc-count"><?= count($secciones) ?> sección(es)</span>
                </header>
                <div class="sgd-doc-table-wrap">
                    <table class="sgd-doc-table savid-datatable" data-dt-page-length="50">
                        <thead>
                            <tr>
                                <th>Orden</th>
                                <th>Código</th>
                                <th>Nombre</th>
                                <th>Clase</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($secciones === []): ?>
                            <tr><td colspan="4" class="sgd-doc-empty">Sin secciones. Importe la plantilla desde Configuración SGD.</td></tr>
                        <?php else: ?>
                            <?php foreach ($secciones as $row): ?>
                                <?php $rid = (int)$row['id']; ?>
                                <tr class="sgd-doc-row<?= $rid === $selectedId ? ' is-active' : '' ?>"
                                    onclick="window.location='<?= htmlspecialchars($listUrl . '&id=' . $rid, ENT_QUOTES, 'UTF-8') ?>'">
                                    <td><?= (int)$row['orden'] ?></td>
                                    <td><code><?= htmlspecialchars((string)$row['codigo'], ENT_QUOTES, 'UTF-8') ?></code></td>
                                    <td><?= htmlspecialchars((string)$row['nombre'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string)($clases[$row['clase']] ?? $row['clase']), ENT_QUOTES, 'UTF-8') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="sgd-panel sgd-doc-form-panel">
                <header class="sgd-panel-head">
                    <h3 class="sgd-panel-title"><?= $selectedId > 0 ? 'Editar sección' : 'Nueva sección' ?></h3>
                </header>
                <form id="sgd-seccion-form" method="post" action="<?= htmlspecialchars($listUrl, ENT_QUOTES, 'UTF-8') ?>" class="crud-form">
                    <input type="hidden" name="_action" value="save">
                    <input type="hidden" name="id" value="<?= htmlspecialchars((string)$form['id'], ENT_QUOTES, 'UTF-8') ?>">

                    <fieldset <?= $canGuardar ? '' : 'disabled' ?>>
                        <div class="form-group">
                            <label for="sgd_sec_codigo">Código</label>
                            <input type="text" id="sgd_sec_codigo" name="codigo" class="form-input" maxlength="32"
                                   value="<?= htmlspecialchars((string)$form['codigo'], ENT_QUOTES, 'UTF-8') ?>"
                                   <?= $selectedId > 0 ? 'readonly' : 'required' ?>
                                   placeholder="ej. objetivo">
                        </div>
                        <div class="form-group">
                            <label for="sgd_sec_nombre">Nombre</label>
                            <input type="text" id="sgd_sec_nombre" name="nombre" class="form-input" maxlength="120" required
                                   value="<?= htmlspecialchars((string)$form['nombre'], ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="form-group">
                            <label for="sgd_sec_clase">Clase</label>
                            <select id="sgd_sec_clase" name="clase" class="form-input">
                                <?php foreach ($clases as $val => $label): ?>
                                    <option value="<?= htmlspecialchars($val, ENT_QUOTES, 'UTF-8') ?>"
                                        <?= ($form['clase'] ?? '') === $val ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="sgd_sec_orden">Orden</label>
                            <input type="number" id="sgd_sec_orden" name="orden" class="form-input" min="0"
                                   value="<?= (int)($form['orden'] ?? 0) ?>">
                        </div>
                        <div class="form-group">
                            <label for="sgd_sec_ayuda">Ayuda</label>
                            <textarea id="sgd_sec_ayuda" name="ayuda" class="form-input" rows="3"><?= htmlspecialchars((string)($form['ayuda'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                        </div>
                    </fieldset>
                </form>
                <?php if ($canEliminar && $selectedId > 0): ?>
                    <form id="sgd-seccion-delete-form" method="post" action="<?= htmlspecialchars($listUrl, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="_action" value="delete">
                        <input type="hidden" name="id" value="<?= $selectedId ?>">
                    </form>
                <?php endif; ?>
            </section>
        </div>

    <?php endif; ?>
</div>
