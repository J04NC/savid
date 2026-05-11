<script>
document.addEventListener("DOMContentLoaded", function () {
    const modal = document.querySelector(".modal-content");
    if (modal) modal.classList.add("modal-xl");
});
</script>

<form id="formUsuarioPermisos">
<div style="padding:12px 16px; border-bottom:1px solid #eee;">
    <strong>Permisos directos:</strong> <?= strtoupper($usuario['nombre'] ?? $usuario['username'] ?? 'USUARIO') ?>
</div>

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
const form = document.getElementById("formUsuarioPermisos");
if (!form) return;

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

document.querySelectorAll(".check-modulo").forEach(chk => {
    chk.addEventListener("change", function() {
        const box = this.closest(".role-module");
        marcarChecks(box.querySelectorAll(".check-perm"), this.checked);
        actualizarTodo();
    });
});

document.querySelectorAll(".check-columna").forEach(chk => {
    chk.addEventListener("change", function() {
        marcarChecks(document.querySelectorAll(".col-" + this.dataset.col), this.checked);
        actualizarTodo();
    });
});

document.querySelectorAll(".check-fila").forEach(chk => {
    chk.addEventListener("change", function() {
        marcarChecks(document.querySelectorAll(".fila-" + this.dataset.fila), this.checked);
        actualizarTodo();
    });
});

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

form.addEventListener("submit", function(e) {
    e.preventDefault();

    const btn = this.querySelector(".btn-save");
    const txt = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = "💾 Guardando...";

    fetch("?url=usuario/permisos/<?= (int)$usuario['id'] ?>", {
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

actualizarTodo();
})();
</script>