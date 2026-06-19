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
    var previewBodyEl = document.getElementById('sgd-form-preview-body');
    var previewHeadEl = document.getElementById('sgd-form-preview-head');
    var pageMeta = {};
    var pageMetaEl = document.getElementById('sgd-form-page-meta');
    if (pageMetaEl) {
        try {
            pageMeta = JSON.parse(pageMetaEl.textContent || '{}');
        } catch (e) {
            pageMeta = {};
        }
    }
    var hiddenSave = document.getElementById('sgd_esquema_json');
    var btnAdd = document.getElementById('sgd-btn-add-campo');
    var btnCargarArquetipo = document.getElementById('sgd-btn-cargar-arquetipo');
    var arquetipoSelect = document.getElementById('sgd_arquetipo_select');
    var canEdit = !!btnAdd;

    var WIDGET_LABELS = {
        grupo_campos: 'Grupo de campos',
        tabla_repetible: 'Tabla repetible',
        lista_repetible: 'Lista repetible',
        texto_enriquecido: 'Texto enriquecido',
        bloque_firmas: 'Bloque de firmas'
    };

    var ESTADO_LABELS = {
        aplica: 'Aplica',
        opcional: 'Opcional',
        no_aplica: 'No aplica'
    };

    var DRAG_ICON = '<svg class="sgd-form-drag-icon" width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" focusable="false">'
        + '<circle cx="5.5" cy="3.5" r="1.25" fill="currentColor"></circle><circle cx="10.5" cy="3.5" r="1.25" fill="currentColor"></circle>'
        + '<circle cx="5.5" cy="8" r="1.25" fill="currentColor"></circle><circle cx="10.5" cy="8" r="1.25" fill="currentColor"></circle>'
        + '<circle cx="5.5" cy="12.5" r="1.25" fill="currentColor"></circle><circle cx="10.5" cy="12.5" r="1.25" fill="currentColor"></circle>'
        + '</svg>';

    var dragFromIdx = null;

    function syncHidden() {
        normalizeBloquesOrden();
        var json = JSON.stringify(esquema);
        if (hiddenSave) hiddenSave.value = json;
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
            html += '<div class="sgd-preview-rich-box"></div>';
        } else if (bloque.widget === 'bloque_firmas' && def.roles) {
            def.roles.forEach(function (r) {
                html += '<div class="sgd-preview-firma-line"><span class="sgd-preview-firma-label">' + escapeHtml(r.label || r.id) + '</span></div>';
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
            html += '<div class="sgd-preview-firma-line"><span class="sgd-preview-firma-label">Firma</span></div>';
        } else {
            var type = c.tipo === 'numero' ? 'number' : (c.tipo === 'fecha' ? 'date' : 'text');
            html += '<input type="' + type + '" class="form-input" disabled>';
        }
        html += '</div>';
        return html;
    }

    function updatePreviewHeadMeta() {
        if (!previewHeadEl) return;
        var metaLine = previewHeadEl.querySelector('.sgd-form-preview-meta-line');
        if (!metaLine) return;
        var parts = [];
        if (pageMeta.proceso) {
            parts.push('<span>' + escapeHtml(pageMeta.proceso) + '</span>');
        }
        if (pageMeta.version) {
            parts.push('Versión plantilla: <strong>' + escapeHtml(pageMeta.version) + '</strong>');
        }
        if (esquema.arquetipo && esquema.arquetipo !== 'libre') {
            parts.push('Arquetipo: <strong>' + escapeHtml(esquema.arquetipo) + '</strong>');
        }
        metaLine.innerHTML = parts.join(' · ');
    }

    function renderPreview() {
        if (!previewBodyEl) return;
        updatePreviewHeadMeta();
        var hasBloques = esquema.bloques && esquema.bloques.length;
        var hasCampos = esquema.campos && esquema.campos.length;
        if (!hasBloques && !hasCampos) {
            previewBodyEl.innerHTML = '<p class="field-note">Sin bloques ni campos. Elija un arquetipo y pulse «Cargar plantilla del arquetipo».</p>';
            return;
        }

        var html = '';
        sortBloques();
        (esquema.bloques || []).forEach(function (b) {
            html += renderBloquePreview(b);
        });
        (esquema.campos || []).forEach(function (c) {
            html += renderFieldPreview(c);
        });
        previewBodyEl.innerHTML = html;
    }

    function sortBloques() {
        if (!esquema.bloques || !esquema.bloques.length) return;
        esquema.bloques.sort(function (a, b) {
            return (a.orden || 0) - (b.orden || 0);
        });
    }

    function normalizeBloquesOrden() {
        sortBloques();
        (esquema.bloques || []).forEach(function (b, i) {
            b.orden = (i + 1) * 10;
        });
    }

    function widgetLabel(widget) {
        return WIDGET_LABELS[widget] || widget || '—';
    }

    function estadoLabel(estado) {
        return ESTADO_LABELS[estado] || estado || 'Aplica';
    }

    function estadoClass(estado) {
        if (estado === 'opcional') return 'sgd-form-pill-estado-opcional';
        if (estado === 'no_aplica') return 'sgd-form-pill-estado-na';
        return 'sgd-form-pill-estado-aplica';
    }

    function reorderBloque(from, to) {
        sortBloques();
        if (from === to || from < 0 || to < 0 || from >= esquema.bloques.length || to >= esquema.bloques.length) {
            return;
        }
        var moved = esquema.bloques.splice(from, 1)[0];
        esquema.bloques.splice(to, 0, moved);
        normalizeBloquesOrden();
        renderList();
    }

    function moveBloque(idx, delta) {
        reorderBloque(idx, idx + delta);
    }

    function bindBloquesSortable() {
        var list = listEl.querySelector('.sgd-form-bloques-sortable');
        if (!list || !canEdit) return;

        list.querySelectorAll('.sgd-form-bloque-item').forEach(function (item) {
            item.setAttribute('draggable', 'true');

            item.addEventListener('dragstart', function (e) {
                if (!e.target.closest('.sgd-form-drag-handle')) {
                    e.preventDefault();
                    return;
                }
                dragFromIdx = parseInt(item.getAttribute('data-idx'), 10);
                if (isNaN(dragFromIdx)) return;
                item.classList.add('is-dragging');
                if (e.dataTransfer) {
                    e.dataTransfer.effectAllowed = 'move';
                    e.dataTransfer.setData('text/plain', String(dragFromIdx));
                }
            });

            item.addEventListener('dragend', function () {
                item.classList.remove('is-dragging');
                list.querySelectorAll('.sgd-form-bloque-item').forEach(function (el) {
                    el.classList.remove('is-drag-over-before', 'is-drag-over-after');
                });
                dragFromIdx = null;
            });

            item.addEventListener('dragover', function (e) {
                e.preventDefault();
                if (e.dataTransfer) e.dataTransfer.dropEffect = 'move';
                var rect = item.getBoundingClientRect();
                var after = e.clientY > rect.top + rect.height / 2;
                item.classList.toggle('is-drag-over-before', !after);
                item.classList.toggle('is-drag-over-after', after);
                item._dropAfter = after;
            });

            item.addEventListener('dragleave', function () {
                item.classList.remove('is-drag-over-before', 'is-drag-over-after');
            });

            item.addEventListener('drop', function (e) {
                e.preventDefault();
                item.classList.remove('is-drag-over-before', 'is-drag-over-after');
                var from = dragFromIdx;
                var to = parseInt(item.getAttribute('data-idx'), 10);
                if (from === null || isNaN(from) || isNaN(to)) return;
                if (item._dropAfter) to += 1;
                if (from < to) to -= 1;
                reorderBloque(from, to);
            });

            item.addEventListener('keydown', function (e) {
                if (!e.altKey) return;
                var idx = parseInt(item.getAttribute('data-idx'), 10);
                if (isNaN(idx)) return;
                if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    moveBloque(idx, -1);
                } else if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    moveBloque(idx, 1);
                }
            });
        });
    }

    function renderBloquesList() {
        var parts = [];
        parts.push('<div class="sgd-form-bloques-panel">');
        parts.push('<div class="sgd-form-bloques-head">');
        parts.push('<h5 class="sgd-form-subtitle">Bloques <span class="sgd-form-count">(' + esquema.bloques.length + ')</span></h5>');
        if (canEdit) {
            parts.push('<p class="field-note sgd-form-order-hint">Arrastre el control <span aria-hidden="true">⠿</span> para definir el orden del formato. Atajo: <kbd>Alt</kbd> + <kbd>↑</kbd>/<kbd>↓</kbd> con el bloque enfocado.</p>');
        }
        parts.push('</div>');
        parts.push('<ul class="sgd-form-bloques-sortable' + (canEdit ? '' : ' sgd-form-bloques-sortable--readonly') + '" role="list" aria-label="Bloques del formato">');
        esquema.bloques.forEach(function (b, idx) {
            var nombre = escapeHtml(b.nombre || b.seccion_codigo);
            var codigo = escapeHtml(b.seccion_codigo);
            var widget = escapeHtml(widgetLabel(b.widget));
            var estado = String(b.estado || 'aplica');
            parts.push('<li class="sgd-form-bloque-item" data-idx="' + idx + '" role="listitem"' + (canEdit ? ' tabindex="0"' : '') + '>');
            if (canEdit) {
                parts.push('<button type="button" class="sgd-form-drag-handle" aria-label="Arrastrar bloque ' + nombre + '" title="Arrastrar para reordenar">');
                parts.push(DRAG_ICON);
                parts.push('</button>');
            }
            parts.push('<span class="sgd-form-bloque-order" aria-hidden="true">' + (idx + 1) + '</span>');
            parts.push('<div class="sgd-form-bloque-main">');
            parts.push('<span class="sgd-form-bloque-name">' + nombre + '</span>');
            parts.push('<code class="sgd-form-bloque-code">' + codigo + '</code>');
            parts.push('</div>');
            parts.push('<div class="sgd-form-bloque-meta">');
            parts.push('<span class="sgd-form-pill sgd-form-pill-widget">' + widget + '</span>');
            parts.push('<span class="sgd-form-pill ' + estadoClass(estado) + '">' + escapeHtml(estadoLabel(estado)) + '</span>');
            parts.push('</div>');
            parts.push('</li>');
        });
        parts.push('</ul></div>');
        return parts.join('');
    }

    function renderList() {
        if (!listEl) return;

        sortBloques();
        var parts = [];

        if (esquema.bloques && esquema.bloques.length) {
            parts.push(renderBloquesList());
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

        bindBloquesSortable();

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
            normalizeBloquesOrden();
            renderList();
        });
    }

    var saveForm = document.getElementById('sgd-form-designer-save');
    if (saveForm) saveForm.addEventListener('submit', syncHidden);

    if (esquema.bloques && esquema.bloques.length) {
        normalizeBloquesOrden();
    }

    renderList();
})();
