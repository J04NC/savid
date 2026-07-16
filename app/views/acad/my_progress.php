<?php
/** @var array<string, mixed>|null $enrolment */
/** @var list<array<string, mixed>> $bySkill */

if (!function_exists('acadH')) {
    function acadH(?string $v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}
?>
<div class="module-container">
    <?php if (!$enrolment): ?>
        <p class="field-note sgd-page-empty">You are not enrolled in a CEFR level yet. Contact your coordinator.</p>
    <?php else: ?>
        <p class="field-note sgd-page-lead">
            Current level: <strong><?= acadH($enrolment['level_codigo']) ?> — <?= acadH($enrolment['level_nombre_en']) ?></strong>
        </p>

        <section class="sgd-panel">
            <header class="sgd-panel-head">
                <h3 class="sgd-panel-title">Progress by skill</h3>
            </header>
            <div class="sgd-doc-table-wrap">
                <?php if ($bySkill === []): ?>
                    <p class="sgd-doc-empty">No attempts yet. Go to <a href="?url=acad/study">Study</a> to get started.</p>
                <?php else: ?>
                    <table class="sgd-doc-table">
                        <thead>
                            <tr><th>Skill</th><th>Attempts</th><th>Graded</th><th>Avg. score</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bySkill as $row): ?>
                                <tr>
                                    <td><span class="sgd-doc-badge"><?= acadH($row['skill_codigo']) ?></span></td>
                                    <td><?= (int)$row['intentos'] ?></td>
                                    <td><?= (int)$row['calificados'] ?></td>
                                    <td><?= $row['promedio_score'] !== null ? htmlspecialchars((string)$row['promedio_score'], ENT_QUOTES, 'UTF-8') : '—' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>
</div>
