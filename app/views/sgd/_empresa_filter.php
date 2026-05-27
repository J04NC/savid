<?php
/** @var array $scope */
$showEmpresa = !empty($scope['showEmpresaFilter']);
$empresaId = $scope['empresaId'] ?? '';
?>
<?php if ($showEmpresa): ?>
<form method="get" class="crud-list-search sgd-empresa-filter">
    <input type="hidden" name="url" value="<?= htmlspecialchars((string)($filterUrl ?? 'sgd'), ENT_QUOTES, 'UTF-8') ?>">
    <label for="sgd_empresa_id" class="crud-list-search-label">Empresa</label>
    <select name="empresa_id" id="sgd_empresa_id" class="form-input" style="max-width:360px;" onchange="this.form.submit()">
        <option value="">— Seleccione —</option>
        <?php foreach ($scope['empresas'] as $emp): ?>
            <option value="<?= (int)$emp['id'] ?>" <?= (int)$empresaId === (int)$emp['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars((string)($emp['razon_social'] ?? $emp['nombre'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
            </option>
        <?php endforeach; ?>
    </select>
</form>
<?php endif; ?>
