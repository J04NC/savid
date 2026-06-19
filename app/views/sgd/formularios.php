<?php
/** @var array $scope */
/** @var int|null $empresaId */
/** @var int $documentoId */
/** @var array<string, mixed>|null $documento */
/** @var string $codigoDisplay */
/** @var string $proposito */
/** @var string $propositoLabel */
/** @var array<string, mixed>|null $version */
/** @var array{version: int, campos: list<array<string, mixed>>} $esquema */
/** @var string $esquemaJson */
/** @var list<string> $tiposCampo */
/** @var list<array<string, mixed>> $arquetipos */
/** @var string $arquetipoPiloto */
/** @var bool $canPreviewPlantilla */
/** @var string $previewPdfUrl */
/** @var bool $canGuardar */
/** @var bool $borradorDesdeVigente */
/** @var string|null $versionVigenteNumero */
/** @var bool $needsNewVersionConfirm */
/** @var array<string, mixed>|null $documentoVersion */

$empresaQuery = $empresaId ? '&empresa_id=' . (int)$empresaId : '';
$formUrl = '?url=sgd/formularios' . $empresaQuery;
$docUrl = '?url=sgd/documentos' . $empresaQuery . ($documentoId > 0 ? '&id=' . $documentoId : '');
$docVersionsUrl = $docUrl . '#sgd-doc-versions';
$versionId = (int)($version['id'] ?? 0);
$esBorrador = $version && (int)($version['estado_id'] ?? 0) === SgdRepository::ESTADO_DOC_BORRADOR;
$arquetipoActual = (string)($esquema['arquetipo'] ?? 'libre');
$archivoRuta = trim((string)($documentoVersion['archivo_ruta'] ?? ''));
$arquetiposSemillaJson = json_encode(
    SgdArquetipoOperativoService::loadCatalog(),
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
);
$pageMetaJson = json_encode([
    'codigo' => $codigoDisplay,
    'nombre' => (string)($documento['nombre'] ?? ''),
    'version' => (string)($version['numero'] ?? '1'),
    'proceso' => (string)($documento['proceso_nombre'] ?? ''),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);

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
    <?php elseif (!empty($needsNewVersionConfirm)): ?>
        <section class="sgd-panel sgd-form-confirm-panel">
            <h3 class="sgd-panel-title">Nueva versión de plantilla</h3>
            <p class="field-note">
                Existe la versión publicada
                <strong><?= htmlspecialchars((string)($versionVigenteNumero ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>
                con plantilla en el sistema.
            </p>
            <p>¿Desea crear un <strong>nuevo borrador</strong> basado en esa plantilla para seguir editando?</p>
            <div class="sgd-form-confirm-actions">
                <a href="<?= htmlspecialchars($docUrl, ENT_QUOTES, 'UTF-8') ?>" class="sgd-doc-btn">Cancelar</a>
                <?php if ($canGuardar): ?>
                    <form method="post" action="<?= htmlspecialchars($formUrl, ENT_QUOTES, 'UTF-8') ?>" class="sgd-form-confirm-form">
                        <input type="hidden" name="_action" value="create_borrador">
                        <input type="hidden" name="documento_id" value="<?= $documentoId ?>">
                        <button type="submit" class="sgd-doc-btn sgd-doc-btn-primary">Crear borrador</button>
                    </form>
                <?php endif; ?>
            </div>
        </section>
    <?php else: ?>

        <div class="crud-toolbar sgd-form-toolbar">
            <a href="<?= htmlspecialchars($docVersionsUrl, ENT_QUOTES, 'UTF-8') ?>" class="sgd-doc-btn">← Versiones y archivo oficial</a>
            <?php if ($canPreviewPlantilla && $previewPdfUrl !== ''): ?>
                <a href="<?= htmlspecialchars($previewPdfUrl, ENT_QUOTES, 'UTF-8') ?>"
                   class="sgd-doc-btn" target="_blank" rel="noopener" title="Abrir PDF de la plantilla">📄 Vista previa PDF</a>
            <?php endif; ?>
            <?php if ($canGuardar && $esBorrador): ?>
                <button type="submit" form="sgd-form-designer-save" class="sgd-doc-btn sgd-doc-btn-primary">💾 Guardar plantilla</button>
            <?php endif; ?>
        </div>

        <header class="sgd-form-head">
            <h3 class="sgd-panel-title">Diseñador de plantilla</h3>
            <p class="sgd-form-doc-meta">
                <strong><?= htmlspecialchars($codigoDisplay, ENT_QUOTES, 'UTF-8') ?></strong>
                — <?= htmlspecialchars((string)($documento['nombre'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
            </p>
            <p class="field-note sgd-form-status-line">
                Borrador <strong>v<?= htmlspecialchars((string)($version['numero'] ?? '1'), ENT_QUOTES, 'UTF-8') ?></strong>
                · Archivo oficial:
                <?php if ($archivoRuta !== ''): ?>
                    <span class="sgd-form-pill sgd-form-pill-estado-aplica">Cargado</span>
                <?php else: ?>
                    <span class="sgd-form-pill sgd-form-pill-estado-opcional">Pendiente</span>
                <?php endif; ?>
                · <a href="<?= htmlspecialchars($docVersionsUrl, ENT_QUOTES, 'UTF-8') ?>">Publicar o subir archivo</a>
            </p>
            <?php if ($borradorDesdeVigente && $versionVigenteNumero): ?>
                <div class="sgd-form-notice" role="status">
                    <span class="sgd-form-notice-icon" aria-hidden="true">ℹ</span>
                    <div>
                        <strong>Borrador basado en la versión publicada <?= htmlspecialchars((string)$versionVigenteNumero, ENT_QUOTES, 'UTF-8') ?></strong>
                        <p class="field-note">Edite la plantilla aquí; la publicación se hace en Versiones y archivo oficial.</p>
                    </div>
                </div>
            <?php endif; ?>
        </header>

        <div class="sgd-form-layout">
            <section class="sgd-panel sgd-form-designer-panel">
                <?php if ($canGuardar && $esBorrador): ?>
                    <div class="sgd-form-arquetipo-bar form-group">
                        <label for="sgd_arquetipo_select">Arquetipo de formulario</label>
                        <div class="sgd-form-arquetipo-row">
                            <select id="sgd_arquetipo_select" class="form-input">
                                <?php foreach ($arquetipos as $arq): ?>
                                    <?php $cod = (string)($arq['codigo'] ?? ''); ?>
                                    <option value="<?= htmlspecialchars($cod, ENT_QUOTES, 'UTF-8') ?>"
                                        <?= $arquetipoActual === $cod ? 'selected' : '' ?>>
                                        <?= htmlspecialchars((string)($arq['nombre'] ?? $cod), ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" id="sgd-btn-cargar-arquetipo" class="sgd-doc-btn">
                                Cargar plantilla del arquetipo
                            </button>
                        </div>
                        <p class="field-note">
                            Piloto F3b: <strong>Acta</strong> (GE-PD3-F1). Los bloques se guardan en el catálogo
                            <code>sgd_seccion</code> con clase <em>operativo</em> (importar desde Configuración SGD).
                        </p>
                    </div>
                <?php endif; ?>

                <h4 class="sgd-form-section-title">Bloques / campos</h4>

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
            </section>

            <section class="sgd-panel sgd-form-preview-panel">
                <h4 class="sgd-form-section-title">Vista previa</h4>
                <p class="field-note sgd-form-preview-hint">Así se verá el formato vacío al diligenciarlo.</p>
                <div class="sgd-form-preview">
                    <div class="sgd-form-preview-sheet">
                        <div class="sgd-form-preview-sheet-head" id="sgd-form-preview-head">
                            <?php if ($codigoDisplay !== ''): ?>
                                <div class="sgd-form-preview-code-label">Código documento</div>
                                <div class="sgd-form-preview-code"><?= htmlspecialchars($codigoDisplay, ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                            <?php if (!empty($documento['nombre'])): ?>
                                <h3 class="sgd-form-preview-title"><?= htmlspecialchars((string)$documento['nombre'], ENT_QUOTES, 'UTF-8') ?></h3>
                            <?php endif; ?>
                            <p class="sgd-form-preview-meta-line">
                                <?php if (!empty($documento['proceso_nombre'])): ?>
                                    <span><?= htmlspecialchars((string)$documento['proceso_nombre'], ENT_QUOTES, 'UTF-8') ?></span>
                                    ·
                                <?php endif; ?>
                                Versión plantilla: <strong><?= htmlspecialchars((string)($version['numero'] ?? '1'), ENT_QUOTES, 'UTF-8') ?></strong>
                                <?php if ($arquetipoActual !== '' && $arquetipoActual !== 'libre'): ?>
                                    · Arquetipo: <strong><?= htmlspecialchars($arquetipoActual, ENT_QUOTES, 'UTF-8') ?></strong>
                                <?php endif; ?>
                            </p>
                        </div>
                        <div id="sgd-form-preview-body" class="sgd-form-preview-sheet-body"></div>
                    </div>
                </div>
            </section>
        </div>

        <script type="application/json" id="sgd-form-esquema-inicial"><?= $esquemaJson ?></script>
        <script type="application/json" id="sgd-form-page-meta"><?= $pageMetaJson ?></script>
        <script type="application/json" id="sgd-form-arquetipos-catalog"><?= $arquetiposSemillaJson ?></script>
        <script src="/js/sgd-formularios.js?v=<?= (int)$sgdFormJsV ?>"></script>
    <?php endif; ?>
</div>
