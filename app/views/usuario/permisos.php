
<form id="formUsuarioPermisos">

<div class="usuario-permisos-toolbar">
    <div class="usuario-permisos-toolbar-title">
        <strong>Permisos directos</strong>
        <span class="usuario-permisos-user"><?= htmlspecialchars(strtoupper($usuario['nombre'] ?? $usuario['username'] ?? 'USUARIO')) ?></span>
    </div>
    <div class="usuario-permisos-scope">
        <div>
            <label class="usuario-permisos-label" for="usuarioPermEmpresaSelect">Empresa</label>
            <select name="empresa_id" id="usuarioPermEmpresaSelect" class="form-input usuario-permisos-select" <?= !$esSuperAdmin ? 'disabled' : '' ?>>
                <?php if ($esSuperAdmin): ?><option value="">Todas (alcance global)</option><?php endif; ?>
                <?php foreach ($empresas as $emp): ?>
                    <option value="<?= (int)$emp['id'] ?>" <?= ((string)$empresaId === (string)$emp['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($emp['razon_social'] ?? $emp['nombre'] ?? ('Empresa #' . $emp['id'])) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if (!$esSuperAdmin): ?>
                <input type="hidden" name="empresa_id" value="<?= htmlspecialchars((string)($empresaId ?? '')) ?>">
            <?php endif; ?>
        </div>
        <div>
            <label class="usuario-permisos-label" for="usuarioPermSedeSelect">Sede</label>
            <select name="sede_id" id="usuarioPermSedeSelect" class="form-input usuario-permisos-select">
                <option value="">Toda la empresa</option>
                <?php foreach ($sedes as $s): ?>
                    <option value="<?= (int)$s['id'] ?>" <?= ((string)$sedeId === (string)$s['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($s['nombre'] ?? '') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <p class="usuario-permisos-legend">
        <span class="perm-legend-swatch perm-legend-swatch--role" aria-hidden="true" title="Viene del rol"></span>
        <strong>Violeta</strong>: el rol concede el permiso — el recuadro aparece <strong>marcado</strong> como si fuera permitir; <strong>desmarcar</strong> inserta <strong>denegar</strong> en <code>permiso</code>; volver a <strong>marcar</strong> elimina la denegación.
        <span class="perm-legend-swatch perm-legend-swatch--direct" aria-hidden="true" title="Solo directo"></span>
        <strong>Verde</strong>: sin rol en este alcance — marcar inserta <strong>permitir</strong>; desmarcar elimina el registro.
        Los cambios se guardan en bloque al dejar de editar (~0,5&nbsp;s) o al cerrar el modal.
        <span class="perm-deny-legend-icon" aria-hidden="true"><span class="perm-deny-legend-inner">−</span></span> denegación activa frente al rol.
    </p>
    <p id="usuarioPermSaveStatus" class="usuario-perm-save-status" aria-live="polite"></p>
    <?php if (empty($empresas) && !$esSuperAdmin): ?>
        <p class="modal-form-alert">Este usuario no tiene empresas asignadas (<code>usuario_empresa</code>). Asigne empresa/sede antes de definir permisos directos por alcance.</p>
    <?php endif; ?>
</div>

<div class="role-filter-bar">
    <input type="text" id="usuarioPermisosFiltro" class="form-input" placeholder="🔍 Filtrar por módulo o ítem..." autocomplete="off">
</div>

<div id="permisosMatriz" class="role-wrapper">
<?php foreach ($matriz as $modulo => $bloque): ?>
<?php
    $accionesMod = $bloque['acciones'] ?? [];
    $itemsMod = $bloque['items'] ?? [];
    $moduloSlug = preg_replace('/[^a-z0-9_-]+/i', '_', (string)$modulo);
?>
<div class="role-module" data-modulo="<?= htmlspecialchars($moduloSlug, ENT_QUOTES, 'UTF-8') ?>">
    <div class="role-module-header">
        <label class="role-module-label">
            <input type="checkbox" class="check-modulo">
            <span>📁 <?= strtoupper(htmlspecialchars((string)$modulo, ENT_QUOTES, 'UTF-8')) ?></span>
        </label>
        <span class="role-counter"><?= count($itemsMod) ?> ITEMS · <?= count($accionesMod) ?> ACCIONES</span>
    </div>
    <div class="role-table-wrap">
        <table class="role-table role-table-perms no-datatable">
            <thead>
                <tr>
                    <th class="sticky-todo th-todo">TODO</th>
                    <th class="sticky-left">ITEM</th>
                    <?php foreach ($accionesMod as $codigo => $nombre): ?>
                        <?php $hdr = RolePermissionService::actionHeaderLabels((string)$codigo, (string)$nombre); ?>
                        <th class="th-action" title="<?= htmlspecialchars($hdr['full'], ENT_QUOTES, 'UTF-8') ?>">
                            <label class="head-check">
                                <input type="checkbox" class="check-columna" data-col="<?= htmlspecialchars((string)$codigo, ENT_QUOTES, 'UTF-8') ?>">
                                <span class="head-check-label"><?= htmlspecialchars($hdr['short'], ENT_QUOTES, 'UTF-8') ?></span>
                            </label>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($itemsMod as $itemId => $item): ?>
                <tr>
                    <td class="sticky-todo cell-check">
                        <input type="checkbox" class="check-fila" data-fila="<?= (int)$itemId ?>">
                    </td>
                    <td class="sticky-left item-name"><?= strtoupper(htmlspecialchars((string)$item['item'], ENT_QUOTES, 'UTF-8')) ?></td>
                    <?php foreach ($accionesMod as $codigo => $nombre): ?>
                        <?php if (isset($item['acciones'][$codigo])): ?>
                            <?php
                                $ia = $item['acciones'][$codigo];
                                $fr = !empty($ia['from_role']);
                                $title = $fr
                                    ? (!empty($ia['denied'])
                                        ? 'Quitar denegación (vuelve el acceso del rol)'
                                        : 'Denegar frente al rol (desmarque el recuadro)')
                                    : ($ia['checked'] ? 'Quitar permiso directo' : 'Añadir permiso directo (permitir)');
                                $hdrCell = RolePermissionService::actionHeaderLabels((string)$codigo, (string)$nombre);
                            ?>
                    <td class="cell-check<?= $fr ? ' cell-has-role' : '' ?><?= !empty($ia['denied']) ? ' cell-has-deny' : '' ?>">
                            <div class="usuario-perm-cell<?= !empty($ia['denied']) ? ' perm-cell-denied' : '' ?>">
                                <span class="perm-check-slot">
                                    <input type="checkbox"
                                        value="<?= (int)$ia['id'] ?>"
                                        class="check-perm fila-<?= (int)$itemId ?> col-<?= htmlspecialchars((string)$codigo, ENT_QUOTES, 'UTF-8') ?>"
                                        data-from-role="<?= $fr ? '1' : '0' ?>"
                                        <?= !empty($ia['checked']) ? 'checked' : '' ?>
                                        title="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>"
                                    >
                                    <?php if (!empty($ia['denied'])): ?>
                                        <span class="perm-deny-inside" title="Denegación activa frente al rol" aria-hidden="true">−</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                    </td>
                        <?php else: ?>
                    <td class="cell-check">
                            <span class="no-perm">—</span>
                    </td>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endforeach; ?>
</div>

<div class="role-footer">
    <button type="button" class="btn-cancel" onclick="closeModalGod()">Cerrar</button>
</div>
</form>

<script>
(function(){
const USUARIO_ID = <?= (int)$usuario['id'] ?>;
const ES_SUPER_ADMIN = <?= !empty($esSuperAdmin) ? 'true' : 'false' ?>;
const DEBOUNCE_MS = 500;

const form = document.getElementById("formUsuarioPermisos");
if (!form) return;

/** @type {Map<number, 'none'|'allow'|'deny'>} */
let baseline = new Map();
let debounceTimer = null;

function cellStateFromCheckbox(chk) {
    const fromRole = chk.getAttribute("data-from-role") === "1";
    if (fromRole) {
        return chk.checked ? "none" : "deny";
    }
    return chk.checked ? "allow" : "none";
}

function setSaveStatus(text) {
    const el = document.getElementById("usuarioPermSaveStatus");
    if (el) el.textContent = text || "";
}

function getEmpresaScopePayload() {
    if (ES_SUPER_ADMIN) {
        const sel = document.getElementById("usuarioPermEmpresaSelect");
        if (!sel || sel.value === "") return { empresa_id: null };
        const n = parseInt(sel.value, 10);
        return { empresa_id: isNaN(n) ? null : n };
    }
    const hid = document.querySelector('#formUsuarioPermisos input[name="empresa_id"][type="hidden"]');
    if (!hid || hid.value === "") return { empresa_id: null };
    const n = parseInt(hid.value, 10);
    return { empresa_id: isNaN(n) ? null : n };
}

function getSedeScopePayload() {
    const sel = document.getElementById("usuarioPermSedeSelect");
    if (!sel || sel.value === "") return { sede_id: null };
    const n = parseInt(sel.value, 10);
    return { sede_id: isNaN(n) ? null : n };
}

function captureBaseline() {
    baseline = new Map();
    document.querySelectorAll("#permisosMatriz .check-perm").forEach(function (chk) {
        const id = parseInt(chk.value, 10);
        if (!id) return;
        baseline.set(id, cellStateFromCheckbox(chk));
    });
}

function computeDiff() {
    const grant_ids = [];
    const deny_ids = [];
    const remove_ids = [];
    document.querySelectorAll("#permisosMatriz .check-perm").forEach(function (chk) {
        const id = parseInt(chk.value, 10);
        if (!id) return;
        const base = baseline.has(id) ? baseline.get(id) : "none";
        const cur = cellStateFromCheckbox(chk);
        if (cur === base) return;
        if (cur === "none") remove_ids.push(id);
        else if (cur === "allow") grant_ids.push(id);
        else deny_ids.push(id);
    });
    return { grant_ids: grant_ids, deny_ids: deny_ids, remove_ids: remove_ids };
}

function clearDebounce() {
    if (debounceTimer) {
        clearTimeout(debounceTimer);
        debounceTimer = null;
    }
}

async function refreshMatrizFromServer() {
    const ep = getEmpresaScopePayload();
    const sp = getSedeScopePayload();
    const params = new URLSearchParams({
        ajax: "matriz",
        empresa_id: ep.empresa_id != null ? String(ep.empresa_id) : "",
        sede_id: sp.sede_id != null ? String(sp.sede_id) : ""
    });
    const r = await fetch("?url=usuario/permisos/" + USUARIO_ID + "&" + params);
    const data = await r.json();
    renderMatriz(data);
    bindChecks();
    actualizarTodo();
    captureBaseline();
    aplicarFiltroModulos();
}

async function flushPendingBatch(opts) {
    clearDebounce();
    const diff = computeDiff();
    if (diff.grant_ids.length === 0 && diff.deny_ids.length === 0 && diff.remove_ids.length === 0) {
        setSaveStatus("");
        return true;
    }

    const body = Object.assign(
        {},
        getEmpresaScopePayload(),
        getSedeScopePayload(),
        diff
    );

    setSaveStatus("Guardando…");

    try {
        const r = await fetch("?url=usuario/permisos/" + USUARIO_ID, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(body)
        });
        const data = await r.json();
        if (!data.success) {
            alert("❌ " + (data.message || "No se pudo guardar"));
            setSaveStatus("Error al guardar");
            return false;
        }

        if (opts && opts.closing) {
            setSaveStatus("");
            return true;
        }

        try {
            await refreshMatrizFromServer();
        } catch (e) {
            captureBaseline();
        }

        setSaveStatus("Guardado");
        setTimeout(function () {
            const d = computeDiff();
            if (d.grant_ids.length === 0 && d.deny_ids.length === 0 && d.remove_ids.length === 0) setSaveStatus("");
        }, 1600);

        return true;
    } catch (e) {
        alert("❌ Error de conexión");
        setSaveStatus("");
        return false;
    }
}

function scheduleFlush() {
    const diff = computeDiff();
    if (diff.grant_ids.length || diff.deny_ids.length || diff.remove_ids.length) {
        setSaveStatus("Pendiente…");
    }
    clearDebounce();
    debounceTimer = setTimeout(function () {
        debounceTimer = null;
        flushPendingBatch({});
    }, DEBOUNCE_MS);
}

window.__modalBeforeClose = async function () {
    return await flushPendingBatch({ closing: true });
};

function marcarChecks(lista, estado) {
    lista.forEach(chk => chk.checked = estado);
}

function actualizarFila(id, box) {
    const master = box.querySelector('.check-fila[data-fila="' + id + '"]');
    const lista = box.querySelectorAll(".fila-" + id);
    if (master) master.checked = lista.length > 0 && Array.from(lista).every(x => x.checked);
}

function actualizarCol(codigo, box) {
    const master = box.querySelector('.check-columna[data-col="' + codigo + '"]');
    const lista = box.querySelectorAll(".col-" + codigo);
    if (master) master.checked = lista.length > 0 && Array.from(lista).every(x => x.checked);
}

function actualizarModulo(box) {
    const master = box.querySelector(".check-modulo");
    const lista = box.querySelectorAll(".check-perm");
    if (master) master.checked = lista.length > 0 && Array.from(lista).every(x => x.checked);
}

function actualizarTodo() {
    document.querySelectorAll(".role-module").forEach(function (box) {
        box.querySelectorAll(".check-fila").forEach(function (x) {
            actualizarFila(x.dataset.fila, box);
        });
        box.querySelectorAll(".check-columna").forEach(function (x) {
            actualizarCol(x.dataset.col, box);
        });
        actualizarModulo(box);
    });
}

function actionHeaderLabels(codigo, nombre) {
    const full = String(nombre || "").trim().toUpperCase();
    const fromCodigo = String(codigo || "").replace(/_/g, " ").toUpperCase();
    if (full.length <= 14) return { short: full, full: full };
    if (fromCodigo.length <= 14) return { short: fromCodigo, full: full };
    return { short: fromCodigo.slice(0, 12) + "…", full: full };
}

function escapeHtml(str) {
    return String(str)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;");
}

/* ==========================================
FILTRO RÁPIDO POR MÓDULO/ÍTEM
========================================== */
const filtroInput = document.getElementById("usuarioPermisosFiltro");

function aplicarFiltroModulos() {
    if (!filtroInput) return;
    const q = filtroInput.value.trim().toLowerCase();
    document.querySelectorAll("#permisosMatriz .role-module").forEach(function (box) {
        const labelSpan = box.querySelector(".role-module-label span");
        const moduloTexto = labelSpan ? labelSpan.textContent.toLowerCase() : "";
        const moduloCoincide = q === "" || moduloTexto.includes(q);
        let algunItemVisible = false;
        box.querySelectorAll("tbody tr").forEach(function (tr) {
            const itemCell = tr.querySelector(".item-name");
            const itemTexto = itemCell ? itemCell.textContent.toLowerCase() : "";
            const visible = moduloCoincide || itemTexto.includes(q);
            tr.style.display = visible ? "" : "none";
            if (visible) algunItemVisible = true;
        });
        box.style.display = (moduloCoincide || algunItemVisible) ? "" : "none";
    });
}

filtroInput?.addEventListener("input", aplicarFiltroModulos);

function renderMatriz(data) {
    const container = document.getElementById("permisosMatriz");
    if (!container) return;

    const matriz = data.matriz || {};
    let html = '';

    for (const modulo in matriz) {
        const bloque = matriz[modulo];
        const acciones = bloque.acciones || {};
        const items = bloque.items || {};
        const codigosOrdenados = Object.keys(acciones);
        const nItems = Object.keys(items).length;
        const nAcc = codigosOrdenados.length;

        html += `
        <div class="role-module">
            <div class="role-module-header">
                <label class="role-module-label">
                    <input type="checkbox" class="check-modulo">
                    <span>📁 ${escapeHtml(String(modulo).toUpperCase())}</span>
                </label>
                <span class="role-counter">${nItems} ITEMS · ${nAcc} ACCIONES</span>
            </div>
            <div class="role-table-wrap">
                <table class="role-table role-table-perms no-datatable">
                    <thead>
                        <tr>
                            <th class="sticky-todo th-todo">TODO</th>
                            <th class="sticky-left">ITEM</th>`;

        for (let i = 0; i < codigosOrdenados.length; i++) {
            const codigo = codigosOrdenados[i];
            const hdr = actionHeaderLabels(codigo, acciones[codigo]);
            html += `
                            <th class="th-action" title="${escapeHtml(hdr.full)}">
                                <label class="head-check">
                                    <input type="checkbox" class="check-columna" data-col="${escapeHtml(codigo)}">
                                    <span class="head-check-label">${escapeHtml(hdr.short)}</span>
                                </label>
                            </th>`;
        }
        html += '</tr></thead><tbody>';

        for (const itemId in items) {
            const item = items[itemId];
            html += `<tr>
                <td class="sticky-todo cell-check">
                    <input type="checkbox" class="check-fila" data-fila="${itemId}">
                </td>
                <td class="sticky-left item-name">${escapeHtml(String(item.item || "").toUpperCase())}</td>`;

            for (let j = 0; j < codigosOrdenados.length; j++) {
                const codigo = codigosOrdenados[j];
                const cell = item.acciones && item.acciones[codigo];
                const roleClass = cell && cell.from_role ? ' cell-has-role' : '';
                const denyClass = cell && cell.denied ? ' cell-has-deny' : '';
                if (cell) {
                    const chk = cell.checked ? 'checked' : '';
                    const fromRole = !!cell.from_role;
                    const dataFr = fromRole ? '1' : '0';
                    const deniedWrap = cell.denied ? ' perm-cell-denied' : '';
                    const denyInside = cell.denied
                        ? '<span class="perm-deny-inside" title="Denegación activa frente al rol" aria-hidden="true">−</span>'
                        : '';
                    let titleAttr;
                    if (fromRole) {
                        titleAttr = cell.denied
                            ? 'Quitar denegación (vuelve el acceso del rol)'
                            : 'Denegar frente al rol (desmarque el recuadro)';
                    } else {
                        titleAttr = cell.checked
                            ? 'Quitar permiso directo'
                            : 'Añadir permiso directo (permitir)';
                    }
                    html += `
                    <td class="cell-check${roleClass}${denyClass}">
                        <div class="usuario-perm-cell${deniedWrap}">
                            <span class="perm-check-slot">
                                <input type="checkbox"
                                    value="${cell.id}"
                                    class="check-perm fila-${itemId} col-${escapeHtml(codigo)}"
                                    data-from-role="${dataFr}"
                                    title="${escapeHtml(titleAttr)}"
                                    ${chk}>
                                ${denyInside}
                            </span>
                        </div>
                    </td>`;
                } else {
                    html += '<td class="cell-check"><span class="no-perm">—</span></td>';
                }
            }
            html += '</tr>';
        }
        html += '</tbody></table></div></div>';
    }

    container.innerHTML = html;
}

async function recargarMatriz(empresaId, sedeId) {
    const ok = await flushPendingBatch({});
    if (!ok) return;

    const container = document.getElementById("permisosMatriz");
    if (!container) return;

    container.innerHTML = '<div class="usuario-permisos-loading">🔄 Cargando permisos…</div>';

    const params = new URLSearchParams({
        ajax: 'matriz',
        empresa_id: empresaId || '',
        sede_id: sedeId || ''
    });

    try {
        const r = await fetch("?url=usuario/permisos/" + USUARIO_ID + "&" + params);
        const data = await r.json();
        renderMatriz(data);
        bindChecks();
        actualizarTodo();
        captureBaseline();
        aplicarFiltroModulos();
    } catch (e) {
        container.innerHTML = '<div class="usuario-permisos-loading" style="color:var(--danger,#c00);">❌ Error cargando permisos</div>';
    }
}

function bindChecks() {
    document.querySelectorAll(".check-modulo").forEach(chk => {
        chk.addEventListener("change", function() {
            const box = this.closest(".role-module");
            if (!box) return;
            marcarChecks(box.querySelectorAll(".check-perm"), this.checked);
            actualizarTodo();
            scheduleFlush();
        });
    });

    document.querySelectorAll(".check-columna").forEach(chk => {
        chk.addEventListener("change", function() {
            const box = this.closest(".role-module");
            if (!box) return;
            marcarChecks(box.querySelectorAll(".col-" + this.dataset.col), this.checked);
            actualizarTodo();
            scheduleFlush();
        });
    });

    document.querySelectorAll(".check-fila").forEach(chk => {
        chk.addEventListener("change", function() {
            const box = this.closest(".role-module");
            if (!box) return;
            marcarChecks(box.querySelectorAll(".fila-" + this.dataset.fila), this.checked);
            actualizarTodo();
            scheduleFlush();
        });
    });

    document.querySelectorAll(".check-perm").forEach(chk => {
        chk.addEventListener("change", function() {
            const box = this.closest(".role-module");
            if (!box) return;
            const clases = [...this.classList];
            const fila = clases.find(x => x.startsWith("fila-"));
            const col = clases.find(x => x.startsWith("col-"));
            if (fila) actualizarFila(fila.replace("fila-", ""), box);
            if (col) actualizarCol(col.replace("col-", ""), box);
            actualizarModulo(box);
            scheduleFlush();
        });
    });
}

document.getElementById("usuarioPermEmpresaSelect")?.addEventListener("change", function() {
    const empresaId = this.value;
    const sedeSelect = document.getElementById("usuarioPermSedeSelect");
    if (!sedeSelect) return;

    sedeSelect.innerHTML = '<option value="">Cargando sedes…</option>';

    if (empresaId === "") {
        sedeSelect.innerHTML = '<option value="">Toda la empresa</option>';
        recargarMatriz("", "");
        return;
    }

    fetch("?url=usuario/permisos/" + USUARIO_ID + "&ajax=sedes&empresa_id=" + encodeURIComponent(empresaId))
        .then(r => r.json())
        .then(sedes => {
            sedeSelect.innerHTML = '<option value="">Toda la empresa</option>';
            sedes.forEach(sede => {
                const op = document.createElement("option");
                op.value = String(sede.id);
                op.textContent = sede.nombre || ("Sede #" + sede.id);
                sedeSelect.appendChild(op);
            });
            recargarMatriz(empresaId, "");
        })
        .catch(() => {
            sedeSelect.innerHTML = '<option value="">Toda la empresa</option>';
            recargarMatriz(empresaId, "");
        });
});

document.getElementById("usuarioPermSedeSelect")?.addEventListener("change", function() {
    const empresaEl = ES_SUPER_ADMIN ? document.getElementById("usuarioPermEmpresaSelect") : document.querySelector('input[name="empresa_id"][type="hidden"]');
    const empresaId = empresaEl ? empresaEl.value : "";
    recargarMatriz(empresaId, this.value);
});

form.addEventListener("submit", function (e) {
    e.preventDefault();
});

bindChecks();
actualizarTodo();
captureBaseline();
})();
</script>
