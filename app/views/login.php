<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Login | Savid</title>
    <link rel="stylesheet" href="/css/login.css">
</head>
<body class="login-page dark-mode">

<img src="/img/logo.png" class="background-logo" alt="Logo fondo">

<div class="main-container">
    <div class="login-box">
        <div class="avatar">
            <img src="/img/avatar.png" alt="Usuario">
        </div>

        <form method="POST" action="?url=login/authenticate">
            <?php 
            // 🔥 SIMPLE - FUNCIONA SIEMPRE
            $error = $_SESSION['login_error'] ?? null;
            $username = $_SESSION['login_username'] ?? '';
            unset($_SESSION['login_error'], $_SESSION['login_username']);
            ?>

            <?php if ($error): ?>
            <div class="error-message"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="form-group">
                <label>Usuario</label>
                <input type="text" name="username" value="<?= htmlspecialchars($username) ?>" placeholder="UserDemo" required>
            </div>

            <div class="form-group">
                <label>Contraseña</label>
                <input type="password" name="password" placeholder="********" required>
            </div>

            <div class="button-row">
                <button type="submit" class="btn primary">Iniciar sesión</button>
                <a href="?url=login/forgot" class="btn secondary">Recuperar contraseña</a>
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