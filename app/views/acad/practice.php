<?php
/** @var array<string, mixed>|null $exercise */
/** @var list<array<string, mixed>> $options */
/** @var array<string, mixed>|null $lastAttempt */
/** @var list<array<string, mixed>> $referenceAudios */
/** @var array<int, array<string, mixed>|null> $audioSlotAttempts */
/** @var array<int, list<array<string, mixed>>> $tongueTwisterHistory */
/** @var int $dialogueRole */
/** @var list<array<string, mixed>> $dialogueTurns */
/** @var string $displayPrompt */
/** @var list<string> $scaffoldColumns */

if (!function_exists('acadH')) {
    function acadH(?string $v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

$estadoLabels = [14 => 'Pending review', 15 => 'Graded', 16 => 'Auto-graded'];
$isAudioResponse = $exercise !== null && $exercise['exercise_type_codigo'] === 'AUDIO_RESPONSE';
$isFillBlanks = $exercise !== null && $exercise['exercise_type_codigo'] === 'FILL_BLANKS';
$isSentenceOrder = $exercise !== null && $exercise['exercise_type_codigo'] === 'SENTENCE_ORDER';
$isTongueTwister = $exercise !== null && $exercise['exercise_type_codigo'] === 'TONGUE_TWISTER';
$isDialogue = $exercise !== null && $exercise['exercise_type_codigo'] === 'DIALOGUE';
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

            <?php if (!$isFillBlanks): ?>
                <p><?= nl2br(acadH($displayPrompt)) ?></p>
            <?php endif; ?>

            <?php if (!$isAudioResponse && !$isTongueTwister && !$isDialogue && $referenceAudios !== []): ?>
                <div class="field-note">
                    <strong>Listen:</strong>
                    <?php foreach ($referenceAudios as $ra): ?>
                        <p>
                            <?php if (!empty($ra['texto_en'])): ?><em><?= acadH($ra['texto_en']) ?></em><br><?php endif; ?>
                            <audio controls src="<?= acadH($ra['ruta']) ?>"></audio>
                        </p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!$isAudioResponse && !$isTongueTwister && !$isDialogue && $lastAttempt): ?>
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
                <p id="acadPracticeStatus" class="field-note"></p>
            <?php elseif ($exercise['exercise_type_codigo'] === 'OPEN_TEXT'): ?>
                <form id="acadWritingForm">
                    <textarea name="respuesta_texto" class="form-input" rows="6" style="width:100%" placeholder="Write your answer in English" required></textarea>
                    <br><button type="submit" class="sgd-doc-btn sgd-doc-btn-primary">Submit answer</button>
                </form>
                <p id="acadPracticeStatus" class="field-note"></p>
            <?php elseif ($isAudioResponse): ?>
                <?php if ($referenceAudios === []): ?>
                    <p class="sgd-doc-empty">No reference audio configured for this exercise yet.</p>
                <?php endif; ?>
                <?php foreach ($referenceAudios as $ra):
                    $raId = (int)$ra['id'];
                    $slotAttempt = $audioSlotAttempts[$raId] ?? null;
                ?>
                <div class="acad-audio-slot sgd-panel" style="margin:12px 0;padding:12px;" data-reference-audio-id="<?= $raId ?>">
                    <p>
                        <?php if (!empty($ra['texto_en'])): ?><strong><?= acadH($ra['texto_en']) ?></strong><br><?php endif; ?>
                        <audio controls src="<?= acadH($ra['ruta']) ?>"></audio>
                    </p>
                    <?php if ($slotAttempt): ?>
                        <div class="field-note">
                            Your recording: <audio controls src="<?= acadH($slotAttempt['audio_ruta']) ?>"></audio><br>
                            Status: <strong><?= acadH($estadoLabels[(int)$slotAttempt['estado_id']] ?? 'Pending') ?></strong>
                            <?php if ($slotAttempt['score'] !== null): ?>
                                — Score: <?= htmlspecialchars((string)round((float)$slotAttempt['score'], 1), ENT_QUOTES, 'UTF-8') ?> / <?= htmlspecialchars((string)round((float)$exercise['max_score'], 1), ENT_QUOTES, 'UTF-8') ?>
                            <?php endif; ?>
                            <?php if (!empty($slotAttempt['feedback_en'])): ?>
                                <br>Feedback: <?= nl2br(acadH($slotAttempt['feedback_en'])) ?>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="acad-audio-slot-controls">
                            <button type="button" class="sgd-doc-btn acad-slot-record">🎙️ Record</button>
                            <button type="button" class="sgd-doc-btn acad-slot-stop" disabled>⏹ Stop</button>
                            <br><audio class="acad-slot-preview" controls style="margin-top:8px;display:none;"></audio>
                            <br>
                            <button type="button" class="sgd-doc-btn acad-slot-delete" style="display:none;">🗑 Delete</button>
                            <button type="button" class="sgd-doc-btn sgd-doc-btn-primary acad-slot-confirm" disabled>✓ Confirm</button>
                            <span class="acad-slot-status field-note"></span>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            <?php elseif ($isFillBlanks): ?>
                <?php
                $segments = explode('___', (string)$exercise['prompt_en']);
                $totalBlanks = count($segments) - 1;
                ?>
                <p class="acad-fillblanks-text">
                    <?php foreach ($segments as $i => $seg): ?>
                        <?= nl2br(acadH($seg)) ?><?php if ($i < $totalBlanks): ?><span class="acad-blank" data-blank-index="<?= $i + 1 ?>"></span><?php endif; ?>
                    <?php endforeach; ?>
                </p>
                <?php if ($totalBlanks === 0 || $options === []): ?>
                    <p class="sgd-doc-empty">This exercise is not configured yet (no blanks or no word bank).</p>
                <?php else: ?>
                    <div class="acad-wordbank">
                        <?php foreach ($options as $opt): ?>
                            <button type="button" class="sgd-doc-btn acad-word-chip" data-option-id="<?= (int)$opt['id'] ?>"><?= acadH($opt['texto_en']) ?></button>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" id="acadFillBlanksSubmit" class="sgd-doc-btn sgd-doc-btn-primary" style="margin-top:10px;">Submit answer</button>
                <?php endif; ?>
                <p id="acadFillBlanksStatus" class="field-note"></p>
            <?php elseif ($isSentenceOrder): ?>
                <?php
                $sentenceGroups = [];
                foreach ($options as $opt) {
                    if ($opt['blank_index'] === null) {
                        continue;
                    }
                    $sentenceGroups[(int)$opt['blank_index']][] = $opt;
                }
                ksort($sentenceGroups);
                ?>
                <?php if ($sentenceGroups === []): ?>
                    <p class="sgd-doc-empty">This exercise is not configured yet (no sentences in the word bank).</p>
                <?php else: ?>
                    <?php if ($scaffoldColumns !== []): ?>
                        <div class="acad-order-scaffold">
                            <?php foreach ($scaffoldColumns as $col): ?>
                                <span class="acad-order-scaffold-col"><?= acadH($col) ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <?php foreach ($sentenceGroups as $sentenceIndex => $words): ?>
                        <div class="acad-order-row" data-sentence-index="<?= (int)$sentenceIndex ?>">
                            <?php foreach ($words as $slotPos => $w): ?>
                                <span class="acad-order-slot" data-sentence-index="<?= (int)$sentenceIndex ?>" data-slot-index="<?= $slotPos + 1 ?>"></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                    <div class="acad-wordbank">
                        <?php foreach ($options as $opt): ?>
                            <button type="button" class="sgd-doc-btn acad-word-chip" data-option-id="<?= (int)$opt['id'] ?>"><?= acadH($opt['texto_en']) ?></button>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" id="acadSentenceOrderSubmit" class="sgd-doc-btn sgd-doc-btn-primary" style="margin-top:10px;">Submit answer</button>
                <?php endif; ?>
                <p id="acadSentenceOrderStatus" class="field-note"></p>
            <?php elseif ($isTongueTwister): ?>
                <?php if ($referenceAudios === []): ?>
                    <p class="sgd-doc-empty">No reference audio configured for this exercise yet.</p>
                <?php endif; ?>
                <p class="field-note acad-tt-disclaimer">
                    ⏱ This measures how fast you can say it, not how well — it's just for fun and to see your own progress.
                    Your teacher will listen to your recordings to check your pronunciation.
                </p>
                <?php foreach ($referenceAudios as $ra):
                    $raId = (int)$ra['id'];
                    $history = $tongueTwisterHistory[$raId] ?? [];
                ?>
                <div class="acad-tt-slot sgd-panel" style="margin:12px 0;padding:12px;" data-reference-audio-id="<?= $raId ?>">
                    <p>
                        <?php if (!empty($ra['texto_en'])): ?><strong><?= acadH($ra['texto_en']) ?></strong><br><?php endif; ?>
                        <audio controls class="acad-tt-reference-audio" src="<?= acadH($ra['ruta']) ?>"></audio>
                        Target: <span class="acad-tt-reference-duration">…</span>
                    </p>
                    <?php if ($history !== []): ?>
                        <div class="field-note acad-tt-history">
                            <strong>Your attempts:</strong>
                            <?php $prevMs = null; foreach ($history as $i => $att):
                                $meta = json_decode((string)($att['meta_json'] ?? ''), true);
                                $ms = is_array($meta) ? ($meta['duration_ms'] ?? null) : null;
                                $trend = '';
                                if ($ms !== null && $prevMs !== null) {
                                    $trend = $ms < $prevMs ? ' 🔽 faster' : ($ms > $prevMs ? ' 🔼 slower' : ' = same');
                                }
                                if ($ms !== null) {
                                    $prevMs = $ms;
                                }
                            ?>
                                <div>
                                    #<?= $i + 1 ?> — <audio controls src="<?= acadH($att['audio_ruta']) ?>" style="height:28px;vertical-align:middle;"></audio>
                                    <?= $ms !== null ? htmlspecialchars(number_format($ms / 1000, 1), ENT_QUOTES, 'UTF-8') . 's' . $trend : '' ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <div class="acad-tt-controls">
                        <button type="button" class="sgd-doc-btn acad-tt-record">🎙️ Record</button>
                        <button type="button" class="sgd-doc-btn acad-tt-stop" disabled>⏹ Stop</button>
                        <br><audio class="acad-tt-preview" controls style="margin-top:8px;display:none;"></audio>
                        <br>
                        <button type="button" class="sgd-doc-btn acad-tt-delete" style="display:none;">🗑 Discard</button>
                        <button type="button" class="sgd-doc-btn sgd-doc-btn-primary acad-tt-confirm" disabled>✓ Save attempt</button>
                        <span class="acad-tt-status field-note"></span>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php elseif ($isDialogue): ?>
                <?php $otherExerciseUrl = '?url=acad/practice&exercise_id=' . (int)$exercise['id']; ?>
                <div class="acad-dlg-controls field-note">
                    <strong>Practice as:</strong>
                    <a href="<?= $otherExerciseUrl ?>&role=1" class="sgd-doc-btn <?= $dialogueRole === 1 ? 'sgd-doc-btn-primary' : '' ?>">Speaker A</a>
                    <a href="<?= $otherExerciseUrl ?>&role=2" class="sgd-doc-btn <?= $dialogueRole === 2 ? 'sgd-doc-btn-primary' : '' ?>">Speaker B</a>
                    <br><br>
                    <strong>Show text:</strong>
                    <button type="button" class="sgd-doc-btn sgd-doc-btn-primary acad-dlg-reveal-btn" data-mode="full">Full text</button>
                    <button type="button" class="sgd-doc-btn acad-dlg-reveal-btn" data-mode="initials">Initials only</button>
                    <button type="button" class="sgd-doc-btn acad-dlg-reveal-btn" data-mode="none">Hidden</button>
                </div>
                <?php if ($dialogueTurns === []): ?>
                    <p class="sgd-doc-empty">This dialogue is not configured yet.</p>
                <?php else: ?>
                    <?php foreach ($dialogueTurns as $t): ?>
                        <div class="acad-dlg-turn acad-dlg-turn-role<?= (int)$t['role'] ?><?= $t['isMine'] ? ' acad-dlg-turn-mine' : '' ?>">
                            <div class="acad-dlg-turn-header">
                                <span class="acad-dlg-speaker">Speaker <?= (int)$t['role'] === 1 ? 'A' : 'B' ?><?= $t['isMine'] ? ' (you)' : '' ?></span>
                                <?php if ($t['audio']): ?>
                                    <audio controls class="acad-dlg-audio" src="<?= acadH($t['audio']['ruta']) ?>"></audio>
                                <?php endif; ?>
                            </div>
                            <p class="acad-dlg-line" data-full="<?= acadH($t['texto_en']) ?>"><?= acadH($t['texto_en']) ?></p>

                            <?php if ($t['isMine'] && $t['audio']): ?>
                                <?php if ($t['attempt']): ?>
                                    <div class="field-note">
                                        Your recording: <audio controls class="acad-dlg-my-recording" src="<?= acadH($t['attempt']['audio_ruta']) ?>"></audio>
                                    </div>
                                <?php else: ?>
                                    <div class="acad-audio-slot" data-reference-audio-id="<?= (int)$t['audio']['id'] ?>">
                                        <div class="acad-audio-slot-controls">
                                            <button type="button" class="sgd-doc-btn acad-slot-record">🎙️ Record</button>
                                            <button type="button" class="sgd-doc-btn acad-slot-stop" disabled>⏹ Stop</button>
                                            <br><audio class="acad-slot-preview" controls style="margin-top:8px;display:none;"></audio>
                                            <br>
                                            <button type="button" class="sgd-doc-btn acad-slot-delete" style="display:none;">🗑 Delete</button>
                                            <button type="button" class="sgd-doc-btn sgd-doc-btn-primary acad-slot-confirm" disabled>✓ Confirm</button>
                                            <span class="acad-slot-status field-note"></span>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <button type="button" id="acadDialoguePlayAll" class="sgd-doc-btn sgd-doc-btn-primary" style="margin-top:14px;">▶ Play full conversation</button>
                    <p id="acadDialogueStatus" class="field-note"></p>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</div>

<script src="/js/acad-practice.js?v=<?= is_readable(BASE_PATH . '/public/js/acad-practice.js') ? (int)filemtime(BASE_PATH . '/public/js/acad-practice.js') : time() ?>"></script>
