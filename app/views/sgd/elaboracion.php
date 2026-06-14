<?php
/** @var array $scope */
/** @var int|null $empresaId */
/** @var int $documentoId */
/** @var array<string, mixed>|null $documento */
/** @var string $codigoDisplay */
/** @var list<array<string, mixed>> $secciones */
/** @var array<string, mixed> $contenido */
/** @var array<string, bool> $opciones */
/** @var array<string, bool> $numeracion */
/** @var list<array<string, mixed>> $opcionalesPerfil */
/** @var list<array<string, mixed>> $documentosMaestro */
/** @var array<string, mixed> $formatoPdf */
/** @var bool $canGuardar */

$empresaQuery = $empresaId ? '&empresa_id=' . (int)$empresaId : '';
$docUrl = '?url=sgd/documentos' . $empresaQuery . ($documentoId > 0 ? '&id=' . $documentoId : '');
$formAction = '?url=sgd/elaboracion' . $empresaQuery;

$modoEfectivo = (string)($documento['modo_efectivo'] ?? '');
$referenciadosIds = [];
if (!empty($contenido['documentos_referenciados']['documento_ids'])) {
    $referenciadosIds = array_map('intval', (array)$contenido['documentos_referenciados']['documento_ids']);
}
$anexosBloques = $contenido['anexos']['bloques'] ?? [['titulo' => '', 'cuerpo' => '']];
if (!is_array($anexosBloques) || $anexosBloques === []) {
    $anexosBloques = [['titulo' => '', 'cuerpo' => '']];
}

$margenes = $formatoPdf['margenes'] ?? [];
$pageStyle = sprintf(
    'padding:%scm %scm %scm %scm',
    (float)($margenes['superior'] ?? 3),
    (float)($margenes['derecho'] ?? 2),
    (float)($margenes['inferior'] ?? 2),
    (float)($margenes['izquierdo'] ?? 3)
);

$refDocsJson = json_encode(array_map(static function ($dm) {
    $codigo = trim((string)($dm['codigo_display'] ?? ''));
    if ($codigo === '') {
        $codigo = trim((string)($dm['proceso_codigo'] ?? '') . '-' . (string)($dm['tipo_codigo'] ?? '') . (string)($dm['consecutivo'] ?? ''));
    }
    $nombre = trim((string)($dm['nombre'] ?? ''));

    return [
        'id' => (int)$dm['id'],
        'label' => $nombre !== '' ? ($codigo . ' — ' . $nombre) : $codigo,
    ];
}, $documentosMaestro ?? []), JSON_UNESCAPED_UNICODE);

$sgdElabJs = BASE_PATH . '/public/js/sgd-elaboracion.js';
$sgdElabJsV = is_readable($sgdElabJs) ? (int)filemtime($sgdElabJs) : time();
$wordImportServerZip = class_exists('ZipArchive', false);

$titulosElab = SgdSeccionService::normalizeTitulosConfig($formatoPdf['titulos'] ?? null);
$titulosCss = SgdElaboracionService::buildTitulosCss($formatoPdf);
$headingTags = ['h2', 'h3', 'h4', 'h5', 'h6'];
?>
<div class="module-container sgd-elaboracion-page sgd-word-mode">
    <?php if ($titulosCss !== ''): ?>
        <style id="sgd-elab-formato-css"><?= $titulosCss ?></style>
    <?php endif; ?>
    <?php $filterUrl = 'sgd/elaboracion'; require BASE_PATH . '/app/views/sgd/_empresa_filter.php'; ?>

    <?php if (empty($empresaId)): ?>
        <p class="field-note sgd-page-empty">Seleccione la empresa en el filtro superior.</p>
    <?php elseif ($documentoId <= 0 || $documento === null): ?>
        <p class="field-note sgd-page-empty">
            Abra la elaboración desde un documento del
            <a href="<?= htmlspecialchars('?url=sgd/documentos' . $empresaQuery, ENT_QUOTES, 'UTF-8') ?>">listado maestro</a>.
        </p>
    <?php elseif ($modoEfectivo !== 'maestro'): ?>
        <p class="field-note sgd-page-empty">
            Este documento no es maestro. Use el
            <a href="<?= htmlspecialchars('?url=sgd/formularios' . $empresaQuery . '&documento_id=' . $documentoId, ENT_QUOTES, 'UTF-8') ?>">diseñador operativo</a>.
        </p>
    <?php elseif ($secciones === []): ?>
        <p class="field-note sgd-page-empty">
            Importe la <a href="<?= htmlspecialchars('?url=sgd/config' . $empresaQuery, ENT_QUOTES, 'UTF-8') ?>">estructura documental</a>
            y configure el perfil en Tipos documentales.
        </p>
    <?php else: ?>

        <form id="sgd-elaboracion-form" method="post" action="<?= htmlspecialchars($formAction, ENT_QUOTES, 'UTF-8') ?>" class="sgd-word-form">
            <input type="hidden" name="documento_id" value="<?= (int)$documentoId ?>">

            <header class="sgd-word-topbar">
                <div class="sgd-word-topbar-left">
                    <a href="<?= htmlspecialchars($docUrl, ENT_QUOTES, 'UTF-8') ?>" class="sgd-word-btn sgd-word-btn-ghost" title="Volver">← Documento</a>
                    <div class="sgd-word-doc-title">
                        <span class="sgd-word-doc-code"><?= htmlspecialchars($codigoDisplay, ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="sgd-word-doc-name"><?= htmlspecialchars((string)($documento['nombre'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                </div>
                <div class="sgd-word-topbar-right">
                    <span id="sgd-word-status" class="sgd-word-status" aria-live="polite"></span>
                    <?php if ($documentoId > 0): ?>
                        <button type="button" class="sgd-word-btn sgd-word-btn-ghost" id="sgd-btn-preview-pdf" title="Generar vista previa del PDF con el contenido guardado">📄 Vista previa PDF</button>
                    <?php endif; ?>
                    <?php if ($canGuardar): ?>
                        <button type="submit" class="sgd-word-btn sgd-word-btn-primary">💾 Guardar</button>
                    <?php endif; ?>
                </div>
            </header>

            <?php if ($canGuardar): ?>
            <div class="sgd-word-ribbon" id="sgd-word-ribbon" role="toolbar" aria-label="Formato de texto">
                <div class="sgd-word-ribbon-group">
                    <button type="button" class="sgd-word-tool" data-cmd="bold" title="Negrilla (Ctrl+B)"><strong>B</strong></button>
                    <button type="button" class="sgd-word-tool" data-cmd="italic" title="Cursiva (Ctrl+I)"><em>I</em></button>
                    <button type="button" class="sgd-word-tool" data-cmd="underline" title="Subrayado (Ctrl+U)"><u>U</u></button>
                </div>
                <?php if (!empty($titulosElab['niveles'])): ?>
                <div class="sgd-word-ribbon-sep"></div>
                <div class="sgd-word-ribbon-group">
                    <select class="sgd-word-select" id="sgd-word-heading" title="Estilo de título">
                        <option value="">Párrafo normal</option>
                        <?php foreach ($titulosElab['niveles'] as $hi => $nivelTitulo):
                            if (!isset($headingTags[$hi])) {
                                break;
                            }
                            $hlabel = trim((string)($nivelTitulo['nombre'] ?? ''));
                            $hejemplo = trim((string)($nivelTitulo['ejemplo'] ?? ''));
                            if ($hlabel === '' && $hejemplo === '') {
                                continue;
                            }
                            if ($hlabel === '') {
                                $hlabel = $hejemplo;
                            } elseif ($hejemplo !== '' && $hejemplo !== $hlabel) {
                                $hlabel .= ' — ' . $hejemplo;
                            }
                        ?>
                            <option value="<?= (int)$hi ?>"><?= htmlspecialchars($hlabel, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="sgd-word-ribbon-sep"></div>
                <div class="sgd-word-ribbon-group">
                    <button type="button" class="sgd-word-tool" data-cmd="insertUnorderedList" title="Viñetas">• Lista</button>
                    <button type="button" class="sgd-word-tool" data-cmd="insertOrderedList" title="Numeración">1. Lista</button>
                </div>
                <div class="sgd-word-ribbon-sep"></div>
                <div class="sgd-word-ribbon-group sgd-word-import-group">
                    <label for="sgd-word-import-input" class="sgd-word-tool sgd-word-import-trigger" id="sgd-word-import-btn" title="Importar contenido desde Word (.docx)" role="button">📄 Word</label>
                    <input type="file" id="sgd-word-import-input" class="sgd-img-file-input" accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document" hidden>
                </div>
                <div class="sgd-word-ribbon-sep"></div>
                <div class="sgd-word-ribbon-group sgd-word-img-group">
                    <button type="button" class="sgd-word-tool" id="sgd-img-insert-btn" title="Insertar imagen (JPG, PNG, WebP)">🖼 Imagen</button>
                    <input type="file" id="sgd-img-file-input" class="sgd-img-file-input" accept="image/jpeg,image/png,image/webp" hidden>
                    <button type="button" class="sgd-word-tool" data-img-align="left" title="Imagen alineada izquierda">◧</button>
                    <button type="button" class="sgd-word-tool" data-img-align="center" title="Imagen centrada">◫</button>
                    <button type="button" class="sgd-word-tool" data-img-align="right" title="Imagen alineada derecha">◨</button>
                </div>
                <div class="sgd-word-ribbon-sep"></div>
                <div class="sgd-word-ribbon-group sgd-word-table-insert-group">
                    <button type="button" class="sgd-word-tool" id="sgd-table-insert-btn" title="Insertar tabla">▦ Tabla</button>
                    <div class="sgd-table-popover" id="sgd-table-insert-panel" hidden>
                        <div class="sgd-table-popover-row">
                            <label>Filas <input type="number" id="sgd-table-rows" class="form-input sgd-table-num" min="1" max="20" value="3"></label>
                            <label>Columnas <input type="number" id="sgd-table-cols" class="form-input sgd-table-num" min="1" max="12" value="3"></label>
                        </div>
                        <label class="sgd-table-popover-check">
                            <input type="checkbox" id="sgd-table-header" checked> Primera fila como encabezado
                        </label>
                        <button type="button" class="sgd-word-btn sgd-word-btn-primary sgd-table-popover-btn" id="sgd-table-insert-confirm">Insertar</button>
                    </div>
                </div>
                <div class="sgd-word-ribbon-group sgd-word-table-edit-group" id="sgd-table-edit-tools" hidden>
                    <span class="sgd-table-tool-label">Filas</span>
                    <button type="button" class="sgd-word-tool sgd-table-cmd" data-table-cmd="row-above" title="Insertar fila arriba">+↑</button>
                    <button type="button" class="sgd-word-tool sgd-table-cmd" data-table-cmd="row-below" title="Insertar fila abajo">+↓</button>
                    <button type="button" class="sgd-word-tool sgd-table-cmd" data-table-cmd="del-row" title="Eliminar fila">−F</button>
                    <span class="sgd-word-ribbon-sep sgd-table-inner-sep"></span>
                    <span class="sgd-table-tool-label">Cols</span>
                    <button type="button" class="sgd-word-tool sgd-table-cmd" data-table-cmd="col-left" title="Insertar columna izquierda">+←</button>
                    <button type="button" class="sgd-word-tool sgd-table-cmd" data-table-cmd="col-right" title="Insertar columna derecha">+→</button>
                    <button type="button" class="sgd-word-tool sgd-table-cmd" data-table-cmd="del-col" title="Eliminar columna">−C</button>
                    <span class="sgd-word-ribbon-sep sgd-table-inner-sep"></span>
                    <span class="sgd-table-tool-label">Celdas</span>
                    <button type="button" class="sgd-word-tool sgd-table-cmd" data-table-cmd="merge-cells" title="Combinar celdas (Shift+clic: bloque; Ctrl+clic: celdas sueltas)">⊞</button>
                    <button type="button" class="sgd-word-tool sgd-table-cmd" data-table-cmd="split-cell" title="Dividir celda combinada">⊟</button>
                    <button type="button" class="sgd-word-tool sgd-table-cmd" data-table-cmd="toggle-header" title="Alternar fila de encabezado">H</button>
                    <span class="sgd-word-ribbon-sep sgd-table-inner-sep"></span>
                    <span class="sgd-table-tool-label">Alinear</span>
                    <button type="button" class="sgd-word-tool sgd-table-cmd" data-table-cmd="align-left" title="Alinear texto izquierda">⯇</button>
                    <button type="button" class="sgd-word-tool sgd-table-cmd" data-table-cmd="align-center" title="Centrar texto">⯈</button>
                    <button type="button" class="sgd-word-tool sgd-table-cmd" data-table-cmd="align-right" title="Alinear texto derecha">⯉</button>
                    <button type="button" class="sgd-word-tool sgd-table-cmd" data-table-cmd="valign-top" title="Alinear arriba">⤒</button>
                    <button type="button" class="sgd-word-tool sgd-table-cmd" data-table-cmd="valign-middle" title="Centrar vertical">⤡</button>
                    <button type="button" class="sgd-word-tool sgd-table-cmd" data-table-cmd="valign-bottom" title="Alinear abajo">⤓</button>
                    <span class="sgd-word-ribbon-sep sgd-table-inner-sep"></span>
                    <div class="sgd-table-border-group">
                        <button type="button" class="sgd-word-tool" id="sgd-table-border-btn" title="Opciones de bordes">▥</button>
                        <div class="sgd-table-popover sgd-table-border-popover" id="sgd-table-border-panel" hidden>
                            <p class="sgd-table-popover-hint">Bordes de la celda o selección</p>
                            <div class="sgd-table-border-grid">
                                <button type="button" class="sgd-word-tool sgd-table-cmd" data-table-cmd="border-all" title="Todos los bordes">▦</button>
                                <button type="button" class="sgd-word-tool sgd-table-cmd" data-table-cmd="border-none" title="Sin bordes">▢</button>
                                <button type="button" class="sgd-word-tool sgd-table-cmd" data-table-cmd="border-top" title="Borde superior">⬒</button>
                                <button type="button" class="sgd-word-tool sgd-table-cmd" data-table-cmd="border-bottom" title="Borde inferior">⬓</button>
                                <button type="button" class="sgd-word-tool sgd-table-cmd" data-table-cmd="border-left" title="Borde izquierdo">⬑</button>
                                <button type="button" class="sgd-word-tool sgd-table-cmd" data-table-cmd="border-right" title="Borde derecho">⬐</button>
                            </div>
                            <p class="sgd-table-popover-hint">Dibujar / borrar en la tabla</p>
                            <div class="sgd-table-border-modes">
                                <button type="button" class="sgd-word-tool" data-table-border-mode="draw" title="Lápiz: clic en celda para dibujar bordes">✏</button>
                                <button type="button" class="sgd-word-tool" data-table-border-mode="erase" title="Borrador: clic en celda para quitar bordes">⌫</button>
                            </div>
                        </div>
                    </div>
                    <span class="sgd-table-color-wrap" title="Color de fondo">
                        <input type="color" id="sgd-cell-bg" class="sgd-table-color" value="#ffffff" aria-label="Color de fondo de celda">
                    </span>
                    <span class="sgd-table-color-wrap" title="Color de texto">
                        <input type="color" id="sgd-cell-color" class="sgd-table-color" value="#000000" aria-label="Color de texto de celda">
                    </span>
                    <button type="button" class="sgd-word-tool sgd-table-cmd" data-table-cmd="clear-colors" title="Quitar colores de celda">◎</button>
                    <button type="button" class="sgd-word-tool sgd-table-cmd" data-table-cmd="del-table" title="Eliminar tabla">✕▦</button>
                </div>
                <div class="sgd-word-ribbon-sep"></div>
                <div class="sgd-word-ribbon-group">
                    <button type="button" class="sgd-word-tool" data-cmd="justifyLeft" title="Alinear izquierda">≡</button>
                    <button type="button" class="sgd-word-tool" data-cmd="justifyCenter" title="Centrar">≡</button>
                    <button type="button" class="sgd-word-tool" data-cmd="justifyFull" title="Justificar">≡≡</button>
                </div>
                <div class="sgd-word-ribbon-sep"></div>
                <div class="sgd-word-ribbon-group">
                    <button type="button" class="sgd-word-tool" data-cmd="undo" title="Deshacer">↶</button>
                    <button type="button" class="sgd-word-tool" data-cmd="redo" title="Rehacer">↷</button>
                    <button type="button" class="sgd-word-tool" data-cmd="removeFormat" title="Quitar formato">✕</button>
                </div>
            </div>
            <?php endif; ?>

            <div class="sgd-word-workspace">
                <aside class="sgd-word-nav" aria-label="Navegación del documento">
                    <h4 class="sgd-word-nav-title">Navegación</h4>
                    <p class="field-note sgd-word-nav-hint">Clic en el número de una sección para quitarlo; las siguientes se renumeran solas. Los títulos del texto aparecen debajo de cada sección.</p>
                    <nav class="sgd-word-nav-list" id="sgd-word-nav-list">
                        <?php foreach ($secciones as $sec): ?>
                            <?php
                            $cod = (string)$sec['codigo'];
                            $num = $sec['numero_visible'] ?? null;
                            $navLabel = $num !== null ? $num . '. ' . $sec['nombre'] : $sec['nombre'];
                            $hasContent = false;
                            if ($cod === 'documentos_referenciados') {
                                $hasContent = $referenciadosIds !== [];
                            } elseif ($cod === 'anexos') {
                                foreach ($anexosBloques as $b) {
                                    if (trim((string)($b['titulo'] ?? '') . (string)($b['cuerpo'] ?? '')) !== '') {
                                        $hasContent = true;
                                        break;
                                    }
                                }
                            } elseif (($sec['clase'] ?? '') === 'contenido') {
                                $hasContent = trim((string)($contenido[$cod] ?? '')) !== '';
                            }
                            ?>
                            <div class="sgd-word-nav-group" data-nav-group="<?= htmlspecialchars($cod, ENT_QUOTES, 'UTF-8') ?>">
                                <a href="#sec-<?= htmlspecialchars($cod, ENT_QUOTES, 'UTF-8') ?>"
                                   class="sgd-word-nav-item<?= $hasContent ? ' is-filled' : '' ?>"
                                   data-nav="<?= htmlspecialchars($cod, ENT_QUOTES, 'UTF-8') ?>"
                                   data-nav-name="<?= htmlspecialchars((string)$sec['nombre'], ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($navLabel, ENT_QUOTES, 'UTF-8') ?>
                                </a>
                                <ul class="sgd-word-nav-sublist" data-nav-sublist="<?= htmlspecialchars($cod, ENT_QUOTES, 'UTF-8') ?>"></ul>
                            </div>
                        <?php endforeach; ?>
                    </nav>

                    <?php if (($opcionalesPerfil ?? []) !== []): ?>
                        <div class="sgd-word-nav-opcionales">
                            <h4 class="sgd-word-nav-title">Opcionales</h4>
                            <p class="field-note">Active y guarde para incluir en el documento.</p>
                            <ul class="sgd-word-opc-list">
                                <?php foreach ($opcionalesPerfil as $op): ?>
                                    <?php $cod = (string)$op['codigo']; ?>
                                    <li>
                                        <label>
                                            <input type="checkbox" name="opciones[<?= htmlspecialchars($cod, ENT_QUOTES, 'UTF-8') ?>]" value="1"
                                                <?= !empty($opciones[$cod]) ? 'checked' : '' ?>>
                                            <?= htmlspecialchars((string)$op['nombre'], ENT_QUOTES, 'UTF-8') ?>
                                        </label>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                </aside>

                <div class="sgd-word-canvas-wrap">
                    <div class="sgd-word-canvas">
                        <article class="sgd-word-page" style="<?= htmlspecialchars($pageStyle, ENT_QUOTES, 'UTF-8') ?>">

                            <header class="sgd-word-page-header">
                                <div class="sgd-word-page-code"><?= htmlspecialchars($codigoDisplay, ENT_QUOTES, 'UTF-8') ?></div>
                                <?php
                                $docNombre = (string)($documento['nombre'] ?? '');
                                if (!empty($titulosElab['niveles'][0]['mayusculas'])) {
                                    $docNombre = mb_strtoupper($docNombre, 'UTF-8');
                                }
                                ?>
                                <div class="sgd-word-page-name"><?= htmlspecialchars($docNombre, ENT_QUOTES, 'UTF-8') ?></div>
                            </header>

                            <?php foreach ($secciones as $sec): ?>
                                <?php
                                $codigo = (string)$sec['codigo'];
                                $clase = (string)$sec['clase'];
                                $nombre = (string)$sec['nombre'];
                                $numero = $sec['numero_visible'] ?? null;
                                $numerable = !empty($sec['numerable']);
                                $numerar = !empty($sec['numerar']);
                                $secId = 'sec-' . $codigo;
                                $headClass = 'sgd-word-sec-head';
                                ?>

                                <section class="sgd-word-block sgd-word-block-<?= htmlspecialchars($clase, ENT_QUOTES, 'UTF-8') ?>"
                                         id="<?= htmlspecialchars($secId, ENT_QUOTES, 'UTF-8') ?>"
                                         data-seccion="<?= htmlspecialchars($codigo, ENT_QUOTES, 'UTF-8') ?>"
                                         <?= $numerable ? ' data-numerable="1"' : '' ?>>

                                    <h2 class="<?= htmlspecialchars($headClass, ENT_QUOTES, 'UTF-8') ?>">
                                        <?php if ($numerable): ?>
                                            <span class="sgd-word-sec-num-wrap<?= $numerar ? '' : ' is-off' ?>">
                                                <?php if ($canGuardar): ?>
                                                    <button type="button" class="sgd-word-sec-num-btn" title="Quitar numeración (clic)"><?= $numero !== null ? (int)$numero . '.' : '' ?></button>
                                                    <button type="button" class="sgd-word-sec-num-add" title="Incluir en numeración">N°</button>
                                                <?php elseif ($numero !== null): ?>
                                                    <span class="sgd-word-sec-num"><?= (int)$numero ?>.</span>
                                                <?php endif; ?>
                                            </span>
                                            <input type="hidden" name="numeracion[<?= htmlspecialchars($codigo, ENT_QUOTES, 'UTF-8') ?>]" value="<?= $numerar ? '1' : '0' ?>" class="sgd-numeracion-input">
                                        <?php endif; ?>
                                        <span class="sgd-word-sec-label"><?= htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php if (!empty($sec['obligatoria'])): ?><span class="sgd-req">*</span><?php endif; ?>
                                    </h2>

                                    <?php if ($clase === 'auto'): ?>
                                        <div class="sgd-word-system-note">
                                            <span class="sgd-word-system-icon">⚙</span>
                                            Generada automáticamente al publicar el PDF
                                        </div>

                                    <?php elseif ($codigo === 'documentos_referenciados'): ?>
                                        <div class="sgd-word-ref-panel" data-ref-panel>
                                            <div class="sgd-word-ref-search-wrap">
                                                <input type="search" class="sgd-word-ref-search form-input" placeholder="Buscar documento por código o nombre…" autocomplete="off">
                                            </div>
                                            <div class="sgd-word-ref-selected" id="sgd-ref-chips"></div>
                                            <ul class="sgd-word-ref-list" id="sgd-ref-list"></ul>
                                            <div id="sgd-ref-hidden">
                                                <?php foreach ($referenciadosIds as $rid): ?>
                                                    <input type="hidden" name="referenciados[]" value="<?= (int)$rid ?>">
                                                <?php endforeach; ?>
                                            </div>
                                        </div>

                                    <?php elseif ($codigo === 'anexos'): ?>
                                        <div id="sgd-anexos-list" class="sgd-anexos-list">
                                            <?php foreach ($anexosBloques as $i => $bloque): ?>
                                                <div class="sgd-anexo-bloque" data-index="<?= (int)$i ?>">
                                                    <input type="text" class="sgd-word-anexo-title form-input"
                                                           name="anexos[<?= (int)$i ?>][titulo]"
                                                           placeholder="Título del anexo (ej. Anexo 1. Cronograma…)"
                                                           value="<?= htmlspecialchars((string)($bloque['titulo'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                           <?= $canGuardar ? '' : 'disabled' ?>>
                                                    <div class="sgd-word-editor sgd-word-editor-anexo"
                                                         contenteditable="<?= $canGuardar ? 'true' : 'false' ?>"
                                                         data-placeholder="Contenido del anexo…"
                                                         data-anexo-index="<?= (int)$i ?>"><?= SgdElaboracionService::htmlForEditor($bloque['cuerpo'] ?? '') ?></div>
                                                    <input type="hidden" name="anexos[<?= (int)$i ?>][cuerpo]" class="sgd-word-hidden-anexo" value="">
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php if ($canGuardar): ?>
                                            <button type="button" class="sgd-word-btn sgd-word-btn-ghost" id="sgd-btn-add-anexo">➕ Añadir anexo</button>
                                        <?php endif; ?>

                                    <?php elseif ($clase === 'sistema'): ?>
                                        <div class="sgd-word-system-note">
                                            <span class="sgd-word-system-icon">⚙</span>
                                            Completado por el sistema al publicar (versiones / firmas)
                                        </div>

                                    <?php else: ?>
                                        <div class="sgd-word-editor-wrap">
                                            <div class="sgd-word-editor"
                                                 contenteditable="<?= $canGuardar ? 'true' : 'false' ?>"
                                                 data-codigo="<?= htmlspecialchars($codigo, ENT_QUOTES, 'UTF-8') ?>"
                                                 data-placeholder="Escriba aquí el contenido de «<?= htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8') ?>»…"
                                                 role="textbox"
                                                 aria-multiline="true"><?= SgdElaboracionService::htmlForEditor($contenido[$codigo] ?? '') ?></div>
                                            <input type="hidden" name="texto[<?= htmlspecialchars($codigo, ENT_QUOTES, 'UTF-8') ?>]" class="sgd-word-hidden" value="">
                                        </div>
                                    <?php endif; ?>
                                </section>
                            <?php endforeach; ?>

                            <?php if (!empty($formatoPdf['pie_pagina'])): ?>
                                <footer class="sgd-word-page-footer">
                                    <?= htmlspecialchars((string)$formatoPdf['pie_pagina'], ENT_QUOTES, 'UTF-8') ?>
                                </footer>
                            <?php endif; ?>

                        </article>
                    </div>
                </div>
            </div>
        </form>

        <script type="application/json" id="sgd-elab-ref-docs"><?= $refDocsJson ?: '[]' ?></script>
        <div id="sgd-word-import-modal" class="sgd-word-import-modal" hidden aria-hidden="true">
            <div class="sgd-word-import-backdrop" data-word-import-close></div>
            <div class="sgd-word-import-dialog" role="dialog" aria-labelledby="sgd-word-import-title" aria-modal="true">
                <header class="sgd-word-import-head">
                    <h3 id="sgd-word-import-title">Importar desde Word</h3>
                    <button type="button" class="sgd-word-import-close" data-word-import-close title="Cerrar">×</button>
                </header>
                <div class="sgd-word-import-body">
                    <p class="field-note sgd-word-import-lead" id="sgd-word-import-lead">Convirtiendo documento…</p>
                    <div class="sgd-word-import-progress" id="sgd-word-import-progress" hidden>
                        <div class="sgd-word-import-progress-row">
                            <div
                                class="sgd-word-import-progress-track"
                                id="sgd-word-import-progress-track"
                                role="progressbar"
                                aria-valuemin="0"
                                aria-valuemax="100"
                                aria-valuenow="0"
                                aria-labelledby="sgd-word-import-lead"
                            >
                                <div class="sgd-word-import-progress-bar" id="sgd-word-import-progress-bar"></div>
                            </div>
                            <span class="sgd-word-import-progress-pct" id="sgd-word-import-progress-pct">0%</span>
                        </div>
                    </div>
                    <div class="sgd-word-import-options" id="sgd-word-import-options" hidden>
                        <label class="sgd-word-import-field">
                            <span>Destino</span>
                            <select id="sgd-word-import-mode" class="sgd-word-select">
                                <option value="cursor">Insertar en la sección actual (cursor)</option>
                                <option value="replace">Reemplazar sección elegida</option>
                                <option value="map">Distribuir por títulos del Word (coincidencia con secciones)</option>
                            </select>
                        </label>
                        <label class="sgd-word-import-field" id="sgd-word-import-section-wrap">
                            <span>Sección</span>
                            <select id="sgd-word-import-section" class="sgd-word-select"></select>
                        </label>
                        <details class="sgd-word-import-diag">
                            <summary>Diagnóstico y vista previa</summary>
                            <pre id="sgd-word-import-stats" class="sgd-word-import-stats"></pre>
                            <div id="sgd-word-import-preview" class="sgd-word-import-preview sgd-word-editor"></div>
                        </details>
                    </div>
                </div>
                <footer class="sgd-word-import-foot">
                    <button type="button" class="sgd-word-btn sgd-word-btn-ghost" data-word-import-close>Cancelar</button>
                    <button type="button" class="sgd-word-btn sgd-word-btn-primary" id="sgd-word-import-confirm" disabled>Importar al documento</button>
                </footer>
            </div>
        </div>

        <script type="application/json" id="sgd-elab-config"><?= json_encode([
            'canEdit' => $canGuardar,
            'documentoId' => (int)$documentoId,
            'uploadMediaUrl' => $canGuardar && $documentoId > 0
                ? ('?url=sgd/elaboracionUploadMedia' . $empresaQuery . '&documento_id=' . (int)$documentoId)
                : '',
            'importWordUrl' => $canGuardar && $documentoId > 0
                ? ('?url=sgd/elaboracionImportWord' . $empresaQuery . '&documento_id=' . (int)$documentoId)
                : '',
            'importWordFetchUrl' => $canGuardar && $documentoId > 0
                ? ('?url=sgd/elaboracionImportWordFetch' . $empresaQuery . '&documento_id=' . (int)$documentoId)
                : '',
            'previewPdfUrl' => $documentoId > 0
                ? ('?url=sgd/elaboracionPreviewPdf' . $empresaQuery . '&documento_id=' . (int)$documentoId)
                : '',
            'wordImportServerZip' => $wordImportServerZip,
            'secciones' => array_values(array_map(static function (array $sec): array {
                return [
                    'codigo' => (string)($sec['codigo'] ?? ''),
                    'nombre' => (string)($sec['nombre'] ?? ''),
                    'clase' => (string)($sec['clase'] ?? ''),
                ];
            }, array_filter($secciones, static fn (array $s): bool => ($s['clase'] ?? '') === 'contenido'))),
            'piePagina' => (string)($formatoPdf['pie_pagina'] ?? ''),
            'headingTags' => $headingTags,
            'titulos' => $titulosElab,
            'fuente' => (string)($formatoPdf['fuente_cuerpo'] ?? 'Arial'),
            'tamano' => (int)($formatoPdf['tamano_cuerpo'] ?? 11),
        ], JSON_UNESCAPED_UNICODE) ?></script>

    <?php endif; ?>
</div>
<?php if (!$wordImportServerZip): ?>
        <script src="https://cdn.jsdelivr.net/npm/mammoth@1.8.0/mammoth.browser.min.js" crossorigin="anonymous"></script>
<?php endif; ?>
<script src="/js/sgd-elaboracion.js?v=<?= (int)$sgdElabJsV ?>"></script>
