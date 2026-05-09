<?php

$menuService = new MenuService($_SESSION['user_id']);
$searchItems = $menuService->getSearchItems();

$moduloId = $_GET['modulo'] ?? null;
$menuItems = [];

if ($moduloId) {
    $menuItems = $menuService->getItemsByModulo($moduloId);
}

?>

<!DOCTYPE html>
<html lang="es">
<head>

<meta charset="UTF-8">
<title>SAVID</title>

<link rel="stylesheet" href="/css/app.css">

</head>

<body class="dark-mode">

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

<script src="/js/app.js"></script>
<script src="/js/theme.js"></script>
<script src="/js/search.js"></script>
<script src="/js/crud.js?v=<?= file_exists(BASE_PATH . '/public/js/crud.js') ? (int)filemtime(BASE_PATH . '/public/js/crud.js') : 1 ?>"></script>

</body>
</html>