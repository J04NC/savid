<?php
/** @var array{id:int, razon_social:string} $empresa */
/** @var string|null $subdominioActual */

$empresaId = (int)$empresa['id'];
$razonSocial = (string)$empresa['razon_social'];
$endpoint = '?url=empresa/subdominio/' . $empresaId;
?>

<div class="empresa-subdominio-modal modal-inner" data-endpoint="<?= htmlspecialchars($endpoint, ENT_QUOTES, 'UTF-8') ?>">

    <header class="modal-form-head">
        <h3 class="modal-form-title">Subdominio exclusivo</h3>
        <p class="modal-form-meta">
            Empresa: <strong><?= htmlspecialchars($razonSocial, ENT_QUOTES, 'UTF-8') ?></strong>
            <span class="empresa-usuarios-meta-id">(ID <?= $empresaId ?>)</span>
        </p>
        <p class="modal-form-help">
            Al asignar un subdominio, los usuarios de esta empresa solo podrán iniciar sesión desde
            ese enlace (no desde el genérico). Se crea el registro DNS automáticamente — puede tardar
            unos minutos en propagarse. Esta acción no se puede deshacer desde aquí una vez creada.
        </p>
    </header>

    <p id="empresaSubdominioFeedback" class="empresa-usuarios-feedback" aria-live="polite"></p>

    <?php if ($subdominioActual !== null): ?>
        <?php $baseDomain = (new EmpresaSubdominioService())->baseDomain(); ?>
        <p class="modal-form-help">
            Subdominio ya asignado: <strong>https://<?= htmlspecialchars($subdominioActual, ENT_QUOTES, 'UTF-8') ?><?= $baseDomain !== '' ? '.' . htmlspecialchars($baseDomain, ENT_QUOTES, 'UTF-8') : '' ?></strong>
        </p>
    <?php else: ?>
        <div class="form-group">
            <label for="empresaSubdominioInput">Subdominio (solo minúsculas, números y guiones)</label>
            <input type="text" id="empresaSubdominioInput" class="form-input" placeholder="clientea" maxlength="63">
        </div>
    <?php endif; ?>

    <footer class="empresa-usuarios-footer">
        <button type="button" class="btn-cancel" onclick="closeModalGod()">Cerrar</button>
        <?php if ($subdominioActual === null): ?>
            <button type="button" id="empresaSubdominioCrear" class="btn-save">🌐 Crear subdominio</button>
        <?php endif; ?>
    </footer>
</div>

<script>
(function () {
    const root = document.querySelector(".empresa-subdominio-modal");
    if (!root) return;

    const endpoint = root.dataset.endpoint;
    const feedbackEl = root.querySelector("#empresaSubdominioFeedback");
    const crearBtn = root.querySelector("#empresaSubdominioCrear");
    const input = root.querySelector("#empresaSubdominioInput");

    function setFeedback(msg, isError) {
        feedbackEl.textContent = msg || "";
        feedbackEl.classList.toggle("empresa-usuarios-feedback-error", !!isError);
        feedbackEl.classList.toggle("empresa-usuarios-feedback-ok", !!msg && !isError);
    }

    if (!crearBtn) return;

    crearBtn.addEventListener("click", function () {
        const valor = (input.value || "").trim();
        if (!valor) {
            setFeedback("Escriba un subdominio.", true);
            return;
        }

        crearBtn.disabled = true;
        const original = crearBtn.innerHTML;
        crearBtn.innerHTML = "🌐 Creando…";

        const fd = new FormData();
        fd.append("subdominio", valor);

        fetch(endpoint, { method: "POST", body: fd, credentials: "same-origin" })
            .then(r => r.json())
            .then(data => {
                crearBtn.disabled = false;
                crearBtn.innerHTML = original;
                if (!data || !data.success) {
                    setFeedback((data && data.error) || "No se pudo crear el subdominio.", true);
                    return;
                }
                setFeedback(data.message || "Subdominio creado.");
                crearBtn.remove();
                input.setAttribute("readonly", "readonly");
            })
            .catch(() => {
                crearBtn.disabled = false;
                crearBtn.innerHTML = original;
                setFeedback("Error de conexión.", true);
            });
    });
})();
</script>
