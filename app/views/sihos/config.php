<?php
/** @var array $scope */
/** @var array|null $config */
/** @var bool $puedeGuardar */

$assetSihos = BASE_PATH . '/public/js/sihos-config.js';
$sihosJsV = is_readable($assetSihos) ? (int)filemtime($assetSihos) : time();
?>

<div class="module-container auditoria-page">

    <div class="crud-toolbar auditoria-toolbar">
        <div class="auditoria-toolbar-title">
            <h2 class="auditoria-title">SIHOS — Conexión</h2>
            <p class="auditoria-subtitle">Cada empresa tiene su propia base de datos de SIHOS. La contraseña se guarda cifrada.</p>
        </div>
    </div>

    <?php $filterUrl = 'sihos'; require BASE_PATH . '/app/views/sgd/_empresa_filter.php'; ?>

    <?php
    /* _empresa_filter.php también usa $empresaId internamente; se recalcula
       aquí después del require para no quedarse con el valor pisado. */
    $empresaId = $scope['empresaId'] ?? null;
    ?>

    <?php if ($empresaId === null): ?>
        <p class="field-note sgd-page-empty">Seleccione la empresa en el filtro superior para configurar su conexión a SIHOS.</p>
    <?php else: ?>

        <form method="post" action="?url=sihos/guardarConfig&empresa_id=<?= (int)$empresaId ?>">
            <div class="crud-form auditoria-filters-grid" style="margin-bottom:20px;">
                <div class="form-group">
                    <label for="sihosHost">Host</label>
                    <input type="text" id="sihosHost" name="host" class="form-input" value="<?= htmlspecialchars($config['host']) ?>" <?= $puedeGuardar ? '' : 'readonly' ?> required>
                </div>
                <div class="form-group">
                    <label for="sihosPuerto">Puerto</label>
                    <input type="number" id="sihosPuerto" name="puerto" class="form-input" value="<?= (int)$config['puerto'] ?>" <?= $puedeGuardar ? '' : 'readonly' ?>>
                </div>
                <div class="form-group">
                    <label for="sihosBaseDatos">Base de datos</label>
                    <input type="text" id="sihosBaseDatos" name="base_datos" class="form-input" value="<?= htmlspecialchars($config['base_datos']) ?>" <?= $puedeGuardar ? '' : 'readonly' ?> required>
                </div>
                <div class="form-group">
                    <label for="sihosUsuario">Usuario</label>
                    <input type="text" id="sihosUsuario" name="usuario" class="form-input" value="<?= htmlspecialchars($config['usuario']) ?>" <?= $puedeGuardar ? '' : 'readonly' ?> required>
                </div>
                <div class="form-group">
                    <label for="sihosCodiInst">CodiInst</label>
                    <input type="text" id="sihosCodiInst" name="codi_inst" class="form-input" value="<?= htmlspecialchars($config['codi_inst']) ?>" <?= $puedeGuardar ? '' : 'readonly' ?> required title="Código de la institución en SIHOS (tabla CodiInst). Obligatorio: algunas instalaciones de SIHOS comparten una misma base de datos entre varias instituciones (p. ej. un hospital y sus puestos de salud satélite), y sin este dato el cruce podría mezclar datos de instituciones distintas.">
                </div>
                <div class="form-group">
                    <label for="sihosPassword">Contraseña</label>
                    <?php if ($puedeGuardar): ?>
                        <input type="password" id="sihosPassword" name="password" class="form-input" placeholder="<?= $config['password_configurada'] ? 'Dejar en blanco para no cambiarla' : 'Sin configurar' ?>" autocomplete="new-password">
                    <?php else: ?>
                        <input type="text" class="form-input" value="<?= $config['password_configurada'] ? '••••••••  (configurada)' : '— no configurada —' ?>" readonly>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label for="sihosCharset">Charset</label>
                    <input type="text" id="sihosCharset" name="charset" class="form-input" value="<?= htmlspecialchars($config['charset']) ?>" <?= $puedeGuardar ? '' : 'readonly' ?>>
                </div>
            </div>

            <?php if ($puedeGuardar): ?>
                <details style="margin-bottom:20px;">
                    <summary style="cursor:pointer; color:#c0392b; font-weight:bold;">⚠️ Credenciales de escritura (opcional — solo para acciones administrativas de alto riesgo)</summary>
                    <p class="field-note" style="margin-top:8px;">
                        Distintas de las de solo lectura de arriba. Se usan <strong>únicamente</strong> para borrar el detalle presupuestal huérfano desde el reporte de cruce — nunca para los reportes normales.
                        Sin esto configurado, esa acción no aparece. El usuario de BD debe tener permiso de escritura solo sobre lo estrictamente necesario en SIHOS.
                    </p>
                    <div class="crud-form auditoria-filters-grid">
                        <div class="form-group">
                            <label for="sihosUsuarioEscritura">Usuario de escritura</label>
                            <input type="text" id="sihosUsuarioEscritura" name="usuario_escritura" class="form-input" value="<?= htmlspecialchars($config['usuario_escritura']) ?>">
                        </div>
                        <div class="form-group">
                            <label for="sihosPasswordEscritura">Contraseña de escritura</label>
                            <input type="password" id="sihosPasswordEscritura" name="password_escritura" class="form-input" placeholder="<?= $config['password_escritura_configurada'] ? 'Dejar en blanco para no cambiarla' : 'Sin configurar' ?>" autocomplete="new-password">
                        </div>
                    </div>
                </details>
            <?php endif; ?>

            <?php if (!$config['configurado']): ?>
                <p class="modal-form-alert">⚠️ Esta empresa aún no tiene conexión a SIHOS configurada.</p>
            <?php endif; ?>

            <?php if ($puedeGuardar): ?>
                <div class="auditoria-filters-footer">
                    <button type="submit" class="auditoria-btn-primary">💾 Guardar conexión</button>
                </div>
            <?php endif; ?>
        </form>

        <div class="crud-toolbar auditoria-toolbar" style="margin-top:10px;">
            <div class="auditoria-toolbar-title">
                <h3 class="auditoria-title" style="font-size:16px;">Probar conexión</h3>
                <p class="auditoria-subtitle">Verifica que SAVID puede alcanzar el servidor de SIHOS de esta empresa con las credenciales guardadas.</p>
            </div>
        </div>

        <div class="auditoria-filters-footer">
            <button type="button" id="btnProbarSihos" data-empresa-id="<?= (int)$empresaId ?>" class="auditoria-btn-primary">🔗 Probar conexión</button>
        </div>

        <p id="sihosConexionStatus" class="usuario-perm-save-status" aria-live="polite"></p>

    <?php endif; ?>

</div>

<script src="/js/sihos-config.js?v=<?= (int)$sihosJsV ?>"></script>
