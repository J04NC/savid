<?php
/**
 * Ayuda contextual (tooltip visual).
 *
 * @var string $helpId   Identificador único
 * @var string $helpLabel Texto para aria-label del botón
 * @var string $helpBody  Explicación principal
 * @var string $helpExample Ejemplo opcional
 */
$helpId = preg_replace('/[^a-z0-9_-]/i', '', (string)($helpId ?? 'help'));
$helpLabel = (string)($helpLabel ?? 'Ayuda');
$helpBody = (string)($helpBody ?? '');
$helpExample = (string)($helpExample ?? '');
$tipId = 'sgd-tip-' . $helpId;
?>
<span class="sgd-help-wrap" data-sgd-help>
    <button
        type="button"
        class="sgd-info"
        aria-expanded="false"
        aria-controls="<?= htmlspecialchars($tipId, ENT_QUOTES, 'UTF-8') ?>"
        aria-label="<?= htmlspecialchars($helpLabel, ENT_QUOTES, 'UTF-8') ?>"
    >?</button>
    <span
        id="<?= htmlspecialchars($tipId, ENT_QUOTES, 'UTF-8') ?>"
        class="sgd-tooltip"
        role="tooltip"
    >
        <span class="sgd-tooltip-body"><?= htmlspecialchars($helpBody, ENT_QUOTES, 'UTF-8') ?></span>
        <?php if ($helpExample !== ''): ?>
            <span class="sgd-tooltip-example"><?= htmlspecialchars($helpExample, ENT_QUOTES, 'UTF-8') ?></span>
        <?php endif; ?>
    </span>
</span>
