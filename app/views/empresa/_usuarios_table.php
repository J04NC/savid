<?php
/** @var array<int, array<string, mixed>> $usuarios */
/** @var bool $esSuperAdmin */

$esSuperAdmin = $esSuperAdmin ?? (
    !empty($_SESSION['es_super_admin']) || (int)($_SESSION['rol_id'] ?? 0) === 1
);

if (empty($usuarios)) {
    echo '<p class="empresa-usuarios-empty">Sin usuarios vinculados a esta empresa.</p>';
    return;
}
?>
<div class="empresa-usuarios-table-wrap">
    <table class="empresa-usuarios-table">
        <thead>
            <tr>
                <th>Username</th>
                <th>Nombre / Razón</th>
                <th>Doc/NIT</th>
                <th class="empresa-usuarios-th-center">Sedes activas</th>
                <th class="empresa-usuarios-th-center">Vínculo</th>
                <th class="empresa-usuarios-th-actions">Acciones</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($usuarios as $r): ?>
            <?php
            $nombre = trim((string)($r['razon_social'] ?? '')) !== ''
                ? (string)$r['razon_social']
                : trim((string)($r['nombres'] ?? '') . ' ' . (string)($r['apellidos'] ?? ''));
            if ($nombre === '') {
                $nombre = '—';
            }
            $doc = (string)($r['nit_or_doc'] ?? '—');
            if ($doc === '') {
                $doc = '—';
            }
            $vinculoEmpresa = (int)($r['vinculo_empresa'] ?? 0) === 1;
            $estadoActivo = $vinculoEmpresa && (int)$r['link_estado_id'] === 1;
            $sedesActivas = (int)($r['sedes_activas'] ?? 0);
            ?>
            <tr class="<?= $estadoActivo || !$vinculoEmpresa ? '' : 'empresa-usuarios-row-inactive' ?>"
                data-uid="<?= (int)$r['id'] ?>"
                data-vinculo-empresa="<?= $vinculoEmpresa ? '1' : '0' ?>">
                <td><?= htmlspecialchars((string)$r['username'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($doc, ENT_QUOTES, 'UTF-8') ?></td>
                <td class="empresa-usuarios-td-center">
                    <span class="empresa-usuarios-sedes-count" title="Sedes de esta empresa con acceso activo">
                        <?= $sedesActivas ?>
                    </span>
                </td>
                <td class="empresa-usuarios-td-center">
                    <?php if (!$vinculoEmpresa): ?>
                    <span class="empresa-usuarios-badge empresa-usuarios-badge-solo-sede" title="Asignado en sede(s) sin vínculo usuario_empresa">
                        Solo sede
                    </span>
                    <?php else: ?>
                    <span class="empresa-usuarios-badge <?= $estadoActivo ? 'empresa-usuarios-badge-active' : 'empresa-usuarios-badge-inactive' ?>">
                        <?= $estadoActivo ? 'Activo' : 'Inactivo' ?>
                    </span>
                    <?php endif; ?>
                </td>
                <td class="empresa-usuarios-td-actions">
                    <?php if ($vinculoEmpresa): ?>
                    <button type="button" class="empresa-usuarios-icon-btn empresa-usuarios-toggle-btn"
                            data-uid="<?= (int)$r['id'] ?>"
                            title="<?= $estadoActivo ? 'Inactivar vínculo' : 'Activar vínculo' ?>">
                        <?= $estadoActivo ? '🚫' : '✅' ?>
                    </button>
                    <?php endif; ?>
                    <?php if ($esSuperAdmin): ?>
                    <button type="button" class="empresa-usuarios-icon-btn empresa-usuarios-delete-btn"
                            data-uid="<?= (int)$r['id'] ?>"
                            title="Eliminar vínculo con la empresa">🗑</button>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
