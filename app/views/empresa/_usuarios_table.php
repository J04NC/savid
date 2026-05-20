<?php
/** @var array<int, array<string, mixed>> $usuarios */
if (empty($usuarios)) {
    echo '<p style="color:#666;">Sin usuarios vinculados.</p>';
    return;
}
?>
<table style="width:100%; border-collapse:collapse; font-size:13px;">
    <thead>
        <tr style="background:#eceff1;">
            <th style="text-align:left; padding:.4rem;">Username</th>
            <th style="text-align:left; padding:.4rem;">Nombre / Razón</th>
            <th style="text-align:left; padding:.4rem;">Doc/NIT</th>
            <th style="text-align:left; padding:.4rem;">Vínculo</th>
            <th style="text-align:left; padding:.4rem;">Acción</th>
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
        $estadoActivo = (int)$r['link_estado_id'] === 1;
        ?>
        <tr>
            <td style="padding:.35rem .4rem; border-bottom:1px dashed #eee;"><?= htmlspecialchars((string)$r['username'], ENT_QUOTES, 'UTF-8') ?></td>
            <td style="padding:.35rem .4rem; border-bottom:1px dashed #eee;"><?= htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8') ?></td>
            <td style="padding:.35rem .4rem; border-bottom:1px dashed #eee;"><?= htmlspecialchars($doc, ENT_QUOTES, 'UTF-8') ?></td>
            <td style="padding:.35rem .4rem; border-bottom:1px dashed #eee;">
                <span style="color:<?= $estadoActivo ? '#2e7d32' : '#b71c1c' ?>; font-weight:600;">
                    <?= $estadoActivo ? 'Activo' : 'Inactivo' ?>
                </span>
            </td>
            <td style="padding:.35rem .4rem; border-bottom:1px dashed #eee;">
                <button type="button" class="empresa-usuarios-toggle-btn" data-uid="<?= (int)$r['id'] ?>"
                        style="padding:.2rem .55rem; border:1px solid #455a64; background:#fff; color:#37474f; border-radius:6px; cursor:pointer; font-size:12px;">
                    <?= $estadoActivo ? 'Inactivar' : 'Activar' ?>
                </button>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
