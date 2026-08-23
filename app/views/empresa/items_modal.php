<?php
/** @var array{id:int, razon_social:string} $empresa */
/** @var array<string, array{modulo_id: int, items: list<array{id: int, nombre: string, habilitado: bool}>}> $grupos */

$empresaId = (int)$empresa['id'];
$razonSocial = (string)$empresa['razon_social'];
$endpoint = '?url=empresa/items/' . $empresaId;
?>

<div class="empresa-items-modal modal-inner" data-endpoint="<?= htmlspecialchars($endpoint, ENT_QUOTES, 'UTF-8') ?>">

    <header class="modal-form-head">
        <h3 class="modal-form-title">Ítems habilitados</h3>
        <p class="modal-form-meta">
            Empresa: <strong><?= htmlspecialchars($razonSocial, ENT_QUOTES, 'UTF-8') ?></strong>
            <span class="empresa-usuarios-meta-id">(ID <?= $empresaId ?>)</span>
        </p>
        <p class="modal-form-help">
            Solo los ítems marcados aquí aparecerán en el menú de esta empresa y podrán asignarse
            en las pantallas de permisos de rol/usuario. Los ítems sin marcar quedan completamente
            bloqueados para todos los usuarios de esta empresa (salvo superadmin).
        </p>
    </header>

    <p id="empresaItemsFeedback" class="empresa-usuarios-feedback" aria-live="polite"></p>

    <div id="empresaItemsList" class="role-wrapper empresa-items-list">
        <?php foreach ($grupos as $moduloNombre => $grupo): ?>
            <div class="role-module">
                <div class="role-module-header">
                    <label class="role-module-label">
                        <input type="checkbox" class="empresa-items-check-modulo">
                        <span>📁 <?= strtoupper(htmlspecialchars($moduloNombre, ENT_QUOTES, 'UTF-8')) ?></span>
                    </label>
                    <span class="role-counter"><?= count($grupo['items']) ?> ítems</span>
                </div>
                <div class="empresa-items-grid">
                    <?php foreach ($grupo['items'] as $item): ?>
                        <label class="empresa-items-check">
                            <input type="checkbox" class="empresa-items-check-item" value="<?= (int)$item['id'] ?>" <?= $item['habilitado'] ? 'checked' : '' ?>>
                            <span><?= htmlspecialchars($item['nombre'], ENT_QUOTES, 'UTF-8') ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <footer class="empresa-usuarios-footer">
        <button type="button" class="btn-cancel" onclick="closeModalGod()">Cancelar</button>
        <button type="button" id="empresaItemsGuardar" class="btn-save">💾 Guardar</button>
    </footer>
</div>

<script>
(function () {
    const root = document.querySelector(".empresa-items-modal");
    if (!root) return;

    const endpoint = root.dataset.endpoint;
    const listEl = root.querySelector("#empresaItemsList");
    const feedbackEl = root.querySelector("#empresaItemsFeedback");
    const guardarBtn = root.querySelector("#empresaItemsGuardar");

    function setFeedback(msg, isError) {
        feedbackEl.textContent = msg || "";
        feedbackEl.classList.toggle("empresa-usuarios-feedback-error", !!isError);
        feedbackEl.classList.toggle("empresa-usuarios-feedback-ok", !!msg && !isError);
    }

    function syncModuloCheckbox(moduloBox) {
        const master = moduloBox.querySelector(".empresa-items-check-modulo");
        const items = moduloBox.querySelectorAll(".empresa-items-check-item");
        master.checked = items.length > 0 && Array.from(items).every(chk => chk.checked);
    }

    listEl.querySelectorAll(".role-module").forEach(syncModuloCheckbox);

    listEl.addEventListener("change", function (e) {
        if (e.target.classList.contains("empresa-items-check-modulo")) {
            const moduloBox = e.target.closest(".role-module");
            moduloBox.querySelectorAll(".empresa-items-check-item").forEach(chk => {
                chk.checked = e.target.checked;
            });
            return;
        }
        if (e.target.classList.contains("empresa-items-check-item")) {
            syncModuloCheckbox(e.target.closest(".role-module"));
        }
    });

    guardarBtn.addEventListener("click", function () {
        const itemIds = Array.from(listEl.querySelectorAll(".empresa-items-check-item:checked")).map(chk => chk.value);

        guardarBtn.disabled = true;
        const original = guardarBtn.innerHTML;
        guardarBtn.innerHTML = "💾 Guardando…";

        const fd = new FormData();
        itemIds.forEach(id => fd.append("item_ids[]", id));

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
                setFeedback("Ítems habilitados guardados. Los usuarios de esta empresa verán el cambio en su próxima acción.");
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
