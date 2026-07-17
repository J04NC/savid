<?php
/** @var array $scope */
/** @var int|null $empresaId */
/** @var int $registroId */
/** @var array<string, mixed>|null $registro */
/** @var array<string, mixed>|null $documento */
/** @var string $codigoDisplay */
/** @var array<string, mixed> $esquema */
/** @var string $esquemaJson */
/** @var string $datosJson */
/** @var bool $editable */
/** @var bool $canGuardar */

$empresaQuery = $empresaId ? '&empresa_id=' . (int)$empresaId : '';
$listUrl = '?url=sgd/registros' . $empresaQuery;
$docId = (int)($registro['documento_id'] ?? $documento['id'] ?? 0);
$backUrl = $docId > 0 ? $listUrl . '&documento_id=' . $docId : $listUrl;
$formAction = '?url=sgd/registrosDiligenciar' . $empresaQuery . '&id=' . $registroId;
$estado = (string)($registro['estado'] ?? '');
$estadoLabels = [
    'borrador' => 'Borrador',
    'en_firma' => 'En firma',
    'firmado' => 'Firmado',
    'cerrado' => 'Cerrado',
    'anulado' => 'Anulado',
];

$sgdRegJs = BASE_PATH . '/public/js/sgd-registros.js';
$sgdRegJsV = is_readable($sgdRegJs) ? (int)filemtime($sgdRegJs) : time();
$sgdBloqueCfgJs = BASE_PATH . '/public/js/sgd-bloque-config.js';
$sgdBloqueCfgJsV = is_readable($sgdBloqueCfgJs) ? (int)filemtime($sgdBloqueCfgJs) : time();
$sgdChecklistJs = BASE_PATH . '/public/js/sgd-checklist-config.js';
$sgdChecklistJsV = is_readable($sgdChecklistJs) ? (int)filemtime($sgdChecklistJs) : time();
?>
<div class="module-container sgd-reg-diligenciar-page">
    <?php $filterUrl = 'sgd/registros'; require BASE_PATH . '/app/views/sgd/_empresa_filter.php'; ?>

    <?php if (empty($empresaId) || !$registro): ?>
        <p class="field-note sgd-page-empty">Registro no encontrado o empresa no seleccionada.</p>
        <p><a href="<?= htmlspecialchars($listUrl, ENT_QUOTES, 'UTF-8') ?>">Volver al listado</a></p>
    <?php else: ?>

        <div class="crud-toolbar sgd-reg-toolbar">
            <div class="sgd-reg-toolbar-actions">
                <a href="<?= htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') ?>" class="sgd-doc-btn">↩ Registros</a>
                <?php if ($editable && $canGuardar): ?>
                    <button type="submit" form="sgd-reg-diligenciar-form" class="sgd-doc-btn sgd-doc-btn-primary" data-sgd-reg-action="guardar">💾 Guardar</button>
                    <button type="submit" form="sgd-reg-cerrar-form" class="sgd-doc-btn" data-sgd-reg-action="cerrar"
                            onclick="return confirm('¿Cerrar el registro? El contenido quedará congelado.');">✓ Cerrar registro</button>
                <?php endif; ?>
            </div>
        </div>

        <header class="sgd-reg-dilig-head">
            <h2 class="sgd-reg-dilig-title"><?= htmlspecialchars((string)($registro['titulo'] ?? 'Registro'), ENT_QUOTES, 'UTF-8') ?></h2>
            <p class="field-note">
                <?= htmlspecialchars($codigoDisplay, ENT_QUOTES, 'UTF-8') ?>
                — <?= htmlspecialchars((string)($documento['nombre'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                · Estado: <strong><?= htmlspecialchars($estadoLabels[$estado] ?? $estado, ENT_QUOTES, 'UTF-8') ?></strong>
                · Plantilla v<?= htmlspecialchars((string)($registro['formulario_version_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
            </p>
        </header>

        <script type="application/json" id="sgd-reg-esquema-inicial"><?= $esquemaJson ?></script>
        <script type="application/json" id="sgd-reg-datos-inicial"><?= $datosJson ?></script>
        <script type="application/json" id="sgd-reg-page-meta"><?= json_encode([
            'editable' => $editable && $canGuardar,
            'registroId' => $registroId,
            'empresaId' => $empresaId,
        ], JSON_UNESCAPED_UNICODE) ?></script>

        <form id="sgd-reg-diligenciar-form" method="post" action="<?= htmlspecialchars($formAction, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="_action" value="guardar">
            <input type="hidden" name="registro_id" value="<?= $registroId ?>">
            <input type="hidden" name="datos_json" id="sgd_reg_datos_json" value="">
            <input type="hidden" name="titulo" id="sgd_reg_titulo" value="<?= htmlspecialchars((string)($registro['titulo'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">

            <div id="sgd-reg-diligenciar-body" class="sgd-reg-dilig-body" aria-live="polite"></div>
        </form>

        <?php if ($editable && $canGuardar): ?>
            <form id="sgd-reg-cerrar-form" method="post" action="<?= htmlspecialchars($formAction, ENT_QUOTES, 'UTF-8') ?>" class="sgd-reg-hidden-form">
                <input type="hidden" name="_action" value="cerrar">
                <input type="hidden" name="registro_id" value="<?= $registroId ?>">
                <input type="hidden" name="datos_json" id="sgd_reg_datos_json_cerrar" value="">
            </form>
        <?php endif; ?>

        <?php if ($estado === 'cerrado' && !empty($registro['contenido_publicado_json'])): ?>
            <section class="sgd-panel sgd-reg-publicado-panel">
                <header class="sgd-panel-head">
                    <h3 class="sgd-panel-title">Contenido publicado (solo lectura)</h3>
                </header>
                <pre class="sgd-reg-json-preview"><?= htmlspecialchars(
                    json_encode($registro['contenido_publicado_json'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                    ENT_QUOTES,
                    'UTF-8'
                ) ?></pre>
            </section>
        <?php endif; ?>

        <script src="/js/sgd-bloque-config.js?v=<?= (int)$sgdBloqueCfgJsV ?>"></script>
        <script src="/js/sgd-checklist-config.js?v=<?= (int)$sgdChecklistJsV ?>"></script>
        <script src="/js/sgd-registros.js?v=<?= (int)$sgdRegJsV ?>"></script>
    <?php endif; ?>
</div>
