<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Empresa y sede | Savid</title>
    <link rel="stylesheet" href="/css/login.css">
</head>
<body class="login-page dark-mode">

<div class="main-container">
    <div class="login-box">
        <h1 style="margin-top:0;font-size:1.25rem;text-align:center;">Seleccione empresa y sede</h1>
        <?php require BASE_PATH . '/app/views/select_context.php'; ?>
    </div>
</div>

<script>
(function () {
    const form = document.getElementById("contextForm");
    if (!form) return;

    form.addEventListener("submit", function (e) {
        e.preventDefault();

        const empresaHidden = document.getElementById("empresaHidden");
        const empresaSelect = document.getElementById("empresaSelect");
        const sedeSelect = document.getElementById("sedeSelect");

        const empresa = empresaHidden ? empresaHidden.value : (empresaSelect ? empresaSelect.value : "");
        const sede = sedeSelect ? sedeSelect.value : "";

        if (!empresa || !sede) {
            alert("Debe seleccionar empresa y sede.");
            return;
        }

        const fd = new FormData(form);

        fetch("?url=context/cambiarSede", { method: "POST", body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    window.location.href = "?url=dashboard";
                } else {
                    alert(data.error || "No se pudo guardar la selección.");
                }
            })
            .catch(() => alert("Error de conexión."));
    });
})();
</script>

</body>
</html>
