<?php
/** @var array $data */

$dosFactor = $data['dosFactor'];
$sesionesPorEmpresa = $data['sesionesPorEmpresa'];
$intentosFallidos = $data['intentosFallidos'];
$totalFallidos24h = $data['totalFallidos24h'];
$cuentasInactivas = $data['cuentasInactivas'];
$diasUmbral = $data['diasInactividadUmbral'];
?>

<div class="module-container seguridad-page">

    <div class="crud-toolbar seguridad-toolbar">
        <div class="seguridad-toolbar-title">
            <h2 class="seguridad-title">Centro de seguridad</h2>
            <p class="seguridad-subtitle">Una sola foto del estado de seguridad del sistema: autenticación de dos factores, sesiones activas, intentos de acceso fallidos y cuentas sin actividad reciente.</p>
        </div>
    </div>

    <div class="seguridad-stats-grid">

        <div class="seguridad-stat-card">
            <div class="seguridad-stat-value"><?= htmlspecialchars(number_format($dosFactor['porcentaje'], 1, ',', '.')) ?>%</div>
            <div class="seguridad-stat-label">Usuarios con 2FA activo</div>
            <div class="seguridad-stat-detail"><?= (int)$dosFactor['conDosFactor'] ?> de <?= (int)$dosFactor['total'] ?> usuarios activos</div>
        </div>

        <div class="seguridad-stat-card">
            <div class="seguridad-stat-value"><?= array_sum(array_column($sesionesPorEmpresa, 'activas')) ?></div>
            <div class="seguridad-stat-label">Sesiones activas (todas las empresas)</div>
            <div class="seguridad-stat-detail"><?= count($sesionesPorEmpresa) ?> empresa(s) con sesión abierta</div>
        </div>

        <div class="seguridad-stat-card <?= $totalFallidos24h > 0 ? 'seguridad-stat-card--alerta' : '' ?>">
            <div class="seguridad-stat-value"><?= (int)$totalFallidos24h ?></div>
            <div class="seguridad-stat-label">Intentos de login fallidos (24h)</div>
            <div class="seguridad-stat-detail"><?= count($intentosFallidos) ?> mostrados abajo (máx. reciente)</div>
        </div>

        <div class="seguridad-stat-card <?= count($cuentasInactivas) > 0 ? 'seguridad-stat-card--alerta' : '' ?>">
            <div class="seguridad-stat-value"><?= count($cuentasInactivas) ?></div>
            <div class="seguridad-stat-label">Cuentas inactivas</div>
            <div class="seguridad-stat-detail">Sin actividad hace más de <?= (int)$diasUmbral ?> días</div>
        </div>

    </div>

    <div class="seguridad-panels">

        <div class="seguridad-panel">
            <h3 class="seguridad-panel-title">Sesiones activas por empresa</h3>
            <?php if (empty($sesionesPorEmpresa)): ?>
                <p class="seguridad-panel-empty">No hay sesiones activas en este momento.</p>
            <?php else: ?>
                <table class="seguridad-table">
                    <thead>
                        <tr>
                            <th>Empresa</th>
                            <th>Sesiones activas</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sesionesPorEmpresa as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars((string)$row['empresa_nombre']) ?></td>
                                <td><?= (int)$row['activas'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="seguridad-panel">
            <h3 class="seguridad-panel-title">Últimos intentos de login fallidos</h3>
            <?php if (empty($intentosFallidos)): ?>
                <p class="seguridad-panel-empty">No hay intentos fallidos registrados.</p>
            <?php else: ?>
                <table class="seguridad-table">
                    <thead>
                        <tr>
                            <th>Usuario</th>
                            <th>IP</th>
                            <th>Fecha</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($intentosFallidos as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars((string)$row['username']) ?></td>
                                <td><code><?= htmlspecialchars((string)$row['ip']) ?></code></td>
                                <td><?= htmlspecialchars((string)$row['created_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="seguridad-panel seguridad-panel-wide">
            <h3 class="seguridad-panel-title">Cuentas inactivas (candidatas a desactivar)</h3>
            <?php if (empty($cuentasInactivas)): ?>
                <p class="seguridad-panel-empty">Todas las cuentas activas tienen actividad reciente.</p>
            <?php else: ?>
                <table class="seguridad-table">
                    <thead>
                        <tr>
                            <th>Usuario</th>
                            <th>Empresa(s)</th>
                            <th>Último inicio de sesión</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cuentasInactivas as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars((string)$row['username']) ?></td>
                                <td><?= htmlspecialchars((string)($row['empresas'] ?? '—')) ?></td>
                                <td><?= $row['ultimo_login'] ? htmlspecialchars((string)$row['ultimo_login']) : '<span class="seguridad-nunca">Nunca</span>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

    </div>

</div>
