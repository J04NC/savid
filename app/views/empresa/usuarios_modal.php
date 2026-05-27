<?php
/** @var array{id:int, razon_social:string} $empresa */
/** @var array<int, array<string, mixed>> $usuarios */

$empresaId = (int)$empresa['id'];
$razonSocial = (string)$empresa['razon_social'];

$endpoint = '?url=empresa/usuarios/' . $empresaId;
$sessionEmpresaId = isset($_SESSION['empresa_id']) ? (int)$_SESSION['empresa_id'] : 0;
$currentUid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$esSuperAdmin = !empty($_SESSION['es_super_admin']) || (int)($_SESSION['rol_id'] ?? 0) === 1;
?>

<div class="empresa-usuarios-modal modal-inner"
     data-endpoint="<?= htmlspecialchars($endpoint, ENT_QUOTES, 'UTF-8') ?>"
     data-empresa-id="<?= $empresaId ?>"
     data-session-empresa-id="<?= $sessionEmpresaId ?>"
     data-current-uid="<?= $currentUid ?>"
     data-es-super-admin="<?= $esSuperAdmin ? '1' : '0' ?>">

    <header class="modal-form-head">
        <h3 class="modal-form-title">Usuarios de la empresa</h3>
        <p class="modal-form-meta">
            Empresa: <strong><?= htmlspecialchars($razonSocial, ENT_QUOTES, 'UTF-8') ?></strong>
            <span class="empresa-usuarios-meta-id">(ID <?= $empresaId ?>)</span>
            <?php if ($empresaId === $sessionEmpresaId): ?>
                <span class="empresa-usuarios-session-badge">Sesión actual</span>
            <?php endif; ?>
        </p>
        <p class="modal-form-help">
            Incluye vínculo en empresa y usuarios asignados solo en sedes (sin fila en <code>usuario_empresa</code>).
        </p>
    </header>

    <section class="empresa-usuarios-search">
        <label class="empresa-usuarios-search-label" for="empresaUsuariosSearchTerm">Vincular usuario</label>
        <div class="empresa-usuarios-search-row">
            <input type="text" id="empresaUsuariosSearchTerm" class="empresa-usuarios-input"
                   placeholder="Buscar por username, NIT, nombre o razón social…"
                   autocomplete="off">
            <button type="button" id="empresaUsuariosSearchBtn" class="btn-save">Buscar</button>
        </div>
        <div id="empresaUsuariosSearchResults" class="empresa-usuarios-search-results"></div>
        <p id="empresaUsuariosFeedback" class="empresa-usuarios-feedback" aria-live="polite"></p>
    </section>

    <section class="empresa-usuarios-list-section">
        <h4 class="empresa-usuarios-list-title">Usuarios vinculados</h4>
        <div id="empresaUsuariosTableWrap">
            <?php require __DIR__ . '/_usuarios_table.php'; ?>
        </div>
    </section>

    <footer class="empresa-usuarios-footer">
        <button type="button" class="btn-cancel" onclick="closeModalGod()">Cerrar</button>
    </footer>
</div>

<script>
(function () {
    const root = document.querySelector(".empresa-usuarios-modal");
    if (!root) return;

    const endpoint = root.dataset.endpoint;
    const empresaId = parseInt(root.dataset.empresaId || "0", 10);
    const sessionEmpresaId = parseInt(root.dataset.sessionEmpresaId || "0", 10);
    const currentUid = parseInt(root.dataset.currentUid || "0", 10);
    const esSuperAdmin = root.dataset.esSuperAdmin === "1";

    const termEl = root.querySelector("#empresaUsuariosSearchTerm");
    const searchBtn = root.querySelector("#empresaUsuariosSearchBtn");
    const resultsEl = root.querySelector("#empresaUsuariosSearchResults");
    const feedbackEl = root.querySelector("#empresaUsuariosFeedback");
    const tableWrap = root.querySelector("#empresaUsuariosTableWrap");

    function setFeedback(msg, isError) {
        feedbackEl.textContent = msg || "";
        feedbackEl.classList.toggle("empresa-usuarios-feedback-error", !!isError);
        feedbackEl.classList.toggle("empresa-usuarios-feedback-ok", !!msg && !isError);
    }

    function escHtml(s) {
        return String(s == null ? "" : s)
            .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;").replace(/'/g, "&#39;");
    }

    function postAction(action, payload) {
        const fd = new FormData();
        fd.append("_action", action);
        Object.keys(payload || {}).forEach(k => fd.append(k, payload[k]));
        return fetch(endpoint, { method: "POST", body: fd, credentials: "same-origin" })
            .then(r => r.json());
    }

    function renderSearchResults(options) {
        if (!options || options.length === 0) {
            resultsEl.innerHTML = '<p class="empresa-usuarios-hint">Sin coincidencias.</p>';
            return;
        }
        resultsEl.innerHTML = options.map(opt => {
            const label = [
                opt.username,
                (opt.razon_social || ((opt.nombres || "") + " " + (opt.apellidos || "")).trim()),
                opt.doc ? ("Doc. " + opt.doc) : "",
            ].filter(Boolean).join(" · ");
            const linked = parseInt(opt.ya_vinculado, 10) === 1;
            return (
                '<div class="empresa-usuarios-search-item">'
                + '<span class="empresa-usuarios-search-item-label">' + escHtml(label) + "</span>"
                + (linked
                    ? '<span class="empresa-usuarios-hint">Ya vinculado</span>'
                    : '<button type="button" class="btn-save empresa-usuarios-link-btn" data-uid="' + opt.id + '">Vincular</button>')
                + "</div>"
            );
        }).join("");
    }

    function renderUsuariosTable(rows) {
        if (!rows || rows.length === 0) {
            tableWrap.innerHTML = '<p class="empresa-usuarios-empty">Sin usuarios vinculados a esta empresa.</p>';
            return;
        }

        const deleteBtn = esSuperAdmin
            ? '<button type="button" class="empresa-usuarios-icon-btn empresa-usuarios-delete-btn" data-uid="{{UID}}" title="Eliminar vínculo con la empresa">🗑</button>'
            : "";

        const trs = rows.map(r => {
            const nombre = (r.razon_social || ((r.nombres || "") + " " + (r.apellidos || "")).trim()) || "—";
            const doc = r.nit_or_doc || "—";
            const vinculoEmpresa = parseInt(r.vinculo_empresa, 10) === 1;
            const activo = vinculoEmpresa && parseInt(r.link_estado_id, 10) === 1;
            const sedes = parseInt(r.sedes_activas, 10) || 0;
            const badgeVinculo = vinculoEmpresa
                ? ('<span class="empresa-usuarios-badge '
                    + (activo ? "empresa-usuarios-badge-active" : "empresa-usuarios-badge-inactive") + '">'
                    + (activo ? "Activo" : "Inactivo") + "</span>")
                : '<span class="empresa-usuarios-badge empresa-usuarios-badge-solo-sede" title="Asignado en sede(s) sin vínculo empresa">Solo sede</span>';
            const toggleBtn = vinculoEmpresa
                ? ('<button type="button" class="empresa-usuarios-icon-btn empresa-usuarios-toggle-btn" data-uid="' + r.id + '" title="'
                    + (activo ? "Inactivar vínculo" : "Activar vínculo") + '">'
                    + (activo ? "🚫" : "✅") + "</button>")
                : "";
            return (
                '<tr class="' + (activo || !vinculoEmpresa ? "" : "empresa-usuarios-row-inactive") + '" data-uid="' + parseInt(r.id, 10) + '" data-vinculo-empresa="' + (vinculoEmpresa ? "1" : "0") + '">'
                + "<td>" + escHtml(r.username || "") + "</td>"
                + "<td>" + escHtml(nombre) + "</td>"
                + "<td>" + escHtml(doc) + "</td>"
                + '<td class="empresa-usuarios-td-center"><span class="empresa-usuarios-sedes-count" title="Sedes activas en esta empresa">' + sedes + "</span></td>"
                + '<td class="empresa-usuarios-td-center">' + badgeVinculo + "</td>"
                + '<td class="empresa-usuarios-td-actions">'
                + toggleBtn
                + deleteBtn.replace("{{UID}}", String(r.id))
                + "</td></tr>"
            );
        }).join("");

        tableWrap.innerHTML =
            '<div class="empresa-usuarios-table-wrap"><table class="empresa-usuarios-table"><thead><tr>'
            + "<th>Username</th><th>Nombre / Razón</th><th>Doc/NIT</th>"
            + '<th class="empresa-usuarios-th-center">Sedes activas</th>'
            + '<th class="empresa-usuarios-th-center">Vínculo</th>'
            + '<th class="empresa-usuarios-th-actions">Acciones</th>'
            + "</tr></thead><tbody>" + trs + "</tbody></table></div>";
    }

    function doSearch() {
        const term = (termEl.value || "").trim();
        if (term.length < 2) {
            resultsEl.innerHTML = '<p class="empresa-usuarios-hint">Escriba al menos 2 caracteres.</p>';
            return;
        }
        setFeedback("");
        postAction("search", { term })
            .then(data => {
                if (!data || !data.success) {
                    setFeedback(data && data.message ? data.message : "Búsqueda falló.", true);
                    return;
                }
                renderSearchResults(data.options || []);
            })
            .catch(() => setFeedback("Error de conexión.", true));
    }

    searchBtn.addEventListener("click", doSearch);
    termEl.addEventListener("keydown", e => {
        if (e.key === "Enter") {
            e.preventDefault();
            doSearch();
        }
    });

    resultsEl.addEventListener("click", e => {
        const btn = e.target.closest(".empresa-usuarios-link-btn");
        if (!btn) return;
        const uid = parseInt(btn.dataset.uid || "0", 10);
        if (!uid) return;
        btn.disabled = true;
        postAction("link", { usuario_id: uid })
            .then(data => {
                if (!data || !data.success) {
                    setFeedback(data && data.message ? data.message : "No se pudo vincular.", true);
                    btn.disabled = false;
                    return;
                }
                setFeedback(data.message || "Usuario vinculado.");
                renderUsuariosTable(data.usuarios || []);
                doSearch();
            })
            .catch(() => {
                setFeedback("Error de conexión.", true);
                btn.disabled = false;
            });
    });

    tableWrap.addEventListener("click", e => {
        const btnToggle = e.target.closest(".empresa-usuarios-toggle-btn");
        const btnDelete = e.target.closest(".empresa-usuarios-delete-btn");
        if (!btnToggle && !btnDelete) return;

        const uid = parseInt((btnToggle || btnDelete).dataset.uid || "0", 10);
        if (!uid) return;

        if (btnDelete) {
            const row = e.target.closest("tr[data-uid]");
            const username = row ? row.cells[0].textContent : "";
            const ok = confirm(
                "¿Eliminar el vínculo del usuario \"" + username + "\" con esta empresa?\n\n"
                + "Se quitarán también sus asignaciones de sede en esta empresa. No se borra la cuenta de usuario."
            );
            if (!ok) return;

            btnDelete.disabled = true;
            postAction("delete", { usuario_id: uid })
                .then(data => {
                    btnDelete.disabled = false;
                    if (!data || !data.success) {
                        setFeedback(data && data.message ? data.message : "No se pudo eliminar.", true);
                        return;
                    }
                    setFeedback(data.message || "Vínculo eliminado.");
                    renderUsuariosTable(data.usuarios || []);
                    doSearch();
                })
                .catch(() => {
                    btnDelete.disabled = false;
                    setFeedback("Error de conexión.", true);
                });
            return;
        }

        if (uid === currentUid && empresaId === sessionEmpresaId && !esSuperAdmin) {
            setFeedback("No puede inactivarse a sí mismo en la empresa de su sesión.", true);
            return;
        }

        if (!confirm(
            "¿Confirma cambiar el estado del vínculo?\n\n"
            + "Si lo inactiva, también se inactivarán las sedes de esta empresa para ese usuario."
        )) {
            return;
        }

        btnToggle.disabled = true;
        postAction("toggle", { usuario_id: uid })
            .then(data => {
                btnToggle.disabled = false;
                if (!data || !data.success) {
                    setFeedback(data && data.message ? data.message : "No se pudo cambiar.", true);
                    return;
                }
                setFeedback(data.message || "Estado actualizado.");
                renderUsuariosTable(data.usuarios || []);
            })
            .catch(() => {
                btnToggle.disabled = false;
                setFeedback("Error de conexión.", true);
            });
    });
})();
</script>
