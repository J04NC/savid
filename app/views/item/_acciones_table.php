<?php
/** @var array<int, array<string, mixed>> $acciones */
/** @var bool $esSuperAdmin */

$esSuperAdmin = $esSuperAdmin ?? (
    !empty($_SESSION['es_super_admin']) || (int)($_SESSION['rol_id'] ?? 0) === 1
);

if (empty($acciones)) {
    echo '<p class="item-acciones-empty">No hay acciones registradas en el catálogo.</p>';
    return;
}
?>
<div class="item-acciones-table-wrap">
    <table class="item-acciones-table">
        <thead>
            <tr>
                <th>Acción</th>
                <th>Código</th>
                <th>Descripción</th>
                <th class="item-acciones-th-center">Vínculo</th>
                <th class="item-acciones-th-actions">Operaciones</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($acciones as $r): ?>
            <?php
            $linked = isset($r['item_accion_id']) && $r['item_accion_id'] !== null && $r['item_accion_id'] !== '';
            $activo = $linked && (int)$r['link_estado_id'] === 1;
            $codigo = trim((string)($r['accion_codigo'] ?? '') . (($r['codigo'] ?? '') !== '' ? ' / ' . $r['codigo'] : ''), ' /');
            ?>
            <tr class="<?= $linked && !$activo ? 'item-acciones-row-inactive' : '' ?>"
                data-accion-id="<?= (int)$r['accion_id'] ?>">
                <td><?= htmlspecialchars((string)($r['icono'] ?? '⚙️') . ' ' . (string)($r['nombre'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td><code class="item-acciones-code"><?= htmlspecialchars($codigo !== '' ? $codigo : '—', ENT_QUOTES, 'UTF-8') ?></code></td>
                <td class="item-acciones-td-desc"><?= htmlspecialchars((string)($r['descripcion'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td class="item-acciones-td-center">
                    <?php if ($linked): ?>
                    <span class="item-acciones-badge <?= $activo ? 'item-acciones-badge-active' : 'item-acciones-badge-inactive' ?>">
                        <?= $activo ? 'Activo' : 'Inactivo' ?>
                    </span>
                    <?php else: ?>
                    <span class="item-acciones-badge item-acciones-badge-none">Sin vínculo</span>
                    <?php endif; ?>
                </td>
                <td class="item-acciones-td-actions">
                    <?php if ($linked): ?>
                    <button type="button" class="item-acciones-icon-btn item-acciones-toggle-btn"
                            data-accion-id="<?= (int)$r['accion_id'] ?>"
                            title="<?= $activo ? 'Inactivar vínculo' : 'Activar vínculo' ?>">
                        <?= $activo ? '🚫' : '✅' ?>
                    </button>
                    <?php else: ?>
                    <button type="button" class="btn-save item-acciones-link-btn"
                            data-accion-id="<?= (int)$r['accion_id'] ?>">Vincular</button>
                    <?php endif; ?>
                    <?php if ($esSuperAdmin && $linked): ?>
                    <button type="button" class="item-acciones-icon-btn item-acciones-unlink-btn"
                            data-accion-id="<?= (int)$r['accion_id'] ?>"
                            title="Quitar vínculo">🗑</button>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
