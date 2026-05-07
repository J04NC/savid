<!-- app/views/home.php -->

<div class="system-menu-wrapper">

<div class="system-menu-header">
    <img src="/img/logo.png" class="system-menu-logo">
    <h1>MÓDULOS</h1>
    <p>Seleccione una opción del sistema</p>
</div>

<div class="system-menu-grid">

<?php foreach ($modulos as $modulo): ?>

<a class="system-card"
   href="?url=dashboard/modulo/<?php echo $modulo['id']; ?>">

 <!--   <div class="system-card-icon">
        <?php //echo IconHelper::get($modulo['nombre']); ?>
    </div>
-->

    <div class="system-card-title">
        <?php echo strtoupper($modulo['nombre']); ?>
    </div>

</a>

<?php endforeach; ?>

</div>

</div>