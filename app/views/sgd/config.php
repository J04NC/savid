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
                ?>

            </fieldset>
        </form>

        <div class="crud-list-search sgd-patrones-ayuda">
            <span class="crud-list-search-label">Referencia rápida — patrón documento</span>
        </div>
        <div class="crud-table-wrapper sgd-ref-table-wrap">
            <table class="crud-table sgd-ref-table">
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
                <table class="crud-table">
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
