<?php
/** @var array $scope */
/** @var int|null $empresaId */
/** @var int $documentoId */
/** @var array<string, mixed>|null $documento */
/** @var string $codigoDisplay */
/** @var list<array<string, mixed>> $registros */
/** @var bool $canGuardar */
/** @var bool $canEliminar */

$empresaQuery = $empresaId ? '&empresa_id=' . (int)$empresaId : '';
$listUrl = '?url=sgd/registros' . $empresaQuery;
$docFilterUrl = $documentoId > 0 ? $listUrl . '&documento_id=' . $documentoId : $listUrl;

$estadoLabels = [
    'borrador' => 'Borrador',
    'en_firma' => 'En firma',
    'firmado' => 'Firmado',
    'cerrado' => 'Cerrado',
    'anulado' => 'Anulado',
];
?>
<div class="module-container sgd-registros-page">
    <?php $filterUrl = 'sgd/registros'; require BASE_PATH . '/app/views/sgd/_empresa_filter.php'; ?>

    <?php if (empty($empresaId)): ?>
        <p class="field-note sgd-page-empty">Seleccione la empresa en el filtro superior para ver registros operativos.</p>
    <?php else: ?>

        <div class="crud-toolbar sgd-reg-toolbar">
            <div class="sgd-reg-toolbar-actions">
                <?php if ($documentoId > 0 && $canGuardar): ?>
                    <form method="post" action="<?= htmlspecialchars($listUrl, ENT_QUOTES, 'UTF-8') ?>" class="sgd-reg-inline-form">
                        <input type="hidden" name="_action" value="crear">
                        <input type="hidden" name="documento_id" value="<?= (int)$documentoId ?>">
                        <button type="submit" class="sgd-doc-btn sgd-doc-btn-primary" title="Nuevo registro diligenciado">➕ Nuevo registro</button>
                    </form>
                <?php endif; ?>
                <?php if ($documentoId > 0): ?>
                    <a href="<?= htmlspecialchars('?url=sgd/documentos' . $empresaQuery . '&id=' . $documentoId, ENT_QUOTES, 'UTF-8') ?>"
                       class="sgd-doc-btn" title="Volver al documento">↩ Documento</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($documento): ?>
            <p class="field-note sgd-page-lead">
                Formato <strong><?= htmlspecialchars($codigoDisplay, ENT_QUOTES, 'UTF-8') ?></strong>
                — <?= htmlspecialchars((string)($documento['nombre'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
            </p>
        <?php else: ?>
            <p class="field-note sgd-page-lead">
                Listado de instancias diligenciadas. Para crear un registro, abra un formato operativo desde el listado maestro.
            </p>
        <?php endif; ?>

        <section class="sgd-panel">
            <header class="sgd-panel-head">
                <h3 class="sgd-panel-title">Registros</h3>
                <span class="sgd-doc-count"><?= count($registros) ?> registro(s)</span>
            </header>
            <div class="sgd-doc-table-wrap">
                <table class="sgd-doc-table savid-datatable" data-dt-page-length="25" data-dt-buttons="false">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Título</th>
                            <?php if ($documentoId <= 0): ?>
                                <th>Documento</th>
                            <?php endif; ?>
                            <th>Arquetipo</th>
                            <th>Estado</th>
                            <th>Creado</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($registros === []): ?>
                            <tr>
                                <td colspan="<?= $documentoId > 0 ? 6 : 7 ?>" class="sgd-doc-empty">No hay registros para mostrar.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($registros as $reg): ?>
                                <?php
                                $estado = (string)($reg['estado'] ?? 'borrador');
                                $diligUrl = '?url=sgd/registrosDiligenciar' . $empresaQuery . '&id=' . (int)$reg['id'];
                                ?>
                                <tr>
                                    <td><?= (int)$reg['id'] ?></td>
                                    <td><?= htmlspecialchars((string)($reg['titulo'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    <?php if ($documentoId <= 0): ?>
                                        <td>
                                            <a href="<?= htmlspecialchars($listUrl . '&documento_id=' . (int)$reg['documento_id'], ENT_QUOTES, 'UTF-8') ?>">
                                                <?= htmlspecialchars((string)($reg['documento_nombre'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                            </a>
                                        </td>
                                    <?php endif; ?>
                                    <td><span class="sgd-doc-badge"><?= htmlspecialchars((string)($reg['arquetipo'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span></td>
                                    <td><?= htmlspecialchars($estadoLabels[$estado] ?? $estado, ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars(substr((string)($reg['created_at'] ?? ''), 0, 16), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <a href="<?= htmlspecialchars($diligUrl, ENT_QUOTES, 'UTF-8') ?>" class="sgd-doc-btn sgd-doc-btn-sm">
                                            <?= in_array($estado, ['borrador', 'en_firma'], true) ? 'Diligenciar' : 'Ver' ?>
                                        </a>
                                        <?php if ($canEliminar && $estado !== 'anulado' && $estado !== 'cerrado'): ?>
                                            <form method="post" action="<?= htmlspecialchars($listUrl, ENT_QUOTES, 'UTF-8') ?>" class="sgd-reg-inline-form"
                                                  onsubmit="return confirm('¿Anular este registro?');">
                                                <input type="hidden" name="_action" value="anular">
                                                <input type="hidden" name="registro_id" value="<?= (int)$reg['id'] ?>">
                                                <?php if ($documentoId > 0): ?>
                                                    <input type="hidden" name="documento_id" value="<?= $documentoId ?>">
                                                <?php endif; ?>
                                                <button type="submit" class="sgd-doc-btn sgd-doc-btn-danger sgd-doc-btn-sm">Anular</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
</div>
