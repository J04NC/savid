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

    var speakingWidget = document.getElementById("acadSpeakingWidget");
    if (speakingWidget) {
        var recordBtn = document.getElementById("acadRecordBtn");
        var stopBtn = document.getElementById("acadStopBtn");
        var fileInput = document.getElementById("acadAudioFileInput");
        var preview = document.getElementById("acadRecordedPreview");
        var submitBtn = document.getElementById("acadSpeakingSubmit");

        var mediaRecorder = null;
        var chunks = [];
        var chosenBlob = null;

        function enableSubmit(blob, mime) {
            chosenBlob = { blob: blob, mime: mime || "audio/webm" };
            preview.src = URL.createObjectURL(blob);
            preview.style.display = "block";
            submitBtn.disabled = false;
        }

        if (navigator.mediaDevices && window.MediaRecorder) {
            recordBtn.addEventListener("click", function () {
                navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
                    chunks = [];
                    mediaRecorder = new MediaRecorder(stream);
                    mediaRecorder.ondataavailable = function (e) { if (e.data.size > 0) chunks.push(e.data); };
                    mediaRecorder.onstop = function () {
                        var blob = new Blob(chunks, { type: "audio/webm" });
                        enableSubmit(blob, "audio/webm");
                        stream.getTracks().forEach(function (t) { t.stop(); });
                    };
                    mediaRecorder.start();
                    recordBtn.disabled = true;
                    stopBtn.disabled = false;
                    setStatus("Recording...");
                }).catch(function () {
                    setStatus("Microphone access denied. Use the file picker instead.");
                });
            });

            stopBtn.addEventListener("click", function () {
                if (mediaRecorder && mediaRecorder.state !== "inactive") {
                    mediaRecorder.stop();
                }
                recordBtn.disabled = false;
                stopBtn.disabled = true;
                setStatus("Recording ready.");
            });
        } else {
            recordBtn.disabled = true;
            stopBtn.disabled = true;
        }

        fileInput.addEventListener("change", function () {
            if (fileInput.files && fileInput.files[0]) {
                enableSubmit(fileInput.files[0], fileInput.files[0].type);
            }
        });

        submitBtn.addEventListener("click", function () {
            if (!chosenBlob) {
                setStatus("Record or choose an audio file first.");
                return;
            }
            var ext = chosenBlob.mime.indexOf("webm") !== -1 ? "webm" : "audio";
            var body = new FormData();
            body.set("exercise_id", exerciseId);
            body.set("archivo", chosenBlob.blob, "recording." + ext);
            submitForm(body);
        });
    }
})();
