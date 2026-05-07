<div class="card">
    <h3>Usuario #<?= $usuarioId ?> - Roles y Permisos</h3>
    
    <form method="POST" action="?url=usuario/saveRoles/<?= $usuarioId ?>">
        <h4>Roles (Plantillas)</h4>
        <?php foreach ($roles as $rol): ?>
        <label>
            <input type="checkbox" name="roles[]" value="<?= $rol['id'] ?>" 
                   <?= in_array($rol['id'], $_POST['roles'] ?? []) ? 'checked' : '' ?>>
            <?= $rol['nombre'] ?>
        </label><br>
        <?php endforeach; ?>
        <button type="submit">Guardar Roles</button>
    </form>

    <hr>
    <a href="?url=usuario/permisos-directos/<?= $usuarioId ?>" class="btn">
        Permisos Directos (personalizados)
    </a>
</div>