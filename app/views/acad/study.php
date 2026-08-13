<?php
/** @var list<array<string, mixed>> $levels */
/** @var list<array<string, mixed>> $modules */
/** @var list<array<string, mixed>> $units */
/** @var list<array<string, mixed>> $lessons */
/** @var list<array<string, mixed>> $exercises */
/** @var array<string, mixed>|null $currentLevel */
/** @var array<string, mixed>|null $currentModule */
/** @var array<string, mixed>|null $currentUnit */
/** @var array<string, mixed>|null $currentLesson */

if (!function_exists('acadH')) {
    function acadH(?string $v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

$skillIcons = ['READING' => '📖', 'WRITING' => '✍️', 'SPEAKING' => '🗣️'];
?>
<div class="module-container">
    <p class="field-note sgd-page-lead">Browse the CEFR curriculum: level → module → unit → lesson → exercise.</p>

    <section class="sgd-panel">
        <header class="sgd-panel-head">
            <h3 class="sgd-panel-title">Levels</h3>
        </header>
        <div class="sgd-doc-table-wrap">
            <?php if ($levels === []): ?>
                <p class="sgd-doc-empty">No CEFR levels configured yet.</p>
            <?php else: ?>
                <?php foreach ($levels as $level): ?>
                    <a class="sgd-doc-btn<?= (int)($currentLevel['id'] ?? 0) === (int)$level['id'] ? ' sgd-doc-btn-primary' : '' ?>"
                       href="?url=acad/study&level_id=<?= (int)$level['id'] ?>">
                        <?= acadH($level['codigo']) ?> — <?= acadH($level['nombre_en']) ?>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>

    <?php if ($currentLevel): ?>
    <section class="sgd-panel">
        <header class="sgd-panel-head">
            <h3 class="sgd-panel-title">Modules — <?= acadH($currentLevel['codigo']) ?></h3>
        </header>
        <div class="sgd-doc-table-wrap">
            <?php if ($modules === []): ?>
                <p class="sgd-doc-empty">No modules yet for this level.</p>
            <?php else: ?>
                <?php foreach ($modules as $module): ?>
                    <a class="sgd-doc-btn<?= (int)($currentModule['id'] ?? 0) === (int)$module['id'] ? ' sgd-doc-btn-primary' : '' ?>"
                       href="?url=acad/study&level_id=<?= (int)$currentLevel['id'] ?>&module_id=<?= (int)$module['id'] ?>">
                        <?= acadH($module['titulo_en']) ?>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($currentModule): ?>
    <section class="sgd-panel">
        <header class="sgd-panel-head">
            <h3 class="sgd-panel-title">Units — <?= acadH($currentModule['titulo_en']) ?></h3>
        </header>
        <div class="sgd-doc-table-wrap">
            <?php if ($units === []): ?>
                <p class="sgd-doc-empty">No units yet for this module.</p>
            <?php else: ?>
                <?php foreach ($units as $unit): ?>
                    <a class="sgd-doc-btn<?= (int)($currentUnit['id'] ?? 0) === (int)$unit['id'] ? ' sgd-doc-btn-primary' : '' ?>"
                       href="?url=acad/study&level_id=<?= (int)$currentLevel['id'] ?>&module_id=<?= (int)$currentModule['id'] ?>&unit_id=<?= (int)$unit['id'] ?>">
                        <?= acadH($unit['titulo_en']) ?>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($currentUnit): ?>
    <section class="sgd-panel">
        <header class="sgd-panel-head">
            <h3 class="sgd-panel-title">Lessons — <?= acadH($currentUnit['titulo_en']) ?></h3>
        </header>
        <div class="sgd-doc-table-wrap">
            <?php if ($lessons === []): ?>
                <p class="sgd-doc-empty">No lessons yet for this unit.</p>
            <?php else: ?>
                <?php foreach ($lessons as $lesson): ?>
                    <a class="sgd-doc-btn<?= (int)($currentLesson['id'] ?? 0) === (int)$lesson['id'] ? ' sgd-doc-btn-primary' : '' ?>"
                       href="?url=acad/study&level_id=<?= (int)$currentLevel['id'] ?>&module_id=<?= (int)$currentModule['id'] ?>&unit_id=<?= (int)$currentUnit['id'] ?>&lesson_id=<?= (int)$lesson['id'] ?>">
                        <?= acadH($lesson['titulo_en']) ?>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($currentLesson): ?>
    <section class="sgd-panel">
        <header class="sgd-panel-head">
            <h3 class="sgd-panel-title">Exercises — <?= acadH($currentLesson['titulo_en']) ?></h3>
            <span class="sgd-doc-count"><?= count($exercises) ?> exercise(s)</span>
        </header>
        <div class="sgd-doc-table-wrap">
            <table class="sgd-doc-table">
                <thead>
                    <tr><th>Skill</th><th>Title</th><th>Type</th><th></th></tr>
                </thead>
                <tbody>
                    <?php if ($exercises === []): ?>
                        <tr><td colspan="4" class="sgd-doc-empty">No exercises yet for this lesson.</td></tr>
                    <?php else: ?>
                        <?php foreach ($exercises as $ex): ?>
                            <tr>
                                <td><?= $skillIcons[$ex['skill_codigo']] ?? '' ?> <?= acadH($ex['skill_codigo']) ?></td>
                                <td><?= acadH($ex['titulo_en']) ?></td>
                                <td><span class="sgd-doc-badge"><?= acadH($ex['exercise_type_codigo']) ?></span></td>
                                <td><a class="sgd-doc-btn sgd-doc-btn-sm sgd-doc-btn-primary" href="?url=acad/practice&exercise_id=<?= (int)$ex['id'] ?>">Practice</a></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>
</div>
