<?php
/** @var array $scope */
/** @var list<string> $samples */
/** @var bool $canImportar */
/** @var int|null $empresaId */

$sgdConfigJs = BASE_PATH . '/public/js/sgd-config.js';
$sgdConfigJsV = is_readable($sgdConfigJs) ? (int)filemtime($sgdConfigJs) : time();
?>
<div class="module-container sgd-import-page">
    <?php $filterUrl = 'sgd/importar'; require BASE_PATH . '/app/views/sgd/_empresa_filter.php'; ?>

    <?php if (empty($empresaId)): ?>
        <p class="field-note sgd-page-empty">Seleccione la empresa antes de importar.</p>
    <?php elseif (!$canImportar): ?>
        <p class="field-note sgd-page-empty">No tiene permiso de importación.</p>
    <?php else: ?>

        <div class="crud-toolbar">
            <div>
                <button type="submit" form="sgd-import-form" class="sgd-btn-import" title="Ejecutar importación">📥 Importar</button>
            </div>
        </div>

        <p class="field-note sgd-page-lead">
            Orden sugerido: <strong>tipos documentales</strong> → <strong>listado maestro</strong> → <strong>CCD</strong> por dependencia.
        </p>

        <form
            id="sgd-import-form"
            method="post"
            action="?url=sgd/ejecutarImportar&empresa_id=<?= (int)$empresaId ?>"
            enctype="multipart/form-data"
            class="sgd-import-layout"
        >
            <div class="sgd-import-columns">
                <div class="sgd-import-col-main">

                    <section class="sgd-panel">
                        <header class="sgd-panel-head">
                            <h3 class="sgd-panel-title">1. Tipo de importación</h3>
                            <?php
                            $helpId = 'tipo-import';
                            $helpLabel = 'Ayuda: tipo de importación';
                            $helpBody = 'Listado maestro: documentos y procesos. CCD: dependencias, series y carpetas archivísticas.';
                            $helpExample = 'Importe primero el listado maestro si va a vincular códigos de calidad.';
                            require BASE_PATH . '/app/views/sgd/_field_help.php';
                            ?>
                        </header>
                        <div class="sgd-option-list" role="radiogroup" aria-label="Tipo de importación">
                            <label class="sgd-option">
                                <input type="radio" name="tipo_import" value="ccd" checked data-sgd-tipo-import>
                                <span class="sgd-option-body">
                                    <span class="sgd-option-title">Cuadro de clasificación (CCD)</span>
                                    <span class="sgd-option-desc">Dependencias, series, subseries y vínculos archivísticos.</span>
                                </span>
                            </label>
                            <label class="sgd-option">
                                <input type="radio" name="tipo_import" value="maestro" data-sgd-tipo-import>
                                <span class="sgd-option-body">
                                    <span class="sgd-option-title">Listado maestro de documentos</span>
                                    <span class="sgd-option-desc">Procedimientos, formatos, registros y códigos SGC.</span>
                                </span>
                            </label>
                        </div>
                    </section>

                    <section class="sgd-panel">
                        <header class="sgd-panel-head">
                            <h3 class="sgd-panel-title">2. Archivo desde su equipo</h3>
                            <?php
                            $helpId = 'archivo';
                            $helpLabel = 'Ayuda: archivo Excel';
                            $helpBody = 'Seleccione el archivo .xls o .xlsx a importar.';
                            $helpExample = 'Si no tiene archivo, use la lista del servidor (panel derecho).';
                            require BASE_PATH . '/app/views/sgd/_field_help.php';
                            ?>
                        </header>
                        <div class="sgd-file-picker">
                            <input type="file" name="archivo" id="sgd_archivo" class="sgd-file-input" accept=".xls,.xlsx">
                            <label for="sgd_archivo" class="sgd-file-trigger">Seleccionar archivo Excel</label>
                            <span class="sgd-file-name" id="sgd_archivo_nombre">Ningún archivo seleccionado</span>
                        </div>
                        <p class="field-note">Formatos: .xls, .xlsx</p>
                    </section>

                    <section class="sgd-panel sgd-panel-ccd-meta" id="sgd-panel-ccd-meta">
                        <header class="sgd-panel-head">
                            <h3 class="sgd-panel-title">3. Metadatos CCD (opcional)</h3>
                            <?php
                            $helpId = 'imp-meta';
                            $helpLabel = 'Ayuda: metadatos CCD';
                            $helpBody = 'Solo aplica cuando importa un CCD. Se guardan en las entradas importadas.';
                            $helpExample = '';
                            require BASE_PATH . '/app/views/sgd/_field_help.php';
                            ?>
                        </header>
                        <div class="sgd-import-meta-row">
                            <div class="form-group sgd-field-vigencia">
                                <label class="sgd-label-with-help" for="sgd_ccd_vigencia">
                                    <span>Vigencia</span>
                                    <?php
                                    $helpId = 'imp-vigencia';
                                    $helpLabel = 'Ayuda: vigencia';
                                    $helpBody = 'Versión o fecha de vigencia del CCD.';
                                    $helpExample = '2024/05/03';
                                    require BASE_PATH . '/app/views/sgd/_field_help.php';
                                    ?>
                                </label>
                                <input type="text" class="form-input" id="sgd_ccd_vigencia" name="ccd_vigencia"
                                       placeholder="2024/05/03">
                            </div>
                            <div class="form-group sgd-field-anio">
                                <label class="sgd-label-with-help" for="sgd_ccd_anio">
                                    <span>Año</span>
                                    <?php
                                    $helpId = 'imp-anio';
                                    $helpLabel = 'Ayuda: año';
                                    $helpBody = 'Año del lote CCD importado.';
                                    $helpExample = (string)date('Y');
                                    require BASE_PATH . '/app/views/sgd/_field_help.php';
                                    ?>
                                </label>
                                <input type="number" class="form-input" id="sgd_ccd_anio" name="ccd_anio"
                                       value="<?= (int)date('Y') ?>" min="2000" max="2100">
                            </div>
                        </div>
                    </section>

                </div>

                <?php if ($samples !== []): ?>
                <aside class="sgd-import-col-aside">
                    <section class="sgd-panel sgd-panel-samples">
                        <header class="sgd-panel-head">
                            <h3 class="sgd-panel-title">O usar archivo del servidor</h3>
                        </header>
                        <p class="field-note">Si no sube archivo, elija uno de la lista:</p>
                        <div class="sgd-option-list sgd-sample-list">
                            <?php foreach ($samples as $f): ?>
                                <label class="sgd-option sgd-option-compact">
                                    <input type="radio" name="archivo_muestra" value="<?= htmlspecialchars($f, ENT_QUOTES) ?>">
                                    <span class="sgd-option-body">
                                        <span class="sgd-option-title"><?= htmlspecialchars($f, ENT_QUOTES) ?></span>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </section>
                </aside>
                <?php endif; ?>

            </div>
        </form>

    <?php endif; ?>
</div>
<script src="/js/sgd-config.js?v=<?= (int)$sgdConfigJsV ?>"></script>
<script src="/js/sgd-import.js?v=<?= (int)(is_readable(BASE_PATH . '/public/js/sgd-import.js') ? filemtime(BASE_PATH . '/public/js/sgd-import.js') : time()) ?>"></script>
