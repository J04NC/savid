<?php
/**
 * Campo de formulario al estilo CRUD automático.
 *
 * @var string $name
 * @var string $label
 * @var string $type text|number|checkbox|file
 * @var mixed $value
 * @var string $placeholder
 * @var string $helpId
 * @var string $helpLabel
 * @var string $helpBody
 * @var string $helpExample
 * @var string $fieldNote HTML permitido solo interno (tags b, code)
 * @var string $inputClass
 * @var string $groupClass
 * @var string $datalistId
 * @var list<string> $datalistOptions
 * @var array<string, string> $inputAttrs atributos extra
 */
$name = (string)($name ?? '');
$label = (string)($label ?? '');
$type = (string)($type ?? 'text');
$value = $value ?? '';
$placeholder = (string)($placeholder ?? '');
$helpId = (string)($helpId ?? $name);
$helpLabel = (string)($helpLabel ?? ('Ayuda: ' . $label));
$helpBody = (string)($helpBody ?? '');
$helpExample = (string)($helpExample ?? '');
$fieldNote = (string)($fieldNote ?? '');
$inputClass = (string)($inputClass ?? 'form-input');
$groupClass = (string)($groupClass ?? '');
$datalistId = (string)($datalistId ?? '');
$datalistOptions = $datalistOptions ?? [];
$inputAttrs = $inputAttrs ?? [];

$groupClass = trim('form-group ' . $groupClass);
?>
<div class="<?= htmlspecialchars($groupClass, ENT_QUOTES, 'UTF-8') ?>">
    <?php if ($type === 'checkbox'): ?>
        <div class="sgd-check-row">
            <label class="sgd-check crud-checkbox-label">
                <input
                    type="checkbox"
                    name="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>"
                    value="1"
                    <?= !empty($value) ? 'checked' : '' ?>
                >
                <span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
            </label>
            <?php if ($helpBody !== ''): ?>
                <?php require BASE_PATH . '/app/views/sgd/_field_help.php'; ?>
            <?php endif; ?>
        </div>
        <?php if ($fieldNote !== ''): ?>
            <p class="field-note"><?= $fieldNote ?></p>
        <?php endif; ?>
    <?php else: ?>
        <label class="sgd-label-with-help" for="sgd_<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>">
            <span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
            <?php if ($helpBody !== ''): ?>
                <?php require BASE_PATH . '/app/views/sgd/_field_help.php'; ?>
            <?php endif; ?>
        </label>

        <?php
        $attrHtml = '';
        foreach ($inputAttrs as $ak => $av) {
            $attrHtml .= ' ' . htmlspecialchars((string)$ak, ENT_QUOTES, 'UTF-8')
                . '="' . htmlspecialchars((string)$av, ENT_QUOTES, 'UTF-8') . '"';
        }
        $listAttr = $datalistId !== '' ? ' list="' . htmlspecialchars($datalistId, ENT_QUOTES, 'UTF-8') . '"' : '';
        ?>

        <input
            type="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>"
            class="<?= htmlspecialchars($inputClass, ENT_QUOTES, 'UTF-8') ?>"
            id="sgd_<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>"
            name="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>"
            value="<?= htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') ?>"
            placeholder="<?= htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8') ?>"
            <?= $listAttr ?>
            <?= $attrHtml ?>
        >

        <?php if ($datalistId !== '' && $datalistOptions !== []): ?>
            <datalist id="<?= htmlspecialchars($datalistId, ENT_QUOTES, 'UTF-8') ?>">
                <?php foreach ($datalistOptions as $opt): ?>
                    <option value="<?= htmlspecialchars((string)$opt, ENT_QUOTES, 'UTF-8') ?>">
                <?php endforeach; ?>
            </datalist>
        <?php endif; ?>

        <?php if ($fieldNote !== ''): ?>
            <p class="field-note"><?= $fieldNote ?></p>
        <?php endif; ?>
    <?php endif; ?>
</div>
