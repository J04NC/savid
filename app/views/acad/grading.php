<?php
/** @var list<array<string, mixed>> $pending */

if (!function_exists('acadH')) {
    function acadH(?string $v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}
?>
<div class="module-container">
    <p class="field-note sgd-page-lead">Writing and speaking attempts waiting for a grade.</p>

    <section class="sgd-panel">
        <header class="sgd-panel-head">
            <h3 class="sgd-panel-title">Pending attempts</h3>
            <span class="sgd-doc-count"><?= count($pending) ?> pending</span>
        </header>
        <div class="sgd-doc-table-wrap">
            <?php if ($pending === []): ?>
                <p class="sgd-doc-empty">Nothing to grade right now.</p>
            <?php else: ?>
                <table class="sgd-doc-table">
                    <thead>
                        <tr><th>Student</th><th>Exercise</th><th>Skill</th><th>Answer</th><th>Grade</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pending as $a): ?>
                            <tr>
                                <td><?= acadH($a['estudiante_username']) ?></td>
                                <td><?= acadH($a['exercise_titulo_en']) ?></td>
                                <td><span class="sgd-doc-badge"><?= acadH($a['skill_codigo']) ?></span></td>
                                <td>
                                    <?php if (!empty($a['audio_ruta'])): ?>
                                        <audio controls src="<?= acadH($a['audio_ruta']) ?>"></audio>
                                    <?php else: ?>
                                        <span title="<?= acadH($a['respuesta_texto']) ?>">
                                            <?= acadH(mb_substr((string)($a['respuesta_texto'] ?? ''), 0, 140)) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form class="acad-grade-form" data-attempt-id="<?= (int)$a['id'] ?>">
                                        <input type="number" step="0.1" min="0" max="<?= htmlspecialchars((string)$a['max_score'], ENT_QUOTES, 'UTF-8') ?>"
                                               name="score" class="form-input" style="width:80px" placeholder="0-<?= htmlspecialchars((string)round((float)$a['max_score'], 1), ENT_QUOTES, 'UTF-8') ?>" required>
                                        <textarea name="feedback_en" class="form-input" style="width:220px" rows="2" placeholder="Feedback (English)"></textarea>
                                        <button type="submit" class="sgd-doc-btn sgd-doc-btn-primary sgd-doc-btn-sm">Grade</button>
                                        <span class="acad-grade-status"></span>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </section>
</div>

<script>
document.addEventListener("DOMContentLoaded", function () {
    document.querySelectorAll(".acad-grade-form").forEach(function (form) {
        form.addEventListener("submit", function (ev) {
            ev.preventDefault();
            var status = form.querySelector(".acad-grade-status");
            var body = new URLSearchParams();
            body.set("attempt_id", form.getAttribute("data-attempt-id"));
            body.set("score", form.querySelector('[name="score"]').value);
            body.set("feedback_en", form.querySelector('[name="feedback_en"]').value);
            status.textContent = "Saving...";
            fetch("?url=acad/grading", { method: "POST", body: body })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    status.textContent = data.message || "Done.";
                    if (data.success) {
                        form.closest("tr").style.opacity = "0.5";
                        form.querySelectorAll("input,textarea,button").forEach(function (el) { el.disabled = true; });
                    }
                })
                .catch(function () { status.textContent = "Network error."; });
        });
    });
});
</script>
