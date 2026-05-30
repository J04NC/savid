<?php

$menuService = new MenuService($_SESSION['user_id']);
$searchItems = $menuService->getSearchItems();

$assetRef = BASE_PATH . '/public/js/app.js';
$cssTokens = BASE_PATH . '/public/css/tokens.css';
$cssApp = BASE_PATH . '/public/css/app.css';
$jsCrud = BASE_PATH . '/public/js/crud.js';
$dtDir = BASE_PATH . '/public/vendor/datatables';
$jqueryPath = BASE_PATH . '/public/vendor/jquery/jquery.min.js';
$cssDtSavid = BASE_PATH . '/public/css/datatables-savid.css';
$jsDtSavid = BASE_PATH . '/public/js/datatables-savid.js';
$assetsV = max(
    is_readable($assetRef) ? (int)filemtime($assetRef) : time(),
    is_readable($cssTokens) ? (int)filemtime($cssTokens) : 0,
    is_readable($cssApp) ? (int)filemtime($cssApp) : 0,
    is_readable($cssDtSavid) ? (int)filemtime($cssDtSavid) : 0,
    is_readable($jsCrud) ? (int)filemtime($jsCrud) : 0,
    is_readable($jsDtSavid) ? (int)filemtime($jsDtSavid) : 0,
    is_readable($dtDir . '/datatables.min.js') ? (int)filemtime($dtDir . '/datatables.min.js') : 0,
    is_readable($dtDir . '/buttons.colVis.min.js') ? (int)filemtime($dtDir . '/buttons.colVis.min.js') : 0,
    is_readable($jqueryPath) ? (int)filemtime($jqueryPath) : 0,
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
<link rel="stylesheet" href="/vendor/datatables/datatables.min.css?v=<?= (int)$assetsV ?>">
<link rel="stylesheet" href="/vendor/datatables/buttons.dataTables.min.css?v=<?= (int)$assetsV ?>">
<link rel="stylesheet" href="/vendor/datatables/colReorder.dataTables.min.css?v=<?= (int)$assetsV ?>">
<link rel="stylesheet" href="/vendor/datatables/fixedHeader.dataTables.min.css?v=<?= (int)$assetsV ?>">
<link rel="stylesheet" href="/vendor/datatables/responsive.dataTables.min.css?v=<?= (int)$assetsV ?>">
<link rel="stylesheet" href="/css/datatables-savid.css?v=<?= (int)$assetsV ?>">

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
<script src="/vendor/jquery/jquery.min.js?v=<?= (int)$assetsV ?>"></script>
<script src="/vendor/datatables/datatables.min.js?v=<?= (int)$assetsV ?>"></script>
<script src="/vendor/datatables/jszip.min.js?v=<?= (int)$assetsV ?>"></script>
<script src="/vendor/datatables/buttons.min.js?v=<?= (int)$assetsV ?>"></script>
<script src="/vendor/datatables/buttons.html5.min.js?v=<?= (int)$assetsV ?>"></script>
<script src="/vendor/datatables/buttons.print.min.js?v=<?= (int)$assetsV ?>"></script>
<script src="/vendor/datatables/buttons.colVis.min.js?v=<?= (int)$assetsV ?>"></script>
<script src="/vendor/datatables/colReorder.min.js?v=<?= (int)$assetsV ?>"></script>
<script src="/vendor/datatables/fixedHeader.min.js?v=<?= (int)$assetsV ?>"></script>
<script src="/vendor/datatables/responsive.min.js?v=<?= (int)$assetsV ?>"></script>
<script src="/js/datatables-savid.js?v=<?= (int)$assetsV ?>"></script>
<script src="/js/crud.js?v=<?= (int)$assetsV ?>"></script>
<?php
if (!empty($_SESSION['sesion_idle_minutos']) && (int)$_SESSION['sesion_idle_minutos'] > 0) {
    $sidPath = BASE_PATH . '/public/js/session_idle.js';
    $sidV = is_readable($sidPath) ? (int)filemtime($sidPath) : (int)$assetsV;
    echo '<script src="/js/session_idle.js?v=' . (int)$sidV . '"></script>';
}
if (!empty($_SESSION['user_id'])) {
    $spPath = BASE_PATH . '/public/js/sesiones_ping.js';
    $spV = is_readable($spPath) ? (int)filemtime($spPath) : (int)$assetsV;
    echo '<script src="/js/sesiones_ping.js?v=' . (int)$spV . '"></script>';
}
?>

</body>
</html>