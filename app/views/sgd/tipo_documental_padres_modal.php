<?php
/** @var array<string, mixed> $tipo */
/** @var list<array<string, mixed>> $tipos */
/** @var list<int> $padresPermitidosIds */
/** @var bool $canGuardar */
/** @var string $endpoint */

$tipoId = (int)$tipo['id'];
$padresSet = array_fill_keys($padresPermitidosIds, true);
?>
<div class="sgd-tipo-padres-modal modal-inner"
     data-endpoint="<?= htmlspecialchars($endpoint, ENT_QUOTES, 'UTF-8') ?>"
     data-tipo-id="<?= $tipoId ?>"
     data-can-guardar="<?= $canGuardar ? '1' : '0' ?>">

    <header class="modal-form-head">
        <h3 class="modal-form-title">Padres permitidos</h3>
        <p class="modal-form-meta">
            Tipo: <strong><?= htmlspecialchars((string)$tipo['codigo'], ENT_QUOTES, 'UTF-8') ?></strong>
            — <?= htmlspecialchars((string)$tipo['nombre'], ENT_QUOTES, 'UTF-8') ?>
        </p>
        <p class="modal-form-help">
            Indique qué otros tipos documentales pueden ser <strong>documento padre</strong> al crear un registro de este tipo
            (por ejemplo, formatos hijos bajo manuales o procedimientos).
        </p>
    </header>

    <form id="sgd-tipo-padres-form" class="sgd-tipo-padres-form">
        <fieldset <?= $canGuardar ? '' : 'disabled' ?>>
            <legend class="sgd-tipo-padres-legend">Padres permitidos</legend>
            <?php if ($tipos === []): ?>
                <p class="modal-form-alert">No hay tipos documentales en esta empresa.</p>
            <?php else: ?>
                <ul class="sgd-tipo-padres-list">
                    <?php foreach ($tipos as $candidato): ?>
                        <?php
                        $cid = (int)$candidato['id'];
                        if ($cid === $tipoId) {
                            continue;
                        }
                        $checked = isset($padresSet[$cid]);
                        $inputId = 'sgd_tipo_padre_' . $cid;
                        ?>
                        <li class="sgd-tipo-padres-item">
                            <label class="sgd-tipo-padres-label" for="<?= $inputId ?>">
                                <input
                                    type="checkbox"
                                    id="<?= $inputId ?>"
                                    name="padre_tipo_ids[]"
                                    value="<?= $cid ?>"
                                    <?= $checked ? 'checked' : '' ?>
                                >
                                <span class="sgd-tipo-padres-codigo"><?= htmlspecialchars((string)$candidato['codigo'], ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="sgd-tipo-padres-nombre"><?= htmlspecialchars((string)$candidato['nombre'], ENT_QUOTES, 'UTF-8') ?></span>
                            </label>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </fieldset>
    </form>

    <p id="sgdTipoPadresFeedback" class="sgd-tipo-padres-feedback" aria-live="polite"></p>

    <footer class="sgd-tipo-padres-footer">
        <?php if ($canGuardar): ?>
            <button type="submit" form="sgd-tipo-padres-form" class="sgd-doc-btn sgd-doc-btn-primary" id="sgdTipoPadresSave">💾 Guardar</button>
        <?php endif; ?>
        <button type="button" class="btn-cancel" onclick="closeModalGod()">Cerrar</button>
    </footer>
</div>

<script>
(function () {
    var root = document.querySelector('.sgd-tipo-padres-modal');
    if (!root || root.dataset.canGuardar !== '1') return;

    var form = document.getElementById('sgd-tipo-padres-form');
    var feedback = document.getElementById('sgdTipoPadresFeedback');
    if (!form) return;

    form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var fd = new FormData(form);
        fetch(root.dataset.endpoint, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!feedback) return;
                feedback.textContent = data.message || '';
                feedback.classList.toggle('sgd-tipo-padres-feedback-ok', !!data.success);
                feedback.classList.toggle('sgd-tipo-padres-feedback-error', !data.success);
            })
            .catch(function () {
                if (feedback) {
                    feedback.textContent = 'Error al guardar.';
                    feedback.classList.add('sgd-tipo-padres-feedback-error');
                }
            });
    });
})();
</script>
