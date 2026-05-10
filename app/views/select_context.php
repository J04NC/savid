<?php
$soloEmpresa = $soloEmpresa ?? false;
$empresaFijaId = $empresaFijaId ?? null;
?>

<form method="POST" id="contextForm">

<div class="form-group">
<label>Empresa</label>

<?php if ($soloEmpresa && $empresaFijaId): ?>
<input type="hidden" name="empresa_id" id="empresaHidden" value="<?= (int)$empresaFijaId ?>">
<p class="form-input" style="margin:0;padding:10px 12px;border-radius:8px;opacity:0.95;">
<?= htmlspecialchars($empresas[0]['razon_social'] ?? '') ?>
</p>
<?php else: ?>
<select name="empresa_id" id="empresaSelect" class="form-input" required>
<option value="">Seleccione empresa</option>
<?php foreach ($empresas as $emp): ?>
<option value="<?= (int)$emp['id'] ?>">
<?= htmlspecialchars($emp['razon_social']) ?>
</option>
<?php endforeach; ?>
</select>
<?php endif; ?>

</div>

<div class="form-group">
<label>Sede</label>

<select name="sede_id" id="sedeSelect" class="form-input" required>
<option value="">Seleccione sede</option>
</select>

<small id="sedeHint" style="opacity:0.7;"></small>
</div>

<div class="button-row">
<button type="submit" class="btn primary">
Continuar
</button>
</div>

</form>

<script>

(function () {

const empresaHidden = document.getElementById("empresaHidden");
const empresaSelect = document.getElementById("empresaSelect");
const sedeSelect = document.getElementById("sedeSelect");

function empresaIdActual() {
    if (empresaHidden) return empresaHidden.value;
    return empresaSelect ? empresaSelect.value : "";
}

function cargarSedes(empresaId) {

    if (!empresaId || !sedeSelect) return;

    sedeSelect.innerHTML = "<option value=\"\">Cargando...</option>";

    fetch("?url=context/cambiarSede&empresa_id=" + encodeURIComponent(empresaId))
        .then(r => r.json())
        .then(rows => {

            sedeSelect.innerHTML = "<option value=\"\">Seleccione sede</option>";

            if (!rows || !rows.length) {
                sedeSelect.innerHTML += "<option value=\"\" disabled>No tiene sedes autorizadas</option>";
                return;
            }

            rows.forEach(x => {
                sedeSelect.innerHTML += "<option value=\"" + x.id + "\">" + x.nombre + "</option>";
            });
        })
        .catch(() => {
            sedeSelect.innerHTML = "<option value=\"\">Error cargando sedes</option>";
        });
}

if (empresaSelect) {
    empresaSelect.addEventListener("change", function () {
        cargarSedes(this.value);
    });
}

if (empresaHidden && empresaHidden.value) {
    cargarSedes(empresaHidden.value);
}

})();

</script>
