<!-- app/views/modulo.php -->

<div class="system-menu-wrapper">

<div class="system-menu-header">
    <img src="/img/logo.png" class="system-menu-logo">
    <h1>OPCIONES</h1>
    <p>Seleccione una acción disponible</p>
</div>

<div class="system-menu-grid">

<?php foreach ($items as $item): ?>

<a class="system-card"
   href="<?php echo !empty($item['ruta'])
        ? '?url='.$item['ruta']
        : '?url=dashboard/item/'.$item['id']; ?>">

 <!--   <div class="system-card-icon">
        <?php //echo IconHelper::get($item['nombre']); ?>
    </div>
-->
    <div class="system-card-title">
        <?php echo strtoupper($item['nombre']); ?>
    </div>

</a>

<?php endforeach; ?>

</div>

</div>