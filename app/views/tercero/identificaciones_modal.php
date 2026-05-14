<?php
/** @var int $tid */
/** @var list<array<string, mixed>> $filas */
/** @var bool $tablaExiste */
?>
<div class="modal-inner tercero-ident-modal" style="padding:16px; max-height:75vh; overflow:auto;">
    <h3 style="margin:0 0 8px;">Identificaciones del tercero</h3>
    <p style="margin:0 0 12px; font-size:13px; opacity:0.85;">Tercero ID: <strong><?= (int)$tid ?></strong></p>

    <?php if (!$tablaExiste): ?>
        <p style="color:#c00;">No existe la tabla <code>terceroidentificacion</code> en la base de datos o no se pudo consultar. Ejecute el DDL correspondiente.</p>
    <?php elseif ($filas === []): ?>
        <p style="opacity:0.9;">Este tercero aún no tiene filas en <strong>terceroidentificacion</strong>. El alta/edición masiva se puede enlazar en el siguiente paso.</p>
    <?php else: ?>
        <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; font-size:13px;">
                <thead>
                <tr>
                    <th style="text-align:left; padding:6px;">Tipo</th>
                    <th style="text-align:left; padding:6px;">Número</th>
                    <th style="text-align:left; padding:6px;">DV</th>
                    <th style="text-align:left; padding:6px;">Principal</th>
                    <th style="text-align:left; padding:6px;">Estado</th>
                    <th style="text-align:left; padding:6px;">Expedición</th>
                    <th style="text-align:left; padding:6px;">Vencimiento</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($filas as $r): ?>
                    <tr>
                        <td style="padding:6px;"><?= htmlspecialchars((string)($r['tipo_documento_nombre'] ?? ('#' . (string)($r['tipodocumento_id'] ?? ''))), ENT_QUOTES, 'UTF-8') ?></td>
                        <td style="padding:6px;"><?= htmlspecialchars((string)($r['numero'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td style="padding:6px;"><?= htmlspecialchars((string)($r['dv'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td style="padding:6px;"><?= !empty($r['principal']) ? 'Sí' : 'No' ?></td>
                        <td style="padding:6px;"><?= htmlspecialchars((string)($r['estado_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td style="padding:6px;"><?= htmlspecialchars((string)($r['fecha_expedicion'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td style="padding:6px;"><?= htmlspecialchars((string)($r['fecha_vencimiento'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <p style="margin-top:16px;">
        <button type="button" class="btn-accion" onclick="typeof closeModalGod==='function'&&closeModalGod()">Cerrar</button>
    </p>
</div>
