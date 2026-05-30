<?php
/** @var array $list */
/** @var array $filters */
/** @var bool $esSuperAdmin */
/** @var string $breadcrumb */

$rows = $list['rows'] ?? [];
$total = (int)($list['total'] ?? 0);
$page = (int)($list['page'] ?? 1);
$pages = (int)($list['pages'] ?? 1);
$summary = $list['summary'] ?? ['activas' => 0, 'total_hoy' => 0];

function sesiones_query_string(array $filters, int $page = 1): string
{
    $q = array_filter([
        'url' => 'sesiones',
        'desde' => $filters['desde'] ?? '',
        'hasta' => $filters['hasta'] ?? '',
        'usuario_id' => $filters['usuario_id'] ?? '',
        'empresa_id' => $filters['empresa_id'] ?? '',
        'sede_id' => $filters['sede_id'] ?? '',
        'solo_activas' => !empty($filters['solo_activas']) ? '1' : '',
        'inactividad_min' => $filters['inactividad_min'] ?? '',
        'q' => $filters['q'] ?? '',
        'page' => $page > 1 ? (string)$page : '',
    ], static fn($v) => $v !== '' && $v !== null);

    return '?' . http_build_query($q);
}

function sesiones_format_dt(?string $dt): string
{
    if ($dt === null || trim($dt) === '') {
        return '—';
    }
    $ts = strtotime($dt);

    return $ts ? date('d/m/Y H:i:s', $ts) : $dt;
}

function sesiones_estado_class(string $estado): string
{
    $e = strtolower(trim($estado));

    return in_array($e, ['activa', 'inactiva', 'cerrada'], true)
        ? 'sesiones-badge-' . $e
        : 'sesiones-badge-default';
}

function sesiones_format_duracion($segundos): string
{
    if ($segundos === null || $segundos === '') {
        return '—';
    }
    $sec = max(0, (int)$segundos);
    if ($sec === 0) {
        return '<1m';
    }
    $h = (int)floor($sec / 3600);
    $m = (int)floor(($sec % 3600) / 60);

    return $h > 0 ? "{$h}h {$m}m" : "{$m}m";
}

$assetSesiones = BASE_PATH . '/public/js/sesiones.js';
$sesionesJsV = is_readable($assetSesiones) ? (int)filemtime($assetSesiones) : time();
?>

<div class="module-container auditoria-page sesiones-page">

    <div class="crud-toolbar auditoria-toolbar">
        <div class="auditoria-toolbar-title">
            <h2 class="auditoria-title">Sesiones activas</h2>
            <p class="auditoria-subtitle">Inicios de sesión, última actividad y cierres (logout o inactividad).</p>
        </div>
        <div class="auditoria-toolbar-actions sesiones-summary-cards">
            <span class="sesiones-stat sesiones-stat-activa" title="Con actividad reciente y sin cierre">
                <strong><?= (int)($summary['activas'] ?? 0) ?></strong> activas
            </span>
            <span class="sesiones-stat" title="Sesiones iniciadas hoy">
                <strong><?= (int)($summary['total_hoy'] ?? 0) ?></strong> hoy
            </span>
        </div>
    </div>

    <form method="get" class="auditoria-filters-form" action="">
        <input type="hidden" name="url" value="sesiones">

        <div class="crud-form auditoria-filters-grid">
            <div class="form-group">
                <label for="sesiones_desde">Desde</label>
                <input type="date" id="sesiones_desde" name="desde" class="form-input"
                       value="<?= htmlspecialchars((string)($filters['desde'] ?? '')) ?>">
            </div>
            <div class="form-group">
                <label for="sesiones_hasta">Hasta</label>
                <input type="date" id="sesiones_hasta" name="hasta" class="form-input"
                       value="<?= htmlspecialchars((string)($filters['hasta'] ?? '')) ?>">
            </div>
            <div class="form-group">
                <label for="sesiones_usuario_id">ID usuario</label>
                <input type="number" id="sesiones_usuario_id" name="usuario_id" min="1" class="form-input"
                       value="<?= htmlspecialchars((string)($filters['usuario_id'] ?? '')) ?>">
            </div>
            <?php
            $reportUrl = 'sesiones';
            require BASE_PATH . '/app/views/shared/report_scope_filters.php';
            ?>
            <div class="form-group">
                <label for="sesiones_inactividad_min">Min. inactividad (activa)</label>
                <input type="number" id="sesiones_inactividad_min" name="inactividad_min" min="5" max="480" class="form-input"
                       value="<?= htmlspecialchars((string)($filters['inactividad_min'] ?? '30')) ?>">
            </div>
            <div class="form-group auditoria-filter-wide">
                <label for="sesiones_q">Búsqueda</label>
                <input type="text" id="sesiones_q" name="q" class="form-input" placeholder="Usuario, IP, id sesión PHP…"
                       value="<?= htmlspecialchars((string)($filters['q'] ?? '')) ?>">
            </div>
            <div class="form-group auditoria-filter-check">
                <label class="auditoria-check-label">
                    <input type="checkbox" name="solo_activas" value="1" <?= !empty($filters['solo_activas']) ? 'checked' : '' ?>>
                    Solo sesiones activas
                </label>
            </div>
        </div>

        <div class="auditoria-filters-footer">
            <button type="submit" class="auditoria-btn-primary">🔍 Filtrar</button>
            <a href="?url=sesiones" class="auditoria-btn-secondary">Limpiar</a>
        </div>
    </form>

    <div class="crud-list-search auditoria-list-meta savid-dt-legacy-search">
        <label class="crud-list-search-label" for="sesionesTableSearch">Buscar en página</label>
        <input type="search" id="sesionesTableSearch" class="crud-search" placeholder="Filtrar filas visibles…" autocomplete="off">
        <span class="auditoria-meta-pill">
            <?= number_format($total, 0, ',', '.') ?> registro<?= $total === 1 ? '' : 's' ?>
            · Pág. <?= (int)$page ?> / <?= (int)$pages ?>
        </span>
    </div>

    <div class="crud-table auditoria-table-wrap savid-dt-host">
        <table class="auditoria-table sesiones-table savid-datatable" data-dt-paging="false">
            <thead>
                <tr>
                    <th>Estado</th>
                    <th>Usuario</th>
                    <th>Empresa</th>
                    <th>Sede</th>
                    <th>Inicio</th>
                    <th>Última actividad</th>
                    <th>Cierre</th>
                    <th>Duración</th>
                    <th>IP</th>
                    <th>Motivo cierre</th>
                </tr>
            </thead>
            <tbody id="sesionesTableBody">
                <?php if ($rows === []): ?>
                    <tr class="auditoria-empty-row">
                        <td colspan="10">No hay sesiones con los filtros actuales.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                        <?php
                        $estado = (string)($r['estado_sesion'] ?? '');
                        $searchHay = strtolower(implode(' ', [
                            (string)($r['username'] ?? ''),
                            (string)($r['empresa_nombre'] ?? ''),
                            (string)($r['sede_nombre'] ?? ''),
                            (string)($r['ip'] ?? ''),
                            $estado,
                        ]));
                        ?>
                        <tr class="crud-row auditoria-row sesiones-row" data-search="<?= htmlspecialchars($searchHay, ENT_QUOTES, 'UTF-8') ?>">
                            <td>
                                <span class="auditoria-badge <?= sesiones_estado_class($estado) ?>">
                                    <?= htmlspecialchars($estado !== '' ? ucfirst($estado) : '—') ?>
                                </span>
                            </td>
                            <td class="auditoria-td-user">
                                <?= htmlspecialchars((string)($r['username'] ?? '—')) ?>
                                <span class="auditoria-muted">#<?= (int)($r['usuario_id'] ?? 0) ?></span>
                            </td>
                            <td><?= htmlspecialchars((string)($r['empresa_nombre'] ?? ($r['empresa_id'] ? '#' . $r['empresa_id'] : '—'))) ?></td>
                            <td><?= htmlspecialchars((string)($r['sede_nombre'] ?? ($r['sede_id'] ? '#' . $r['sede_id'] : '—'))) ?></td>
                            <td class="auditoria-td-datetime"><?= htmlspecialchars(sesiones_format_dt($r['login_at'] ?? null)) ?></td>
                            <td class="auditoria-td-datetime"><?= htmlspecialchars(sesiones_format_dt($r['last_activity_at'] ?? null)) ?></td>
                            <td class="auditoria-td-datetime"><?= htmlspecialchars(sesiones_format_dt($r['logout_at'] ?? null)) ?></td>
                            <td><?= htmlspecialchars(sesiones_format_duracion($r['duracion_segundos'] ?? null)) ?></td>
                            <td><?= htmlspecialchars((string)($r['ip'] ?? '—')) ?></td>
                            <td><?= htmlspecialchars((string)($r['logout_motivo'] ?? '—')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pages > 1): ?>
        <nav class="auditoria-pagination" aria-label="Paginación">
            <?php if ($page > 1): ?>
                <a href="<?= htmlspecialchars(sesiones_query_string($filters, $page - 1)) ?>" class="auditoria-btn-secondary">← Anterior</a>
            <?php endif; ?>
            <span class="auditoria-pagination-info">Página <?= (int)$page ?> de <?= (int)$pages ?></span>
            <?php if ($page < $pages): ?>
                <a href="<?= htmlspecialchars(sesiones_query_string($filters, $page + 1)) ?>" class="auditoria-btn-secondary">Siguiente →</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>

</div>

<script src="/js/report_scope_filters.js?v=<?= (int)$sesionesJsV ?>"></script>
<script src="/js/sesiones.js?v=<?= (int)$sesionesJsV ?>"></script>
