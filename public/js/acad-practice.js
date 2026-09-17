(function () {
    "use strict";

    var panel = document.getElementById("acadPracticePanel");
    if (!panel) return;

    var exerciseId = panel.getAttribute("data-exercise-id");
    var statusEl = document.getElementById("acadPracticeStatus");

    function setStatus(text) {
        if (statusEl) statusEl.textContent = text;
    }

    function submitForm(body) {
        setStatus("Submitting...");
        return fetch("?url=acad/practice", { method: "POST", body: body })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    var msg = data.message || "Submitted.";
                    if (typeof data.correct === "boolean") {
                        msg += data.correct
                            ? " Score: " + data.score + "/" + data.max_score
                            : " Score: " + data.score + "/" + data.max_score;
                    }
                    setStatus(msg);
                } else {
                    setStatus(data.message || "Error submitting answer.");
                }
                return data;
            })
            .catch(function () {
                setStatus("Network error.");
            });
    }

    var readingForm = document.getElementById("acadReadingForm");
    if (readingForm) {
        readingForm.addEventListener("submit", function (ev) {
            ev.preventDefault();
            var selected = readingForm.querySelector('input[name="selected_option_id"]:checked');
            if (!selected) {
                setStatus("Select an option first.");
                return;
            }
            var body = new URLSearchParams();
            body.set("exercise_id", exerciseId);
            body.set("selected_option_id", selected.value);
            submitForm(body);
        });
    }

    var writingForm = document.getElementById("acadWritingForm");
    if (writingForm) {
        writingForm.addEventListener("submit", function (ev) {
            ev.preventDefault();
            var text = writingForm.querySelector('[name="respuesta_texto"]').value;
            var body = new URLSearchParams();
            body.set("exercise_id", exerciseId);
            body.set("respuesta_texto", text);
            submitForm(body);
        });
    }

    // Un ejercicio AUDIO_RESPONSE puede tener N audios de referencia, cada uno
    // con su propio ciclo grabar/escuchar/borrar/re-grabar/confirmar. Cada
    // ".acad-audio-slot" se inicializa por separado con su propio estado
    // (closure), para que grabar en un slot no afecte a los demás.
    document.querySelectorAll(".acad-audio-slot").forEach(initAudioSlot);

    initFillBlanks();
    initSentenceOrder();
    document.querySelectorAll(".acad-tt-slot").forEach(initTongueTwisterSlot);
    initTongueTwisterReferenceDurations();
    initDialogue();

    function initAudioSlot(slotEl) {
        var referenceAudioId = slotEl.getAttribute("data-reference-audio-id");
        var recordBtn = slotEl.querySelector(".acad-slot-record");
        var stopBtn = slotEl.querySelector(".acad-slot-stop");
        var preview = slotEl.querySelector(".acad-slot-preview");
        var deleteBtn = slotEl.querySelector(".acad-slot-delete");
        var confirmBtn = slotEl.querySelector(".acad-slot-confirm");
        var statusEl = slotEl.querySelector(".acad-slot-status");
        if (!recordBtn || !confirmBtn) return;

        var mediaRecorder = null;
        var chunks = [];
        var chosenBlob = null;

        function slotStatus(text) {
            if (statusEl) statusEl.textContent = text;
        }

        function showTake(blob, mime) {
            chosenBlob = { blob: blob, mime: mime || "audio/webm" };
            preview.src = URL.createObjectURL(blob);
            preview.style.display = "block";
            deleteBtn.style.display = "inline-block";
            confirmBtn.disabled = false;
            recordBtn.disabled = true;
        }

        function resetTake() {
            chosenBlob = null;
            preview.removeAttribute("src");
            preview.style.display = "none";
            deleteBtn.style.display = "none";
            confirmBtn.disabled = true;
            recordBtn.disabled = false;
        }

        if (navigator.mediaDevices && window.MediaRecorder) {
            recordBtn.addEventListener("click", function () {
                navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
                    chunks = [];
                    mediaRecorder = new MediaRecorder(stream);
                    mediaRecorder.ondataavailable = function (e) { if (e.data.size > 0) chunks.push(e.data); };
                    mediaRecorder.onstop = function () {
                        var blob = new Blob(chunks, { type: "audio/webm" });
                        showTake(blob, "audio/webm");
                        stream.getTracks().forEach(function (t) { t.stop(); });
                    };
                    mediaRecorder.start();
                    recordBtn.disabled = true;
                    stopBtn.disabled = false;
                    slotStatus("Recording...");
                }).catch(function () {
                    slotStatus("Microphone access denied. Please allow microphone access to record.");
                });
            });

            stopBtn.addEventListener("click", function () {
                if (mediaRecorder && mediaRecorder.state !== "inactive") {
                    mediaRecorder.stop();
                }
                stopBtn.disabled = true;
                slotStatus("Recording ready. Listen, then delete to retry or confirm to submit.");
            });
        } else {
            recordBtn.disabled = true;
            stopBtn.disabled = true;
            slotStatus("Recording is not supported in this browser.");
        }

        deleteBtn.addEventListener("click", function () {
            resetTake();
            slotStatus("Recording deleted. Record again when ready.");
        });

        confirmBtn.addEventListener("click", function () {
            if (!chosenBlob) {
                slotStatus("Record or choose an audio file first.");
                return;
            }
            var ext = chosenBlob.mime.indexOf("webm") !== -1 ? "webm" : "audio";
            var body = new FormData();
            body.set("exercise_id", exerciseId);
            body.set("reference_audio_id", referenceAudioId);
            body.set("archivo", chosenBlob.blob, "recording." + ext);

            confirmBtn.disabled = true;
            slotStatus("Submitting...");
            fetch("?url=acad/practice", { method: "POST", body: body })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) {
                        slotStatus("Confirmed — submitted for grading.");
                        recordBtn.disabled = true;
                        deleteBtn.disabled = true;
                    } else {
                        slotStatus(data.message || "Error submitting recording.");
                        confirmBtn.disabled = false;
                    }
                })
                .catch(function () {
                    slotStatus("Network error.");
                    confirmBtn.disabled = false;
                });
        });
    }

    /**
     * "Fill in the blanks": toca una palabra de la bolsa para seleccionarla,
     * luego toca el hueco donde va (se descartó arrastre real porque no es
     * confiable en celular). Tocar un hueco ya lleno lo deshace y devuelve la
     * palabra a la bolsa. Todo en memoria hasta que se pulsa "Submit answer".
     */
    function initFillBlanks() {
        var blanks = document.querySelectorAll(".acad-blank");
        var chips = document.querySelectorAll(".acad-word-chip");
        var submitBtn = document.getElementById("acadFillBlanksSubmit");
        var fbStatus = document.getElementById("acadFillBlanksStatus");
        if (!blanks.length || !submitBtn) return;

        var selectedChip = null;
        var submitted = false;

        function fbSetStatus(text) {
            if (fbStatus) fbStatus.textContent = text;
        }

        function selectChip(chip) {
            if (selectedChip) selectedChip.classList.remove("acad-word-chip-selected");
            if (selectedChip === chip) {
                selectedChip = null;
                return;
            }
            selectedChip = chip;
            chip.classList.add("acad-word-chip-selected");
        }

        chips.forEach(function (chip) {
            chip.addEventListener("click", function () {
                if (submitted || chip.classList.contains("acad-word-chip-used")) return;
                selectChip(chip);
            });
        });

        blanks.forEach(function (blank) {
            blank.addEventListener("click", function () {
                if (submitted) return;

                var occupantId = blank.getAttribute("data-occupant-chip-id");
                if (occupantId) {
                    var occupant = document.querySelector('.acad-word-chip[data-option-id="' + occupantId + '"]');
                    if (occupant) occupant.classList.remove("acad-word-chip-used");
                    blank.removeAttribute("data-occupant-chip-id");
                    blank.removeAttribute("data-word");
                    blank.textContent = "";
                    blank.classList.remove("acad-blank-filled");
                    return;
                }

                if (!selectedChip) {
                    fbSetStatus("Select a word first, then tap a blank.");
                    return;
                }

                var word = selectedChip.textContent;
                blank.textContent = word;
                blank.setAttribute("data-word", word);
                blank.setAttribute("data-occupant-chip-id", selectedChip.getAttribute("data-option-id"));
                blank.classList.add("acad-blank-filled");
                selectedChip.classList.add("acad-word-chip-used");
                selectedChip.classList.remove("acad-word-chip-selected");
                selectedChip = null;
                fbSetStatus("");
            });
        });

        submitBtn.addEventListener("click", function () {
            var answers = {};
            blanks.forEach(function (blank) {
                var word = blank.getAttribute("data-word");
                if (word) answers[blank.getAttribute("data-blank-index")] = word;
            });

            var body = new URLSearchParams();
            body.set("exercise_id", exerciseId);
            body.set("blanks", JSON.stringify(answers));

            fbSetStatus("Submitting...");
            submitBtn.disabled = true;

            fetch("?url=acad/practice", { method: "POST", body: body })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) {
                        submitted = true;
                        fbSetStatus(data.message + " (" + data.score + "/" + data.max_score + ")");
                    } else {
                        fbSetStatus(data.message || "Error submitting answer.");
                        submitBtn.disabled = false;
                    }
                })
                .catch(function () {
                    fbSetStatus("Network error.");
                    submitBtn.disabled = false;
                });
        });
    }

    /**
     * "Sentence order": misma mecanica de toca-palabra / toca-hueco que
     * initFillBlanks(), pero los huecos ("slots") estan agrupados por
     * oracion (una palabra suelta puede tocar cualquier slot de cualquier
     * oracion; solo se penaliza al calificar si quedo en la oracion o
     * posicion equivocada). El envio manda, por oracion, la secuencia de
     * ids de opcion colocados en orden de slot.
     */
    function initSentenceOrder() {
        var rows = document.querySelectorAll(".acad-order-row");
        var chips = document.querySelectorAll(".acad-wordbank .acad-word-chip");
        var submitBtn = document.getElementById("acadSentenceOrderSubmit");
        var soStatus = document.getElementById("acadSentenceOrderStatus");
        if (!rows.length || !submitBtn) return;

        var selectedChip = null;
        var submitted = false;

        function soSetStatus(text) {
            if (soStatus) soStatus.textContent = text;
        }

        function selectChip(chip) {
            if (selectedChip) selectedChip.classList.remove("acad-word-chip-selected");
            if (selectedChip === chip) {
                selectedChip = null;
                return;
            }
            selectedChip = chip;
            chip.classList.add("acad-word-chip-selected");
        }

        chips.forEach(function (chip) {
            chip.addEventListener("click", function () {
                if (submitted || chip.classList.contains("acad-word-chip-used")) return;
                selectChip(chip);
            });
        });

        document.querySelectorAll(".acad-order-slot").forEach(function (slot) {
            slot.addEventListener("click", function () {
                if (submitted) return;

                var occupantId = slot.getAttribute("data-filled-option-id");
                if (occupantId) {
                    var occupant = document.querySelector('.acad-word-chip[data-option-id="' + occupantId + '"]');
                    if (occupant) occupant.classList.remove("acad-word-chip-used");
                    slot.removeAttribute("data-filled-option-id");
                    slot.textContent = "";
                    slot.classList.remove("acad-order-slot-filled");
                    return;
                }

                if (!selectedChip) {
                    soSetStatus("Select a word first, then tap a slot.");
                    return;
                }

                slot.textContent = selectedChip.textContent;
                slot.setAttribute("data-filled-option-id", selectedChip.getAttribute("data-option-id"));
                slot.classList.add("acad-order-slot-filled");
                selectedChip.classList.add("acad-word-chip-used");
                selectedChip.classList.remove("acad-word-chip-selected");
                selectedChip = null;
                soSetStatus("");
            });
        });

        submitBtn.addEventListener("click", function () {
            var answers = {};
            rows.forEach(function (row) {
                var sentenceIndex = row.getAttribute("data-sentence-index");
                var ids = [];
                row.querySelectorAll(".acad-order-slot").forEach(function (slot) {
                    var id = slot.getAttribute("data-filled-option-id");
                    if (id) ids.push(parseInt(id, 10));
                });
                answers[sentenceIndex] = ids;
            });

            var body = new URLSearchParams();
            body.set("exercise_id", exerciseId);
            body.set("order_answers", JSON.stringify(answers));

            soSetStatus("Submitting...");
            submitBtn.disabled = true;

            fetch("?url=acad/practice", { method: "POST", body: body })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) {
                        submitted = true;
                        soSetStatus(data.message + " (" + data.score + "/" + data.max_score + ")");
                    } else {
                        soSetStatus(data.message || "Error submitting answer.");
                        submitBtn.disabled = false;
                    }
                })
                .catch(function () {
                    soSetStatus("Network error.");
                    submitBtn.disabled = false;
                });
        });
    }

    /**
     * Lee la duración (en segundos) de cada audio de referencia de un
     * ejercicio TONGUE_TWISTER directamente del navegador (metadata nativa
     * de <audio>, sin ffmpeg/ffprobe en el servidor) y la muestra como meta
     * de referencia junto al reproductor.
     */
    function initTongueTwisterReferenceDurations() {
        document.querySelectorAll(".acad-tt-reference-audio").forEach(function (audioEl) {
            var target = audioEl.parentElement ? audioEl.parentElement.querySelector(".acad-tt-reference-duration") : null;
            if (!target) return;
            function show() {
                if (audioEl.duration && isFinite(audioEl.duration)) {
                    target.textContent = audioEl.duration.toFixed(1) + "s";
                }
            }
            if (audioEl.readyState >= 1) show();
            audioEl.addEventListener("loadedmetadata", show);
        });
    }

    /**
     * Trabalenguas cronometrado: mismo ciclo de grabar/escuchar/descartar
     * que initAudioSlot(), pero SIN bloqueo tras confirmar — el estudiante
     * puede grabar tantas tomas como quiera para ver su progresión de
     * velocidad. La duración se mide con Date.now() alrededor de
     * start()/stop() del MediaRecorder (nada de análisis de audio en
     * servidor) y se manda junto con la grabación; al confirmar se recarga
     * la página para que el historial (ya renderizado en servidor) muestre
     * la nueva toma — así no hay que duplicar en JS el formato de la lista.
     */
    function initTongueTwisterSlot(slotEl) {
        var recordBtn = slotEl.querySelector(".acad-tt-record");
        var stopBtn = slotEl.querySelector(".acad-tt-stop");
        var preview = slotEl.querySelector(".acad-tt-preview");
        var deleteBtn = slotEl.querySelector(".acad-tt-delete");
        var confirmBtn = slotEl.querySelector(".acad-tt-confirm");
        var statusEl = slotEl.querySelector(".acad-tt-status");
        var referenceAudioId = slotEl.getAttribute("data-reference-audio-id");
        if (!recordBtn || !confirmBtn) return;

        var mediaRecorder = null;
        var chunks = [];
        var chosenBlob = null;
        var recordStartedAt = 0;
        var durationMs = 0;

        function ttStatus(text) {
            if (statusEl) statusEl.textContent = text;
        }

        function showTake(blob) {
            chosenBlob = blob;
            preview.src = URL.createObjectURL(blob);
            preview.style.display = "block";
            deleteBtn.style.display = "inline-block";
            confirmBtn.disabled = false;
            recordBtn.disabled = true;
        }

        function resetTake() {
            chosenBlob = null;
            preview.removeAttribute("src");
            preview.style.display = "none";
            deleteBtn.style.display = "none";
            confirmBtn.disabled = true;
            recordBtn.disabled = false;
        }

        if (navigator.mediaDevices && window.MediaRecorder) {
            recordBtn.addEventListener("click", function () {
                navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
                    chunks = [];
                    mediaRecorder = new MediaRecorder(stream);
                    mediaRecorder.ondataavailable = function (e) { if (e.data.size > 0) chunks.push(e.data); };
                    mediaRecorder.onstop = function () {
                        durationMs = Date.now() - recordStartedAt;
                        showTake(new Blob(chunks, { type: "audio/webm" }));
                        stream.getTracks().forEach(function (t) { t.stop(); });
                    };
                    recordStartedAt = Date.now();
                    mediaRecorder.start();
                    recordBtn.disabled = true;
                    stopBtn.disabled = false;
                    ttStatus("Recording...");
                }).catch(function () {
                    ttStatus("Microphone access denied. Please allow microphone access to record.");
                });
            });

            stopBtn.addEventListener("click", function () {
                if (mediaRecorder && mediaRecorder.state !== "inactive") {
                    mediaRecorder.stop();
                }
                stopBtn.disabled = true;
                ttStatus("Got it — listen back, then discard to retry or save to keep this attempt.");
            });
        } else {
            recordBtn.disabled = true;
            stopBtn.disabled = true;
            ttStatus("Recording is not supported in this browser.");
        }

        deleteBtn.addEventListener("click", function () {
            resetTake();
            ttStatus("Discarded. Record again when ready.");
        });

        confirmBtn.addEventListener("click", function () {
            if (!chosenBlob) {
                ttStatus("Record an attempt first.");
                return;
            }
            var body = new FormData();
            body.set("exercise_id", exerciseId);
            body.set("reference_audio_id", referenceAudioId);
            body.set("duration_ms", String(durationMs));
            body.set("archivo", chosenBlob, "recording.webm");

            confirmBtn.disabled = true;
            ttStatus("Saving...");
            fetch("?url=acad/practice", { method: "POST", body: body })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) {
                        ttStatus("Saved! Reloading to show it in your attempt history...");
                        location.reload();
                    } else {
                        ttStatus(data.message || "Error saving attempt.");
                        confirmBtn.disabled = false;
                    }
                })
                .catch(function () {
                    ttStatus("Network error.");
                    confirmBtn.disabled = false;
                });
        });
    }

    /**
     * Diálogo (role-play): controla el modo de revelado de texto (completo /
     * solo iniciales / oculto — las 3 pasadas que pide el libro) y arma la
     * reproducción encadenada de la conversación completa (audio TTS para
     * los turnos del otro rol, tu propia grabación confirmada para los
     * tuyos). El widget de grabación en sí no necesita código nuevo: cada
     * turno tuyo se renderiza como un ".acad-audio-slot" normal, que
     * initAudioSlot() ya inicializa más arriba en este mismo archivo.
     */
    function initDialogue() {
        var revealBtns = document.querySelectorAll(".acad-dlg-reveal-btn");
        var lines = document.querySelectorAll(".acad-dlg-line");
        if (!revealBtns.length) return;

        function applyMode(mode) {
            lines.forEach(function (p) {
                var full = p.getAttribute("data-full") || "";
                if (mode === "initials") {
                    p.textContent = full.replace(/[A-Za-z]+/g, function (w) {
                        return w.charAt(0) + "_".repeat(w.length - 1);
                    });
                    p.classList.remove("acad-dlg-line-hidden");
                } else if (mode === "none") {
                    p.textContent = "";
                    p.classList.add("acad-dlg-line-hidden");
                } else {
                    p.textContent = full;
                    p.classList.remove("acad-dlg-line-hidden");
                }
            });
            revealBtns.forEach(function (b) {
                b.classList.toggle("sgd-doc-btn-primary", b.getAttribute("data-mode") === mode);
            });
        }

        revealBtns.forEach(function (btn) {
            btn.addEventListener("click", function () { applyMode(btn.getAttribute("data-mode")); });
        });

        var playAllBtn = document.getElementById("acadDialoguePlayAll");
        var dlgStatus = document.getElementById("acadDialogueStatus");
        if (!playAllBtn) return;

        function dlgSetStatus(text) {
            if (dlgStatus) dlgStatus.textContent = text;
        }

        playAllBtn.addEventListener("click", function () {
            var clips = [];
            document.querySelectorAll(".acad-dlg-turn").forEach(function (turn) {
                var mine = turn.querySelector(".acad-dlg-my-recording");
                var reference = turn.querySelector(".acad-dlg-audio");
                var src = mine ? mine.getAttribute("src") : (reference ? reference.getAttribute("src") : null);
                if (src) clips.push(src);
            });
            if (!clips.length) {
                dlgSetStatus("No audio available yet.");
                return;
            }

            var player = new Audio();
            var i = 0;
            player.onended = playNext;
            player.onerror = function () { dlgSetStatus("Could not play turn " + i + "."); };

            function playNext() {
                if (i >= clips.length) {
                    dlgSetStatus("Done.");
                    return;
                }
                i++;
                dlgSetStatus("Playing turn " + i + " of " + clips.length + "...");
                player.src = clips[i - 1];
                player.play();
            }

            playNext();
        });
    }
})();
