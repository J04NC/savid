<?php
/** @var array<string, mixed> $tercero */
/** @var list<array<string, mixed>> $filas */
/** @var list<array<string, mixed>> $tiposDocumento */
/** @var bool $esSuperAdmin */

$tid = (int)$tercero['id'];
$endpoint = '?url=tercero/identificaciones/' . $tid;
$nombre = trim((string)($tercero['razon_social'] ?? ''));
if ($nombre === '') {
    $nombre = trim((string)($tercero['nombres'] ?? '') . ' ' . (string)($tercero['apellidos'] ?? ''));
}
if ($nombre === '') {
    $nombre = '—';
}
$nitTipoId = 9;
?>
<div class="tercero-ident-modal modal-inner"
     data-endpoint="<?= htmlspecialchars($endpoint, ENT_QUOTES, 'UTF-8') ?>"
     data-tercero-id="<?= $tid ?>"
     data-nit-tipo-id="<?= $nitTipoId ?>"
     data-es-super-admin="<?= $esSuperAdmin ? '1' : '0' ?>">

    <header class="modal-form-head">
        <h3 class="modal-form-title">Identificaciones del tercero</h3>
        <p class="modal-form-meta">
            Tercero: <strong><?= htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8') ?></strong>
            <span class="tercero-ident-meta-id">(ID <?= $tid ?>)</span>
        </p>
        <p class="modal-form-help">
            <?php if ($esSuperAdmin): ?>
                Gestione documentos del tercero. Solo puede haber una identificación principal activa.
            <?php else: ?>
                Consulta de identificaciones. Solo un superadministrador puede crear o modificar registros.
            <?php endif; ?>
        </p>
    </header>

    <?php if ($esSuperAdmin): ?>
    <section class="tercero-ident-form-section">
        <h4 class="tercero-ident-section-title" id="terceroIdentFormTitle">Nueva identificación</h4>
        <form id="formTerceroIdent" class="tercero-ident-form" autocomplete="off">
            <input type="hidden" name="identificacion_id" id="terceroIdentId" value="">
            <div class="tercero-ident-form-grid">
                <label class="tercero-ident-field">
                    <span class="tercero-ident-label">Tipo documento <span class="tercero-ident-required">*</span></span>
                    <select name="tipodocumento_id" id="terceroIdentTipo" class="tercero-ident-input" required>
                        <option value="">Seleccione</option>
                        <?php foreach ($tiposDocumento as $td): ?>
                        <option value="<?= (int)$td['id'] ?>"><?= htmlspecialchars((string)$td['nombre'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="tercero-ident-field">
                    <span class="tercero-ident-label">Número <span class="tercero-ident-required">*</span></span>
                    <input type="text" name="numero" id="terceroIdentNumero" class="tercero-ident-input" maxlength="32" required>
                </label>
                <label class="tercero-ident-field tercero-ident-dv-wrap" id="terceroIdentDvWrap" hidden>
                    <span class="tercero-ident-label">DV</span>
                    <input type="text" name="dv" id="terceroIdentDv" class="tercero-ident-input" maxlength="2" readonly>
                </label>
                <label class="tercero-ident-field">
                    <span class="tercero-ident-label">Estado</span>
                    <select name="estado_id" id="terceroIdentEstado" class="tercero-ident-input">
                        <option value="1">Activo</option>
                        <option value="2">Inactivo</option>
                    </select>
                </label>
                <label class="tercero-ident-field tercero-ident-check-field">
                    <span class="tercero-ident-label">&nbsp;</span>
                    <label class="tercero-ident-checkbox">
                        <input type="checkbox" name="principal" id="terceroIdentPrincipal" value="1">
                        Principal
                    </label>
                </label>
                <label class="tercero-ident-field">
                    <span class="tercero-ident-label">Fecha expedición</span>
                    <input type="date" name="fecha_expedicion" id="terceroIdentFechaExp" class="tercero-ident-input">
                </label>
                <label class="tercero-ident-field">
                    <span class="tercero-ident-label">Fecha vencimiento</span>
                    <input type="date" name="fecha_vencimiento" id="terceroIdentFechaVen" class="tercero-ident-input">
                </label>
                <label class="tercero-ident-field tercero-ident-field-full">
                    <span class="tercero-ident-label">Observación</span>
                    <input type="text" name="observacion" id="terceroIdentObs" class="tercero-ident-input" maxlength="255">
                </label>
            </div>
            <div class="tercero-ident-form-actions">
                <button type="button" class="btn-cancel" id="terceroIdentBtnClear">Limpiar</button>
                <button type="submit" class="btn-save" id="terceroIdentBtnSave">Guardar identificación</button>
            </div>
        </form>
    </section>
    <?php endif; ?>

    <section class="tercero-ident-list-section">
        <h4 class="tercero-ident-section-title">Identificaciones registradas</h4>
        <div id="terceroIdentTableWrap">
            <?php require __DIR__ . '/_identificaciones_table.php'; ?>
        </div>
    </section>

    <p id="terceroIdentFeedback" class="tercero-ident-feedback" aria-live="polite"></p>

    <footer class="tercero-ident-footer">
        <button type="button" class="btn-cancel" onclick="closeModalGod()">Cerrar</button>
    </footer>
</div>

<script>
(function () {
    const root = document.querySelector(".tercero-ident-modal");
    if (!root) return;

    const endpoint = root.dataset.endpoint;
    const nitTipoId = parseInt(root.dataset.nitTipoId || "9", 10);
    const esSuperAdmin = root.dataset.esSuperAdmin === "1";
    const tableWrap = root.querySelector("#terceroIdentTableWrap");
    const feedbackEl = root.querySelector("#terceroIdentFeedback");
    const form = root.querySelector("#formTerceroIdent");

    function setFeedback(msg, isError) {
        if (!feedbackEl) return;
        feedbackEl.textContent = msg || "";
        feedbackEl.classList.toggle("tercero-ident-feedback-error", !!isError);
        feedbackEl.classList.toggle("tercero-ident-feedback-ok", !!msg && !isError);
    }

    function escHtml(s) {
        return String(s == null ? "" : s)
            .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;");
    }

    function nitDvFromDigits(digits) {
        const s = String(digits || "").replace(/\D/g, "");
        if (!s) return "0";
        const factors = [3, 7, 13, 17, 19, 23, 29, 37, 41, 43, 47, 53, 59, 67, 71];
        let sum = 0;
        for (let i = 0; i < s.length; i++) {
            sum += parseInt(s[s.length - 1 - i], 10) * factors[i % factors.length];
        }
        const r = sum % 11;
        return String(r < 2 ? r : 11 - r);
    }

    function postAction(action, payload) {
        const fd = new FormData();
        fd.append("_action", action);
        Object.keys(payload || {}).forEach(k => fd.append(k, payload[k]));
        return fetch(endpoint, { method: "POST", body: fd, credentials: "same-origin" })
            .then(r => r.json());
    }

    function syncDvVisibility() {
        if (!esSuperAdmin || !form) return;
        const tipo = form.querySelector("#terceroIdentTipo");
        const wrap = form.querySelector("#terceroIdentDvWrap");
        const dv = form.querySelector("#terceroIdentDv");
        const num = form.querySelector("#terceroIdentNumero");
        if (!tipo || !wrap) return;
        const isNit = parseInt(tipo.value || "0", 10) === nitTipoId;
        wrap.hidden = !isNit;
        if (isNit && num && dv) {
            dv.value = nitDvFromDigits(num.value);
        } else if (dv) {
            dv.value = "";
        }
    }

    function resetForm() {
        if (!form) return;
        form.reset();
        form.querySelector("#terceroIdentId").value = "";
        const title = root.querySelector("#terceroIdentFormTitle");
        if (title) title.textContent = "Nueva identificación";
        syncDvVisibility();
    }

    function fillForm(data) {
        if (!form || !data) return;
        form.querySelector("#terceroIdentId").value = data.id || "";
        form.querySelector("#terceroIdentTipo").value = data.tipodocumento_id || "";
        form.querySelector("#terceroIdentNumero").value = data.numero || "";
        form.querySelector("#terceroIdentDv").value = data.dv || "";
        form.querySelector("#terceroIdentEstado").value = String(data.estado_id || "1");
        form.querySelector("#terceroIdentPrincipal").checked = parseInt(data.principal, 10) === 1;
        form.querySelector("#terceroIdentFechaExp").value = data.fecha_expedicion || "";
        form.querySelector("#terceroIdentFechaVen").value = data.fecha_vencimiento || "";
        form.querySelector("#terceroIdentObs").value = data.observacion || "";
        const title = root.querySelector("#terceroIdentFormTitle");
        if (title) title.textContent = "Editar identificación";
        syncDvVisibility();
        form.scrollIntoView({ behavior: "smooth", block: "nearest" });
    }

    function renderTable(rows) {
        if (!rows || rows.length === 0) {
            tableWrap.innerHTML = '<p class="tercero-ident-empty">Sin identificaciones registradas para este tercero.</p>';
            return;
        }

        const actionsTh = esSuperAdmin
            ? '<th class="tercero-ident-th-actions">Acciones</th>'
            : "";
        const trs = rows.map(r => {
            const activo = parseInt(r.estado_id, 10) === 1;
            const esPrincipal = parseInt(r.principal, 10) === 1;
            const rowJson = escHtml(JSON.stringify({
                id: r.id,
                tipodocumento_id: r.tipodocumento_id,
                numero: r.numero || "",
                dv: r.dv != null && r.dv !== "" ? String(r.dv) : "",
                principal: esPrincipal ? 1 : 0,
                estado_id: r.estado_id,
                fecha_expedicion: r.fecha_expedicion || "",
                fecha_vencimiento: r.fecha_vencimiento || "",
                observacion: r.observacion || "",
                tipo_documento_nombre: r.tipo_documento_nombre || "",
            }));
            const actionsTd = esSuperAdmin
                ? '<td class="tercero-ident-td-actions">'
                    + '<button type="button" class="tercero-ident-icon-btn tercero-ident-btn-edit" title="Editar">✏️</button>'
                    + (esPrincipal ? "" : '<button type="button" class="tercero-ident-icon-btn tercero-ident-btn-principal" title="Marcar como principal">★</button>')
                    + '<button type="button" class="tercero-ident-icon-btn tercero-ident-btn-toggle" title="'
                    + (activo ? "Inactivar" : "Activar") + '">' + (activo ? "🚫" : "✅") + "</button>"
                    + '<button type="button" class="tercero-ident-icon-btn tercero-ident-btn-delete" title="Eliminar">🗑</button>'
                    + "</td>"
                : "";
            return (
                '<tr class="' + (activo ? "" : "tercero-ident-row-inactive") + '" data-ident-id="' + parseInt(r.id, 10) + '" data-row-json="' + rowJson + '">'
                + "<td>" + escHtml(r.tipo_documento_nombre || "—") + "</td>"
                + "<td>" + escHtml(r.numero || "") + "</td>"
                + "<td>" + escHtml(r.dv != null && r.dv !== "" ? r.dv : "—") + "</td>"
                + '<td class="tercero-ident-td-center">' + (esPrincipal
                    ? '<span class="tercero-ident-badge tercero-ident-badge-principal" title="Principal">★</span>'
                    : '<span class="tercero-ident-muted">—</span>') + "</td>"
                + '<td class="tercero-ident-td-center"><span class="tercero-ident-badge '
                + (activo ? "tercero-ident-badge-active" : "tercero-ident-badge-inactive") + '">'
                + (activo ? "Activo" : "Inactivo") + "</span></td>"
                + "<td>" + escHtml(r.fecha_expedicion || "—") + "</td>"
                + "<td>" + escHtml(r.fecha_vencimiento || "—") + "</td>"
                + '<td class="tercero-ident-td-obs">' + escHtml(r.observacion || "") + "</td>"
                + actionsTd + "</tr>"
            );
        }).join("");

        tableWrap.innerHTML =
            '<div class="tercero-ident-table-wrap"><table class="tercero-ident-table"><thead><tr>'
            + "<th>Tipo</th><th>Número</th><th>DV</th>"
            + '<th class="tercero-ident-th-center">Principal</th>'
            + '<th class="tercero-ident-th-center">Estado</th>'
            + "<th>Expedición</th><th>Vencimiento</th><th>Observación</th>"
            + actionsTh + "</tr></thead><tbody>" + trs + "</tbody></table></div>";
    }

    if (esSuperAdmin && form) {
        const tipoEl = form.querySelector("#terceroIdentTipo");
        const numEl = form.querySelector("#terceroIdentNumero");
        const btnClear = root.querySelector("#terceroIdentBtnClear");

        tipoEl.addEventListener("change", syncDvVisibility);
        numEl.addEventListener("input", syncDvVisibility);
        if (btnClear) btnClear.addEventListener("click", resetForm);
        syncDvVisibility();

        form.addEventListener("submit", function (e) {
            e.preventDefault();
            const btn = form.querySelector("#terceroIdentBtnSave");
            const txt = btn.textContent;
            btn.disabled = true;
            btn.textContent = "Guardando…";
            postAction("save", Object.fromEntries(new FormData(form)))
                .then(data => {
                    btn.disabled = false;
                    btn.textContent = txt;
                    if (!data || !data.success) {
                        setFeedback(data && data.message ? data.message : "No se pudo guardar.", true);
                        return;
                    }
                    setFeedback(data.message || "Guardado.");
                    resetForm();
                    renderTable(data.identificaciones || []);
                })
                .catch(() => {
                    btn.disabled = false;
                    btn.textContent = txt;
                    setFeedback("Error de conexión.", true);
                });
        });
    }

    if (esSuperAdmin) {
        tableWrap.addEventListener("click", function (e) {
            const btnEdit = e.target.closest(".tercero-ident-btn-edit");
            const btnToggle = e.target.closest(".tercero-ident-btn-toggle");
            const btnDelete = e.target.closest(".tercero-ident-btn-delete");
            const btnPrincipal = e.target.closest(".tercero-ident-btn-principal");
            const row = e.target.closest("tr[data-row-json]");
            if (!row) return;

            if (btnEdit) {
                try {
                    fillForm(JSON.parse(row.getAttribute("data-row-json")));
                } catch (err) { /* ignore */ }
                return;
            }

            const identId = parseInt(row.dataset.identId || "0", 10);
            if (!identId) return;

            if (btnPrincipal) {
                postAction("set_principal", { identificacion_id: identId })
                    .then(data => {
                        if (!data || !data.success) {
                            setFeedback(data && data.message ? data.message : "Error.", true);
                            return;
                        }
                        setFeedback(data.message || "Principal actualizada.");
                        renderTable(data.identificaciones || []);
                    })
                    .catch(() => setFeedback("Error de conexión.", true));
                return;
            }

            if (btnDelete) {
                const doc = row.cells[0].textContent + " " + row.cells[1].textContent;
                if (!confirm("¿Eliminar la identificación " + doc.trim() + "?\n\nNo se puede deshacer.")) return;
                btnDelete.disabled = true;
                postAction("delete", { identificacion_id: identId })
                    .then(data => {
                        btnDelete.disabled = false;
                        if (!data || !data.success) {
                            setFeedback(data && data.message ? data.message : "No se pudo eliminar.", true);
                            return;
                        }
                        setFeedback(data.message || "Eliminada.");
                        renderTable(data.identificaciones || []);
                        resetForm();
                    })
                    .catch(() => {
                        btnDelete.disabled = false;
                        setFeedback("Error de conexión.", true);
                    });
                return;
            }

            if (btnToggle) {
                btnToggle.disabled = true;
                postAction("toggle", { identificacion_id: identId })
                    .then(data => {
                        btnToggle.disabled = false;
                        if (!data || !data.success) {
                            setFeedback(data && data.message ? data.message : "Error.", true);
                            return;
                        }
                        setFeedback(data.message || "Estado actualizado.");
                        renderTable(data.identificaciones || []);
                    })
                    .catch(() => {
                        btnToggle.disabled = false;
                        setFeedback("Error de conexión.", true);
                    });
            }
        });
    }
})();
</script>
