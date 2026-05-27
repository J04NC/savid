<?php
/** @var array $filters */
/** @var array $reportScope */
/** @var string $reportUrl */

$empresas = $reportScope['empresas'] ?? [];
$sedes = $reportScope['sedes'] ?? [];
$showEmpresa = !empty($reportScope['showEmpresaFilter']);
$showSede = !empty($reportScope['showSedeFilter']);
$filterEmpresaId = (int)($filters['empresa_id'] ?? 0);
$filterSedeId = (int)($filters['sede_id'] ?? 0);
?>

<?php if ($showEmpresa): ?>
<div class="form-group">
    <label for="report_filter_empresa">Empresa</label>
    <select id="report_filter_empresa" name="empresa_id" class="form-input report-scope-empresa">
        <option value="">— Todas —</option>
        <?php foreach ($empresas as $emp): ?>
            <option value="<?= (int)$emp['id'] ?>" <?= $filterEmpresaId === (int)$emp['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars((string)($emp['razon_social'] ?? ('#' . $emp['id']))) ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>
<?php endif; ?>

<?php if ($showSede): ?>
<div class="form-group">
    <label for="report_filter_sede">Sede</label>
    <select id="report_filter_sede" name="sede_id" class="form-input report-scope-sede"
            <?= $showEmpresa && $filterEmpresaId <= 0 && count($empresas) > 1 ? 'disabled' : '' ?>>
        <option value="">— Todas —</option>
        <?php foreach ($sedes as $sede): ?>
            <option value="<?= (int)$sede['id'] ?>" <?= $filterSedeId === (int)$sede['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars((string)($sede['nombre'] ?? ('#' . $sede['id']))) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <?php if ($showEmpresa && count($empresas) > 1 && $filterEmpresaId <= 0): ?>
        <small class="auditoria-muted">Seleccione empresa para filtrar por sede.</small>
    <?php endif; ?>
</div>
<?php endif; ?>
