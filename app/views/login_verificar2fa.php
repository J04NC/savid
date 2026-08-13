<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verificación en dos pasos | Savid</title>
    <link rel="stylesheet" href="/css/login.css">
</head>
<body class="login-page dark-mode">

<img src="/img/logo.png" class="background-logo" alt="Logo fondo">

<div class="main-container">
    <div class="login-box">
        <form method="POST" action="?url=login/verificar2faConfirmar">
            <input type="hidden" name="csrf_token" value="<?= CsrfService::token() ?>">
            <?php
            $error = $_SESSION['tfa_error'] ?? null;
            unset($_SESSION['tfa_error']);
            ?>

            <?php if ($error): ?>
            <div class="error-message"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <p style="margin-bottom: 20px; font-size: 14px;">
                Te enviamos un código de 6 dígitos por correo. Ingrésalo para completar el inicio de sesión.
            </p>

            <div class="form-group">
                <label>Código de verificación</label>
                <input type="text" name="code" inputmode="numeric" pattern="[0-9]*" maxlength="6" placeholder="000000" required autofocus>
            </div>

            <div style="display:flex; align-items:center; gap:8px; margin-bottom:20px;">
                <input type="checkbox" name="remember_device" id="rememberDevice" value="1" style="width:auto; padding:0; margin:0; border-radius:3px;">
                <label for="rememberDevice" style="font-size:14px;">Recordar este dispositivo por 30 días</label>
            </div>

            <div class="button-row">
                <button type="submit" class="btn primary">Verificar</button>
                <a href="?url=login" class="btn secondary">Cancelar</a>
            </div>
        </form>

        <form method="POST" action="?url=login/verificar2faReenviar" style="margin-top: 10px; text-align: center;">
            <input type="hidden" name="csrf_token" value="<?= CsrfService::token() ?>">
            <button type="submit" class="btn secondary" style="width:100%;">Reenviar código</button>
        </form>

        <div class="theme-toggle">
            <button id="toggleTheme" class="theme-icon">☀️</button>
        </div>
    </div>
</div>

<script src="/js/login.js"></script>
</body>
</html>
