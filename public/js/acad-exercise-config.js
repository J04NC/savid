(function () {
    "use strict";

    function qs(sel, ctx) {
        return (ctx || document).querySelector(sel);
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

    function renderOptionsEditor(panel, exerciseId) {
        panel.innerHTML =
            "<h3>Multiple choice options</h3>" +
            "<div id=\"acadOptionsList\"></div>" +
            "<button type=\"button\" id=\"acadOptionAdd\" class=\"sgd-doc-btn\">＋ Add option</button> " +
            "<button type=\"button\" id=\"acadOptionsSave\" class=\"sgd-doc-btn sgd-doc-btn-primary\">Save options</button> " +
            "<span id=\"acadOptionsStatus\"></span>";

        var list = qs("#acadOptionsList", panel);

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

        qs("#acadOptionAdd", panel).addEventListener("click", function () { addRow("", false); });

        qs("#acadOptionsSave", panel).addEventListener("click", function () {
            var options = [];
            list.querySelectorAll(".acad-option-row").forEach(function (row) {
                options.push({
                    texto_en: row.querySelector(".acad-option-text").value,
                    es_correcta: row.querySelector(".acad-option-correct").checked,
                });
            });

            var status = qs("#acadOptionsStatus", panel);
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

    function renderAudioUploader(panel, exerciseId) {
        panel.innerHTML =
            "<h3>Reference audio (speaking)</h3>" +
            '<input type="file" id="acadAudioFile" accept="audio/*"> ' +
            '<button type="button" id="acadAudioUpload" class="sgd-doc-btn sgd-doc-btn-primary">Upload audio</button> ' +
            '<span id="acadAudioStatus"></span>';

        qs("#acadAudioUpload", panel).addEventListener("click", function () {
            var fileInput = qs("#acadAudioFile", panel);
            var status = qs("#acadAudioStatus", panel);
            if (!fileInput.files || !fileInput.files[0]) {
                status.textContent = "Choose an audio file first.";
                return;
            }
            var form = new FormData();
            form.set("exercise_id", String(exerciseId));
            form.set("archivo", fileInput.files[0]);
            status.textContent = "Uploading...";

            fetch("?url=acad/exerciseUploadAudio", { method: "POST", body: form })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    status.textContent = data.message || (data.success ? "Uploaded." : "Error.");
                })
                .catch(function () { status.textContent = "Network error."; });
        });
    }

    function refresh(form) {
        var panel = ensurePanel(form);
        var exerciseId = currentExerciseId();

        if (!exerciseId) {
            renderNote(panel, "Save the exercise first to configure options or upload reference audio.");
            return;
        }

        var typeCode = currentExerciseTypeCode();
        if (typeCode === "MULTIPLE_CHOICE") {
            renderOptionsEditor(panel, exerciseId);
        } else if (typeCode === "AUDIO_RESPONSE") {
            renderAudioUploader(panel, exerciseId);
        } else {
            renderNote(panel, "This exercise type has no extra configuration.");
        }
    }

    document.addEventListener("DOMContentLoaded", function () {
        var form = qs('form[data-crud-context="acad_exercise"]');
        if (!form) return;

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
