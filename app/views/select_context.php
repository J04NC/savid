<form method="POST" id="contextForm">

<div class="form-group">
<label>Empresa</label>

<select name="empresa_id" id="empresaSelect" class="form-input" required>
<option value="">Seleccione empresa</option>

<?php foreach($empresas as $emp): ?>
<option value="<?= $emp['id'] ?>">
<?= $emp['razon_social'] ?>
</option>
<?php endforeach; ?>

</select>
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

const form = document.getElementById("contextForm");

form.addEventListener("submit", function(e){

    const empresa = document.getElementById("empresaSelect").value;
    const sede = document.getElementById("sedeSelect").value;

    if(!empresa || !sede){
        e.preventDefault();
        alert("Debe seleccionar empresa y sede.");
    }

});

</script>