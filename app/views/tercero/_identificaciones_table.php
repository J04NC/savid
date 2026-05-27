<?php
/** @var list<array<string, mixed>> $filas */
/** @var bool $esSuperAdmin */

$esSuperAdmin = $esSuperAdmin ?? (
    class_exists('PermisoService')
        ? PermisoService::isSuperAdminSession()
        : (!empty($_SESSION['es_super_admin']) || (int)($_SESSION['rol_id'] ?? 0) === 1)
);

if (empty($filas)) {
    echo '<p class="tercero-ident-empty">Sin identificaciones registradas para este tercero.</p>';
    return;
}
?>
<div class="tercero-ident-table-wrap">
    <table class="tercero-ident-table">
        <thead>
            <tr>
                <th>Tipo</th>
                <th>Número</th>
                <th>DV</th>
                <th class="tercero-ident-th-center">Principal</th>
                <th class="tercero-ident-th-center">Estado</th>
                <th>Expedición</th>
                <th>Vencimiento</th>
                <th>Observación</th>
                <?php if ($esSuperAdmin): ?>
                <th class="tercero-ident-th-actions">Acciones</th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($filas as $r): ?>
            <?php
            $activo = (int)($r['estado_id'] ?? 0) === 1;
            $esPrincipal = !empty($r['principal']);
            $rowJson = htmlspecialchars(json_encode([
                'id' => (int)$r['id'],
                'tipodocumento_id' => (int)$r['tipodocumento_id'],
                'numero' => (string)($r['numero'] ?? ''),
                'dv' => $r['dv'] !== null && $r['dv'] !== '' ? (string)$r['dv'] : '',
                'principal' => $esPrincipal ? 1 : 0,
                'estado_id' => (int)($r['estado_id'] ?? 1),
                'fecha_expedicion' => (string)($r['fecha_expedicion'] ?? ''),
                'fecha_vencimiento' => (string)($r['fecha_vencimiento'] ?? ''),
                'observacion' => (string)($r['observacion'] ?? ''),
                'tipo_documento_nombre' => (string)($r['tipo_documento_nombre'] ?? ''),
            ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
            ?>
            <tr class="<?= $activo ? '' : 'tercero-ident-row-inactive' ?>"
                data-ident-id="<?= (int)$r['id'] ?>"
                data-row-json="<?= $rowJson ?>">
                <td><?= htmlspecialchars((string)($r['tipo_documento_nombre'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string)($r['numero'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string)($r['dv'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                <td class="tercero-ident-td-center">
                    <?php if ($esPrincipal): ?>
                    <span class="tercero-ident-badge tercero-ident-badge-principal" title="Identificación principal">★</span>
                    <?php else: ?>
                    <span class="tercero-ident-muted">—</span>
                    <?php endif; ?>
                </td>
                <td class="tercero-ident-td-center">
                    <span class="tercero-ident-badge <?= $activo ? 'tercero-ident-badge-active' : 'tercero-ident-badge-inactive' ?>">
                        <?= $activo ? 'Activo' : 'Inactivo' ?>
                    </span>
                </td>
                <td><?= htmlspecialchars((string)($r['fecha_expedicion'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string)($r['fecha_vencimiento'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                <td class="tercero-ident-td-obs"><?= htmlspecialchars((string)($r['observacion'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <?php if ($esSuperAdmin): ?>
                <td class="tercero-ident-td-actions">
                    <button type="button" class="tercero-ident-icon-btn tercero-ident-btn-edit" title="Editar">✏️</button>
                    <?php if (!$esPrincipal): ?>
                    <button type="button" class="tercero-ident-icon-btn tercero-ident-btn-principal" title="Marcar como principal">★</button>
                    <?php endif; ?>
                    <button type="button" class="tercero-ident-icon-btn tercero-ident-btn-toggle"
                            title="<?= $activo ? 'Inactivar' : 'Activar' ?>">
                        <?= $activo ? '🚫' : '✅' ?>
                    </button>
                    <button type="button" class="tercero-ident-icon-btn tercero-ident-btn-delete" title="Eliminar">🗑</button>
                </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
