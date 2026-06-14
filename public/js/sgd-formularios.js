(function () {
    'use strict';

    var esquemaEl = document.getElementById('sgd-form-esquema-inicial');
    if (!esquemaEl) return;

    var catalogEl = document.getElementById('sgd-form-arquetipos-catalog');
    var catalog = {};
    if (catalogEl) {
        try {
            catalog = JSON.parse(catalogEl.textContent || '{}');
        } catch (e) {
            catalog = {};
        }
    }

    var esquema;
    try {
        esquema = JSON.parse(esquemaEl.textContent || '{}');
    } catch (e) {
        esquema = { version: 2, arquetipo: 'libre', bloques: [], campos: [] };
    }

    if (!esquema.campos || !Array.isArray(esquema.campos)) {
        esquema.campos = [];
    }
    if (!esquema.bloques || !Array.isArray(esquema.bloques)) {
        esquema.bloques = [];
    }
    if (!esquema.version) {
        esquema.version = esquema.bloques.length ? 2 : 1;
    }
    if (!esquema.arquetipo) {
        esquema.arquetipo = 'libre';
    }

    var listEl = document.getElementById('sgd-form-campos-list');
    var previewEl = document.getElementById('sgd-form-preview');
    var hiddenSave = document.getElementById('sgd_esquema_json');
    var hiddenPublish = document.getElementById('sgd_esquema_json_publish');
    var btnAdd = document.getElementById('sgd-btn-add-campo');
    var btnCargarArquetipo = document.getElementById('sgd-btn-cargar-arquetipo');
    var arquetipoSelect = document.getElementById('sgd_arquetipo_select');
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

    function escapeHtml(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function buildEsquemaFromArquetipo(codigo) {
        codigo = String(codigo || 'libre').toLowerCase();
        if (codigo === 'libre' || codigo === 'solo_archivo') {
            return { version: 2, arquetipo: codigo, bloques: [], campos: esquema.campos || [] };
        }

        var perfiles = catalog.perfiles_arquetipo || {};
        var perfil = perfiles[codigo];
        if (!perfil) {
            return { version: 2, arquetipo: codigo, bloques: [], campos: [] };
        }

        var bloquesDef = {};
        (catalog.bloques_operativos || []).forEach(function (b) {
            if (b && b.codigo) bloquesDef[b.codigo] = b;
        });

        var bloques = [];
        var orden = 10;
        Object.keys(perfil).forEach(function (secCodigo) {
            var estado = perfil[secCodigo];
            if (estado === 'no_aplica') return;
            var def = bloquesDef[secCodigo];
            if (!def) return;
            var definicion = {};
            ['campos', 'columnas', 'item', 'roles'].forEach(function (k) {
                if (def[k]) definicion[k] = def[k];
            });
            bloques.push({
                seccion_codigo: secCodigo,
                nombre: def.nombre || secCodigo,
                widget: def.widget || 'grupo_campos',
                estado: estado,
                orden: orden,
                definicion: definicion
            });
            orden += 10;
        });

        return { version: 2, arquetipo: codigo, bloques: bloques, campos: [] };
    }

    function renderBloquePreview(bloque) {
        var html = '<div class="sgd-preview-bloque">';
        html += '<h5 class="sgd-preview-bloque-title">' + escapeHtml(bloque.nombre || bloque.seccion_codigo) + '</h5>';
        html += '<p class="field-note">Widget: <code>' + escapeHtml(bloque.widget || '') + '</code>';
        if (bloque.estado === 'opcional') html += ' · Opcional';
        html += '</p>';

        var def = bloque.definicion || {};
        if (bloque.widget === 'grupo_campos' && def.campos) {
            def.campos.forEach(function (c) {
                html += renderFieldPreview(c);
            });
        } else if (bloque.widget === 'tabla_repetible' && def.columnas) {
            html += '<div class="sgd-preview-table">Tabla: ';
            html += def.columnas.map(function (col) { return escapeHtml(col.label || col.id); }).join(' · ');
            html += ' <span class="field-note">(filas repetibles)</span></div>';
        } else if (bloque.widget === 'lista_repetible') {
            html += '<p class="field-note">Lista numerada repetible</p>';
        } else if (bloque.widget === 'texto_enriquecido') {
            html += '<textarea class="form-input" rows="4" disabled placeholder="Desarrollo…"></textarea>';
        } else if (bloque.widget === 'bloque_firmas' && def.roles) {
            def.roles.forEach(function (r) {
                html += '<div class="sgd-preview-firma">' + escapeHtml(r.label || r.id) + '</div>';
            });
        }
        html += '</div>';
        return html;
    }

    function renderFieldPreview(c) {
        var req = c.requerido ? ' <span class="sgd-req">*</span>' : '';
        var html = '<div class="form-group sgd-preview-field">';
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
        return html;
    }

    function renderPreview() {
        if (!previewEl) return;
        var hasBloques = esquema.bloques && esquema.bloques.length;
        var hasCampos = esquema.campos && esquema.campos.length;
        if (!hasBloques && !hasCampos) {
            previewEl.innerHTML = '<p class="field-note">Sin bloques ni campos. Elija un arquetipo y pulse «Cargar plantilla del arquetipo».</p>';
            return;
        }
        var html = '<div class="sgd-form-preview-inner">';
        if (esquema.arquetipo) {
            html += '<p class="field-note sgd-preview-arquetipo">Arquetipo: <strong>' + escapeHtml(esquema.arquetipo) + '</strong></p>';
        }
        (esquema.bloques || []).forEach(function (b) {
            html += renderBloquePreview(b);
        });
        (esquema.campos || []).forEach(function (c) {
            html += renderFieldPreview(c);
        });
        html += '</div>';
        previewEl.innerHTML = html;
    }

    function renderList() {
        if (!listEl) return;

        var parts = [];

        if (esquema.bloques && esquema.bloques.length) {
            parts.push('<h5 class="sgd-form-subtitle">Bloques (' + esquema.bloques.length + ')</h5>');
            parts.push('<table class="sgd-form-campos-table"><thead><tr><th>Código</th><th>Nombre</th><th>Widget</th><th>Estado</th></tr></thead><tbody>');
            esquema.bloques.forEach(function (b) {
                parts.push('<tr><td><code>' + escapeHtml(b.seccion_codigo) + '</code></td>');
                parts.push('<td>' + escapeHtml(b.nombre) + '</td>');
                parts.push('<td>' + escapeHtml(b.widget) + '</td>');
                parts.push('<td>' + escapeHtml(b.estado || 'aplica') + '</td></tr>');
            });
            parts.push('</tbody></table>');
        }

        if (esquema.campos && esquema.campos.length) {
            parts.push('<h5 class="sgd-form-subtitle">Campos libres (' + esquema.campos.length + ')</h5>');
            parts.push('<table class="sgd-form-campos-table"><thead><tr><th>Id</th><th>Etiqueta</th><th>Tipo</th><th></th></tr></thead><tbody>');
            esquema.campos.forEach(function (c, idx) {
                parts.push('<tr><td><code>' + escapeHtml(c.id) + '</code></td>');
                parts.push('<td>' + escapeHtml(c.label) + (c.requerido ? ' *' : '') + '</td>');
                parts.push('<td>' + escapeHtml(c.tipo) + '</td><td>');
                if (canEdit) {
                    parts.push('<button type="button" class="sgd-form-del-campo" data-idx="' + idx + '">Quitar</button>');
                }
                parts.push('</td></tr>');
            });
            parts.push('</tbody></table>');
        }

        if (!parts.length) {
            listEl.innerHTML = '<p class="field-note">Agregue campos libres o cargue un arquetipo (recomendado: Acta).</p>';
        } else {
            listEl.innerHTML = parts.join('');
        }

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

    if (btnCargarArquetipo && arquetipoSelect) {
        btnCargarArquetipo.addEventListener('click', function () {
            var codigo = arquetipoSelect.value || 'libre';
            if (esquema.bloques.length || esquema.campos.length) {
                if (!confirm('¿Reemplazar bloques y campos actuales con la plantilla del arquetipo seleccionado?')) {
                    return;
                }
            }
            esquema = buildEsquemaFromArquetipo(codigo);
            renderList();
        });
    }

    var saveForm = document.getElementById('sgd-form-designer-save');
    var publishForm = document.getElementById('sgd-form-designer-publish');
    if (saveForm) saveForm.addEventListener('submit', syncHidden);
    if (publishForm) publishForm.addEventListener('submit', syncHidden);

    renderList();
})();
