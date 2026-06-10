(function () {
    'use strict';

    var esquemaEl = document.getElementById('sgd-form-esquema-inicial');
    if (!esquemaEl) return;

    var esquema;
    try {
        esquema = JSON.parse(esquemaEl.textContent || '{}');
    } catch (e) {
        esquema = { version: 1, campos: [] };
    }
    if (!esquema.campos || !Array.isArray(esquema.campos)) {
        esquema.campos = [];
    }

    var listEl = document.getElementById('sgd-form-campos-list');
    var previewEl = document.getElementById('sgd-form-preview');
    var hiddenSave = document.getElementById('sgd_esquema_json');
    var hiddenPublish = document.getElementById('sgd_esquema_json_publish');
    var btnAdd = document.getElementById('sgd-btn-add-campo');
    var canEdit = !!btnAdd;

    function syncHidden() {
        var json = JSON.stringify(esquema);
        if (hiddenSave) hiddenSave.value = json;
        if (hiddenPublish) hiddenPublish.value = json;
    }

    function slugId(raw) {
        return String(raw || '').toLowerCase().trim()
            .replace(/[^a-z0-9_]+/g, '_')
            .replace(/^_+|_+$/g, '');
    }

    function renderPreview() {
        if (!previewEl) return;
        if (!esquema.campos.length) {
            previewEl.innerHTML = '<p class="field-note">Sin campos definidos.</p>';
            return;
        }
        var html = '<div class="sgd-form-preview-inner">';
        esquema.campos.forEach(function (c) {
            var req = c.requerido ? ' <span class="sgd-req">*</span>' : '';
            html += '<div class="form-group sgd-preview-field">';
            html += '<label>' + escapeHtml(c.label || c.id) + req + '</label>';
            if (c.tipo === 'textarea') {
                html += '<textarea class="form-input" rows="3" disabled placeholder="…"></textarea>';
            } else if (c.tipo === 'lista') {
                html += '<select class="form-input" disabled><option>—</option></select>';
            } else if (c.tipo === 'firma') {
                html += '<div class="sgd-preview-firma">[ Bloque de firma ]</div>';
            } else {
                var type = c.tipo === 'numero' ? 'number' : (c.tipo === 'fecha' ? 'date' : 'text');
                html += '<input type="' + type + '" class="form-input" disabled>';
            }
            html += '</div>';
        });
        html += '</div>';
        previewEl.innerHTML = html;
    }

    function escapeHtml(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function renderList() {
        if (!listEl) return;
        if (!esquema.campos.length) {
            listEl.innerHTML = '<p class="field-note">Agregue campos con el formulario de la izquierda.</p>';
            renderPreview();
            syncHidden();
            return;
        }
        var html = '<table class="sgd-form-campos-table"><thead><tr><th>Id</th><th>Etiqueta</th><th>Tipo</th><th></th></tr></thead><tbody>';
        esquema.campos.forEach(function (c, idx) {
            html += '<tr><td><code>' + escapeHtml(c.id) + '</code></td>';
            html += '<td>' + escapeHtml(c.label) + (c.requerido ? ' *' : '') + '</td>';
            html += '<td>' + escapeHtml(c.tipo) + '</td><td>';
            if (canEdit) {
                html += '<button type="button" class="sgd-form-del-campo" data-idx="' + idx + '">Quitar</button>';
            }
            html += '</td></tr>';
        });
        html += '</tbody></table>';
        listEl.innerHTML = html;

        listEl.querySelectorAll('.sgd-form-del-campo').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var i = parseInt(btn.getAttribute('data-idx'), 10);
                if (!isNaN(i)) {
                    esquema.campos.splice(i, 1);
                    renderList();
                }
            });
        });

        renderPreview();
        syncHidden();
    }

    if (btnAdd) {
        btnAdd.addEventListener('click', function () {
            var idInput = document.getElementById('sgd_campo_id');
            var labelInput = document.getElementById('sgd_campo_label');
            var tipoInput = document.getElementById('sgd_campo_tipo');
            var reqInput = document.getElementById('sgd_campo_requerido');
            if (!idInput || !labelInput || !tipoInput) return;

            var id = slugId(idInput.value);
            var label = String(labelInput.value || '').trim();
            if (!id || !label) return;

            if (esquema.campos.some(function (c) { return c.id === id; })) {
                alert('Ya existe un campo con ese identificador.');
                return;
            }

            esquema.campos.push({
                id: id,
                label: label,
                tipo: tipoInput.value || 'texto',
                requerido: !!(reqInput && reqInput.checked),
                orden: (esquema.campos.length + 1) * 10
            });

            idInput.value = '';
            labelInput.value = '';
            if (reqInput) reqInput.checked = false;
            renderList();
        });
    }

    var saveForm = document.getElementById('sgd-form-designer-save');
    var publishForm = document.getElementById('sgd-form-designer-publish');
    if (saveForm) {
        saveForm.addEventListener('submit', syncHidden);
    }
    if (publishForm) {
        publishForm.addEventListener('submit', syncHidden);
    }

    renderList();
})();
