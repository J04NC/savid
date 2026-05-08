<script>
document.addEventListener("DOMContentLoaded", function () {
    const modal = document.querySelector(".modal-content");
    if (modal) modal.classList.add("modal-lg");
});
</script>

<form id="formUsuarioRoles" style="padding:16px;">
    <h3 style="margin:0 0 12px;">Roles de usuario</h3>
    <p style="margin:0 0 16px; color:#666;">
        Usuario: <strong><?= htmlspecialchars($usuario['nombre'] ?? $usuario['username'] ?? 'Usuario') ?></strong>
    </p>

    <div style="display:grid; grid-template-columns:repeat(2,minmax(220px,1fr)); gap:8px 16px; margin-bottom:18px;">
        <?php foreach ($roles as $rol): ?>
            <label style="display:flex; align-items:center; gap:8px;">
                <input
                    type="checkbox"
                    name="roles[]"
                    value="<?= (int)$rol['id'] ?>"
                    <?= in_array((int)$rol['id'], $selectedRoles, true) ? 'checked' : '' ?>
                >
                <span><?= htmlspecialchars($rol['nombre']) ?></span>
            </label>
        <?php endforeach; ?>
    </div>

    <div class="role-footer">
        <button type="button" class="btn-cancel" onclick="closeModalGod()">Cancelar</button>
        <button type="submit" class="btn-save">💾 Guardar</button>
    </div>
</form>

<script>
(function(){
const form = document.getElementById("formUsuarioRoles");
if (!form) return;

form.addEventListener("submit", function(e) {
    e.preventDefault();

    const btn = this.querySelector(".btn-save");
    const txt = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = "💾 Guardando...";

    fetch("?url=usuario/saveRoles/<?= (int)$usuario['id'] ?>", {
        method: "POST",
        body: new FormData(this)
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = txt;
        if (data.success) {
            alert("✅ Roles guardados correctamente");
            closeModalGod();
        } else {
            alert("❌ " + (data.message || "No se pudo guardar"));
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.innerHTML = txt;
        alert("❌ Error de conexión");
    });
});
})();
</script>
