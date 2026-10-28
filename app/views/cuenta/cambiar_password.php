<?php
/** @var string $breadcrumb */
?>
<div class="module-container">

    <h1><?= htmlspecialchars($breadcrumb ?? 'Cambiar contraseña', ENT_QUOTES, 'UTF-8') ?></h1>

    <form method="POST" action="?url=cuenta/cambiarPasswordGuardar" class="crud-form" style="max-width:420px;">
        <input type="hidden" name="csrf_token" value="<?= CsrfService::token() ?>">

        <div class="form-group">
            <label>Contraseña actual</label>
            <input type="password" name="password_actual" required autocomplete="current-password">
        </div>

        <div class="form-group">
            <label>Nueva contraseña</label>
            <input type="password" name="password_nueva" required autocomplete="new-password">
        </div>

        <div class="form-group">
            <label>Confirmar nueva contraseña</label>
            <input type="password" name="password_confirmar" required autocomplete="new-password">
        </div>

        <div class="button-row">
            <button type="submit" class="btn primary">Guardar</button>
            <a href="?url=dashboard" class="btn">Cancelar</a>
        </div>
    </form>

</div>
