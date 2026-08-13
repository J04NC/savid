<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Nueva contraseña | Savid</title>
    <link rel="stylesheet" href="/css/login.css">
</head>
<body class="login-page dark-mode">

<img src="/img/logo.png" class="background-logo" alt="Logo fondo">

<div class="main-container">
    <div class="login-box">
        <?php if (!$validation['valid']): ?>

            <div class="error-message"><?= htmlspecialchars($validation['error']) ?></div>
            <div class="button-row">
                <a href="?url=login/forgot" class="btn primary">Solicitar nuevo enlace</a>
            </div>

        <?php else: ?>

        <form method="POST" action="?url=login/resetPasswordSave">
            <?php
            $error = $_SESSION['reset_error'] ?? null;
            unset($_SESSION['reset_error']);
            ?>

            <?php if ($error): ?>
            <div class="error-message"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
            <input type="hidden" name="csrf_token" value="<?= CsrfService::token() ?>">

            <div class="form-group">
                <label>Nueva contraseña</label>
                <input type="password" name="password" placeholder="********" required>
            </div>

            <div class="form-group">
                <label>Confirmar contraseña</label>
                <input type="password" name="password_confirm" placeholder="********" required>
            </div>

            <div class="button-row">
                <button type="submit" class="btn primary">Guardar contraseña</button>
                <a href="?url=login" class="btn secondary">Cancelar</a>
            </div>
        </form>

        <?php endif; ?>

        <div class="theme-toggle">
            <button id="toggleTheme" class="theme-icon">☀️</button>
        </div>
    </div>
</div>

<script src="/js/login.js"></script>
</body>
</html>
