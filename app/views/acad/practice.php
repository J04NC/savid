<?php
/** @var array<string, mixed>|null $exercise */
/** @var list<array<string, mixed>> $options */
/** @var array<string, mixed>|null $lastAttempt */

if (!function_exists('acadH')) {
    function acadH(?string $v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

$estadoLabels = [14 => 'Pending review', 15 => 'Graded', 16 => 'Auto-graded'];
?>
<div class="module-container">
    <?php if (!$exercise): ?>
        <p class="field-note sgd-page-empty">Exercise not found. Go back to <a href="?url=acad/study">Study</a>.</p>
    <?php else: ?>
        <p class="field-note sgd-page-lead">
            <a href="?url=acad/study&level_id=<?= (int)$exercise['level_id'] ?>&module_id=<?= (int)$exercise['module_id'] ?>&unit_id=<?= (int)$exercise['unit_id'] ?>&lesson_id=<?= (int)$exercise['lesson_id'] ?>">← Back to lesson</a>
        </p>

        <section class="sgd-panel" id="acadPracticePanel"
                  data-exercise-id="<?= (int)$exercise['id'] ?>"
                  data-exercise-type="<?= acadH($exercise['exercise_type_codigo']) ?>">
            <header class="sgd-panel-head">
                <h3 class="sgd-panel-title"><?= acadH($exercise['titulo_en']) ?></h3>
                <span class="sgd-doc-badge"><?= acadH($exercise['skill_codigo']) ?></span>
            </header>

            <p><?= nl2br(acadH($exercise['prompt_en'])) ?></p>

            <?php if (!empty($exercise['audio_referencia_ruta'])): ?>
                <p><strong>Listen:</strong><br><audio controls src="<?= acadH($exercise['audio_referencia_ruta']) ?>"></audio></p>
            <?php endif; ?>

            <?php if ($lastAttempt): ?>
                <div class="field-note" id="acadLastAttempt">
                    Last attempt: <strong><?= acadH($estadoLabels[(int)$lastAttempt['estado_id']] ?? 'Pending') ?></strong>
                    <?php if ($lastAttempt['score'] !== null): ?>
                        — Score: <?= htmlspecialchars((string)round((float)$lastAttempt['score'], 1), ENT_QUOTES, 'UTF-8') ?> / <?= htmlspecialchars((string)round((float)$exercise['max_score'], 1), ENT_QUOTES, 'UTF-8') ?>
                    <?php endif; ?>
                    <?php if (!empty($lastAttempt['feedback_en'])): ?>
                        <br>Feedback: <?= nl2br(acadH($lastAttempt['feedback_en'])) ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($exercise['exercise_type_codigo'] === 'MULTIPLE_CHOICE'): ?>
                <form id="acadReadingForm">
                    <?php foreach ($options as $opt): ?>
                        <label style="display:block;margin:6px 0;">
                            <input type="radio" name="selected_option_id" value="<?= (int)$opt['id'] ?>" required>
                            <?= acadH($opt['texto_en']) ?>
                        </label>
                    <?php endforeach; ?>
                    <button type="submit" class="sgd-doc-btn sgd-doc-btn-primary">Submit answer</button>
                </form>
            <?php elseif ($exercise['exercise_type_codigo'] === 'OPEN_TEXT'): ?>
                <form id="acadWritingForm">
                    <textarea name="respuesta_texto" class="form-input" rows="6" style="width:100%" placeholder="Write your answer in English" required></textarea>
                    <br><button type="submit" class="sgd-doc-btn sgd-doc-btn-primary">Submit answer</button>
                </form>
            <?php elseif ($exercise['exercise_type_codigo'] === 'AUDIO_RESPONSE'): ?>
                <div id="acadSpeakingWidget">
                    <button type="button" id="acadRecordBtn" class="sgd-doc-btn">🎙️ Record</button>
                    <button type="button" id="acadStopBtn" class="sgd-doc-btn" disabled>⏹ Stop</button>
                    <input type="file" id="acadAudioFileInput" accept="audio/*">
                    <br><audio id="acadRecordedPreview" controls style="margin-top:8px;display:none;"></audio>
                    <br><button type="button" id="acadSpeakingSubmit" class="sgd-doc-btn sgd-doc-btn-primary" disabled>Submit recording</button>
                </div>
            <?php endif; ?>

            <p id="acadPracticeStatus" class="field-note"></p>
        </section>
    <?php endif; ?>
</div>

<script src="/js/acad-practice.js?v=<?= is_readable(BASE_PATH . '/public/js/acad-practice.js') ? (int)filemtime(BASE_PATH . '/public/js/acad-practice.js') : time() ?>"></script>
