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
/** @var bool $canEliminarVersion */
/** @var bool $canRevertVigente */
/** @var list<array<string, mixed>> $versiones */
/** @var string $suggestedVersionNumero */
/** @var bool $canAutoGeneratePdf */
/** @var bool $canPreviewPlantilla */
/** @var string $previewPlantillaUrl */
/** @var bool $esOperativo */
/** @var string $formatoArchivoEsperado */
/** @var bool $canAutoGenerateEsqueleto */
/** @var list<string> $uploadExtensions */
/** @var string $arquetipoOperativo */

$versiones = $versiones ?? [];
$suggestedVersionNumero = $suggestedVersionNumero ?? '1';
$canAutoGeneratePdf = !empty($canAutoGeneratePdf);
$canPreviewPlantilla = !empty($canPreviewPlantilla);
$previewPlantillaUrl = (string)($previewPlantillaUrl ?? '');
$esOperativo = !empty($esOperativo);
$formatoArchivoEsperado = (string)($formatoArchivoEsperado ?? 'pdf_auto');
$canAutoGenerateEsqueleto = !empty($canAutoGenerateEsqueleto);
$uploadExtensions = $uploadExtensions ?? ['pdf'];
$arquetipoOperativo = (string)($arquetipoOperativo ?? 'libre');
$canAutoPublishVersion = $canAutoGeneratePdf || ($esOperativo && $canAutoGenerateEsqueleto);
$canEliminarVersion = !empty($canEliminarVersion);
$canRevertVigente = !empty($canRevertVigente);
$canVerAccionesVersion = $canGuardar || $canEliminarVersion || $canRevertVigente;
$uploadAccept = implode(',', array_map(
    static fn(string $ext): string => match ($ext) {
        'pdf' => 'application/pdf,.pdf',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,.xlsx',
        'xls' => 'application/vnd.ms-excel,.xls',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document,.docx',
        'doc' => 'application/msword,.doc',
        default => '.' . $ext,
    },
    $uploadExtensions
));
$uploadLabel = SgdArquetipoOperativoService::uploadFormatsLabel($uploadExtensions);

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
$versionUploadAction = '?url=sgd/documentoVersionUpload' . $empresaQuery;
$previewPdfBase = '?url=sgd/elaboracionPreviewPdf' . $empresaQuery;

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
                <?php if (!empty($form['id'])): ?>
                    <?php
                    $modoDocBtn = trim((string)($form['modo'] ?? ''));
                    if ($modoDocBtn === '' && !empty($form['tipo_documental_id'])) {
                        foreach ($catalogos['tipos'] ?? [] as $_t) {
                            if ((int)$_t['id'] === (int)$form['tipo_documental_id']) {
                                $modoDocBtn = (string)($_t['modo'] ?? '');
                                break;
                            }
                        }
                    }
                    ?>
                    <?php if ($modoDocBtn === 'maestro'): ?>
                        <a href="<?= htmlspecialchars('?url=sgd/elaboracion' . $empresaQuery . '&documento_id=' . (int)$form['id'], ENT_QUOTES, 'UTF-8') ?>"
                           class="sgd-doc-btn" title="Redactar contenido del documento maestro">✍️ Elaborar contenido</a>
                    <?php else: ?>
                        <a href="<?= htmlspecialchars('?url=sgd/formularios' . $empresaQuery . '&documento_id=' . (int)$form['id'], ENT_QUOTES, 'UTF-8') ?>"
                           class="sgd-doc-btn" title="Diseñar plantilla operativa">📝 Diseñar plantilla</a>
                        <a href="<?= htmlspecialchars('?url=sgd/registros' . $empresaQuery . '&documento_id=' . (int)$form['id'], ENT_QUOTES, 'UTF-8') ?>"
                           class="sgd-doc-btn" title="Instancias diligenciadas de este formato">📋 Registros</a>
                        <?php if ($canPreviewPlantilla && $previewPlantillaUrl !== ''): ?>
                            <a href="<?= htmlspecialchars($previewPlantillaUrl, ENT_QUOTES, 'UTF-8') ?>"
                               class="sgd-doc-btn" target="_blank" rel="noopener"
                               title="Vista previa PDF de la plantilla del formato">📄 Vista previa plantilla</a>
                        <?php endif; ?>
                    <?php endif; ?>
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
                    <table class="sgd-doc-table savid-datatable" data-dt-page-length="50" data-dt-buttons="false">
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
                        <?php if (!empty($form['id']) && $versiones !== []): ?>
                            <input type="text" id="sgd_doc_version" class="form-input" maxlength="32" readonly
                                   value="<?= htmlspecialchars((string)($form['version_actual'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="version_actual" value="<?= htmlspecialchars((string)($form['version_actual'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            <p class="field-note">Se actualiza al publicar una versión en el panel inferior.</p>
                        <?php else: ?>
                            <input type="text" name="version_actual" id="sgd_doc_version" class="form-input" maxlength="32"
                                   value="<?= htmlspecialchars((string)($form['version_actual'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        <?php endif; ?>
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

                <?php if (!empty($form['id'])): ?>
                    <section class="sgd-doc-versions" id="sgd-doc-versions">
                        <header class="sgd-doc-versions-head">
                            <h4 class="sgd-doc-versions-title">Versiones y archivo oficial</h4>
                            <span class="sgd-doc-versions-count"><?= count($versiones) ?> versión(es)</span>
                        </header>
                        <?php if ($esOperativo): ?>
                            <p class="field-note sgd-doc-ver-formato-hint">
                                Formato operativo (arquetipo <strong><?= htmlspecialchars($arquetipoOperativo, ENT_QUOTES, 'UTF-8') ?></strong>).
                                <?php if ($formatoArchivoEsperado === 'xlsx_upload'): ?>
                                    El esqueleto oficial es <strong>Excel (.xlsx)</strong>. Suba la plantilla aquí y publíquela desde el diseñador o desde esta tabla.
                                <?php elseif ($formatoArchivoEsperado === 'docx_upload'): ?>
                                    El esqueleto oficial es <strong>Word (.docx)</strong>. Suba la plantilla aquí y publíquela desde el diseñador o desde esta tabla.
                                <?php elseif ($canAutoGenerateEsqueleto): ?>
                                    Al publicar desde el <strong>diseñador de plantilla</strong> se generará el PDF esqueleto y quedará vigente aquí.
                                    También puede subir <strong><?= htmlspecialchars($uploadLabel, ENT_QUOTES, 'UTF-8') ?></strong> como archivo oficial.
                                <?php else: ?>
                                    Suba el archivo de referencia (<strong><?= htmlspecialchars($uploadLabel, ENT_QUOTES, 'UTF-8') ?></strong>) antes de publicar.
                                <?php endif; ?>
                            </p>
                        <?php endif; ?>
                        <?php if ($canEliminarVersion): ?>
                            <p class="field-note sgd-doc-ver-delete-hint">
                                Puede eliminar versiones en <strong>borrador</strong> u <strong>obsoletas</strong>.
                            </p>
                        <?php endif; ?>
                        <?php if ($canRevertVigente): ?>
                            <p class="field-note sgd-doc-ver-revert-hint">
                                <strong>Revertir publicación</strong> (versión vigente): requiere contraseña y solo si no hay registros diligenciados.
                            </p>
                        <?php endif; ?>
                        <p class="field-note sgd-doc-ver-cloud-note">Si el archivo está en Dropbox o la nube, espere el mensaje «listo para subir» antes de pulsar Subir. Si falla, descárguelo al teléfono y elíjalo desde Archivos.</p>

                        <?php if ($versiones === []): ?>
                            <p class="field-note sgd-doc-versions-empty">Sin versiones registradas. Cree una versión en borrador<?= $canAutoPublishVersion ? ' y publíquela (el archivo se generará al publicar si aplica)' : ', suba el archivo oficial y publíquela' ?>.</p>
                        <?php else: ?>
                            <div class="sgd-doc-versions-table-wrap">
                                <table class="sgd-doc-versions-table">
                                    <thead>
                                        <tr>
                                            <th>Versión</th>
                                            <th>Estado</th>
                                            <th>Archivo</th>
                                            <th>Aprobación</th>
                                            <?php if ($canVerAccionesVersion): ?><th>Acciones</th><?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($versiones as $ver): ?>
                                            <?php
                                            $vid = (int)$ver['id'];
                                            $estadoId = (int)($ver['estado_id'] ?? 0);
                                            $esBorrador = $estadoId === SgdRepository::ESTADO_DOC_BORRADOR;
                                            $esObsoleto = $estadoId === SgdRepository::ESTADO_DOC_OBSOLETO;
                                            $esVigente = (int)($ver['es_vigente'] ?? 0) === 1;
                                            $pdfPath = trim((string)($ver['archivo_ruta'] ?? ''));
                                            $archivoTipo = trim((string)($ver['archivo_tipo'] ?? ''));
                                            if ($archivoTipo === '' && $pdfPath !== '') {
                                                $archivoTipo = SgdArquetipoOperativoService::detectArchivoTipo($pdfPath);
                                            }
                                            $archivoLabel = $archivoTipo !== ''
                                                ? SgdArquetipoOperativoService::archivoTipoLabel($archivoTipo)
                                                : 'Archivo';
                                            $fechaAprVal = trim((string)($ver['fecha_aprobacion'] ?? ''));
                                            if ($fechaAprVal === '') {
                                                $fechaAprVal = trim((string)($form['fecha_ultima_aprobacion'] ?? ''));
                                            }
                                            if ($fechaAprVal === '') {
                                                $fechaAprVal = date('Y-m-d');
                                            }
                                            $fechaEditable = $canGuardar && in_array($estadoId, [SgdRepository::ESTADO_DOC_BORRADOR, SgdRepository::ESTADO_DOC_VIGENTE], true);
                                            $publishFormId = 'sgd-ver-publish-' . $vid;
                                            $canPublish = $esBorrador && ($pdfPath !== '' || $canAutoPublishVersion);
                                            $canDeleteVersion = $canEliminarVersion && !$esVigente && ($esBorrador || $esObsoleto);
                                            ?>
                                            <tr class="sgd-doc-ver-row<?= $esVigente ? ' is-vigente' : '' ?>" data-version-id="<?= $vid ?>">
                                                <td>
                                                    <strong><?= htmlspecialchars((string)$ver['numero'], ENT_QUOTES, 'UTF-8') ?></strong>
                                                    <?php if (!empty($ver['notas'])): ?>
                                                        <span class="sgd-doc-ver-notas"><?= htmlspecialchars((string)$ver['notas'], ENT_QUOTES, 'UTF-8') ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="sgd-doc-ver-estado sgd-doc-ver-estado-<?= $estadoId ?>">
                                                        <?= htmlspecialchars((string)($ver['estado_nombre'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                                    </span>
                                                </td>
                                                <td class="sgd-doc-ver-pdf">
                                                    <?php if ($pdfPath !== ''): ?>
                                                        <a href="<?= htmlspecialchars($pdfPath, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Ver <?= htmlspecialchars($archivoLabel, ENT_QUOTES, 'UTF-8') ?></a>
                                                    <?php elseif ($canGuardar && $esBorrador): ?>
                                                        <?php
                                                        $verReturnUrl = $listUrl . '&id=' . (int)$form['id'];
                                                        ?>
                                                        <form
                                                            method="post"
                                                            enctype="multipart/form-data"
                                                            action="<?= htmlspecialchars($versionUploadAction, ENT_QUOTES, 'UTF-8') ?>"
                                                            class="sgd-doc-ver-upload-form"
                                                            data-return-url="<?= htmlspecialchars($verReturnUrl, ENT_QUOTES, 'UTF-8') ?>"
                                                            data-version-id="<?= $vid ?>"
                                                            data-documento-id="<?= (int)$form['id'] ?>"
                                                            data-upload-extensions="<?= htmlspecialchars(implode(',', $uploadExtensions), ENT_QUOTES, 'UTF-8') ?>"
                                                        >
                                                            <div class="sgd-doc-ver-upload-row">
                                                                <label class="sgd-doc-ver-upload">
                                                                    <span class="sgd-doc-ver-upload-btn">Elegir <?= htmlspecialchars(ucfirst($uploadLabel), ENT_QUOTES, 'UTF-8') ?></span>
                                                                    <input
                                                                        type="file"
                                                                        accept="<?= htmlspecialchars($uploadAccept, ENT_QUOTES, 'UTF-8') ?>"
                                                                        class="sgd-doc-ver-file"
                                                                    >
                                                                </label>
                                                                <button type="submit" class="sgd-doc-btn sgd-doc-ver-submit-btn" disabled>
                                                                    Subir
                                                                </button>
                                                            </div>
                                                            <span class="sgd-doc-ver-file-name" aria-live="polite"></span>
                                                        </form>
                                                        <?php if ($canAutoPublishVersion): ?>
                                                            <p class="field-note sgd-doc-ver-upload-hint">Opcional si sube <?= htmlspecialchars(strtolower($uploadLabel), ENT_QUOTES, 'UTF-8') ?>; si no, el PDF puede generarse al publicar.</p>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="sgd-doc-ver-sin-pdf">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="sgd-doc-ver-fecha-cell">
                                                    <?php if ($fechaEditable): ?>
                                                        <?php if ($canPublish): ?>
                                                            <label class="sgd-doc-ver-fecha-label" for="sgd_ver_fecha_<?= $vid ?>">Aprobación</label>
                                                            <input
                                                                type="date"
                                                                id="sgd_ver_fecha_<?= $vid ?>"
                                                                name="fecha_aprobacion"
                                                                form="<?= htmlspecialchars($publishFormId, ENT_QUOTES, 'UTF-8') ?>"
                                                                value="<?= htmlspecialchars($fechaAprVal, ENT_QUOTES, 'UTF-8') ?>"
                                                                required
                                                                class="form-input sgd-doc-ver-fecha-input"
                                                            >
                                                        <?php else: ?>
                                                            <form method="post" action="<?= htmlspecialchars($formAction, ENT_QUOTES, 'UTF-8') ?>" class="sgd-doc-ver-fecha-form">
                                                                <input type="hidden" name="_action" value="version_save_fecha">
                                                                <input type="hidden" name="documento_id" value="<?= (int)$form['id'] ?>">
                                                                <input type="hidden" name="version_id" value="<?= $vid ?>">
                                                                <input
                                                                    type="date"
                                                                    name="fecha_aprobacion"
                                                                    value="<?= htmlspecialchars($fechaAprVal, ENT_QUOTES, 'UTF-8') ?>"
                                                                    required
                                                                    class="form-input sgd-doc-ver-fecha-input"
                                                                    title="Fecha de aprobación"
                                                                >
                                                                <button type="submit" class="sgd-doc-ver-fecha-btn" title="Guardar fecha">💾</button>
                                                            </form>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <?= htmlspecialchars((string)($ver['fecha_aprobacion'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                                    <?php endif; ?>
                                                </td>
                                                <?php if ($canVerAccionesVersion): ?>
                                                    <td class="sgd-doc-ver-actions">
                                                        <?php if ($esBorrador && $esOperativo): ?>
                                                            <a href="<?= htmlspecialchars('?url=sgd/formularios' . $empresaQuery . '&documento_id=' . (int)$form['id'], ENT_QUOTES, 'UTF-8') ?>"
                                                               class="sgd-doc-btn sgd-doc-ver-btn">Diseñar</a>
                                                        <?php endif; ?>
                                                        <?php if ($canPublish): ?>
                                                            <?php if ($canAutoPublishVersion && $pdfPath === ''): ?>
                                                                <?php if ($esOperativo && $previewPlantillaUrl !== ''): ?>
                                                                <a href="<?= htmlspecialchars($previewPlantillaUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="sgd-doc-btn sgd-doc-ver-btn">Vista previa</a>
                                                                <?php else: ?>
                                                                <?php
                                                                $previewUrl = $previewPdfBase . '&documento_id=' . (int)$form['id'] . '&version_id=' . $vid;
                                                                ?>
                                                                <a href="<?= htmlspecialchars($previewUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="sgd-doc-btn sgd-doc-ver-btn">Vista previa PDF</a>
                                                                <?php endif; ?>
                                                            <?php endif; ?>
                                                            <form
                                                                id="<?= htmlspecialchars($publishFormId, ENT_QUOTES, 'UTF-8') ?>"
                                                                method="post"
                                                                action="<?= htmlspecialchars($formAction, ENT_QUOTES, 'UTF-8') ?>"
                                                                class="sgd-doc-ver-inline-form sgd-doc-ver-publish-form"
                                                                onsubmit="return confirm('¿Publicar la versión <?= htmlspecialchars((string)$ver['numero'], ENT_QUOTES, 'UTF-8') ?>? Las demás quedarán obsoletas.');"
                                                            >
                                                                <input type="hidden" name="_action" value="version_publish">
                                                                <input type="hidden" name="documento_id" value="<?= (int)$form['id'] ?>">
                                                                <input type="hidden" name="version_id" value="<?= $vid ?>">
                                                                <button type="submit" class="sgd-doc-btn sgd-doc-btn-primary sgd-doc-ver-btn">Publicar</button>
                                                            </form>
                                                        <?php endif; ?>
                                                        <?php if ($canDeleteVersion): ?>
                                                            <form
                                                                method="post"
                                                                action="<?= htmlspecialchars($formAction, ENT_QUOTES, 'UTF-8') ?>"
                                                                class="sgd-doc-ver-inline-form"
                                                                onsubmit="return confirm('Se eliminará permanentemente la versión <?= htmlspecialchars((string)$ver['numero'], ENT_QUOTES, 'UTF-8') ?> (<?= $esBorrador ? 'borrador' : 'obsoleta' ?>) y su archivo asociado.\n\n¿Desea continuar?');"
                                                            >
                                                                <input type="hidden" name="_action" value="version_delete">
                                                                <input type="hidden" name="documento_id" value="<?= (int)$form['id'] ?>">
                                                                <input type="hidden" name="version_id" value="<?= $vid ?>">
                                                                <button type="submit" class="sgd-doc-btn sgd-doc-ver-btn sgd-doc-ver-btn-danger" title="Eliminar versión">Eliminar</button>
                                                            </form>
                                                        <?php endif; ?>
                                                        <?php if ($canRevertVigente && $esVigente): ?>
                                                            <button
                                                                type="button"
                                                                class="sgd-doc-btn sgd-doc-ver-btn sgd-doc-ver-btn-warning sgd-btn-revert-vigente"
                                                                title="Deshacer publicación de esta versión"
                                                                data-version-id="<?= $vid ?>"
                                                                data-version-numero="<?= htmlspecialchars((string)$ver['numero'], ENT_QUOTES, 'UTF-8') ?>"
                                                                data-codigo="<?= htmlspecialchars((string)($form['codigo_display'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                            >Revertir publicación</button>
                                                        <?php endif; ?>
                                                    </td>
                                                <?php endif; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>

                        <?php if ($canGuardar): ?>
                            <div class="sgd-doc-versions-tools">
                                <form method="post" action="<?= htmlspecialchars($formAction, ENT_QUOTES, 'UTF-8') ?>" class="sgd-doc-ver-create-form">
                                    <input type="hidden" name="_action" value="version_create">
                                    <input type="hidden" name="documento_id" value="<?= (int)$form['id'] ?>">
                                    <div class="form-group">
                                        <label for="sgd_ver_numero">Nueva versión</label>
                                        <input type="text" name="numero" id="sgd_ver_numero" class="form-input" maxlength="32"
                                               value="<?= htmlspecialchars($suggestedVersionNumero, ENT_QUOTES, 'UTF-8') ?>"
                                               placeholder="Siguiente número sugerido">
                                    </div>
                                    <div class="form-group">
                                        <label for="sgd_ver_notas">Notas (opcional)</label>
                                        <input type="text" name="notas" id="sgd_ver_notas" class="form-input" maxlength="255" placeholder="Ej. Revisión anual 2026">
                                    </div>
                                    <div class="form-group">
                                        <label for="sgd_ver_fecha_aprobacion">Fecha aprobación (opcional)</label>
                                        <input type="date" name="fecha_aprobacion" id="sgd_ver_fecha_aprobacion" class="form-input"
                                               value="<?= htmlspecialchars(trim((string)($form['fecha_ultima_aprobacion'] ?? '')) ?: date('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>">
                                    </div>
                                    <button type="submit" class="sgd-doc-btn">➕ Crear borrador</button>
                                </form>
                                <p class="field-note">Crea el par plantilla + versión de archivo con el mismo número.</p>

                                <form method="post" action="<?= htmlspecialchars($formAction, ENT_QUOTES, 'UTF-8') ?>"
                                      class="sgd-doc-ver-obsolete-form"
                                      onsubmit="return confirm('¿Marcar todo el documento como obsoleto?');">
                                    <input type="hidden" name="_action" value="version_obsolete">
                                    <input type="hidden" name="documento_id" value="<?= (int)$form['id'] ?>">
                                    <button type="submit" class="sgd-doc-btn sgd-doc-btn-danger">Marcar documento obsoleto</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </section>

                    <?php if ($canRevertVigente && !empty($form['id'])): ?>
                        <div id="sgd-revert-vigente-modal" class="sgd-revert-modal" hidden aria-hidden="true">
                            <div class="sgd-revert-backdrop" data-revert-close></div>
                            <div class="sgd-revert-dialog" role="dialog" aria-modal="true" aria-labelledby="sgd-revert-title">
                                <header class="sgd-revert-head">
                                    <h4 id="sgd-revert-title">Revertir publicación</h4>
                                    <button type="button" class="sgd-revert-close" data-revert-close aria-label="Cerrar">&times;</button>
                                </header>
                                <form method="post" action="<?= htmlspecialchars($formAction, ENT_QUOTES, 'UTF-8') ?>" class="sgd-revert-form" id="sgd-revert-vigente-form">
                                    <input type="hidden" name="_action" value="version_revert_vigente">
                                    <input type="hidden" name="documento_id" value="<?= (int)$form['id'] ?>">
                                    <input type="hidden" name="version_id" id="sgd-revert-version-id" value="">
                                    <p class="field-note sgd-revert-intro">
                                        La versión <strong id="sgd-revert-version-label"></strong> volverá a <strong>borrador</strong>
                                        y dejará de ser la versión oficial. Solo es posible si no hay registros diligenciados.
                                    </p>
                                    <div class="form-group">
                                        <label for="sgd_revert_confirm_codigo">Código del documento</label>
                                        <input type="text" name="confirm_codigo" id="sgd_revert_confirm_codigo" class="form-input" required
                                               autocomplete="off" spellcheck="false" placeholder="Ej. GE-PD3-F1">
                                        <p class="field-note">Escriba: <code id="sgd-revert-codigo-hint"></code></p>
                                    </div>
                                    <div class="form-group">
                                        <label for="sgd_revert_confirm_version">Número de versión</label>
                                        <input type="text" name="confirm_version" id="sgd_revert_confirm_version" class="form-input" required
                                               autocomplete="off" placeholder="Ej. 1">
                                    </div>
                                    <div class="form-group">
                                        <label for="sgd_revert_confirm_password">Su contraseña</label>
                                        <input type="password" name="confirm_password" id="sgd_revert_confirm_password" class="form-input" required
                                               autocomplete="current-password">
                                    </div>
                                    <footer class="sgd-revert-foot">
                                        <button type="button" class="sgd-doc-btn" data-revert-close>Cancelar</button>
                                        <button type="submit" class="sgd-doc-btn sgd-doc-ver-btn-warning">Revertir publicación</button>
                                    </footer>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

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
