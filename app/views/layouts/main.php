<?php

$menuService = new MenuService($_SESSION['user_id']);
$searchItems = $menuService->getSearchItems();

$moduloId = $_GET['modulo'] ?? null;
$menuItems = [];

if ($moduloId) {
    $menuItems = $menuService->getItemsByModulo($moduloId);
}

$assetRef = BASE_PATH . '/public/js/app.js';
$cssTokens = BASE_PATH . '/public/css/tokens.css';
$cssApp = BASE_PATH . '/public/css/app.css';
$assetsV = max(
    is_readable($assetRef) ? (int)filemtime($assetRef) : time(),
    is_readable($cssTokens) ? (int)filemtime($cssTokens) : 0,
    is_readable($cssApp) ? (int)filemtime($cssApp) : 0,
    (int)($_SESSION['asset_cache_bust'] ?? 0),
    1
);

?>

<!DOCTYPE html>
<html lang="es">
<head>

<meta charset="UTF-8">
<title>SAVID</title>

<link rel="stylesheet" href="/css/tokens.css?v=<?= (int)$assetsV ?>">
<link rel="stylesheet" href="/css/app.css?v=<?= (int)$assetsV ?>">

</head>

<?php
$sessionIdleMin = isset($_SESSION['sesion_idle_minutos']) ? (int)$_SESSION['sesion_idle_minutos'] : 0;
if ($sessionIdleMin < 0) {
    $sessionIdleMin = 0;
}
?>

<body class="dark-mode" data-session-idle-minutes="<?= (int)$sessionIdleMin ?>">

<div class="app-container">

<!-- SIDEBAR -->

<div class="sidebar-floating">

<div class="user-header" onclick="toggleUserPanel()">
<span class="arrow">▶</span>
<span class="username"><?php echo $_SESSION['nombre']; ?></span>
</div>

<div class="user-body hidden" id="userBody">

<img src="/img/avatar.png" class="avatar-sidebar">

<p class="empresa">
<?php echo $_SESSION['empresa'] ?? 'Sin empresa'; ?>
</p>

<p class="sede">
<?php echo $_SESSION['sede'] ?? 'Sin sede'; ?>
</p>

<div class="sidebar-actions">

<a href="#" id="btnCambiarSede" title="Cambiar empresa / sede">🔄</a>

<a href="?url=dashboard/refreshAssets" title="Actualizar JS y CSS (evitar caché antigua)">🧹</a>

<a href="?url=login/logout" title="Cerrar sesión">🚪</a>

<button id="toggleTheme" class="theme-icon">
☀️
</button>

</div>

</div>

<?php
if ($menuItems) {
    MenuHelper::renderMenu($menuItems);
}
?>

</div>

<!-- CONTENIDO -->

<div class="main-content">

<header class="topbar">

<div class="breadcrumb">
<?= $breadcrumb ?? '' ?>
</div>

</header>

<main class="content">
<?php if (!empty($_SESSION['flash_notice'])): ?>
<div class="error-message" style="margin-bottom:16px;">
<?= htmlspecialchars((string)$_SESSION['flash_notice'], ENT_QUOTES, 'UTF-8') ?>
</div>
<?php unset($_SESSION['flash_notice']); endif; ?>
<?php require $view; ?>
</main>

</div>

</div>

<!-- BUSCADOR -->

<div id="searchOverlay" class="search-overlay">

<input
type="text"
id="searchInput"
placeholder="Buscar en el sistema..."
autocomplete="off"
>

<div id="searchResults"></div>

</div>

<!-- MODAL CONTEXTO -->
<div id="contextModal" class="modal hidden">
<div class="modal-content">
<span class="close-modal" id="closeContextModal">&times;</span>

<div id="contextContainer"></div>

</div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function(){

    let empresa = "<?= $_SESSION['empresa_id'] ?? '' ?>";
    let sede    = "<?= $_SESSION['sede_id'] ?? '' ?>";

    // abre modal solo si falta empresa o sede
    if(!empresa || !sede){

        setTimeout(() => {
            document.getElementById("btnCambiarSede")?.click();
        }, 300);

    }

});
</script>

<script>

const systemRoutes = [

<?php foreach ($searchItems as $item): ?>

{
name:"<?php echo strtoupper($item['nombre']); ?>",
url:"?url=<?php echo $item['ruta']; ?>"
},

<?php endforeach; ?>

];

</script>

<script src="/js/app.js?v=<?= (int)$assetsV ?>"></script>
<script src="/js/theme.js?v=<?= (int)$assetsV ?>"></script>
<script src="/js/search.js?v=<?= (int)$assetsV ?>"></script>
<script src="/js/crud.js?v=<?= (int)$assetsV ?>"></script>
<?php
if (!empty($_SESSION['sesion_idle_minutos']) && (int)$_SESSION['sesion_idle_minutos'] > 0) {
    $sidPath = BASE_PATH . '/public/js/session_idle.js';
    $sidV = is_readable($sidPath) ? (int)filemtime($sidPath) : (int)$assetsV;
    echo '<script src="/js/session_idle.js?v=' . (int)$sidV . '"></script>';
}
?>

</body>
</html>