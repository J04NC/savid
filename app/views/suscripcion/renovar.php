<div class="modal-form-head">
    <h3 class="modal-form-title">Renovar suscripción</h3>
</div>

<?php if (empty($preview['success'])): ?>

<p class="modal-form-alert"><?= htmlspecialchars((string)($preview['error'] ?? 'No se pudo cargar la información.'), ENT_QUOTES, 'UTF-8') ?></p>
<div class="role-footer">
    <button type="button" class="btn-cancel" onclick="closeModalGod()">Cerrar</button>
</div>

<?php else: ?>

<p><strong>Empresa:</strong> <?= htmlspecialchars((string)$preview['empresa_nombre'], ENT_QUOTES, 'UTF-8') ?></p>
<p><strong>Plan actual:</strong> <?= htmlspecialchars((string)$preview['plan_nombre'], ENT_QUOTES, 'UTF-8') ?></p>
<p><strong>Nueva vigencia:</strong>
    <?= htmlspecialchars(date('d/m/Y', strtotime((string)$preview['fecha_inicio'])), ENT_QUOTES, 'UTF-8') ?>
    &rarr;
    <?= htmlspecialchars(date('d/m/Y', strtotime((string)$preview['fecha_fin'])), ENT_QUOTES, 'UTF-8') ?>
</p>
<p style="margin-top:12px; font-size:13px; opacity:.8;">
    Esto crea una suscripción nueva con el mismo plan; la suscripción anterior no se modifica.
</p>

<p id="suscripcionRenovarStatus" class="usuario-perm-save-status" aria-live="polite"></p>

<div class="role-footer">
    <button type="button" id="btnConfirmarRenovacion" class="btn-save">Confirmar renovación</button>
    <button type="button" class="btn-cancel" onclick="closeModalGod()">Cancelar</button>
</div>

<script>
(function () {
    const SUSCRIPCION_ID = <?= (int)$suscripcionId ?>;
    const btn = document.getElementById('btnConfirmarRenovacion');
    const status = document.getElementById('suscripcionRenovarStatus');
    if (!btn) return;

    btn.addEventListener('click', function () {
        btn.disabled = true;
        if (status) status.textContent = 'Guardando…';

        fetch('?url=suscripcion/renovarConfirmar/' + SUSCRIPCION_ID, {
            method: 'POST',
            credentials: 'same-origin',
        })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j.ok) {
                    if (status) status.textContent = '';
                    alert(j.error || 'No se pudo renovar la suscripción.');
                    btn.disabled = false;
                    return;
                }
                alert(j.message || 'Suscripción renovada correctamente.');
                location.reload();
            })
            .catch(function () {
                if (status) status.textContent = '';
                alert('Error de conexión.');
                btn.disabled = false;
            });
    });
})();
</script>

<?php endif; ?>
