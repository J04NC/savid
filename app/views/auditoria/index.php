<?php
/** @var array $filters */
/** @var list<string> $tablas */
/** @var bool $esSuperAdmin */
/** @var string $breadcrumb */

$desdeArchivo = !empty($filters['archivo']);

$assetAuditoria = BASE_PATH . '/public/js/auditoria.js';
$auditoriaJsV = is_readable($assetAuditoria) ? (int)filemtime($assetAuditoria) : time();
?>

<div class="module-container auditoria-page">

    <div class="crud-toolbar auditoria-toolbar">
        <div class="auditoria-toolbar-title">
            <h2 class="auditoria-title">Auditoría del sistema</h2>
            <p class="auditoria-subtitle">Inserciones, actualizaciones y eliminaciones registradas en la base de datos.</p>
        </div>
        <div class="auditoria-toolbar-actions">
            <?php if ($esSuperAdmin): ?>
                <button type="button" id="btnArchivarAuditoria" class="auditoria-btn-secondary" title="Mover registros de más de 24 meses al archivo histórico">
                    📦 Archivar antiguos
                </button>
            <?php endif; ?>
        </div>
    </div>

    <form method="get" class="auditoria-filters-form" action="">
        <input type="hidden" name="url" value="auditoria">

        <div class="crud-form auditoria-filters-grid">
            <div class="form-group">
                <label for="auditoria_desde">Desde</label>
                <input type="date" id="auditoria_desde" name="desde" class="form-input"
                       value="<?= htmlspecialchars((string)($filters['desde'] ?? '')) ?>">
            </div>
            <div class="form-group">
                <label for="auditoria_hasta">Hasta</label>
                <input type="date" id="auditoria_hasta" name="hasta" class="form-input"
                       value="<?= htmlspecialchars((string)($filters['hasta'] ?? '')) ?>">
            </div>
            <div class="form-group">
                <label for="auditoria_tabla">Tabla</label>
                <select id="auditoria_tabla" name="tabla" class="form-input">
                    <option value="">— Todas —</option>
                    <?php foreach ($tablas as $t): ?>
                        <option value="<?= htmlspecialchars($t) ?>" <?= ($filters['tabla'] ?? '') === $t ? 'selected' : '' ?>>
                            <?= htmlspecialchars($t) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="auditoria_accion">Acción</label>
                <select id="auditoria_accion" name="accion" class="form-input">
                    <option value="">— Todas —</option>
                    <?php foreach (['INSERT', 'UPDATE', 'DELETE'] as $acc): ?>
                        <option value="<?= $acc ?>" <?= ($filters['accion'] ?? '') === $acc ? 'selected' : '' ?>><?= $acc ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="auditoria_usuario_id">ID usuario</label>
                <input type="number" id="auditoria_usuario_id" name="usuario_id" min="1" class="form-input"
                       value="<?= htmlspecialchars((string)($filters['usuario_id'] ?? '')) ?>" placeholder="Ej. 1">
            </div>
            <?php
            $reportUrl = 'auditoria';
            require BASE_PATH . '/app/views/shared/report_scope_filters.php';
            ?>
            <div class="form-group">
                <label for="auditoria_registro_id">ID registro</label>
                <input type="text" id="auditoria_registro_id" name="registro_id" class="form-input"
                       value="<?= htmlspecialchars((string)($filters['registro_id'] ?? '')) ?>" placeholder="PK afectada">
            </div>
            <div class="form-group auditoria-filter-wide">
                <label for="auditoria_q">Búsqueda libre</label>
                <input type="text" id="auditoria_q" name="q" class="form-input"
                       placeholder="SQL, tabla, id…"
                       value="<?= htmlspecialchars((string)($filters['q'] ?? '')) ?>">
            </div>
            <div class="form-group auditoria-filter-check">
                <label class="auditoria-check-label">
                    <input type="checkbox" name="archivo" value="1" <?= $desdeArchivo ? 'checked' : '' ?>>
                    Consultar archivo histórico
                </label>
            </div>
        </div>

        <div class="auditoria-filters-footer">
            <button type="submit" class="auditoria-btn-primary">🔍 Filtrar</button>
            <a href="?url=auditoria" class="auditoria-btn-secondary">Limpiar</a>
        </div>
    </form>

    <?php if ($desdeArchivo): ?>
        <p class="auditoria-meta-pill">Consultando <strong>archivo histórico</strong>.</p>
    <?php endif; ?>

    <div class="crud-table auditoria-table-wrap">
        <table class="auditoria-table savid-datatable"
               data-dt-server="1"
               data-dt-server-url="?url=auditoria/datos"
               data-dt-order='[[0,"desc"]]'>
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Acción</th>
                    <th>Tabla</th>
                    <th>Registro</th>
                    <th>Usuario</th>
                    <th>Empresa</th>
                    <th>Sede</th>
                    <th class="auditoria-th-actions">Detalle</th>
                </tr>
            </thead>
            <tbody id="auditoriaTableBody"></tbody>
        </table>
    </div>

</div>

<div id="auditoriaModal" class="modal hidden" role="dialog" aria-modal="true" aria-labelledby="auditoriaModalTitle">
    <div class="modal-content modal-xl auditoria-modal">
        <span class="close-modal" id="auditoriaModalClose" role="button" tabindex="0" aria-label="Cerrar">&times;</span>
        <header class="modal-form-head">
            <h3 class="modal-form-title" id="auditoriaModalTitle">Detalle de auditoría</h3>
            <p class="modal-form-help">Cambios capturados en el momento de la operación.</p>
        </header>
        <div id="auditoriaModalBody" class="auditoria-modal-body"></div>
        <footer class="auditoria-modal-footer">
            <button type="button" class="btn-cancel" id="auditoriaModalCloseBtn">Cerrar</button>
        </footer>
    </div>
</div>

<script src="/js/report_scope_filters.js?v=<?= (int)$auditoriaJsV ?>"></script>
<script src="/js/auditoria.js?v=<?= (int)$auditoriaJsV ?>"></script>
