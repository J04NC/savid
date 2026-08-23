<?php
/** @var array{id:int, razon_social:string} $empresa */
/** @var list<array{id: int, nombre: string, descripcion: string, habilitado: bool}> $roles */

$empresaId = (int)$empresa['id'];
$razonSocial = (string)$empresa['razon_social'];
$endpoint = '?url=empresa/roles/' . $empresaId;
?>

<div class="empresa-items-modal empresa-roles-modal modal-inner" data-endpoint="<?= htmlspecialchars($endpoint, ENT_QUOTES, 'UTF-8') ?>">

    <header class="modal-form-head">
        <h3 class="modal-form-title">Roles habilitados</h3>
        <p class="modal-form-meta">
            Empresa: <strong><?= htmlspecialchars($razonSocial, ENT_QUOTES, 'UTF-8') ?></strong>
            <span class="empresa-usuarios-meta-id">(ID <?= $empresaId ?>)</span>
        </p>
        <p class="modal-form-help">
            Solo los roles marcados aquí aparecerán en la pantalla de Roles y podrán asignarse a
            usuarios de esta empresa. Crear, renombrar o eliminar un rol sigue siendo exclusivo del
            superadministrador — desde aquí solo eliges cuáles de los roles ya existentes puede usar
            esta empresa.
        </p>
    </header>

    <p id="empresaRolesFeedback" class="empresa-usuarios-feedback" aria-live="polite"></p>

    <div id="empresaRolesList" class="empresa-roles-list">
        <?php foreach ($roles as $rol): ?>
            <label class="empresa-roles-item">
                <input type="checkbox" class="empresa-roles-check" value="<?= (int)$rol['id'] ?>" <?= $rol['habilitado'] ? 'checked' : '' ?>>
                <span class="empresa-roles-item-body">
                    <span class="empresa-roles-item-nombre"><?= htmlspecialchars($rol['nombre'], ENT_QUOTES, 'UTF-8') ?></span>
                    <?php if ($rol['descripcion'] !== ''): ?>
                        <span class="empresa-roles-item-desc"><?= htmlspecialchars($rol['descripcion'], ENT_QUOTES, 'UTF-8') ?></span>
                    <?php endif; ?>
                </span>
            </label>
        <?php endforeach; ?>
    </div>

    <footer class="empresa-usuarios-footer">
        <button type="button" class="btn-cancel" onclick="closeModalGod()">Cancelar</button>
        <button type="button" id="empresaRolesGuardar" class="btn-save">💾 Guardar</button>
    </footer>
</div>

<script>
(function () {
    const root = document.querySelector(".empresa-roles-modal");
    if (!root) return;

    const endpoint = root.dataset.endpoint;
    const listEl = root.querySelector("#empresaRolesList");
    const feedbackEl = root.querySelector("#empresaRolesFeedback");
    const guardarBtn = root.querySelector("#empresaRolesGuardar");

    function setFeedback(msg, isError) {
        feedbackEl.textContent = msg || "";
        feedbackEl.classList.toggle("empresa-usuarios-feedback-error", !!isError);
        feedbackEl.classList.toggle("empresa-usuarios-feedback-ok", !!msg && !isError);
    }

    guardarBtn.addEventListener("click", function () {
        const rolIds = Array.from(listEl.querySelectorAll(".empresa-roles-check:checked")).map(chk => chk.value);

        guardarBtn.disabled = true;
        const original = guardarBtn.innerHTML;
        guardarBtn.innerHTML = "💾 Guardando…";

        const fd = new FormData();
        rolIds.forEach(id => fd.append("rol_ids[]", id));

        fetch(endpoint, {
            method: "POST",
            body: fd,
            credentials: "same-origin",
            headers: { "ngrok-skip-browser-warning": "true" }
        })
            .then(r => {
                if (r.status === 403) {
                    throw new Error("SESSION_OR_TOKEN");
                }
                return r.text().then(text => {
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        const preview = text.replace(/\s+/g, " ").trim().slice(0, 120);
                        throw new Error("BAD_JSON: " + preview);
                    }
                });
            })
            .then(data => {
                guardarBtn.disabled = false;
                guardarBtn.innerHTML = original;
                if (!data || !data.success) {
                    setFeedback(data && data.message ? data.message : "No se pudo guardar.", true);
                    return;
                }
                setFeedback("Roles habilitados guardados. Los usuarios de esta empresa verán el cambio en su próxima acción.");
            })
            .catch(err => {
                guardarBtn.disabled = false;
                guardarBtn.innerHTML = original;
                const errMsg = err && err.message ? err.message : "";
                let msg = "Error de conexión.";
                if (errMsg === "SESSION_OR_TOKEN") {
                    msg = "Tu sesión o el token de seguridad expiró. Recarga la página completa (no solo el modal) e intenta de nuevo.";
                } else if (errMsg.indexOf("BAD_JSON:") === 0) {
                    msg = "Respuesta inesperada del servidor: " + errMsg.slice("BAD_JSON:".length).trim();
                }
                setFeedback(msg, true);
            });
    });
})();
</script>
