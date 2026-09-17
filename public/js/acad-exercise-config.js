(function () {
    "use strict";

    function qs(sel, ctx) {
        return (ctx || document).querySelector(sel);
    }

    function escapeHtml(s) {
        var d = document.createElement("div");
        d.textContent = s || "";
        return d.innerHTML;
    }

    function currentExerciseId() {
        var hid = document.getElementById("crud_id");
        var id = hid ? parseInt(hid.value, 10) : 0;
        return isNaN(id) ? 0 : id;
    }

    function currentExerciseTypeCode() {
        var sel = qs('[name="exercise_type_id"]');
        if (!sel || sel.selectedIndex < 0) return "";
        var opt = sel.options[sel.selectedIndex];
        return opt ? (opt.textContent || "").trim() : "";
    }

    function ensurePanel(form) {
        var panel = document.getElementById("acadExercisePanel");
        if (panel) return panel;

        panel = document.createElement("div");
        panel.id = "acadExercisePanel";
        panel.className = "crud-form-section";
        panel.style.marginTop = "16px";
        panel.style.display = "none";

        var toolbar = form.querySelector(".crud-toolbar") || form.querySelector(".form-toolbar");
        if (toolbar && toolbar.parentNode) {
            toolbar.parentNode.insertBefore(panel, toolbar);
        } else {
            form.appendChild(panel);
        }

        return panel;
    }

    function renderNote(panel, text) {
        panel.innerHTML = "<p class=\"crud-hint\">" + text + "</p>";
    }

    function ensureSections(panel) {
        if (!qs("#acadReferenceAudioSection", panel)) {
            panel.innerHTML =
                '<div id="acadReferenceAudioSection"></div>' +
                '<div id="acadOptionsSection" style="margin-top:20px;"></div>';
        }

        return {
            audio: qs("#acadReferenceAudioSection", panel),
            options: qs("#acadOptionsSection", panel),
        };
    }

    function renderOptionsEditor(container, exerciseId) {
        container.innerHTML =
            "<h3>Multiple choice options</h3>" +
            "<div id=\"acadOptionsList\"></div>" +
            "<button type=\"button\" id=\"acadOptionAdd\" class=\"sgd-doc-btn\">＋ Add option</button> " +
            "<button type=\"button\" id=\"acadOptionsSave\" class=\"sgd-doc-btn sgd-doc-btn-primary\">Save options</button> " +
            "<span id=\"acadOptionsStatus\"></span>";

        var list = qs("#acadOptionsList", container);

        function addRow(texto, esCorrecta) {
            var row = document.createElement("div");
            row.className = "acad-option-row";
            row.style.margin = "6px 0";
            row.innerHTML =
                '<input type="checkbox" class="acad-option-correct"' + (esCorrecta ? " checked" : "") + '> ' +
                '<input type="text" class="acad-option-text form-input" style="width:60%" placeholder="Option text (English)" value="">' +
                ' <button type="button" class="acad-option-remove sgd-doc-btn sgd-doc-btn-danger">✕</button>';
            row.querySelector(".acad-option-text").value = texto || "";
            row.querySelector(".acad-option-remove").addEventListener("click", function () {
                row.remove();
            });
            list.appendChild(row);
        }

        fetch("?url=acad/exerciseOptions&exercise_id=" + exerciseId)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success && Array.isArray(data.options) && data.options.length) {
                    data.options.forEach(function (o) { addRow(o.texto_en, o.es_correcta); });
                } else {
                    addRow("", false);
                    addRow("", false);
                }
            })
            .catch(function () { addRow("", false); addRow("", false); });

        qs("#acadOptionAdd", container).addEventListener("click", function () { addRow("", false); });

        qs("#acadOptionsSave", container).addEventListener("click", function () {
            var options = [];
            list.querySelectorAll(".acad-option-row").forEach(function (row) {
                options.push({
                    texto_en: row.querySelector(".acad-option-text").value,
                    es_correcta: row.querySelector(".acad-option-correct").checked,
                });
            });

            var status = qs("#acadOptionsStatus", container);
            status.textContent = "Saving...";

            var body = new URLSearchParams();
            body.set("exercise_id", String(exerciseId));
            body.set("options", JSON.stringify(options));

            fetch("?url=acad/exerciseOptions", { method: "POST", body: body })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    status.textContent = data.message || (data.success ? "Saved." : "Error.");
                })
                .catch(function () { status.textContent = "Network error."; });
        });
    }

    /**
     * Bolsa de palabras de un ejercicio "Fill in the blanks": cada fila es una
     * palabra + el hueco (1..N) que resuelve, vacío = señuelo. Incluye un
     * contador en vivo de "___" detectados en el campo Prompt del propio
     * formulario, para que el admin vea si su bolsa cubre todos los huecos
     * antes de guardar (la validación real la hace el backend igual).
     */
    function renderWordBankEditor(container, exerciseId, form) {
        container.innerHTML =
            "<h3>Word bank (Fill in the blanks)</h3>" +
            '<p class="crud-hint" id="acadWordBankBlankCount"></p>' +
            "<div id=\"acadWordBankList\"></div>" +
            "<button type=\"button\" id=\"acadWordAdd\" class=\"sgd-doc-btn\">＋ Add word</button> " +
            "<button type=\"button\" id=\"acadWordBankSave\" class=\"sgd-doc-btn sgd-doc-btn-primary\">Save word bank</button> " +
            "<span id=\"acadWordBankStatus\"></span>";

        var list = qs("#acadWordBankList", container);
        var blankCountEl = qs("#acadWordBankBlankCount", container);
        var promptField = qs('[name="prompt_en"]', form);

        function updateBlankCount() {
            if (!blankCountEl) return;
            var text = promptField ? (promptField.value || "") : "";
            var count = (text.match(/___/g) || []).length;
            blankCountEl.textContent = "Detected " + count + " blank" + (count === 1 ? "" : "s") + " (___) in the Prompt.";
        }

        if (promptField) {
            promptField.addEventListener("input", updateBlankCount);
        }
        updateBlankCount();

        function addRow(texto, blankIndex) {
            var row = document.createElement("div");
            row.className = "acad-word-row";
            row.style.margin = "6px 0";
            row.innerHTML =
                '<input type="text" class="acad-word-text form-input" style="width:50%" placeholder="Word or phrase (English)" value="">' +
                ' <input type="number" class="acad-word-blank form-input" style="width:90px" min="1" placeholder="Blank #" value="">' +
                ' <button type="button" class="acad-word-remove sgd-doc-btn sgd-doc-btn-danger">✕</button>';
            row.querySelector(".acad-word-text").value = texto || "";
            row.querySelector(".acad-word-blank").value = blankIndex !== null && blankIndex !== undefined ? blankIndex : "";
            row.querySelector(".acad-word-remove").addEventListener("click", function () {
                row.remove();
            });
            list.appendChild(row);
        }

        fetch("?url=acad/exerciseWordBank&exercise_id=" + exerciseId)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success && Array.isArray(data.words) && data.words.length) {
                    data.words.forEach(function (w) { addRow(w.texto_en, w.blank_index); });
                } else {
                    addRow("", 1);
                    addRow("", "");
                }
            })
            .catch(function () { addRow("", 1); addRow("", ""); });

        qs("#acadWordAdd", container).addEventListener("click", function () { addRow("", ""); });

        qs("#acadWordBankSave", container).addEventListener("click", function () {
            var words = [];
            list.querySelectorAll(".acad-word-row").forEach(function (row) {
                var blankVal = row.querySelector(".acad-word-blank").value;
                words.push({
                    texto_en: row.querySelector(".acad-word-text").value,
                    blank_index: blankVal === "" ? null : parseInt(blankVal, 10),
                });
            });

            var status = qs("#acadWordBankStatus", container);
            status.textContent = "Saving...";

            var body = new URLSearchParams();
            body.set("exercise_id", String(exerciseId));
            body.set("words", JSON.stringify(words));

            fetch("?url=acad/exerciseWordBank", { method: "POST", body: body })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    status.textContent = data.message || (data.success ? "Saved." : "Error.");
                })
                .catch(function () { status.textContent = "Network error."; });
        });
    }

    /**
     * "Sentence order": el admin escribe cada oración como texto plano (se
     * tokeniza por espacios en el backend, no hay que capturar palabra por
     * palabra ni su posición a mano) más una bolsa opcional de palabras
     * señuelo compartida por todo el ejercicio.
     */
    function renderSentenceOrderEditor(container, exerciseId) {
        container.innerHTML =
            "<h3>Sentence order</h3>" +
            '<p class="crud-hint">Write each sentence in the correct order, as plain text — it will be split into words automatically.</p>' +
            "<div id=\"acadSentenceList\"></div>" +
            "<button type=\"button\" id=\"acadSentenceAdd\" class=\"sgd-doc-btn\">＋ Add sentence</button><br><br>" +
            '<label class="crud-hint">Distractor words (comma-separated, optional):</label><br>' +
            '<input type="text" id="acadSentenceDistractors" class="form-input" style="width:80%" placeholder="e.g. are, plays, forest"><br><br>' +
            "<button type=\"button\" id=\"acadSentenceOrderSave\" class=\"sgd-doc-btn sgd-doc-btn-primary\">Save sentences</button> " +
            "<span id=\"acadSentenceOrderSaveStatus\"></span>";

        var list = qs("#acadSentenceList", container);
        var distractorsInput = qs("#acadSentenceDistractors", container);

        function addRow(text) {
            var row = document.createElement("div");
            row.className = "acad-sentence-row";
            row.style.margin = "6px 0";
            row.innerHTML =
                '<input type="text" class="acad-sentence-text form-input" style="width:70%" placeholder="e.g. I read books at home" value="">' +
                ' <button type="button" class="acad-sentence-remove sgd-doc-btn sgd-doc-btn-danger">✕</button>';
            row.querySelector(".acad-sentence-text").value = text || "";
            row.querySelector(".acad-sentence-remove").addEventListener("click", function () {
                row.remove();
            });
            list.appendChild(row);
        }

        fetch("?url=acad/exerciseSentenceOrder&exercise_id=" + exerciseId)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success && Array.isArray(data.sentences) && data.sentences.length) {
                    data.sentences.forEach(function (s) { addRow(s); });
                } else {
                    addRow("");
                }
                if (data.success && Array.isArray(data.distractors)) {
                    distractorsInput.value = data.distractors.map(function (d) { return d.texto_en; }).join(", ");
                }
            })
            .catch(function () { addRow(""); });

        qs("#acadSentenceAdd", container).addEventListener("click", function () { addRow(""); });

        qs("#acadSentenceOrderSave", container).addEventListener("click", function () {
            var sentences = [];
            list.querySelectorAll(".acad-sentence-text").forEach(function (input) {
                var v = input.value.trim();
                if (v) sentences.push(v);
            });
            var distractors = (distractorsInput.value || "")
                .split(",")
                .map(function (w) { return w.trim(); })
                .filter(function (w) { return w !== ""; });

            var status = qs("#acadSentenceOrderSaveStatus", container);
            status.textContent = "Saving...";

            var body = new URLSearchParams();
            body.set("exercise_id", String(exerciseId));
            body.set("sentences", JSON.stringify(sentences));
            body.set("distractors", JSON.stringify(distractors));

            fetch("?url=acad/exerciseSentenceOrder", { method: "POST", body: body })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    status.textContent = data.message || (data.success ? "Saved." : "Error.");
                })
                .catch(function () { status.textContent = "Network error."; });
        });
    }

    /**
     * "Dialogue": lista de turnos (rol + línea) más un generador de voces
     * que sintetiza TODOS los turnos de una vez con una voz por rol (para
     * sonar a dos hablantes distintos). A diferencia de FILL_BLANKS/
     * SENTENCE_ORDER, aquí este editor reemplaza también al gestor genérico
     * de audio de referencia (sections.audio queda vacío para este tipo,
     * ver refresh()) porque el audio de un diálogo SIEMPRE se genera en
     * bloque, emparejado turno a turno — agregar clips sueltos ahí rompería
     * ese emparejamiento por `orden`.
     */
    function renderDialogueEditor(container, exerciseId) {
        var voices = [
            ["en-US-JennyNeural", "Jenny (female)"],
            ["en-US-AriaNeural", "Aria (female)"],
            ["en-US-MichelleNeural", "Michelle (female)"],
            ["en-US-AnaNeural", "Ana (female, child-like)"],
            ["en-US-GuyNeural", "Guy (male)"],
            ["en-US-AndrewNeural", "Andrew (male)"],
            ["en-US-BrianNeural", "Brian (male)"],
            ["en-US-ChristopherNeural", "Christopher (male)"],
            ["en-US-EricNeural", "Eric (male)"],
            ["en-US-RogerNeural", "Roger (male)"],
            ["en-US-SteffanNeural", "Steffan (male)"],
        ];

        function voiceOptions(selected) {
            return voices.map(function (v) {
                return '<option value="' + v[0] + '"' + (v[0] === selected ? " selected" : "") + '>' + escapeHtml(v[1]) + "</option>";
            }).join("");
        }

        container.innerHTML =
            "<h3>Dialogue turns</h3>" +
            '<p class="crud-hint">Each turn is one line said by Speaker A or Speaker B, in order.</p>' +
            "<div id=\"acadDialogueList\"></div>" +
            "<button type=\"button\" id=\"acadTurnAdd\" class=\"sgd-doc-btn\">＋ Add turn</button> " +
            "<button type=\"button\" id=\"acadDialogueSave\" class=\"sgd-doc-btn sgd-doc-btn-primary\">Save turns</button> " +
            "<span id=\"acadDialogueSaveStatus\"></span>" +
            '<div style="margin-top:16px;padding-top:10px;border-top:1px dashed #444;">' +
            "<h4>Generate voices</h4>" +
            '<p class="crud-hint">Save the turns first, then pick a voice for each speaker. This regenerates ALL turn audio at once.</p>' +
            'Speaker A voice: <select id="acadDlgVoice1">' + voiceOptions("en-US-JennyNeural") + "</select><br>" +
            'Speaker B voice: <select id="acadDlgVoice2">' + voiceOptions("en-US-GuyNeural") + "</select><br>" +
            '<button type="button" id="acadDlgGenerateVoices" class="sgd-doc-btn sgd-doc-btn-primary" style="margin-top:8px;">🔊 Generate voices for all turns</button> ' +
            '<span id="acadDlgVoiceStatus"></span>' +
            "</div>";

        var list = qs("#acadDialogueList", container);

        function addRow(texto, role) {
            var row = document.createElement("div");
            row.className = "acad-turn-row";
            row.style.margin = "6px 0";
            row.innerHTML =
                '<select class="acad-turn-role">' +
                '<option value="1"' + (role === 2 ? "" : " selected") + ">Speaker A</option>" +
                '<option value="2"' + (role === 2 ? " selected" : "") + ">Speaker B</option>" +
                "</select> " +
                '<input type="text" class="acad-turn-text form-input" style="width:55%" placeholder="Line for this turn" value="">' +
                ' <button type="button" class="acad-turn-remove sgd-doc-btn sgd-doc-btn-danger">✕</button>';
            row.querySelector(".acad-turn-text").value = texto || "";
            row.querySelector(".acad-turn-remove").addEventListener("click", function () { row.remove(); });
            list.appendChild(row);
        }

        fetch("?url=acad/exerciseDialogueTurns&exercise_id=" + exerciseId)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success && Array.isArray(data.turns) && data.turns.length) {
                    data.turns.forEach(function (t) { addRow(t.texto_en, t.role); });
                } else {
                    addRow("", 1);
                    addRow("", 2);
                }
            })
            .catch(function () { addRow("", 1); addRow("", 2); });

        qs("#acadTurnAdd", container).addEventListener("click", function () { addRow("", 1); });

        qs("#acadDialogueSave", container).addEventListener("click", function () {
            var turns = [];
            list.querySelectorAll(".acad-turn-row").forEach(function (row) {
                turns.push({
                    texto_en: row.querySelector(".acad-turn-text").value,
                    role: parseInt(row.querySelector(".acad-turn-role").value, 10),
                });
            });

            var status = qs("#acadDialogueSaveStatus", container);
            status.textContent = "Saving...";

            var body = new URLSearchParams();
            body.set("exercise_id", String(exerciseId));
            body.set("turns", JSON.stringify(turns));

            fetch("?url=acad/exerciseDialogueTurns", { method: "POST", body: body })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    status.textContent = data.message || (data.success ? "Saved." : "Error.");
                })
                .catch(function () { status.textContent = "Network error."; });
        });

        qs("#acadDlgGenerateVoices", container).addEventListener("click", function () {
            var status = qs("#acadDlgVoiceStatus", container);
            var btn = qs("#acadDlgGenerateVoices", container);
            status.textContent = "Generating voices for all turns… this can take a bit.";
            btn.disabled = true;

            var body = new URLSearchParams();
            body.set("exercise_id", String(exerciseId));
            body.set("generate_dialogue", "1");
            body.set("voice_role1", qs("#acadDlgVoice1", container).value);
            body.set("voice_role2", qs("#acadDlgVoice2", container).value);

            fetch("?url=acad/exerciseReferenceAudio", { method: "POST", body: body })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    status.textContent = data.message || (data.success ? "Generated." : "Error.");
                    btn.disabled = false;
                })
                .catch(function () {
                    status.textContent = "Network error.";
                    btn.disabled = false;
                });
        });
    }

    /**
     * Lista de N audios de referencia del ejercicio, cada uno con reproductor
     * y botón de borrar, más un panel para agregar uno nuevo (grabado en el
     * navegador con MediaRecorder, igual que el widget del estudiante, o
     * subido como archivo).
     */
    function renderReferenceAudioManager(container, exerciseId) {
        container.innerHTML =
            '<h3 id="acadRefAudioTitle">Reference audio</h3>' +
            '<div id="acadRefAudioList"></div>' +
            '<div style="margin-top:10px;">' +
            '<input type="text" id="acadRefAudioLabel" class="form-input" style="width:220px" placeholder="Label (optional, e.g. Good morning)"> ' +
            '<button type="button" id="acadRefAudioRecord" class="sgd-doc-btn">🎙️ Record</button> ' +
            '<button type="button" id="acadRefAudioStop" class="sgd-doc-btn" disabled>⏹ Stop</button> ' +
            '<input type="file" id="acadRefAudioFile" accept="audio/*">' +
            '<br><audio id="acadRefAudioPreview" controls style="margin-top:8px;display:none;"></audio>' +
            '<br><button type="button" id="acadRefAudioAdd" class="sgd-doc-btn sgd-doc-btn-primary" disabled>＋ Add reference audio</button> ' +
            '<span id="acadRefAudioStatus"></span>' +
            '</div>' +
            '<div style="margin-top:14px;padding-top:10px;border-top:1px dashed #444;">' +
            '<span class="crud-hint">Or generate with an AI voice (no recording needed):</span><br>' +
            '<input type="text" id="acadRefAudioTtsText" class="form-input" style="width:60%;margin-top:6px;" placeholder="Text to speak, e.g. Good morning."> ' +
            '<button type="button" id="acadRefAudioTtsGenerate" class="sgd-doc-btn sgd-doc-btn-primary">🔊 Generate voice</button> ' +
            '<span id="acadRefAudioTtsStatus"></span>' +
            '</div>';

        var titleEl = qs("#acadRefAudioTitle", container);
        var list = qs("#acadRefAudioList", container);
        var labelInput = qs("#acadRefAudioLabel", container);
        var recordBtn = qs("#acadRefAudioRecord", container);
        var stopBtn = qs("#acadRefAudioStop", container);
        var fileInput = qs("#acadRefAudioFile", container);
        var preview = qs("#acadRefAudioPreview", container);
        var addBtn = qs("#acadRefAudioAdd", container);
        var status = qs("#acadRefAudioStatus", container);
        var ttsText = qs("#acadRefAudioTtsText", container);
        var ttsBtn = qs("#acadRefAudioTtsGenerate", container);
        var ttsStatus = qs("#acadRefAudioTtsStatus", container);

        var mediaRecorder = null;
        var chunks = [];
        var chosenBlob = null;

        function loadList() {
            fetch("?url=acad/exerciseReferenceAudio&exercise_id=" + exerciseId)
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.success) {
                        titleEl.textContent = "Reference audio";
                        list.innerHTML = '<p class="crud-hint" style="color:#c0392b">' + escapeHtml(data.message || "Could not load reference audio.") + '</p>';
                        return;
                    }
                    var audios = Array.isArray(data.audios) ? data.audios : [];
                    titleEl.textContent = "Reference audio (" + audios.length + ")";
                    list.innerHTML = audios.length ? "" : '<p class="crud-hint">No reference audio yet.</p>';
                    audios.forEach(function (a) {
                        var row = document.createElement("div");
                        row.style.margin = "6px 0";
                        row.innerHTML =
                            (a.texto_en ? "<strong>" + escapeHtml(a.texto_en) + "</strong> " : "") +
                            '<audio controls src="' + escapeHtml(a.ruta) + '"></audio> ' +
                            '<button type="button" class="sgd-doc-btn sgd-doc-btn-danger acad-refaudio-remove">✕ Delete</button>';
                        row.querySelector(".acad-refaudio-remove").addEventListener("click", function () {
                            var body = new URLSearchParams();
                            body.set("exercise_id", String(exerciseId));
                            body.set("delete_id", String(a.id));
                            fetch("?url=acad/exerciseReferenceAudio", { method: "POST", body: body })
                                .then(function (r) { return r.json(); })
                                .then(function () { loadList(); });
                        });
                        list.appendChild(row);
                    });
                })
                .catch(function () {
                    titleEl.textContent = "Reference audio";
                    list.innerHTML = '<p class="crud-hint" style="color:#c0392b">Network error loading reference audio.</p>';
                });
        }

        function showTake(blob) {
            chosenBlob = blob;
            preview.src = URL.createObjectURL(blob);
            preview.style.display = "block";
            addBtn.disabled = false;
        }

        if (navigator.mediaDevices && window.MediaRecorder) {
            recordBtn.addEventListener("click", function () {
                navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
                    chunks = [];
                    mediaRecorder = new MediaRecorder(stream);
                    mediaRecorder.ondataavailable = function (e) { if (e.data.size > 0) chunks.push(e.data); };
                    mediaRecorder.onstop = function () {
                        showTake(new Blob(chunks, { type: "audio/webm" }));
                        stream.getTracks().forEach(function (t) { t.stop(); });
                    };
                    mediaRecorder.start();
                    recordBtn.disabled = true;
                    stopBtn.disabled = false;
                    status.textContent = "Recording...";
                }).catch(function () {
                    status.textContent = "Microphone access denied. Use the file picker instead.";
                });
            });

            stopBtn.addEventListener("click", function () {
                if (mediaRecorder && mediaRecorder.state !== "inactive") mediaRecorder.stop();
                recordBtn.disabled = false;
                stopBtn.disabled = true;
                status.textContent = "Recording ready.";
            });
        } else {
            recordBtn.disabled = true;
            stopBtn.disabled = true;
        }

        fileInput.addEventListener("change", function () {
            if (fileInput.files && fileInput.files[0]) showTake(fileInput.files[0]);
        });

        addBtn.addEventListener("click", function () {
            if (!chosenBlob) {
                status.textContent = "Record or choose an audio file first.";
                return;
            }
            var mime = chosenBlob.type || "audio/webm";
            var ext = mime.indexOf("webm") !== -1 ? "webm" : "audio";
            var body = new FormData();
            body.set("exercise_id", String(exerciseId));
            body.set("texto_en", labelInput.value || "");
            body.set("archivo", chosenBlob, "reference." + ext);

            status.textContent = "Uploading...";
            addBtn.disabled = true;

            fetch("?url=acad/exerciseReferenceAudio", { method: "POST", body: body })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    status.textContent = data.message || (data.success ? "Added." : "Error.");
                    if (data.success) {
                        labelInput.value = "";
                        fileInput.value = "";
                        preview.removeAttribute("src");
                        preview.style.display = "none";
                        chosenBlob = null;
                        loadList();
                    } else {
                        addBtn.disabled = false;
                    }
                })
                .catch(function () {
                    status.textContent = "Network error.";
                    addBtn.disabled = false;
                });
        });

        ttsBtn.addEventListener("click", function () {
            var text = (ttsText.value || "").trim();
            if (!text) {
                ttsStatus.textContent = "Type the text to speak first.";
                return;
            }
            var body = new URLSearchParams();
            body.set("exercise_id", String(exerciseId));
            body.set("generate_text", text);

            ttsStatus.textContent = "Generating voice…";
            ttsBtn.disabled = true;

            fetch("?url=acad/exerciseReferenceAudio", { method: "POST", body: body })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    ttsStatus.textContent = data.message || (data.success ? "Generated." : "Error.");
                    ttsBtn.disabled = false;
                    if (data.success) {
                        ttsText.value = "";
                        loadList();
                    }
                })
                .catch(function () {
                    ttsStatus.textContent = "Network error.";
                    ttsBtn.disabled = false;
                });
        });

        loadList();
    }

    /**
     * El motor CRUD genérico pinta un botón "Configurar" en el toolbar por
     * tener esa acción concedida sobre acad_exercise (la necesitamos para
     * autorizar los endpoints de audio/opciones), pero ese botón nunca tuvo
     * una acción propia asignada (accion_codigo vacío) — crud.js le dispara
     * un alert() de "sin handler" al hacer clic. Lo reusamos para lo que su
     * propio nombre indica: mostrar/ocultar este panel de configuración.
     * Se intercepta en fase de captura para que nuestro handler corra ANTES
     * que el de crud.js (el orden de fases del DOM lo garantiza sin importar
     * el orden de carga de los scripts) y se detiene la propagación para que
     * el alert() genérico nunca llegue a dispararse.
     */
    function wireConfigurarButton(form, panel) {
        // Delegado en el form (no en el botón directamente) por si el motor
        // CRUD llega a reconstruir el toolbar al seleccionar una fila.
        form.addEventListener("click", function (ev) {
            var btn = ev.target.closest('.btn-accion[data-accion=""]');
            if (!btn) return;
            ev.preventDefault();
            ev.stopImmediatePropagation();
            panel.style.display = panel.style.display === "none" ? "" : "none";
        }, true);
    }

    function refresh(form) {
        var panel = ensurePanel(form);
        var exerciseId = currentExerciseId();

        if (!exerciseId) {
            renderNote(panel, "Save the exercise first to configure reference audio or options.");
            return;
        }

        var sections = ensureSections(panel);
        var typeCode = currentExerciseTypeCode();

        if (typeCode === "DIALOGUE") {
            // El audio de un diálogo se genera en bloque (un turno = un
            // audio, emparejados por orden) desde el propio editor de
            // turnos — el gestor genérico de audio suelto no aplica aquí.
            sections.audio.innerHTML = "";
        } else {
            renderReferenceAudioManager(sections.audio, exerciseId);
        }

        if (typeCode === "MULTIPLE_CHOICE") {
            renderOptionsEditor(sections.options, exerciseId);
        } else if (typeCode === "FILL_BLANKS") {
            renderWordBankEditor(sections.options, exerciseId, form);
        } else if (typeCode === "SENTENCE_ORDER") {
            renderSentenceOrderEditor(sections.options, exerciseId);
        } else if (typeCode === "DIALOGUE") {
            renderDialogueEditor(sections.options, exerciseId);
        } else {
            sections.options.innerHTML = "";
        }
    }

    document.addEventListener("DOMContentLoaded", function () {
        var form = qs('form[data-crud-context="acad_exercise"]');
        if (!form) return;

        var panel = ensurePanel(form);
        wireConfigurarButton(form, panel);
        refresh(form);

        var typeSelect = qs('[name="exercise_type_id"]', form);
        if (typeSelect) {
            typeSelect.addEventListener("change", function () { refresh(form); });
        }

        document.addEventListener("click", function (ev) {
            if (ev.target.closest(".crud-row")) {
                setTimeout(function () { refresh(form); }, 80);
            }
        });
    });
})();
