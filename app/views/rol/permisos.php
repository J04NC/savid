<script>
document.addEventListener("DOMContentLoaded", function () {
    const modal = document.querySelector(".modal-content");
    if (modal) modal.classList.add("modal-xl");
});
</script>

<form id="formRolPermisos">
<input type="hidden" name="rol_id" value="<?= $rolId ?>">

<div style="padding:12px 16px; border-bottom:1px solid #eee; display:grid; grid-template-columns:1fr 1fr; gap:10px;">

    <!-- EMPRESA -->
    <div>
        <label style="font-size:12px;font-weight:700;">EMPRESA</label>
        <select name="empresa_id" id="empresaSelect" style="width:100%;padding:8px;" <?= !$esSuperAdmin ? 'disabled' : '' ?>>
            <?php if ($esSuperAdmin): ?><option value="">Todas</option><?php endif; ?>
            <?php foreach ($empresas as $emp): ?>
                <option value="<?= $emp['id'] ?>" <?= ((string)$empresaId === (string)$emp['id']) ? 'selected' : '' ?>>
                    <?= $emp['razon_social'] ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if (!$esSuperAdmin): ?><input type="hidden" name="empresa_id" value="<?= $empresaId ?>"><?php endif; ?>
    </div>

    <!-- SEDE -->
    <div>
        <label style="font-size:12px;font-weight:700;">SEDE</label>
        <select name="sede_id" id="sedeSelect" style="width:100%;padding:8px;">
            <option value="">Todas</option>
            <?php foreach ($sedes as $s): ?>
                <option value="<?= $s['id'] ?>" <?= ((string)$sedeId === (string)$s['id']) ? 'selected' : '' ?>>
                    <?= $s['nombre'] ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

</div>

<!-- CONTENEDOR QUE SE RECARGA -->
<div id="permisosMatriz" class="role-wrapper">

<?php foreach ($matriz as $modulo => $items): ?>
<div class="role-module">

    <div class="role-module-header">
        <label class="role-module-label">
            <input type="checkbox" class="check-modulo">
            <span>📁 <?= strtoupper($modulo) ?></span>
        </label>
        <span class="role-counter"><?= count($items) ?> ITEMS</span>
    </div>

    <div class="role-table-wrap">
        <table class="role-table">
            <thead>
                <tr>
                    <th class="sticky-left">ITEM</th>
                    <?php foreach ($acciones as $codigo => $nombre): ?>
                        <th>
                            <label class="head-check">
                                <input type="checkbox" class="check-columna" data-col="<?= $codigo ?>">
                                <span><?= strtoupper($nombre) ?></span>
                            </label>
                        </th>
                    <?php endforeach; ?>
                    <th>TODO</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $itemId => $item): ?>
                <tr>
                    <td class="sticky-left item-name"><?= strtoupper($item['item']) ?></td>
                    <?php foreach ($acciones as $codigo => $nombre): ?>
                    <td class="cell-check">
                        <?php if (isset($item['acciones'][$codigo])): ?>
                            <input type="checkbox" 
                                name="permisos[]" 
                                value="<?= $item['acciones'][$codigo]['id'] ?>"
                                class="check-perm fila-<?= $itemId ?> col-<?= $codigo ?>"
                                <?= $item['acciones'][$codigo]['checked'] ? 'checked' : '' ?>
                            >
                        <?php else: ?>
                            <span class="no-perm">—</span>
                        <?php endif; ?>
                    </td>
                    <?php endforeach; ?>
                    <td class="cell-check">
                        <input type="checkbox" class="check-fila" data-fila="<?= $itemId ?>">
                    </td>
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

let accionesGlobales = <?= json_encode($acciones) ?>;

/* ==========================================
RECARGAR SOLO LA MATRIZ
========================================== */
function recargarMatriz(empresaId = '', sedeId = '') {
    const container = document.getElementById("permisosMatriz");
    if (!container) return;
    
    container.innerHTML = '<div style="padding:20px;text-align:center;">🔄 Cargando permisos...</div>';
    
    const params = new URLSearchParams({
        ajax: 'matriz',
        id: '<?= $rolId ?>'
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
        container.innerHTML = '<div style="padding:20px;color:red;">❌ Error cargando permisos</div>';
    });
}

/* ==========================================
RENDERIZAR MATRIZ
========================================== */
function renderMatriz(data) {
    accionesGlobales = data.acciones;
    const container = document.getElementById("permisosMatriz");
    
    let html = '';
    
    for (let modulo in data.matriz) {
        let items = data.matriz[modulo];
        html += `
        <div class="role-module">
            <div class="role-module-header">
                <label class="role-module-label">
                    <input type="checkbox" class="check-modulo">
                    <span>📁 ${modulo.toUpperCase()}</span>
                </label>
                <span class="role-counter">${Object.keys(items).length} ITEMS</span>
            </div>
            <div class="role-table-wrap">
                <table class="role-table">
                    <thead>
                        <tr>
                            <th class="sticky-left">ITEM</th>
        `;
        
        for (let codigo in data.acciones) {
            html += `
                            <th>
                                <label class="head-check">
                                    <input type="checkbox" class="check-columna" data-col="${codigo}">
                                    <span>${data.acciones[codigo].toUpperCase()}</span>
                                </label>
                            </th>
            `;
        }
        html += '<th>TODO</th></tr></thead><tbody>';
        
        for (let itemId in items) {
            let item = items[itemId];
            html += `<tr>
                <td class="sticky-left item-name">${item.item.toUpperCase()}</td>`;
            
            for (let codigo in data.acciones) {
                if (item.acciones[codigo]) {
                    html += `
                    <td class="cell-check">
                        <input type="checkbox" 
                            name="permisos[]" 
                            value="${item.acciones[codigo].id}"
                            class="check-perm fila-${itemId} col-${codigo}"
                            ${item.acciones[codigo].checked ? 'checked' : ''}>
                    </td>`;
                } else {
                    html += '<td class="cell-check"><span class="no-perm">—</span></td>';
                }
            }
            html += `
                    <td class="cell-check">
                        <input type="checkbox" class="check-fila" data-fila="${itemId}">
                    </td>
                </tr>`;
        }
        html += '</tbody></table></div></div>';
    }
    
    container.innerHTML = html;
}

/* ==========================================
VINCULAR CHECKS
========================================== */
function bindChecks() {
    
    function marcarChecks(lista, estado) {
        lista.forEach(chk => chk.checked = estado);
    }
    
    function actualizarFila(id) {
        const master = document.querySelector('.check-fila[data-fila="' + id + '"]');
        const lista = document.querySelectorAll(".fila-" + id);
        if (master) master.checked = Array.from(lista).every(x => x.checked);
    }
    
    function actualizarCol(id) {
        const master = document.querySelector('.check-columna[data-col="' + id + '"]');
        const lista = document.querySelectorAll(".col-" + id);
        if (master) master.checked = Array.from(lista).every(x => x.checked);
    }
    
    function actualizarModulo(box) {
        const master = box.querySelector(".check-modulo");
        const lista = box.querySelectorAll(".check-perm");
        if (master) master.checked = Array.from(lista).every(x => x.checked);
    }
    
    function actualizarTodo() {
        document.querySelectorAll(".check-fila").forEach(x => actualizarFila(x.dataset.fila));
        document.querySelectorAll(".check-columna").forEach(x => actualizarCol(x.dataset.col));
        document.querySelectorAll(".role-module").forEach(x => actualizarModulo(x));
    }
    
    // MODULO
    document.querySelectorAll(".check-modulo").forEach(chk => {
        chk.addEventListener("change", function() {
            const box = this.closest(".role-module");
            marcarChecks(box.querySelectorAll(".check-perm"), this.checked);
            actualizarTodo();
        });
    });
    
    // COLUMNA
    document.querySelectorAll(".check-columna").forEach(chk => {
        chk.addEventListener("change", function() {
            marcarChecks(document.querySelectorAll(".col-" + this.dataset.col), this.checked);
            actualizarTodo();
        });
    });
    
    // FILA
    document.querySelectorAll(".check-fila").forEach(chk => {
        chk.addEventListener("change", function() {
            marcarChecks(document.querySelectorAll(".fila-" + this.dataset.fila), this.checked);
            actualizarTodo();
        });
    });
    
    // CELDA
    document.querySelectorAll(".check-perm").forEach(chk => {
        chk.addEventListener("change", function() {
            const clases = [...this.classList];
            const fila = clases.find(x => x.startsWith("fila-"));
            const col = clases.find(x => x.startsWith("col-"));
            if (fila) actualizarFila(fila.replace("fila-",""));
            if (col) actualizarCol(col.replace("col-",""));
            actualizarModulo(this.closest(".role-module"));
        });
    });
}

/* ==========================================
EVENTOS SELECTS
========================================== */
document.getElementById("empresaSelect")?.addEventListener("change", function() {
    const empresaId = this.value;
    const sedeSelect = document.getElementById("sedeSelect");
    
    sedeSelect.innerHTML = '<option value="">Cargando sedes...</option>';
    
    if (empresaId === "") {
        sedeSelect.innerHTML = '<option value="">Todas</option>';
        recargarMatriz('', '');
        return;
    }
    
    fetch(`?url=rol/permisos&ajax=sedes&empresa_id=${empresaId}&id=<?= $rolId ?>`)
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
    const sedeId = this.value;
    recargarMatriz(empresaId, sedeId);
});

/* ==========================================
GUARDAR
========================================== */
form.addEventListener("submit", function(e) {
    e.preventDefault();
    
    const btn = this.querySelector(".btn-save");
    const txt = btn.innerHTML;
    
    btn.disabled = true;
    btn.innerHTML = "💾 Guardando...";
    
    fetch("?url=rol/permisos&id=<?= $rolId ?>", {
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

// INICIALIZAR
bindChecks();

})();
</script>