<?php
/** @var list<array<string, mixed>> $students */

if (!function_exists('acadH')) {
    function acadH(?string $v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}
?>
<div class="module-container">
    <p class="field-note sgd-page-lead">Progress of enrolled students in the current company.</p>

    <section class="sgd-panel">
        <header class="sgd-panel-head">
            <h3 class="sgd-panel-title">Students</h3>
            <span class="sgd-doc-count"><?= count($students) ?> student(s)</span>
        </header>
        <div class="sgd-doc-table-wrap">
            <?php if ($students === []): ?>
                <p class="sgd-doc-empty">No enrolled students yet.</p>
            <?php else: ?>
                <table class="sgd-doc-table savid-datatable" data-dt-page-length="25" data-dt-buttons="false">
                    <thead>
                        <tr><th>Student</th><th>Level</th><th>Attempts</th><th>Graded</th><th>Pending</th><th>Avg. score</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $s): ?>
                            <tr>
                                <td><?= acadH($s['username']) ?></td>
                                <td><span class="sgd-doc-badge"><?= acadH((string)($s['level_codigo'] ?? '')) ?></span></td>
                                <td><?= (int)$s['intentos'] ?></td>
                                <td><?= (int)$s['calificados'] ?></td>
                                <td><?= (int)$s['pendientes'] ?></td>
                                <td><?= $s['promedio_score'] !== null ? htmlspecialchars((string)$s['promedio_score'], ENT_QUOTES, 'UTF-8') : '—' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </section>
</div>
