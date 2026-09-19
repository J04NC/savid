<?php
/** @var array $config */

$assetSistema = BASE_PATH . '/public/js/sistema-correo.js';
$sistemaJsV = is_readable($assetSistema) ? (int)filemtime($assetSistema) : time();
?>

<div class="module-container auditoria-page">

    <div class="crud-toolbar auditoria-toolbar">
        <div class="auditoria-toolbar-title">
            <h2 class="auditoria-title">Configuración del sistema — Correo saliente</h2>
            <p class="auditoria-subtitle">Estado de Resend (<code class="auditoria-code">RESEND_API_KEY</code> en <code class="auditoria-code">config/.env</code>) y envío de un correo de prueba.</p>
        </div>
    </div>

    <div class="crud-form auditoria-filters-grid" style="margin-bottom:20px;">
        <div class="form-group">
            <label>API key de Resend</label>
            <input type="text" class="form-input" value="<?= $config['configurada'] ? '••••••••  (configurada)' : '— no configurada —' ?>" readonly>
        </div>
        <div class="form-group">
            <label>Remitente</label>
            <input type="text" class="form-input" value="<?= htmlspecialchars($config['remitente']) ?>" readonly>
        </div>
        <div class="form-group auditoria-filter-wide">
            <label>APP_URL (usado en enlaces de correos, ej. recuperar contraseña)</label>
            <input type="text" class="form-input" value="<?= htmlspecialchars($config['app_url']) ?>" readonly>
        </div>
    </div>

    <?php if (!$config['configurada']): ?>
        <p class="modal-form-alert">⚠️ <code>RESEND_API_KEY</code> no está configurada — ningún correo saliente se puede enviar (recuperación de contraseña, 2FA, alertas de seguridad, etc.) hasta que se configure en <code>config/.env</code>.</p>
    <?php endif; ?>

    <div class="crud-toolbar auditoria-toolbar" style="margin-top:10px;">
        <div class="auditoria-toolbar-title">
            <h3 class="auditoria-title" style="font-size:16px;">Enviar correo de prueba</h3>
            <p class="auditoria-subtitle">Confirma que la configuración actual realmente entrega correo, sin necesidad de entrar al servidor.</p>
        </div>
    </div>

    <div class="crud-form auditoria-filters-grid">
        <div class="form-group auditoria-filter-wide">
            <label for="sistemaCorreoDestino">Enviar a</label>
            <input type="email" id="sistemaCorreoDestino" class="form-input" placeholder="correo@dominio.com">
        </div>
    </div>

    <div class="auditoria-filters-footer">
        <button type="button" id="btnProbarCorreo" class="auditoria-btn-primary">✉️ Enviar correo de prueba</button>
    </div>

    <p id="sistemaCorreoStatus" class="usuario-perm-save-status" aria-live="polite"></p>

</div>

<script src="/js/sistema-correo.js?v=<?= (int)$sistemaJsV ?>"></script>
