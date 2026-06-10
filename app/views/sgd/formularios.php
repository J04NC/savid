<?php
/** @var array $scope */
/** @var int|null $empresaId */
/** @var int $documentoId */
/** @var array<string, mixed>|null $documento */
/** @var string $codigoDisplay */
/** @var string $proposito */
/** @var string $propositoLabel */
/** @var array<string, mixed>|null $version */
/** @var list<array<string, mixed>> $versiones */
/** @var array{version: int, campos: list<array<string, mixed>>} $esquema */
/** @var string $esquemaJson */
/** @var list<string> $tiposCampo */
/** @var bool $canGuardar */

$empresaQuery = $empresaId ? '&empresa_id=' . (int)$empresaId : '';
$formUrl = '?url=sgd/formularios' . $empresaQuery;
$docUrl = '?url=sgd/documentos' . $empresaQuery . ($documentoId > 0 ? '&id=' . $documentoId : '');
$versionId = (int)($version['id'] ?? 0);
$esBorrador = (int)($version['estado_id'] ?? 0) === SgdRepository::ESTADO_DOC_BORRADOR;

$sgdFormJs = BASE_PATH . '/public/js/sgd-formularios.js';
$sgdFormJsV = is_readable($sgdFormJs) ? (int)filemtime($sgdFormJs) : time();
?>
<div class="module-container sgd-formularios-page">
    <?php $filterUrl = 'sgd/formularios'; require BASE_PATH . '/app/views/sgd/_empresa_filter.php'; ?>

    <?php if (empty($empresaId)): ?>
        <p class="field-note sgd-page-empty">Seleccione la empresa en el filtro superior.</p>
    <?php elseif ($documentoId <= 0 || $documento === null): ?>
        <p class="field-note sgd-page-empty">
            Abra el diseñador desde un documento del
            <a href="<?= htmlspecialchars('?url=sgd/documentos' . $empresaQuery, ENT_QUOTES, 'UTF-8') ?>">listado maestro</a>
            (botón «Diseñar plantilla»).
        </p>
    <?php else: ?>

        <div class="crud-toolbar sgd-form-toolbar">
            <a href="<?= htmlspecialchars($docUrl, ENT_QUOTES, 'UTF-8') ?>" class="sgd-doc-btn">← Volver al documento</a>
            <?php if ($canGuardar && $esBorrador): ?>
                <button type="submit" form="sgd-form-designer-save" class="sgd-doc-btn sgd-doc-btn-primary">💾 Guardar plantilla</button>
                <button type="submit" form="sgd-form-designer-publish" class="sgd-doc-btn"
                        onclick="return confirm('¿Publicar esta versión de plantilla?');">Publicar</button>
            <?php endif; ?>
        </div>

        <header class="sgd-form-head">
            <h3 class="sgd-panel-title">Diseñador de plantilla</h3>
            <p class="sgd-form-doc-meta">
                <strong><?= htmlspecialchars($codigoDisplay, ENT_QUOTES, 'UTF-8') ?></strong>
                — <?= htmlspecialchars((string)($documento['nombre'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
            </p>
            <p class="field-note">
                Propósito: <strong><?= htmlspecialchars($propositoLabel, ENT_QUOTES, 'UTF-8') ?></strong>
                · Versión borrador <strong><?= htmlspecialchars((string)($version['numero'] ?? '1'), ENT_QUOTES, 'UTF-8') ?></strong>
            </p>
        </header>

        <div class="sgd-form-layout">
            <section class="sgd-panel sgd-form-designer-panel">
                <h4 class="sgd-form-section-title">Campos</h4>

                <?php if ($canGuardar && $esBorrador): ?>
                    <form id="sgd-form-add-campo" class="sgd-form-add-campo" onsubmit="return false;">
                        <div class="form-group">
                            <label for="sgd_campo_id">Identificador</label>
                            <input type="text" id="sgd_campo_id" class="form-input" maxlength="64" placeholder="ej. acta_numero" required>
                        </div>
                        <div class="form-group">
                            <label for="sgd_campo_label">Etiqueta</label>
                            <input type="text" id="sgd_campo_label" class="form-input" maxlength="120" placeholder="Acta N°" required>
                        </div>
                        <div class="form-group">
                            <label for="sgd_campo_tipo">Tipo</label>
                            <select id="sgd_campo_tipo" class="form-input">
                                <?php foreach ($tiposCampo as $tipo): ?>
                                    <option value="<?= htmlspecialchars($tipo, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(ucfirst($tipo), ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="sgd-form-check">
                                <input type="checkbox" id="sgd_campo_requerido" value="1"> Requerido
                            </label>
                        </div>
                        <button type="button" id="sgd-btn-add-campo" class="sgd-doc-btn">➕ Agregar campo</button>
                    </form>
                <?php endif; ?>

                <div id="sgd-form-campos-list" class="sgd-form-campos-list"></div>

                <form id="sgd-form-designer-save" method="post" action="<?= htmlspecialchars($formUrl, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="_action" value="save">
                    <input type="hidden" name="documento_id" value="<?= $documentoId ?>">
                    <input type="hidden" name="version_id" value="<?= $versionId ?>">
                    <input type="hidden" name="esquema_json" id="sgd_esquema_json" value="">
                </form>
                <form id="sgd-form-designer-publish" method="post" action="<?= htmlspecialchars($formUrl, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="_action" value="publish">
                    <input type="hidden" name="documento_id" value="<?= $documentoId ?>">
                    <input type="hidden" name="version_id" value="<?= $versionId ?>">
                    <input type="hidden" name="esquema_json" id="sgd_esquema_json_publish" value="">
                </form>
            </section>

            <section class="sgd-panel sgd-form-preview-panel">
                <h4 class="sgd-form-section-title">Vista previa</h4>
                <div id="sgd-form-preview" class="sgd-form-preview"></div>
            </section>
        </div>

        <?php if ($versiones !== []): ?>
            <section class="sgd-form-versions-history">
                <h4 class="sgd-form-section-title">Historial de versiones</h4>
                <ul class="sgd-form-ver-list">
                    <?php foreach ($versiones as $ver): ?>
                        <li class="<?= (int)($ver['es_vigente'] ?? 0) === 1 ? 'is-vigente' : '' ?>">
                            v<?= htmlspecialchars((string)$ver['numero'], ENT_QUOTES, 'UTF-8') ?>
                            — <?= htmlspecialchars((string)($ver['estado_nombre'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>

        <script type="application/json" id="sgd-form-esquema-inicial"><?= $esquemaJson ?></script>
        <script src="/js/sgd-formularios.js?v=<?= (int)$sgdFormJsV ?>"></script>
    <?php endif; ?>
</div>
