<script>
document.addEventListener("DOMContentLoaded", function () {
    const modal = document.querySelector(".modal-content");
    if (modal) modal.classList.add("modal-lg");
});
</script>

<form id="formUsuarioEmpresaSede" style="padding:16px; max-height:75vh; overflow:auto;">
    <h3 style="margin:0 0 8px;">Empresas y sedes del usuario</h3>
    <p style="margin:0 0 16px; color:#666; font-size:14px;">
        Usuario: <strong><?= htmlspecialchars($usuario['nombre'] ?? $usuario['username'] ?? '') ?></strong>
    </p>

    <h4 style="margin:16px 0 8px; font-size:14px;">Empresas permitidas</h4>
    <?php if (empty($empresasDisponibles)): ?>
        <p style="color:#c00;">No tienes empresas asignadas para poder configurar a otros usuarios. Contacta al administrador.</p>
    <?php else: ?>
        <div style="display:grid; grid-template-columns:repeat(auto-fill,minmax(240px,1fr)); gap:8px; margin-bottom:20px;">
            <?php foreach ($empresasDisponibles as $emp): ?>
                <label style="display:flex; align-items:center; gap:8px;">
                    <input
                        type="checkbox"
                        name="empresas[]"
                        value="<?= (int)$emp['id'] ?>"
                        class="chk-empresa"
                        data-empresa="<?= (int)$emp['id'] ?>"
                        <?= in_array((int)$emp['id'], $selectedEmpresaIds, true) ? 'checked' : '' ?>
                    >
                    <span><?= htmlspecialchars($emp['razon_social']) ?></span>
                </label>
            <?php endforeach; ?>
        </div>

        <h4 style="margin:16px 0 8px; font-size:14px;">Sedes permitidas</h4>
        <p style="margin:0 0 12px; font-size:12px; color:#666;">
            Solo puedes marcar sedes de empresas seleccionadas arriba.
        </p>

        <?php foreach ($empresasDisponibles as $emp): ?>
            <?php
            $eid = (int)$emp['id'];
            $sedesList = $sedesPorEmpresa[$eid] ?? [];
            ?>
            <div class="bloque-sedes" data-empresa="<?= $eid ?>" style="margin-bottom:14px; padding:10px; border:1px solid #e5e5e5; border-radius:8px;">
                <strong style="font-size:13px;"><?= htmlspecialchars($emp['razon_social']) ?></strong>
                <?php if (empty($sedesList)): ?>
                    <p style="margin:8px 0 0; font-size:12px; color:#888;">Sin sedes activas.</p>
                <?php else: ?>
                    <div style="display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:6px; margin-top:8px;">
                        <?php foreach ($sedesList as $sede): ?>
                            <label style="display:flex; align-items:center; gap:8px; font-size:13px;">
                                <input
                                    type="checkbox"
                                    name="sedes[]"
                                    value="<?= (int)$sede['id'] ?>"
                                    data-empresa-sede="<?= $eid ?>"
                                    class="chk-sede"
                                    <?= in_array((int)$sede['id'], $selectedSedeIds, true) ? 'checked' : '' ?>
                                >
                                <?= htmlspecialchars($sede['nombre']) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <div class="role-footer" style="margin-top:20px;">
        <button type="button" class="btn-cancel" onclick="closeModalGod()">Cancelar</button>
        <button type="submit" class="btn-save">💾 Guardar</button>
    </div>
</form>

<script>
(function(){
const form = document.getElementById("formUsuarioEmpresaSede");
if (!form) return;

function syncSedesHabilitadas() {
    const empresasOn = new Set();
    document.querySelectorAll(".chk-empresa:checked").forEach(ch => {
        empresasOn.add(ch.dataset.empresa);
    });
    document.querySelectorAll(".chk-sede").forEach(ch => {
        const emp = ch.dataset.empresaSede;
        const ok = empresasOn.has(emp);
        ch.disabled = !ok;
        if (!ok) ch.checked = false;
    });
}

document.querySelectorAll(".chk-empresa").forEach(ch => {
    ch.addEventListener("change", syncSedesHabilitadas);
});
syncSedesHabilitadas();

form.addEventListener("submit", function(e) {
    e.preventDefault();
    const btn = this.querySelector(".btn-save");
    const txt = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = "💾 Guardando...";

    fetch("?url=usuario/empresa_sede/<?= (int)$usuario['id'] ?>", {
        method: "POST",
        body: new FormData(this)
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = txt;
        if (data.success) {
            alert("✅ Empresas y sedes guardadas");
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
