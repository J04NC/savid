<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login | Savid</title>
    <link rel="stylesheet" href="/css/login.css">
    <?php if (TurnstileService::habilitado()): ?>
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    <?php endif; ?>
</head>
<body class="login-page dark-mode">

<img src="/img/logo.png" class="background-logo" alt="Logo fondo">

<div class="main-container">
    <div class="login-box">
        <form method="POST" action="?url=login/authenticate">
            <input type="hidden" name="csrf_token" value="<?= CsrfService::token() ?>">
            <?php
            // 🔥 SIMPLE - FUNCIONA SIEMPRE
            $error = $_SESSION['login_error'] ?? null;
            $username = $_SESSION['login_username'] ?? '';
            $forgotSuccess = $_SESSION['forgot_success'] ?? null;
            unset($_SESSION['login_error'], $_SESSION['login_username'], $_SESSION['forgot_success']);
            ?>

            <?php if ($error): ?>
            <div class="error-message"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if ($forgotSuccess): ?>
            <div class="success-message"><?= htmlspecialchars($forgotSuccess) ?></div>
            <?php endif; ?>

            <div class="form-group">
                <label>Usuario</label>
                <input type="text" name="username" value="<?= htmlspecialchars($username) ?>" placeholder="UserDemo" required>
            </div>

            <div class="form-group">
                <label>Contraseña</label>
                <input type="password" name="password" placeholder="********" required>
            </div>

            <?php if (TurnstileService::habilitado()): ?>
            <?php /* sin data-theme: el login tiene un toggle manual claro/oscuro
                     (#toggleTheme), así que se deja el valor por defecto "auto"
                     (se adapta solo) en vez de fijarlo a un tema que podría no
                     coincidir con lo que el usuario eligió */ ?>
            <div class="cf-turnstile" data-sitekey="<?= htmlspecialchars(TurnstileService::siteKey()) ?>"></div>
            <?php endif; ?>

            <div class="button-row">
                <button type="submit" class="btn primary">Iniciar sesión</button>
                <a href="?url=login/forgot" class="btn secondary">Recuperar contraseña</a>
            </div>
        </form>

        <div class="theme-toggle">
            <button id="toggleTheme" class="theme-icon">☀️</button>
        </div>

        <p style="text-align:center; margin-top:18px; font-size:12.5px;">
            <a href="?url=privacidad" style="color:#1dd1a1; text-decoration:none;">Política de privacidad</a>
        </p>
    </div>
</div>

<script src="/js/login.js"></script>
</body>
</html>