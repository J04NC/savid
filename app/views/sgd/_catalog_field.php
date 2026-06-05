<?php
/**
 * Campo autocomplete local SGD (misma UX que crud-catalog-wrap).
 *
 * @var string $name
 * @var string $inputId
 * @var string|int|null $value
 * @var string $displayValue
 * @var string $placeholder
 * @var bool $required
 * @var string $catalogKey dependencias|series|subseries|documentos
 * @var string $parentFieldsJson JSON array de nombres de campo padre
 * @var string $extraAttrs attrs extra en el hidden (ej. data-sgd-ccd-field)
 */
$parentFieldsJson = $parentFieldsJson ?? '[]';
$value = $value ?? '';
$displayValue = $displayValue ?? '';
$required = !empty($required);
$placeholder = $placeholder ?? 'Buscar…';
?>
<div class="crud-catalog-wrap sgd-local-catalog-wrap"
     data-sgd-catalog-key="<?= htmlspecialchars($catalogKey, ENT_QUOTES, 'UTF-8') ?>"
     data-sgd-catalog-parents="<?= htmlspecialchars($parentFieldsJson, ENT_QUOTES, 'UTF-8') ?>"
     <?= !empty($allowEmpty) ? 'data-sgd-catalog-allow-empty="1"' : '' ?>
     <?= !empty($submitOnPick) ? 'data-sgd-catalog-submit-on-pick="1"' : '' ?>>
    <input type="hidden"
           name="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>"
           id="<?= htmlspecialchars($inputId, ENT_QUOTES, 'UTF-8') ?>"
           value="<?= htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') ?>"
           class="form-input crud-catalog-id sgd-local-catalog-id"
           <?= $required ? 'required' : '' ?>
           <?= $extraAttrs ?? '' ?>>
    <input type="search"
           id="<?= htmlspecialchars($inputId, ENT_QUOTES, 'UTF-8') ?>_search"
           value="<?= htmlspecialchars($displayValue, ENT_QUOTES, 'UTF-8') ?>"
           placeholder="<?= htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8') ?>"
           autocomplete="off"
           class="form-input crud-catalog-search sgd-local-catalog-search">
    <ul class="crud-catalog-dropdown" hidden></ul>
</div>
