<?php
/** @var array<string, mixed> $item */
/** @var array<int, array<string, mixed>> $acciones */

$itemId = (int)$item['id'];
$endpoint = '?url=item/acciones/' . $itemId;
$esSuperAdmin = !empty($_SESSION['es_super_admin']) || (int)($_SESSION['rol_id'] ?? 0) === 1;
?>
<div class="item-acciones-modal modal-inner"
     data-endpoint="<?= htmlspecialchars($endpoint, ENT_QUOTES, 'UTF-8') ?>"
     data-item-id="<?= $itemId ?>"
     data-es-super-admin="<?= $esSuperAdmin ? '1' : '0' ?>">

    <header class="modal-form-head">
        <h3 class="modal-form-title">Acciones del ítem</h3>
        <p class="modal-form-meta">
            Ítem: <strong><?= htmlspecialchars((string)$item['nombre'], ENT_QUOTES, 'UTF-8') ?></strong>
            <?php if (!empty($item['ruta'])): ?>
                <span class="item-acciones-meta-ruta">(<?= htmlspecialchars((string)$item['ruta'], ENT_QUOTES, 'UTF-8') ?>)</span>
            <?php endif; ?>
            <?php if (!empty($item['modulo_nombre'])): ?>
                · Módulo: <?= htmlspecialchars((string)$item['modulo_nombre'], ENT_QUOTES, 'UTF-8') ?>
            <?php endif; ?>
        </p>
        <p class="modal-form-help">
            Vincule acciones para que aparezcan como botones en el CRUD de este ítem. Inactivar oculta el botón sin borrar el vínculo.
        </p>
    </header>

    <div id="itemAccionesTableWrap">
        <?php require __DIR__ . '/_acciones_table.php'; ?>
    </div>

    <p id="itemAccionesFeedback" class="item-acciones-feedback" aria-live="polite"></p>

    <footer class="item-acciones-footer">
        <button type="button" class="btn-cancel" onclick="closeModalGod()">Cerrar</button>
    </footer>
</div>

<script>
(function () {
    const root = document.querySelector(".item-acciones-modal");
    if (!root) return;

    const endpoint = root.dataset.endpoint;
    const esSuperAdmin = root.dataset.esSuperAdmin === "1";
    const tableWrap = root.querySelector("#itemAccionesTableWrap");
    const feedbackEl = root.querySelector("#itemAccionesFeedback");

    function setFeedback(msg, isError) {
        if (!feedbackEl) return;
        feedbackEl.textContent = msg || "";
        feedbackEl.classList.toggle("item-acciones-feedback-error", !!isError);
        feedbackEl.classList.toggle("item-acciones-feedback-ok", !!msg && !isError);
    }

    function escHtml(s) {
        return String(s == null ? "" : s)
            .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;");
    }

    function postAction(action, payload) {
        const fd = new FormData();
        fd.append("_action", action);
        Object.keys(payload || {}).forEach(k => fd.append(k, payload[k]));
        return fetch(endpoint, { method: "POST", body: fd, credentials: "same-origin" })
            .then(r => r.json());
    }

    function renderTable(rows) {
        if (!rows || rows.length === 0) {
            tableWrap.innerHTML = '<p class="item-acciones-empty">No hay acciones registradas en el catálogo.</p>';
            return;
        }

        const deleteBtn = esSuperAdmin
            ? '<button type="button" class="item-acciones-icon-btn item-acciones-unlink-btn" data-accion-id="{{AID}}" title="Quitar vínculo">🗑</button>'
            : "";

        const trs = rows.map(r => {
            const linked = r.item_accion_id != null && r.item_accion_id !== "";
            const activo = linked && parseInt(r.link_estado_id, 10) === 1;
            const codigo = [r.accion_codigo, r.codigo].filter(Boolean).join(" / ");
            const desc = r.descripcion ? String(r.descripcion) : "";
            return (
                '<tr class="' + (linked && !activo ? "item-acciones-row-inactive" : "") + '" data-accion-id="' + parseInt(r.accion_id, 10) + '">'
                + '<td>' + escHtml(r.icono || "⚙️") + " " + escHtml(r.nombre || "") + "</td>"
                + '<td><code class="item-acciones-code">' + escHtml(codigo || "—") + "</code></td>"
                + '<td class="item-acciones-td-desc">' + escHtml(desc) + "</td>"
                + '<td class="item-acciones-td-center">'
                + (linked
                    ? '<span class="item-acciones-badge ' + (activo ? "item-acciones-badge-active" : "item-acciones-badge-inactive") + '">'
                        + (activo ? "Activo" : "Inactivo") + "</span>"
                    : '<span class="item-acciones-badge item-acciones-badge-none">Sin vínculo</span>')
                + "</td>"
                + '<td class="item-acciones-td-actions">'
                + (linked
                    ? '<button type="button" class="item-acciones-icon-btn item-acciones-toggle-btn" data-accion-id="' + r.accion_id + '" title="'
                        + (activo ? "Inactivar vínculo" : "Activar vínculo") + '">' + (activo ? "🚫" : "✅") + "</button>"
                    : '<button type="button" class="btn-save item-acciones-link-btn" data-accion-id="' + r.accion_id + '">Vincular</button>')
                + deleteBtn.replace("{{AID}}", String(r.accion_id))
                + "</td></tr>"
            );
        }).join("");

        tableWrap.innerHTML =
            '<div class="item-acciones-table-wrap"><table class="item-acciones-table"><thead><tr>'
            + "<th>Acción</th><th>Código</th><th>Descripción</th>"
            + '<th class="item-acciones-th-center">Vínculo</th>'
            + '<th class="item-acciones-th-actions">Operaciones</th>'
            + "</tr></thead><tbody>" + trs + "</tbody></table></div>";
    }

    tableWrap.addEventListener("click", function (e) {
        const btnLink = e.target.closest(".item-acciones-link-btn");
        const btnToggle = e.target.closest(".item-acciones-toggle-btn");
        const btnUnlink = e.target.closest(".item-acciones-unlink-btn");
        if (!btnLink && !btnToggle && !btnUnlink) return;

        const accionId = parseInt((btnLink || btnToggle || btnUnlink).dataset.accionId || "0", 10);
        if (!accionId) return;

        if (btnUnlink) {
            const row = e.target.closest("tr[data-accion-id]");
            const nombre = row ? row.cells[0].textContent.trim() : "";
            if (!confirm('¿Quitar el vínculo de la acción "' + nombre + '" con este ítem?')) return;
            btnUnlink.disabled = true;
            postAction("unlink", { accion_id: accionId })
                .then(data => {
                    btnUnlink.disabled = false;
                    if (!data || !data.success) {
                        setFeedback(data && data.message ? data.message : "No se pudo quitar.", true);
                        return;
                    }
                    setFeedback(data.message || "Vínculo eliminado.");
                    renderTable(data.acciones || []);
                })
                .catch(() => {
                    btnUnlink.disabled = false;
                    setFeedback("Error de conexión.", true);
                });
            return;
        }

        if (btnLink) {
            btnLink.disabled = true;
            postAction("link", { accion_id: accionId })
                .then(data => {
                    btnLink.disabled = false;
                    if (!data || !data.success) {
                        setFeedback(data && data.message ? data.message : "No se pudo vincular.", true);
                        return;
                    }
                    setFeedback(data.message || "Acción vinculada.");
                    renderTable(data.acciones || []);
                })
                .catch(() => {
                    btnLink.disabled = false;
                    setFeedback("Error de conexión.", true);
                });
            return;
        }

        btnToggle.disabled = true;
        postAction("toggle", { accion_id: accionId })
            .then(data => {
                btnToggle.disabled = false;
                if (!data || !data.success) {
                    setFeedback(data && data.message ? data.message : "No se pudo cambiar.", true);
                    return;
                }
                setFeedback(data.message || "Estado actualizado.");
                renderTable(data.acciones || []);
            })
            .catch(() => {
                btnToggle.disabled = false;
                setFeedback("Error de conexión.", true);
            });
    });
})();
</script>
