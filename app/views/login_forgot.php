<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Recuperar contraseña | Savid</title>
    <link rel="stylesheet" href="/css/login.css">
</head>
<body class="login-page dark-mode">

<img src="/img/logo.png" class="background-logo" alt="Logo fondo">

<div class="main-container">
    <div class="login-box">
        <div class="avatar">
            <img src="/img/avatar.png" alt="Usuario">
        </div>

        <form method="POST" action="?url=login/forgotSend">
            <?php
            $message = $_SESSION['forgot_message'] ?? null;
            unset($_SESSION['forgot_message']);
            ?>

            <?php if ($message): ?>
            <div class="success-message"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <p style="margin-bottom: 20px; font-size: 14px;">
                Ingresa tu usuario o correo y te enviaremos un enlace para restablecer tu contraseña.
            </p>

            <div class="form-group">
                <label>Usuario o correo</label>
                <input type="text" name="identificador" placeholder="UserDemo o correo@dominio.com" required autofocus>
            </div>

            <div class="button-row">
                <button type="submit" class="btn primary">Enviar enlace</button>
                <a href="?url=login" class="btn secondary">Volver</a>
            </div>
        </form>

        <div class="theme-toggle">
            <button id="toggleTheme" class="theme-icon">☀️</button>
        </div>
    </div>
</div>

<script src="/js/login.js"></script>
</body>
</html>
