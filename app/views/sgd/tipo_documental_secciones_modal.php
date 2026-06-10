<?php
/** @var array<string, mixed> $tipo */
/** @var list<array<string, mixed>> $secciones */
/** @var array<int, string> $estados */
/** @var bool $canGuardar */
/** @var string $endpoint */

$tipoId = (int)$tipo['id'];
$estadosValidos = [
    'no_aplica' => 'No aplica',
    'aplica' => 'Aplica (obligatoria)',
    'opcional' => 'Opcional',
];
?>
<div class="sgd-tipo-secciones-modal modal-inner"
     data-endpoint="<?= htmlspecialchars($endpoint, ENT_QUOTES, 'UTF-8') ?>"
     data-can-guardar="<?= $canGuardar ? '1' : '0' ?>">

    <header class="modal-form-head">
        <h3 class="modal-form-title">Perfil de secciones</h3>
        <p class="modal-form-meta">
            Tipo: <strong><?= htmlspecialchars((string)$tipo['codigo'], ENT_QUOTES, 'UTF-8') ?></strong>
            — <?= htmlspecialchars((string)$tipo['nombre'], ENT_QUOTES, 'UTF-8') ?>
        </p>
        <p class="modal-form-help">
            Defina qué partes del documento <strong>aplican</strong>, <strong>no aplican</strong> u son <strong>opcionales</strong>
            (según contenido) para este tipo maestro.
        </p>
    </header>

    <form id="sgd-tipo-secciones-form" class="sgd-tipo-secciones-form">
        <fieldset <?= $canGuardar ? '' : 'disabled' ?>>
            <?php if ($secciones === []): ?>
                <p class="modal-form-alert">
                    No hay secciones en el catálogo. Importe la estructura documental desde Configuración SGD.
                </p>
            <?php else: ?>
                <table class="crud-table sgd-tipo-secciones-table">
                    <thead>
                        <tr>
                            <th>Sección</th>
                            <th>Clase</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($secciones as $sec): ?>
                        <?php
                        $sid = (int)$sec['id'];
                        $actual = $estados[$sid] ?? 'no_aplica';
                        ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars((string)$sec['nombre'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <br><code><?= htmlspecialchars((string)$sec['codigo'], ENT_QUOTES, 'UTF-8') ?></code>
                            </td>
                            <td><?= htmlspecialchars((string)$sec['clase'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <select name="estado_seccion[<?= $sid ?>]" class="form-input">
                                    <?php foreach ($estadosValidos as $val => $label): ?>
                                        <option value="<?= htmlspecialchars($val, ENT_QUOTES, 'UTF-8') ?>"
                                            <?= $actual === $val ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </fieldset>
    </form>

    <p id="sgdTipoSeccionesFeedback" class="sgd-tipo-padres-feedback" aria-live="polite"></p>

    <footer class="sgd-tipo-padres-footer">
        <?php if ($canGuardar && $secciones !== []): ?>
            <button type="submit" form="sgd-tipo-secciones-form" class="sgd-doc-btn sgd-doc-btn-primary">💾 Guardar</button>
        <?php endif; ?>
        <button type="button" class="btn-cancel" onclick="closeModalGod()">Cerrar</button>
    </footer>
</div>

<script>
(function () {
    var root = document.querySelector('.sgd-tipo-secciones-modal');
    if (!root || root.dataset.canGuardar !== '1') return;
    var form = document.getElementById('sgd-tipo-secciones-form');
    var feedback = document.getElementById('sgdTipoSeccionesFeedback');
    if (!form) return;
    form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        fetch(root.dataset.endpoint, { method: 'POST', body: new FormData(form), credentials: 'same-origin' })
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
