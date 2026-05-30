<?php
/** @var array $list */
/** @var array $filters */
/** @var list<string> $tablas */
/** @var bool $esSuperAdmin */
/** @var string $breadcrumb */

$rows = $list['rows'] ?? [];
$total = (int)($list['total'] ?? 0);
$page = (int)($list['page'] ?? 1);
$pages = (int)($list['pages'] ?? 1);
$desdeArchivo = !empty($filters['archivo']);

function auditoria_query_string(array $filters, int $page = 1): string
{
    $q = array_filter([
        'url' => 'auditoria',
        'desde' => $filters['desde'] ?? '',
        'hasta' => $filters['hasta'] ?? '',
        'tabla' => $filters['tabla'] ?? '',
        'accion' => $filters['accion'] ?? '',
        'usuario_id' => $filters['usuario_id'] ?? '',
        'empresa_id' => $filters['empresa_id'] ?? '',
        'sede_id' => $filters['sede_id'] ?? '',
        'registro_id' => $filters['registro_id'] ?? '',
        'q' => $filters['q'] ?? '',
        'archivo' => !empty($filters['archivo']) ? '1' : '',
        'page' => $page > 1 ? (string)$page : '',
    ], static fn($v) => $v !== '' && $v !== null);

    return '?' . http_build_query($q);
}

function auditoria_format_datetime(?string $dt): string
{
    if ($dt === null || trim($dt) === '') {
        return '—';
    }
    $ts = strtotime($dt);

    return $ts ? date('d/m/Y H:i:s', $ts) : $dt;
}

function auditoria_accion_class(string $accion): string
{
    $a = strtolower(trim($accion));

    return in_array($a, ['insert', 'update', 'delete'], true) ? 'auditoria-badge-' . $a : 'auditoria-badge-default';
}

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

    <div class="crud-list-search auditoria-list-meta savid-dt-legacy-search">
        <label class="crud-list-search-label" for="auditoriaTableSearch">Buscar en página</label>
        <input type="search" id="auditoriaTableSearch" class="crud-search" placeholder="Filtrar filas visibles…" autocomplete="off">
        <span class="auditoria-meta-pill">
            <?= number_format($total, 0, ',', '.') ?> registro<?= $total === 1 ? '' : 's' ?>
            · Pág. <?= (int)$page ?> / <?= (int)$pages ?>
            <?php if ($desdeArchivo): ?> · <strong>Archivo</strong><?php endif; ?>
        </span>
    </div>

    <div class="crud-table auditoria-table-wrap savid-dt-host">
        <table class="auditoria-table savid-datatable" data-dt-paging="false">
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
            <tbody id="auditoriaTableBody">
                <?php if ($rows === []): ?>
                    <tr class="auditoria-empty-row">
                        <td colspan="8">No hay registros con los filtros actuales.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                        <?php
                        $accion = (string)($r['accion'] ?? '');
                        $searchHay = strtolower(implode(' ', [
                            (string)($r['occurred_at'] ?? ''),
                            $accion,
                            (string)($r['tabla'] ?? ''),
                            (string)($r['registro_id'] ?? ''),
                            (string)($r['usuario_username'] ?? ''),
                            (string)($r['usuario_id'] ?? ''),
                            (string)($r['sql_resumen'] ?? ''),
                        ]));
                        ?>
                        <tr class="crud-row auditoria-row" data-search="<?= htmlspecialchars($searchHay, ENT_QUOTES, 'UTF-8') ?>">
                            <td class="auditoria-td-datetime" title="<?= htmlspecialchars((string)($r['occurred_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars(auditoria_format_datetime($r['occurred_at'] ?? null)) ?>
                            </td>
                            <td>
                                <span class="auditoria-badge <?= auditoria_accion_class($accion) ?>">
                                    <?= htmlspecialchars($accion !== '' ? $accion : '—') ?>
                                </span>
                            </td>
                            <td><code class="auditoria-code"><?= htmlspecialchars((string)($r['tabla'] ?? '—')) ?></code></td>
                            <td><?= htmlspecialchars((string)($r['registro_id'] ?? '—')) ?></td>
                            <td class="auditoria-td-user">
                                <?= htmlspecialchars((string)($r['usuario_username'] ?? '')) ?>
                                <?php if (!empty($r['usuario_id'])): ?>
                                    <span class="auditoria-muted">#<?= (int)$r['usuario_id'] ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= !empty($r['empresa_id']) ? (int)$r['empresa_id'] : '—' ?></td>
                            <td><?= !empty($r['sede_id']) ? (int)$r['sede_id'] : '—' ?></td>
                            <td class="auditoria-td-actions">
                                <button type="button"
                                    class="auditoria-btn-icon btn-auditoria-detalle"
                                    title="Ver detalle"
                                    data-id="<?= (int)($r['id'] ?? 0) ?>"
                                    data-archivo="<?= $desdeArchivo ? '1' : '0' ?>">👁</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pages > 1): ?>
        <nav class="auditoria-pagination" aria-label="Paginación">
            <?php if ($page > 1): ?>
                <a href="<?= htmlspecialchars(auditoria_query_string($filters, $page - 1)) ?>" class="auditoria-btn-secondary">← Anterior</a>
            <?php endif; ?>
            <span class="auditoria-pagination-info">Página <?= (int)$page ?> de <?= (int)$pages ?></span>
            <?php if ($page < $pages): ?>
                <a href="<?= htmlspecialchars(auditoria_query_string($filters, $page + 1)) ?>" class="auditoria-btn-secondary">Siguiente →</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>

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
