
<form id="formRolPermisos">
<input type="hidden" name="rol_id" value="<?= $rolId ?>">

<div class="rol-permisos-scope">

    <div>
        <label class="rol-permisos-label">EMPRESA</label>
        <select name="empresa_id" id="empresaSelect" class="form-input rol-permisos-select" <?= !$esSuperAdmin ? 'disabled' : '' ?>>
            <?php if ($esSuperAdmin): ?><option value="">Todas</option><?php endif; ?>
            <?php foreach ($empresas as $emp): ?>
                <option value="<?= $emp['id'] ?>" <?= ((string)$empresaId === (string)$emp['id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($emp['razon_social'], ENT_QUOTES, 'UTF-8') ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if (!$esSuperAdmin): ?><input type="hidden" name="empresa_id" value="<?= (int)$empresaId ?>"><?php endif; ?>
    </div>

    <div>
        <label class="rol-permisos-label">SEDE</label>
        <select name="sede_id" id="sedeSelect" class="form-input rol-permisos-select">
            <option value="">Todas</option>
            <?php foreach ($sedes as $s): ?>
                <option value="<?= $s['id'] ?>" <?= ((string)$sedeId === (string)$s['id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($s['nombre'], ENT_QUOTES, 'UTF-8') ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

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
                    <?php $hdrCell = RolePermissionService::actionHeaderLabels((string)$codigo, (string)$nombre); ?>
                    <td class="cell-check">
                        <?php if (isset($item['acciones'][$codigo])): ?>
                            <input type="checkbox"
                                name="permisos[]"
                                value="<?= (int)$item['acciones'][$codigo]['id'] ?>"
                                class="check-perm fila-<?= (int)$itemId ?> col-<?= htmlspecialchars((string)$codigo, ENT_QUOTES, 'UTF-8') ?>"
                                title="<?= htmlspecialchars($hdrCell['full'], ENT_QUOTES, 'UTF-8') ?>"
                                <?= !empty($item['acciones'][$codigo]['checked']) ? 'checked' : '' ?>
                            >
                        <?php else: ?>
                            <span class="no-perm">—</span>
                        <?php endif; ?>
                    </td>
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
    <button type="button" class="btn-cancel" onclick="closeModalGod()">Cancelar</button>
    <button type="submit" class="btn-save">💾 Guardar</button>
</div>

</form>

<script>
(function(){

const form = document.getElementById("formRolPermisos");
if (!form) return;

function escapeHtml(s) {
    return String(s)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;");
}

function actionHeaderLabels(codigo, nombre) {
    const full = String(nombre || "").trim().toUpperCase();
    const fromCodigo = String(codigo || "").replace(/_/g, " ").toUpperCase();
    if (full.length <= 14) return { short: full, full: full };
    if (fromCodigo.length <= 14) return { short: fromCodigo, full: full };
    return { short: fromCodigo.slice(0, 12) + "…", full: full };
}

function slugModulo(modulo) {
    return String(modulo).replace(/[^a-z0-9_-]+/gi, "_");
}

/* ==========================================
RECARGAR SOLO LA MATRIZ
========================================== */
function recargarMatriz(empresaId = '', sedeId = '') {
    const container = document.getElementById("permisosMatriz");
    if (!container) return;

    container.innerHTML = '<div class="rol-permisos-loading">🔄 Cargando permisos…</div>';

    const params = new URLSearchParams({
        ajax: 'matriz',
        id: '<?= (int)$rolId ?>'
    });
    if (empresaId) params.set('empresa_id', empresaId);
    if (sedeId) params.set('sede_id', sedeId);

    fetch(`?url=rol/permisos&${params}`)
    .then(r => r.json())
    .then(data => {
        renderMatriz(data);
        bindChecks();
    })
    .catch(err => {
        console.error("Error:", err);
        container.innerHTML = '<div class="rol-permisos-loading rol-permisos-error">❌ Error cargando permisos</div>';
    });
}

/* ==========================================
RENDERIZAR MATRIZ (acciones por módulo)
========================================== */
function renderMatriz(data) {
    const container = document.getElementById("permisosMatriz");
    const matriz = data.matriz || {};
    let html = '';

    for (let modulo in matriz) {
        const bloque = matriz[modulo];
        const acciones = bloque.acciones || {};
        const items = bloque.items || {};
        const modSlug = slugModulo(modulo);
        const nItems = Object.keys(items).length;
        const nAcc = Object.keys(acciones).length;

        html += `
        <div class="role-module" data-modulo="${escapeHtml(modSlug)}">
            <div class="role-module-header">
                <label class="role-module-label">
                    <input type="checkbox" class="check-modulo">
                    <span>📁 ${escapeHtml(modulo.toUpperCase())}</span>
                </label>
                <span class="role-counter">${nItems} ITEMS · ${nAcc} ACCIONES</span>
            </div>
            <div class="role-table-wrap">
                <table class="role-table role-table-perms no-datatable">
                    <thead><tr>
                        <th class="sticky-todo th-todo">TODO</th>
                        <th class="sticky-left">ITEM</th>`;

        const codigosOrdenados = Object.keys(acciones);
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

        for (let itemId in items) {
            const item = items[itemId];
            html += `<tr>
                <td class="sticky-todo cell-check">
                    <input type="checkbox" class="check-fila" data-fila="${itemId}">
                </td>
                <td class="sticky-left item-name">${escapeHtml(String(item.item || "").toUpperCase())}</td>`;

            for (let j = 0; j < codigosOrdenados.length; j++) {
                const codigo = codigosOrdenados[j];
                if (item.acciones && item.acciones[codigo]) {
                    const ia = item.acciones[codigo];
                    const hdr = actionHeaderLabels(codigo, acciones[codigo]);
                    html += `
                    <td class="cell-check">
                        <input type="checkbox"
                            name="permisos[]"
                            value="${ia.id}"
                            class="check-perm fila-${itemId} col-${escapeHtml(codigo)}"
                            title="${escapeHtml(hdr.full)}"
                            ${ia.checked ? 'checked' : ''}>
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

/* ==========================================
VINCULAR CHECKS (alcance por .role-module)
========================================== */
function bindChecks() {

    function marcarChecks(lista, estado) {
        lista.forEach(chk => { chk.checked = estado; });
    }

    function actualizarFila(id, box) {
        const master = box.querySelector('.check-fila[data-fila="' + id + '"]');
        const lista = box.querySelectorAll(".fila-" + id);
        if (master) master.checked = Array.from(lista).every(x => x.checked);
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

    document.querySelectorAll(".check-modulo").forEach(function (chk) {
        chk.addEventListener("change", function() {
            const box = this.closest(".role-module");
            if (!box) return;
            marcarChecks(box.querySelectorAll(".check-perm"), this.checked);
            actualizarTodo();
        });
    });

    document.querySelectorAll(".check-columna").forEach(function (chk) {
        chk.addEventListener("change", function() {
            const box = this.closest(".role-module");
            if (!box) return;
            marcarChecks(box.querySelectorAll(".col-" + this.dataset.col), this.checked);
            actualizarTodo();
        });
    });

    document.querySelectorAll(".check-fila").forEach(function (chk) {
        chk.addEventListener("change", function() {
            const box = this.closest(".role-module");
            if (!box) return;
            marcarChecks(box.querySelectorAll(".fila-" + this.dataset.fila), this.checked);
            actualizarTodo();
        });
    });

    document.querySelectorAll(".check-perm").forEach(function (chk) {
        chk.addEventListener("change", function() {
            const box = this.closest(".role-module");
            if (!box) return;
            const clases = [...this.classList];
            const fila = clases.find(x => x.startsWith("fila-"));
            const col = clases.find(x => x.startsWith("col-"));
            if (fila) actualizarFila(fila.replace("fila-", ""), box);
            if (col) actualizarCol(col.replace("col-", ""), box);
            actualizarModulo(box);
        });
    });
}

document.getElementById("empresaSelect")?.addEventListener("change", function() {
    const empresaId = this.value;
    const sedeSelect = document.getElementById("sedeSelect");

    sedeSelect.innerHTML = '<option value="">Cargando sedes...</option>';

    if (empresaId === "") {
        sedeSelect.innerHTML = '<option value="">Todas</option>';
        recargarMatriz('', '');
        return;
    }

    fetch(`?url=rol/permisos&ajax=sedes&empresa_id=${empresaId}&id=<?= (int)$rolId ?>`)
    .then(r => r.json())
    .then(sedes => {
        sedeSelect.innerHTML = '<option value="">Todas</option>';
        sedes.forEach(sede => {
            const op = document.createElement("option");
            op.value = sede.id;
            op.textContent = sede.nombre;
            sedeSelect.appendChild(op);
        });
        recargarMatriz(empresaId, '');
    })
    .catch(() => {
        sedeSelect.innerHTML = '<option value="">Todas</option>';
        recargarMatriz(empresaId, '');
    });
});

document.getElementById("sedeSelect")?.addEventListener("change", function() {
    const empresaSelect = document.getElementById("empresaSelect");
    const empresaId = empresaSelect ? empresaSelect.value : '';
    recargarMatriz(empresaId, this.value);
});

form.addEventListener("submit", function(e) {
    e.preventDefault();

    const btn = this.querySelector(".btn-save");
    const txt = btn.innerHTML;

    btn.disabled = true;
    btn.innerHTML = "💾 Guardando...";

    fetch("?url=rol/permisos&id=<?= (int)$rolId ?>", {
        method: "POST",
        body: new FormData(this)
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = txt;

        if (data.success) {
            alert("✅ Permisos guardados correctamente");
            closeModalGod();
        } else {
            alert("❌ Error guardando permisos");
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.innerHTML = txt;
        alert("❌ Error de conexión");
    });
});

bindChecks();

})();
</script>
