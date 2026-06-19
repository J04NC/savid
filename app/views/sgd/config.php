<?php
/** @var array $scope */
/** @var array|null $config */
/** @var array $counts */
/** @var list<array> $tipos */
/** @var bool $canConfigurar */

$sgdConfigJs = BASE_PATH . '/public/js/sgd-config.js';
$sgdConfigJsV = is_readable($sgdConfigJs) ? (int)filemtime($sgdConfigJs) : time();

$patronesDocumentoSugeridos = [
    '{proceso}-{tipo}{n}',
    '{proceso}-{tipo}{n}-F{n}',
    '{proceso}-{tipo}{n}-R{n}',
    '{proceso}-{tipo}{n}-M{n}',
];
$patronesCarpetaSugeridos = [
    '{dep}.{serie}.{subserie}',
    '{dep}.{serie}.{subserie}.{detalle}',
];
?>
<div class="module-container sgd-config-page">
    <?php $filterUrl = 'sgd/config'; require BASE_PATH . '/app/views/sgd/_empresa_filter.php'; ?>

    <?php if (empty($scope['empresaId'])): ?>
        <p class="field-note sgd-page-empty">Seleccione la empresa en el filtro superior para configurar SGD.</p>
    <?php else: ?>

        <div class="crud-toolbar">
            <div>
                <?php if ($canConfigurar): ?>
                    <button type="submit" form="sgd-config-form" title="Guardar configuración">💾</button>
                <?php endif; ?>
            </div>
            <div class="crud-list-search" style="margin-left:auto;">
                <span class="crud-list-search-label">
                    Empresa · <?= (int)($counts['documentos'] ?? 0) ?> documentos · <?= (int)($counts['ccd'] ?? 0) ?> entradas CCD
                </span>
            </div>
        </div>

        <p class="field-note sgd-page-lead">
            Misma disposición que los mantenimientos del sistema. Use <strong>?</strong> para ayuda rápida o lea la nota bajo cada campo.
        </p>

        <form
            id="sgd-config-form"
            method="post"
            action="?url=sgd/guardarConfig&empresa_id=<?= (int)$scope['empresaId'] ?>"
            class="crud-form sgd-config-form"
        >
            <fieldset <?= $canConfigurar ? '' : 'disabled' ?> class="sgd-config-fieldset">

                <?php
                $name = 'sgd_activo';
                $label = 'SGD activo para esta empresa';
                $type = 'checkbox';
                $value = !empty($config['sgd_activo']);
                $helpId = 'activo';
                $helpLabel = 'Ayuda: activar SGD';
                $helpBody = 'Enciende el módulo de gestión documental para esta empresa en SAVID.';
                $helpExample = 'Apagado: no conviene importar ni operar documentos SGD aquí.';
                $fieldNote = 'Recomendado marcarlo cuando ya definió tipos documentales y va a importar el listado maestro.';
                $groupClass = 'crud-form-field-full sgd-check-group';
                require BASE_PATH . '/app/views/sgd/_form_field.php';

                $name = 'patron_documento';
                $label = 'Patrón documento (referencia)';
                $type = 'text';
                $value = (string)($config['patron_documento'] ?? '{proceso}-{tipo}{n}');
                $placeholder = '{proceso}-{tipo}{n}';
                $helpId = 'patron-doc';
                $helpLabel = 'Ayuda: patrón de código de documento';
                $helpBody = 'Plantilla de cómo se componen los códigos del listado maestro. Hoy es referencia para su equipo y para futuras reglas automáticas; al importar Excel los códigos vienen tal como están en el archivo.';
                $helpExample = 'GE-PD3-F1 = proceso GE + tipo PD + número 3 + formato F1.';
                $fieldNote = 'Piezas que puede usar: <code>{proceso}</code> prefijo (GE, GH…), <code>{tipo}</code> sigla (PD, F, R, M…), '
                    . '<code>{n}</code> consecutivo, <code>-F{n}</code> o <code>-R{n}</code> subcódigo de formato/registro. '
                    . 'Escriba libremente o elija una sugerencia al escribir.';
                $datalistId = 'sgd-patrones-documento';
                $datalistOptions = $patronesDocumentoSugeridos;
                $groupClass = 'crud-form-field-full sgd-pattern-doc-group';
                $inputAttrs = [];
                require BASE_PATH . '/app/views/sgd/_form_field.php';

                $name = 'patron_carpeta';
                $label = 'Patrón carpeta CCD (referencia)';
                $type = 'text';
                $value = (string)($config['patron_carpeta'] ?? '{dep}.{serie}.{subserie}');
                $placeholder = '{dep}.{serie}.{subserie}';
                $helpId = 'patron-ccd';
                $helpLabel = 'Ayuda: patrón de carpeta archivística';
                $helpBody = 'Plantilla del código de carpeta en el cuadro de clasificación (dependencia, serie, subserie).';
                $helpExample = '40.1.7 = dependencia 40, serie 1, subserie 7.';
                $fieldNote = 'Piezas: <code>{dep}</code>, <code>{serie}</code>, <code>{subserie}</code>, opcional <code>{detalle}</code>. '
                    . 'Separador habitual: punto (.) entre partes.';
                $datalistId = 'sgd-patrones-carpeta';
                $datalistOptions = $patronesCarpetaSugeridos;
                $groupClass = 'sgd-pattern-folder-group';
                $inputAttrs = [];
                require BASE_PATH . '/app/views/sgd/_form_field.php';

                $name = 'ccd_vigencia';
                $label = 'Vigencia CCD';
                $type = 'text';
                $value = (string)($config['ccd_vigencia'] ?? '');
                $placeholder = 'Ej. 2024/05/03';
                $helpId = 'vigencia';
                $helpLabel = 'Ayuda: vigencia del CCD';
                $helpBody = 'Texto que identifica la versión vigente del cuadro de clasificación (como en su Excel o acta de aprobación).';
                $helpExample = 'Vigente: 2024/05/03';
                $fieldNote = '';
                $datalistId = '';
                $datalistOptions = [];
                $groupClass = 'sgd-vigencia-group';
                $inputAttrs = [];
                require BASE_PATH . '/app/views/sgd/_form_field.php';

                $name = 'ccd_anio';
                $label = 'Año CCD';
                $type = 'number';
                $value = (string)($config['ccd_anio'] ?? date('Y'));
                $placeholder = '';
                $helpId = 'anio';
                $helpLabel = 'Ayuda: año del CCD';
                $helpBody = 'Año de referencia para importaciones y consultas del catálogo archivístico.';
                $helpExample = (string)date('Y');
                $fieldNote = '';
                $datalistId = '';
                $datalistOptions = [];
                $groupClass = 'sgd-anio-group';
                $inputAttrs = ['min' => '2000', 'max' => '2100'];
                require BASE_PATH . '/app/views/sgd/_form_field.php';

                $name = 'archivo_word_proteccion_obligatoria';
                $label = 'Exigir protección contra escritura en archivos Word';
                $type = 'checkbox';
                $value = !empty($archivoOficial['word_proteccion_escritura_obligatoria']);
                $helpId = 'word-proteccion';
                $helpLabel = 'Protección Word';
                $helpBody = 'Al subir un archivo .docx como documento oficial, el sistema comprueba si tiene restricción de edición activa (Revisar → Restringir edición en Word).';
                $helpExample = 'Activado: rechaza Word sin protección. Desactivado: acepta el archivo pero advierte si no hay protección.';
                $fieldNote = 'Recomendado mantener activo para formatos operativos cuyo archivo oficial debe conservarse sin cambios accidentales.';
                $groupClass = 'crud-form-field-full sgd-check-group';
                require BASE_PATH . '/app/views/sgd/_form_field.php';
                ?>

                <div class="sgd-section-title-inline" style="margin-top:24px;">
                    <span>Formato PDF — documentos internos</span>
                </div>
                <p class="field-note">Márgenes en centímetros. El logo se toma del registro de la empresa.</p>

                <?php
                $margenes = $formatoPdf['margenes'] ?? [];
                foreach (['superior' => 'Superior', 'inferior' => 'Inferior', 'izquierdo' => 'Izquierdo', 'derecho' => 'Derecho'] as $mk => $mlabel):
                    $name = 'margen_' . $mk;
                    $label = 'Margen ' . $mlabel . ' (cm)';
                    $type = 'number';
                    $value = (string)($margenes[$mk] ?? '');
                    $helpId = 'margen-' . $mk;
                    $helpLabel = 'Margen ' . $mlabel;
                    $helpBody = 'Margen ' . strtolower($mlabel) . ' del documento interno en el PDF generado.';
                    $helpExample = '3.0';
                    $fieldNote = '';
                    $datalistId = '';
                    $datalistOptions = [];
                    $groupClass = 'sgd-margen-group';
                    $inputAttrs = ['step' => '0.1', 'min' => '0', 'max' => '10'];
                    require BASE_PATH . '/app/views/sgd/_form_field.php';
                endforeach;

                $titulosConfig = SgdSeccionService::normalizeTitulosConfig($formatoPdf['titulos'] ?? null);
                $titulosNiveles = $titulosConfig['niveles'];
                $flagLabels = SgdSeccionService::tituloFlagKeys();

                $name = 'fuente_documento';
                $label = 'Fuente (cuerpo y títulos)';
                $type = 'text';
                $value = (string)($formatoPdf['fuente_cuerpo'] ?? 'Arial');
                $helpId = 'fuente-doc';
                $helpLabel = 'Fuente';
                $helpBody = 'Tipografía del cuerpo y de los niveles de título en el PDF.';
                $helpExample = 'Arial';
                $fieldNote = '';
                $datalistId = '';
                $datalistOptions = [];
                $groupClass = 'sgd-fuente-group';
                $inputAttrs = ['maxlength' => '40'];
                require BASE_PATH . '/app/views/sgd/_form_field.php';

                $name = 'tamano_documento';
                $label = 'Tamaño (pt)';
                $type = 'number';
                $value = (string)($formatoPdf['tamano_cuerpo'] ?? '11');
                $helpId = 'tamano-doc';
                $helpLabel = 'Tamaño';
                $helpBody = 'Tamaño en puntos para cuerpo y títulos.';
                $helpExample = '11';
                $groupClass = 'sgd-tamano-group';
                $inputAttrs = ['min' => '8', 'max' => '24'];
                require BASE_PATH . '/app/views/sgd/_form_field.php';
                ?>

                <div class="sgd-section-title-inline" style="margin-top:16px;">
                    <span>Jerarquía de títulos</span>
                </div>
                <p class="field-note">Defina los niveles de título y el formato de cada uno. Puede agregar o quitar niveles según la norma documental de su organización.</p>

                <input type="hidden" name="titulo_nivel_count" id="sgd-titulo-count" value="<?= count($titulosNiveles) ?>">

                <?php if ($titulosNiveles === []): ?>
                    <p class="field-note sgd-titulos-empty" id="sgd-titulos-empty">Sin niveles de título configurados. Use el botón inferior para agregar el primero.</p>
                <?php endif; ?>

                <div class="crud-table-wrapper sgd-titulos-table-wrap<?= $titulosNiveles === [] ? ' is-empty' : '' ?>">
                    <table class="crud-table sgd-titulos-table" id="sgd-titulos-table">
                        <thead>
                            <tr>
                                <th class="sgd-titulos-orden-col">#</th>
                                <th>Nombre del nivel</th>
                                <th>Ejemplo de numeración</th>
                                <?php foreach ($flagLabels as $flabel): ?>
                                    <th class="sgd-titulos-flag-col"><?= htmlspecialchars($flabel, ENT_QUOTES, 'UTF-8') ?></th>
                                <?php endforeach; ?>
                                <th class="sgd-titulos-actions-col"></th>
                            </tr>
                        </thead>
                        <tbody id="sgd-titulos-tbody">
                            <?php foreach ($titulosNiveles as $i => $nivelCfg): ?>
                                <tr class="sgd-titulo-row" data-index="<?= (int)$i ?>">
                                    <td class="sgd-titulos-orden-col"><?= (int)$i + 1 ?></td>
                                    <td>
                                        <input type="text"
                                               name="titulo_nombre[<?= (int)$i ?>]"
                                               class="form-input sgd-titulo-nombre"
                                               maxlength="80"
                                               value="<?= htmlspecialchars((string)($nivelCfg['nombre'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                               placeholder="Ej. Capítulo, Sección…">
                                    </td>
                                    <td>
                                        <input type="text"
                                               name="titulo_ejemplo[<?= (int)$i ?>]"
                                               class="form-input sgd-titulo-ejemplo"
                                               maxlength="80"
                                               value="<?= htmlspecialchars((string)($nivelCfg['ejemplo'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                               placeholder="Ej. 1, 1.1, 1.1.1…">
                                    </td>
                                    <?php foreach (array_keys($flagLabels) as $flag): ?>
                                        <td class="sgd-titulos-flag-col">
                                            <label class="sgd-check-row sgd-titulos-check">
                                                <input type="hidden" name="titulo_<?= htmlspecialchars($flag, ENT_QUOTES, 'UTF-8') ?>[<?= (int)$i ?>]" value="0">
                                                <input type="checkbox"
                                                       name="titulo_<?= htmlspecialchars($flag, ENT_QUOTES, 'UTF-8') ?>[<?= (int)$i ?>]"
                                                       value="1"
                                                       <?= !empty($nivelCfg[$flag]) ? 'checked' : '' ?>>
                                            </label>
                                        </td>
                                    <?php endforeach; ?>
                                    <td class="sgd-titulos-actions-col">
                                        <button type="button" class="sgd-titulo-remove" title="Quitar nivel">✕</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="sgd-titulos-toolbar">
                    <button type="button" class="sgd-titulo-add" id="sgd-titulo-add">+ Agregar nivel</button>
                </div>

                <template id="sgd-titulo-row-tpl">
                    <tr class="sgd-titulo-row" data-index="__INDEX__">
                        <td class="sgd-titulos-orden-col">__ORDEN__</td>
                        <td>
                            <input type="text" name="titulo_nombre[__INDEX__]" class="form-input sgd-titulo-nombre" maxlength="80" placeholder="Ej. Capítulo, Sección…">
                        </td>
                        <td>
                            <input type="text" name="titulo_ejemplo[__INDEX__]" class="form-input sgd-titulo-ejemplo" maxlength="80" placeholder="Ej. 1, 1.1, 1.1.1…">
                        </td>
                        <?php foreach (array_keys($flagLabels) as $flag): ?>
                            <td class="sgd-titulos-flag-col">
                                <label class="sgd-check-row sgd-titulos-check">
                                    <input type="hidden" name="titulo_<?= htmlspecialchars($flag, ENT_QUOTES, 'UTF-8') ?>[__INDEX__]" value="0">
                                    <input type="checkbox" name="titulo_<?= htmlspecialchars($flag, ENT_QUOTES, 'UTF-8') ?>[__INDEX__]" value="1">
                                </label>
                            </td>
                        <?php endforeach; ?>
                        <td class="sgd-titulos-actions-col">
                            <button type="button" class="sgd-titulo-remove" title="Quitar nivel">✕</button>
                        </td>
                    </tr>
                </template>

                <?php
                $name = 'pie_pagina';
                ?>
                <div class="form-group crud-form-field-full">
                    <label class="sgd-label-with-help" for="sgd_pie_pagina">
                        <span>Pie de página legal</span>
                        <?php
                        $helpId = 'pie-pagina';
                        $helpLabel = 'Pie de página';
                        $helpBody = 'Texto legal en el pie de cada página del PDF.';
                        $helpExample = 'Este documento es propiedad de la organización…';
                        require BASE_PATH . '/app/views/sgd/_field_help.php';
                        ?>
                    </label>
                    <textarea id="sgd_pie_pagina" name="pie_pagina" class="form-input" rows="3"><?= htmlspecialchars((string)($formatoPdf['pie_pagina'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>
                <?php
                unset($name);
                ?>

            </fieldset>
        </form>

        <?php if ($canConfigurar): ?>
            <div class="crud-toolbar" style="margin-top:20px; display:flex; flex-wrap:wrap; gap:10px;">
                <form method="post" action="?url=sgd/cargarSeccionesPlantilla&empresa_id=<?= (int)$scope['empresaId'] ?>">
                    <button type="submit" title="Importar catálogo de secciones y perfiles para tipos maestro"
                            onclick="return confirm('¿Importar plantilla de secciones? Actualiza secciones existentes por código.');">
                        📑 Importar plantilla de secciones
                    </button>
                </form>
                <form method="post" action="?url=sgd/cargarBloquesOperativos&empresa_id=<?= (int)$scope['empresaId'] ?>">
                    <button type="submit" title="Bloques operativos (acta, bitácora…) para formatos F/R"
                            onclick="return confirm('¿Importar bloques operativos? Actualiza bloques existentes por código.');">
                        📝 Importar bloques operativos
                    </button>
                </form>
            </div>
        <?php endif; ?>

        <div class="crud-list-search sgd-patrones-ayuda">
            <span class="crud-list-search-label">Referencia rápida — patrón documento</span>
        </div>
        <div class="crud-table-wrapper sgd-ref-table-wrap">
            <table class="crud-table sgd-ref-table savid-datatable" data-dt-page-length="10">
                <thead>
                    <tr>
                        <th>Placeholder</th>
                        <th>Significado</th>
                        <th>Ejemplo en código real</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td><code>{proceso}</code></td><td>Prefijo del mapa de procesos</td><td><code>GE</code>, <code>GH</code>, <code>SGI</code></td></tr>
                    <tr><td><code>{tipo}</code></td><td>Sigla del tipo documental (catálogo Tipos)</td><td><code>PD</code>, <code>F</code>, <code>R</code>, <code>M</code></td></tr>
                    <tr><td><code>{n}</code></td><td>Número del documento dentro del proceso+tipo</td><td><code>3</code> en <code>GE-PD3</code></td></tr>
                    <tr><td><code>-F{n}</code> / <code>-R{n}</code></td><td>Subcódigo opcional (formato o registro)</td><td><code>F1</code> → <code>GE-PD3-F1</code></td></tr>
                </tbody>
            </table>
        </div>

        <div class="crud-toolbar" style="margin-top:28px;">
            <div class="sgd-section-title-inline">
                <span>Tipos documentales</span>
                <span class="crud-list-search-label">(<?= count($tipos) ?>)</span>
                <?php
                $helpId = 'tipos';
                $helpLabel = 'Ayuda: tipos documentales';
                $helpBody = 'Catálogo de siglas de su empresa. Definen si el documento es maestro, dinámico (formato/registro) u híbrido.';
                $helpExample = 'F y R suelen ser dinámicos; PD y M suelen ser maestros.';
                require BASE_PATH . '/app/views/sgd/_field_help.php';
                ?>
            </div>
            <?php if ($canConfigurar && $tipos === []): ?>
                <form method="post" action="?url=sgd/cargarTiposPlantilla&empresa_id=<?= (int)$scope['empresaId'] ?>" style="margin-left:auto;">
                    <button type="submit" title="Cargar plantilla M, PD, F, R…">➕ Plantilla tipos</button>
                </form>
            <?php endif; ?>
        </div>

        <?php if ($tipos === []): ?>
            <p class="field-note">Sin tipos aún. Cargue la plantilla o use el menú <strong>Tipos documentales</strong>.</p>
        <?php else: ?>
            <div class="crud-table-wrapper">
                <table class="crud-table savid-datatable" data-dt-page-length="50">
                    <thead><tr><th>Código</th><th>Nombre</th><th>Modo</th></tr></thead>
                    <tbody>
                    <?php foreach ($tipos as $t): ?>
                        <tr>
                            <td><?= htmlspecialchars((string)$t['codigo'], ENT_QUOTES) ?></td>
                            <td><?= htmlspecialchars((string)$t['nombre'], ENT_QUOTES) ?></td>
                            <td><?= htmlspecialchars((string)$t['modo'], ENT_QUOTES) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

    <?php endif; ?>
</div>
<script src="/js/sgd-config.js?v=<?= (int)$sgdConfigJsV ?>"></script>
