(function () {
    'use strict';

    var esquemaEl = document.getElementById('sgd-form-esquema-inicial');
    if (!esquemaEl) return;

    var catalogEl = document.getElementById('sgd-form-arquetipos-catalog');
    var catalog = {};
    if (catalogEl) {
        try { catalog = JSON.parse(catalogEl.textContent || '{}'); } catch (e) { catalog = {}; }
    }

    var esquema;
    try { esquema = JSON.parse(esquemaEl.textContent || '{}'); } catch (e) {
        esquema = { version: 3, arquetipo: 'libre', elementos: [] };
    }
    esquema = ensureV3(esquema);

    var listEl = document.getElementById('sgd-form-campos-list');
    var biblioEl = document.getElementById('sgd-form-biblioteca-list');
    var biblioPanelEl = document.getElementById('sgd-form-biblioteca-panel');
    var previewBodyEl = document.getElementById('sgd-form-preview-body');
    var previewHeadEl = document.getElementById('sgd-form-preview-head');
    var pageMeta = {};
    var pageMetaEl = document.getElementById('sgd-form-page-meta');
    if (pageMetaEl) {
        try { pageMeta = JSON.parse(pageMetaEl.textContent || '{}'); } catch (e) { pageMeta = {}; }
    }
    var hiddenSave = document.getElementById('sgd_esquema_json');
    var btnAdd = document.getElementById('sgd-btn-add-campo');
    var btnCargarSemilla = document.getElementById('sgd-btn-cargar-arquetipo');
    var arquetipoSelect = document.getElementById('sgd_arquetipo_select');
    var canEdit = pageMeta.canEdit !== undefined ? !!pageMeta.canEdit : !!btnAdd;
    var insertAfterIdx = -1;
    var expandedBlockCodigo = null;
    var bc = window.SgdBloqueConfig || null;

    var WIDGET_LABELS = {
        grupo_campos: 'Grupo de campos',
        tabla_repetible: 'Tabla repetible',
        lista_repetible: 'Lista repetible',
        texto_enriquecido: 'Texto enriquecido',
        bloque_firmas: 'Bloque de firmas'
    };
    var ESTADO_LABELS = { aplica: 'Aplica', opcional: 'Opcional', no_aplica: 'No aplica' };
    var DRAG_ICON = '<svg class="sgd-form-drag-icon" width="16" height="16" viewBox="0 0 16 16" aria-hidden="true"><circle cx="5.5" cy="3.5" r="1.25" fill="currentColor"></circle><circle cx="10.5" cy="3.5" r="1.25" fill="currentColor"></circle><circle cx="5.5" cy="8" r="1.25" fill="currentColor"></circle><circle cx="10.5" cy="8" r="1.25" fill="currentColor"></circle><circle cx="5.5" cy="12.5" r="1.25" fill="currentColor"></circle><circle cx="10.5" cy="12.5" r="1.25" fill="currentColor"></circle></svg>';
    var pointerDrag = null;

    function ensureV3(raw) {
        if (raw.version >= 3 && Array.isArray(raw.elementos)) {
            if (!raw.arquetipo) raw.arquetipo = 'libre';
            return raw;
        }
        var elementos = [];
        var orden = 10;
        (raw.bloques || []).forEach(function (b) {
            elementos.push({
                tipo: 'bloque',
                seccion_codigo: b.seccion_codigo,
                nombre: b.nombre,
                widget: b.widget,
                estado: b.estado || 'aplica',
                definicion: b.definicion,
                orden: orden
            });
            orden += 10;
        });
        (raw.campos || []).forEach(function (c) {
            elementos.push({
                tipo: 'campo',
                id: c.id,
                tipo_campo: c.tipo,
                label: c.label,
                requerido: !!c.requerido,
                opciones: c.opciones,
                orden: orden
            });
            orden += 10;
        });
        return { version: 3, arquetipo: raw.arquetipo || 'libre', elementos: elementos };
    }

    function bloquesDefMap() {
        var map = {};
        (catalog.bloques_operativos || []).forEach(function (b) {
            if (b && b.codigo) map[b.codigo] = b;
        });
        return map;
    }

    function bibliotecaCodigos(arquetipo) {
        arquetipo = String(arquetipo || 'libre').toLowerCase();
        var bib = catalog.biblioteca_arquetipo || {};
        if (bib[arquetipo] && bib[arquetipo].length) return bib[arquetipo].slice();
        var perfil = (catalog.plantillas_semilla_arquetipo || catalog.perfiles_arquetipo || {})[arquetipo];
        return perfil ? Object.keys(perfil) : [];
    }

    function buildElementoBloque(codigo, estado) {
        var def = bloquesDefMap()[codigo];
        if (!def) return null;
        var definicion = {};
        ['campos', 'columnas', 'item', 'roles'].forEach(function (k) {
            if (def[k]) definicion[k] = def[k];
        });
        var el = {
            tipo: 'bloque',
            seccion_codigo: codigo,
            nombre: def.nombre || codigo,
            widget: def.widget || 'grupo_campos',
            estado: estado || 'aplica',
            definicion: definicion
        };
        if (window.SgdChecklistConfig && SgdChecklistConfig.isChecklistBlock(codigo)) {
            el.config = SgdChecklistConfig.buildDefaultConfig(definicion);
        }
        return el;
    }

    function buildSemillaV3(codigo) {
        codigo = String(codigo || 'libre').toLowerCase();
        if (codigo === 'libre') {
            return { version: 3, arquetipo: 'libre', elementos: [] };
        }
        var semillas = catalog.plantillas_semilla_arquetipo || catalog.perfiles_arquetipo || {};
        var semilla = semillas[codigo];
        if (!semilla) return { version: 3, arquetipo: codigo, elementos: [] };
        var elementos = [];
        var orden = 10;
        Object.keys(semilla).forEach(function (sec) {
            if (semilla[sec] === 'no_aplica') return;
            var el = buildElementoBloque(sec, semilla[sec]);
            if (!el) return;
            if (window.SgdChecklistConfig && SgdChecklistConfig.isChecklistBlock(sec) && codigo === 'checklist') {
                el.config.modo = 'filas_fijas';
            }
            el.orden = orden;
            elementos.push(el);
            orden += 10;
        });
        return { version: 3, arquetipo: codigo, elementos: elementos };
    }

    function slugId(raw) {
        return String(raw || '').toLowerCase().trim().replace(/[^a-z0-9_]+/g, '_').replace(/^_+|_+$/g, '');
    }

    function escapeHtml(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function sortElementos() {
        esquema.elementos.sort(function (a, b) { return (a.orden || 0) - (b.orden || 0); });
    }

    function assignOrden() {
        esquema.elementos.forEach(function (el, i) { el.orden = (i + 1) * 10; });
    }

    function syncHidden() {
        sortElementos();
        assignOrden();
        if (hiddenSave) hiddenSave.value = JSON.stringify(esquema);
    }

    function catalogDefinicion(codigo) {
        var def = bloquesDefMap()[codigo];
        if (!def) return {};
        var definicion = {};
        ['campos', 'columnas', 'item', 'roles'].forEach(function (k) {
            if (def[k]) definicion[k] = def[k];
        });
        return definicion;
    }

    function ensureBlockConfig(el) {
        if (!bc || !bc.isConfigurable(el.widget || '')) return;
        var catDef = catalogDefinicion(el.seccion_codigo);
        var key = bc.configKey(el.widget);
        if (!key) return;
        if (!el.config || !el.config[key] || !el.config[key].length) {
            el.config = bc.buildDefaultConfig(catDef, el.widget);
        }
    }

    function resolvedDefinicion(el) {
        if (!bc) return el.definicion || {};
        return bc.resolveDefinicion(el, catalogDefinicion(el.seccion_codigo));
    }

    function configSummary(el) {
        if (!bc || !bc.isConfigurable(el.widget || '')) return '';
        var catDef = catalogDefinicion(el.seccion_codigo);
        var cfg = bc.getEffectiveConfig(el, catDef);
        var key = bc.configKey(el.widget);
        var total = (catDef[key] || []).length;
        var visible = (cfg[key] || []).filter(function (r) { return r.visible; }).length;
        if (total === 0 || visible === total) return '';
        return visible + '/' + total + ' ' + bc.configLabel(el.widget).toLowerCase();
    }

    function bloqueEnPlantilla(codigo) {
        return esquema.elementos.some(function (el) {
            return el.tipo === 'bloque' && el.seccion_codigo === codigo;
        });
    }

    function widgetLabel(w) { return WIDGET_LABELS[w] || w || '—'; }
    function estadoClass(e) {
        if (e === 'opcional') return 'sgd-form-pill-estado-opcional';
        if (e === 'no_aplica') return 'sgd-form-pill-estado-na';
        return 'sgd-form-pill-estado-aplica';
    }

    function renderFieldPreview(c) {
        var tipo = c.tipo_campo || c.tipo || 'texto';
        var req = c.requerido ? ' <span class="sgd-req">*</span>' : '';
        var html = '<div class="form-group sgd-preview-field"><label>' + escapeHtml(c.label || c.id) + req + '</label>';
        if (tipo === 'textarea') {
            html += '<textarea class="form-input" rows="3" disabled></textarea>';
        } else if (tipo === 'lista') {
            html += '<select class="form-input" disabled><option>—</option></select>';
        } else {
            html += '<input type="text" class="form-input" disabled>';
        }
        return html + '</div>';
    }

    function isBlockConfigurable(el) {
        if (window.SgdChecklistConfig && SgdChecklistConfig.isChecklistBlock(el.seccion_codigo)) return true;
        return bc && bc.isConfigurable(el.widget || '');
    }

    function renderBloquePreview(bloque) {
        var titulo = bc ? bc.resolveTitulo(bloque) : (bloque.nombre || bloque.seccion_codigo);
        var catItem = bloquesDefMap()[bloque.seccion_codigo] || {};
        var ayuda = bc ? bc.resolveAyuda(bloque, catItem) : '';
        var html = '<div class="sgd-preview-bloque"><h5 class="sgd-preview-bloque-title">' + escapeHtml(titulo) + '</h5>';
        if (bloque.estado === 'opcional') html += '<p class="field-note">Opcional</p>';
        if (ayuda) html += '<p class="field-note sgd-preview-ayuda">' + escapeHtml(ayuda) + '</p>';
        var def = resolvedDefinicion(bloque);
        var w = bloque.widget || 'grupo_campos';
        if (window.SgdChecklistConfig && SgdChecklistConfig.isChecklistBlock(bloque.seccion_codigo)) {
            html += renderChecklistPreview(bloque);
            return html + '</div>';
        }
        if (w === 'grupo_campos' && def.campos) {
            def.campos.forEach(function (c) { html += renderFieldPreview({ tipo: c.tipo, label: c.label, requerido: c.requerido }); });
        } else if (w === 'tabla_repetible' && def.columnas) {
            html += '<div class="sgd-preview-table">Tabla: ' + def.columnas.map(function (c) { return escapeHtml(c.label || c.id); }).join(' · ') + '</div>';
        } else if (w === 'lista_repetible') {
            html += '<p class="field-note">Lista repetible</p>';
        } else if (w === 'texto_enriquecido') {
            html += '<div class="sgd-preview-rich-box"></div>';
        } else if (w === 'bloque_firmas' && def.roles) {
            def.roles.forEach(function (r) {
                html += '<div class="sgd-preview-firma-line"><span class="sgd-preview-firma-label">' + escapeHtml(r.label || r.id) + '</span></div>';
            });
        }
        return html + '</div>';
    }

    function renderChecklistPreview(bloque) {
        var cl = window.SgdChecklistConfig;
        if (!cl) return '';
        cl.ensureChecklistConfig(bloque, catalogDefinicion(bloque.seccion_codigo));
        var cols = cl.visibleColumnas(bloque.config);
        var html = '<table class="sgd-preview-table sgd-preview-checklist"><thead><tr>';
        if (bloque.config.modo === 'filas_fijas') html += '<th>N°</th>';
        cols.forEach(function (c) {
            html += '<th>' + escapeHtml(c.label || c.id) + '</th>';
        });
        html += '</tr></thead><tbody>';
        var filas = bloque.config.modo === 'filas_fijas' ? (bloque.config.filas || []) : [null, null, null];
        if (!filas.length && bloque.config.modo === 'filas_fijas') {
            html += '<tr><td colspan="' + (cols.length + 1) + '" class="field-note">Sin filas en plantilla</td></tr>';
        }
        filas.forEach(function (fila) {
            html += '<tr>';
            if (bloque.config.modo === 'filas_fijas') {
                html += '<td>' + escapeHtml(fila && fila.numero ? fila.numero : '') + '</td>';
            }
            cols.forEach(function (c) {
                var cell = '';
                if (fila && c.rol === 'contenido' && fila.celdas) cell = fila.celdas[c.id] || '';
                else if (c.tipo === 'lista' && c.opciones && c.opciones.length) cell = c.opciones.join(' / ');
                html += '<td>' + escapeHtml(cell) + '</td>';
            });
            html += '</tr>';
        });
        return html + '</tbody></table>';
    }

    function renderPreview() {
        if (!previewBodyEl) return;
        sortElementos();
        var html = '';
        esquema.elementos.forEach(function (el) {
            if (el.tipo === 'bloque' && el.estado !== 'no_aplica') {
                html += renderBloquePreview(el);
            } else if (el.tipo === 'campo') {
                html += renderFieldPreview(el);
            }
        });
        previewBodyEl.innerHTML = html || '<p class="field-note">Agregue bloques de la biblioteca o campos sueltos.</p>';
    }

    function toggleBibliotecaPanel(arquetipo) {
        if (!biblioPanelEl) return;
        biblioPanelEl.hidden = String(arquetipo || '').toLowerCase() !== 'acta';
    }

    function renderBiblioteca() {
        toggleBibliotecaPanel(esquema.arquetipo || (arquetipoSelect ? arquetipoSelect.value : 'libre'));
        if (!biblioEl) return;
        var arq = esquema.arquetipo || (arquetipoSelect ? arquetipoSelect.value : 'libre');
        var codigos = bibliotecaCodigos(arq);
        var defMap = bloquesDefMap();
        if (!codigos.length) {
            biblioEl.innerHTML = '<p class="field-note">Sin biblioteca definida para este arquetipo.</p>';
            return;
        }
        var parts = ['<ul class="sgd-form-biblioteca" role="list">'];
        codigos.forEach(function (cod) {
            var def = defMap[cod];
            if (!def) return;
            var enPlantilla = bloqueEnPlantilla(cod);
            parts.push('<li class="sgd-form-biblio-item' + (enPlantilla ? ' is-added' : '') + '">');
            parts.push('<span class="sgd-form-biblio-name">' + escapeHtml(def.nombre || cod) + '</span>');
            parts.push('<code class="sgd-form-bloque-code">' + escapeHtml(cod) + '</code>');
            parts.push('<span class="sgd-form-pill sgd-form-pill-widget">' + escapeHtml(widgetLabel(def.widget)) + '</span>');
            if (canEdit) {
                parts.push('<button type="button" class="sgd-doc-btn sgd-doc-btn-sm sgd-form-biblio-add" data-codigo="' + escapeHtml(cod) + '"'
                    + (enPlantilla ? ' disabled title="Ya está en la plantilla"' : '') + '>+ Agregar</button>');
            }
            parts.push('</li>');
        });
        parts.push('</ul>');
        biblioEl.innerHTML = parts.join('');
        biblioEl.querySelectorAll('.sgd-form-biblio-add').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var cod = btn.getAttribute('data-codigo');
                if (cod) addBloque(cod);
            });
        });
    }

    function renderBlockConfigHtml(el, idx) {
        if (window.SgdChecklistConfig && SgdChecklistConfig.isChecklistBlock(el.seccion_codigo)) {
            window.SgdChecklistConfig.ensureChecklistConfig(el, catalogDefinicion(el.seccion_codigo));
            return window.SgdChecklistConfig.renderDesignerPanel(el, idx, canEdit, escapeHtml);
        }
        if (!bc || !bc.isConfigurable(el.widget || '')) return '';
        ensureBlockConfig(el);
        var catDef = catalogDefinicion(el.seccion_codigo);
        var catalogItem = bloquesDefMap()[el.seccion_codigo] || {};
        var cfg = el.config || {};
        var rows = bc.orderedConfigRows(el, catDef);
        var tituloVal = cfg.titulo || el.nombre || '';
        var ayudaVal = cfg.ayuda || catalogItem.ayuda || '';

        var html = '<div class="sgd-form-bloque-config" data-idx="' + idx + '">';
        html += '<div class="sgd-form-config-block-meta">';
        html += '<div class="form-group"><label>Título del bloque</label>';
        html += '<input type="text" class="form-input sgd-form-cfg-titulo" data-idx="' + idx + '" maxlength="120" value="' + escapeHtml(tituloVal) + '" placeholder="' + escapeHtml(el.nombre || '') + '"></div>';
        html += '<div class="form-group"><label>Ayuda contextual</label>';
        html += '<textarea class="form-input sgd-form-cfg-ayuda" data-idx="' + idx + '" rows="2" maxlength="500" placeholder="Texto de orientación al diligenciar">' + escapeHtml(ayudaVal) + '</textarea></div>';
        if (bc.supportsTerceroAutocomplete(el.seccion_codigo)) {
            html += '<label class="sgd-form-check"><input type="checkbox" class="sgd-form-cfg-autocompletar" data-idx="' + idx + '"' + (cfg.autocompletar_maestro === 'tercero' ? ' checked' : '') + '> Buscar en maestro de terceros</label>';
        }
        html += '</div>';

        html += '<p class="sgd-form-config-title">' + escapeHtml(bc.configLabel(el.widget)) + ' · orden, visibilidad y etiquetas</p>';
        html += '<ul class="sgd-form-config-fields">';
        rows.forEach(function (pair, fi) {
            var item = pair.catalog;
            var row = pair.config;
            var itemLabel = item.label || item.id;
            html += '<li class="sgd-form-config-field" data-field-id="' + escapeHtml(item.id) + '">';
            html += '<div class="sgd-form-config-field-order">';
            html += '<button type="button" class="sgd-form-move-btn sgd-form-cfg-field-up" data-idx="' + idx + '" data-field="' + escapeHtml(item.id) + '"' + (fi === 0 ? ' disabled' : '') + '>↑</button>';
            html += '<button type="button" class="sgd-form-move-btn sgd-form-cfg-field-down" data-idx="' + idx + '" data-field="' + escapeHtml(item.id) + '"' + (fi === rows.length - 1 ? ' disabled' : '') + '>↓</button>';
            html += '</div>';
            html += '<label class="sgd-form-config-visible"><input type="checkbox" class="sgd-form-cfg-visible" data-idx="' + idx + '" data-field="' + escapeHtml(item.id) + '"' + (row.visible ? ' checked' : '') + '></label>';
            html += '<span class="sgd-form-config-cat-label">' + escapeHtml(itemLabel) + '</span>';
            html += '<input type="text" class="form-input sgd-form-cfg-label" data-idx="' + idx + '" data-field="' + escapeHtml(item.id) + '" maxlength="120" placeholder="Etiqueta personalizada" value="' + escapeHtml(row.label || '') + '">';
            if (Object.prototype.hasOwnProperty.call(item, 'requerido')) {
                html += '<label class="sgd-form-config-req"><input type="checkbox" class="sgd-form-cfg-req" data-idx="' + idx + '" data-field="' + escapeHtml(item.id) + '"' + (row.requerido ? ' checked' : '') + (!row.visible ? ' disabled' : '') + '> Oblig.</label>';
            }
            html += '<input type="text" class="form-input sgd-form-cfg-default" data-idx="' + idx + '" data-field="' + escapeHtml(item.id) + '" maxlength="120" placeholder="Valor por defecto" value="' + escapeHtml(row.default || '') + '">';
            html += '</li>';
        });
        html += '</ul>';
        html += '<button type="button" class="sgd-doc-btn sgd-doc-btn-sm sgd-form-cfg-reset" data-idx="' + idx + '">Restaurar catálogo</button>';
        html += '</div>';
        return html;
    }

    function renderPlantillaList() {
        if (!listEl) return;
        sortElementos();
        var parts = [];
        parts.push('<div class="sgd-form-plantilla-head"><h5 class="sgd-form-subtitle">Plantilla del documento <span class="sgd-form-count">(' + esquema.elementos.length + ')</span></h5>');
        if (canEdit) {
            parts.push('<p class="field-note sgd-form-order-hint">Arrastre para ordenar bloques y campos. Los campos sueltos pueden ir entre bloques.</p>');
        }
        parts.push('</div>');
        parts.push('<ul class="sgd-form-elementos-sortable' + (canEdit ? '' : ' sgd-form-bloques-sortable--readonly') + '" role="list">');
        esquema.elementos.forEach(function (el, idx) {
            var isBloque = el.tipo === 'bloque';
            var label = isBloque ? (bc ? bc.resolveTitulo(el) : (el.nombre || el.seccion_codigo)) : (el.label || el.id);
            var isExpanded = isBloque && expandedBlockCodigo === el.seccion_codigo;
            var summary = isBloque ? configSummary(el) : '';
            parts.push('<li class="sgd-form-elemento-item' + (isBloque ? ' is-bloque' : ' is-campo') + (isExpanded ? ' is-config-open' : '') + '" data-idx="' + idx + '" role="listitem"' + (canEdit ? ' tabindex="0"' : '') + '>');
            if (canEdit) {
                parts.push('<span class="sgd-form-drag-handle" title="Arrastrar">' + DRAG_ICON + '</span>');
                parts.push('<div class="sgd-form-bloque-actions"><button type="button" class="sgd-form-move-btn sgd-form-move-up" data-idx="' + idx + '"' + (idx === 0 ? ' disabled' : '') + '>↑</button>');
                parts.push('<button type="button" class="sgd-form-move-btn sgd-form-move-down" data-idx="' + idx + '"' + (idx === esquema.elementos.length - 1 ? ' disabled' : '') + '>↓</button></div>');
            }
            parts.push('<span class="sgd-form-bloque-order">' + (idx + 1) + '</span>');
            parts.push('<div class="sgd-form-bloque-main"><span class="sgd-form-bloque-name">' + escapeHtml(label) + '</span>');
            if (isBloque) {
                parts.push('<code class="sgd-form-bloque-code">' + escapeHtml(el.seccion_codigo) + '</code>');
            } else {
                parts.push('<code class="sgd-form-bloque-code">' + escapeHtml(el.id) + '</code>');
            }
            parts.push('</div><div class="sgd-form-bloque-meta">');
            if (isBloque) {
                parts.push('<span class="sgd-form-pill sgd-form-pill-widget">' + escapeHtml(widgetLabel(el.widget)) + '</span>');
                if (summary) {
                    parts.push('<span class="sgd-form-pill sgd-form-pill-config-summary">' + escapeHtml(summary) + '</span>');
                }
                if (canEdit) {
                    parts.push('<select class="sgd-form-estado-select" data-idx="' + idx + '" aria-label="Estado del bloque">');
                    ['aplica', 'opcional', 'no_aplica'].forEach(function (st) {
                        parts.push('<option value="' + st + '"' + (el.estado === st ? ' selected' : '') + '>' + ESTADO_LABELS[st] + '</option>');
                    });
                    parts.push('</select>');
                } else {
                    parts.push('<span class="sgd-form-pill ' + estadoClass(el.estado) + '">' + escapeHtml(ESTADO_LABELS[el.estado] || el.estado) + '</span>');
                }
            } else {
                parts.push('<span class="sgd-form-pill">Campo ' + escapeHtml(el.tipo_campo || 'texto') + '</span>');
            }
            parts.push('</div>');
            if (canEdit && isBloque && isBlockConfigurable(el)) {
                parts.push('<button type="button" class="sgd-form-config-toggle sgd-doc-btn sgd-doc-btn-sm" data-idx="' + idx + '" data-codigo="' + escapeHtml(el.seccion_codigo) + '" title="Configurar campos del bloque"' + (isExpanded ? ' aria-expanded="true"' : ' aria-expanded="false"') + '>⚙</button>');
            }
            if (canEdit) {
                parts.push('<button type="button" class="sgd-form-del-elemento sgd-doc-btn sgd-doc-btn-danger sgd-doc-btn-sm" data-idx="' + idx + '" title="Quitar">✕</button>');
            }
            if (isExpanded) {
                parts.push(renderBlockConfigHtml(el, idx));
            }
            parts.push('</li>');
        });
        parts.push('</ul>');
        if (!esquema.elementos.length) {
            parts.push('<p class="field-note">Plantilla vacía. Use la biblioteca o cargue la semilla del arquetipo.</p>');
        }
        listEl.innerHTML = parts.join('');

        listEl.querySelectorAll('.sgd-form-estado-select').forEach(function (sel) {
            sel.addEventListener('change', function () {
                var i = parseInt(sel.getAttribute('data-idx'), 10);
                if (!isNaN(i) && esquema.elementos[i]) {
                    esquema.elementos[i].estado = sel.value;
                    renderAll();
                }
            });
        });
        listEl.querySelectorAll('.sgd-form-del-elemento').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var i = parseInt(btn.getAttribute('data-idx'), 10);
                if (!isNaN(i)) {
                    var removed = esquema.elementos[i];
                    if (removed && removed.tipo === 'bloque' && removed.seccion_codigo === expandedBlockCodigo) {
                        expandedBlockCodigo = null;
                    }
                    esquema.elementos.splice(i, 1);
                    renderAll();
                }
            });
        });

        bindElementosReorder();
        bindBlockConfigHandlers();
        if (window.SgdChecklistConfig) {
            window.SgdChecklistConfig.bindDesignerHandlers(listEl, function () { return esquema; }, function () {
                renderPlantillaList();
                renderPreview();
                syncHidden();
            });
        }
        renderPreview();
        renderBiblioteca();
        syncHidden();
    }

    function renderAll() {
        renderPlantillaList();
    }

    function addBloque(codigo) {
        if (bloqueEnPlantilla(codigo)) return;
        var el = buildElementoBloque(codigo, 'aplica');
        if (!el) return;
        if (insertAfterIdx >= 0 && insertAfterIdx < esquema.elementos.length) {
            esquema.elementos.splice(insertAfterIdx + 1, 0, el);
        } else {
            esquema.elementos.push(el);
        }
        assignOrden();
        renderAll();
    }

    function reorderElemento(from, to) {
        if (from === to || from < 0 || to < 0 || from >= esquema.elementos.length || to >= esquema.elementos.length) return;
        var moved = esquema.elementos.splice(from, 1)[0];
        esquema.elementos.splice(to, 0, moved);
        assignOrden();
        renderAll();
    }

    function reorderConfigField(elIdx, fieldId, dir) {
        var el = esquema.elementos[elIdx];
        if (!el || el.tipo !== 'bloque' || !bc) return;
        ensureBlockConfig(el);
        var key = bc.configKey(el.widget);
        var rows = el.config[key];
        var i = -1;
        rows.forEach(function (r, idx) { if (r.id === fieldId) i = idx; });
        var j = i + dir;
        if (i < 0 || j < 0 || j >= rows.length) return;
        var tmp = rows[i];
        rows[i] = rows[j];
        rows[j] = tmp;
        renderPlantillaList();
        renderPreview();
        syncHidden();
    }

    function bindBlockConfigHandlers() {
        if (!listEl || listEl._sgdBlockCfgInit) return;
        listEl._sgdBlockCfgInit = true;

        listEl.addEventListener('click', function (e) {
            var toggle = e.target.closest('.sgd-form-config-toggle');
            if (toggle) {
                e.preventDefault();
                e.stopPropagation();
                var codigo = toggle.getAttribute('data-codigo');
                expandedBlockCodigo = expandedBlockCodigo === codigo ? null : codigo;
                renderPlantillaList();
                return;
            }
            var reset = e.target.closest('.sgd-form-cfg-reset');
            if (reset) {
                e.preventDefault();
                var ri = parseInt(reset.getAttribute('data-idx'), 10);
                var rel = esquema.elementos[ri];
                if (rel && rel.tipo === 'bloque') {
                    var def = bloquesDefMap()[rel.seccion_codigo];
                    if (def) rel.nombre = def.nombre || rel.seccion_codigo;
                    delete rel.config;
                    renderPlantillaList();
                    renderPreview();
                    syncHidden();
                }
                return;
            }
            var fup = e.target.closest('.sgd-form-cfg-field-up');
            if (fup && !fup.disabled) {
                e.preventDefault();
                reorderConfigField(parseInt(fup.getAttribute('data-idx'), 10), fup.getAttribute('data-field'), -1);
                return;
            }
            var fdown = e.target.closest('.sgd-form-cfg-field-down');
            if (fdown && !fdown.disabled) {
                e.preventDefault();
                reorderConfigField(parseInt(fdown.getAttribute('data-idx'), 10), fdown.getAttribute('data-field'), 1);
            }
        });

        listEl.addEventListener('change', function (e) {
            var vis = e.target.closest('.sgd-form-cfg-visible');
            if (vis) {
                var vi = parseInt(vis.getAttribute('data-idx'), 10);
                var fieldId = vis.getAttribute('data-field');
                var vel = esquema.elementos[vi];
                if (!vel || vel.tipo !== 'bloque' || !fieldId) return;
                ensureBlockConfig(vel);
                var vkey = bc.configKey(vel.widget);
                var vrow = vel.config[vkey].find(function (r) { return r.id === fieldId; });
                if (vrow) {
                    vrow.visible = vis.checked;
                    if (!vis.checked) vrow.requerido = false;
                }
                var reqEl = listEl.querySelector('.sgd-form-cfg-req[data-idx="' + vi + '"][data-field="' + fieldId + '"]');
                if (reqEl) {
                    reqEl.disabled = !vis.checked;
                    if (!vis.checked) reqEl.checked = false;
                }
                renderPreview();
                syncHidden();
                return;
            }
            var req = e.target.closest('.sgd-form-cfg-req');
            if (req) {
                var ri = parseInt(req.getAttribute('data-idx'), 10);
                var rf = req.getAttribute('data-field');
                var rel = esquema.elementos[ri];
                if (!rel || rel.tipo !== 'bloque' || !rf) return;
                ensureBlockConfig(rel);
                var rkey = bc.configKey(rel.widget);
                var rrow = rel.config[rkey].find(function (r) { return r.id === rf; });
                if (rrow) rrow.requerido = req.checked;
                renderPreview();
                syncHidden();
                return;
            }
            var auto = e.target.closest('.sgd-form-cfg-autocompletar');
            if (auto) {
                var ai = parseInt(auto.getAttribute('data-idx'), 10);
                var ael = esquema.elementos[ai];
                if (!ael || ael.tipo !== 'bloque') return;
                if (!ael.config) ael.config = {};
                if (auto.checked) ael.config.autocompletar_maestro = 'tercero';
                else delete ael.config.autocompletar_maestro;
                syncHidden();
            }
        });

        listEl.addEventListener('input', function (e) {
            var tit = e.target.closest('.sgd-form-cfg-titulo');
            if (tit) {
                var ti = parseInt(tit.getAttribute('data-idx'), 10);
                var tel = esquema.elementos[ti];
                if (!tel || tel.tipo !== 'bloque') return;
                if (!tel.config) tel.config = {};
                tel.config.titulo = tit.value.trim();
                tel.nombre = tel.config.titulo || (bloquesDefMap()[tel.seccion_codigo] ? bloquesDefMap()[tel.seccion_codigo].nombre : '') || tel.seccion_codigo;
                renderPreview();
                syncHidden();
                return;
            }
            var ay = e.target.closest('.sgd-form-cfg-ayuda');
            if (ay) {
                var yi = parseInt(ay.getAttribute('data-idx'), 10);
                var yel = esquema.elementos[yi];
                if (!yel || yel.tipo !== 'bloque') return;
                if (!yel.config) yel.config = {};
                yel.config.ayuda = ay.value.trim();
                renderPreview();
                syncHidden();
                return;
            }
            var lbl = e.target.closest('.sgd-form-cfg-label');
            if (lbl) {
                var li = parseInt(lbl.getAttribute('data-idx'), 10);
                var lf = lbl.getAttribute('data-field');
                var lel = esquema.elementos[li];
                if (!lel || lel.tipo !== 'bloque' || !lf) return;
                ensureBlockConfig(lel);
                var lkey = bc.configKey(lel.widget);
                var lrow = lel.config[lkey].find(function (r) { return r.id === lf; });
                if (lrow) {
                    lrow.label = lbl.value.trim();
                    if (!lrow.label) delete lrow.label;
                }
                renderPreview();
                syncHidden();
                return;
            }
            var def = e.target.closest('.sgd-form-cfg-default');
            if (def) {
                var di = parseInt(def.getAttribute('data-idx'), 10);
                var df = def.getAttribute('data-field');
                var del = esquema.elementos[di];
                if (!del || del.tipo !== 'bloque' || !df) return;
                ensureBlockConfig(del);
                var dkey = bc.configKey(del.widget);
                var drow = del.config[dkey].find(function (r) { return r.id === df; });
                if (drow) {
                    drow.default = def.value.trim();
                    if (!drow.default) delete drow.default;
                }
                renderPreview();
                syncHidden();
            }
        });
    }

    function bindElementosReorder() {
        if (!listEl || listEl._sgdElReorderInit) return;
        listEl._sgdElReorderInit = true;

        listEl.addEventListener('click', function (e) {
            if (!canEdit) return;
            var up = e.target.closest('.sgd-form-move-up');
            if (up && !up.disabled) {
                e.preventDefault();
                e.stopPropagation();
                var ui = parseInt(up.getAttribute('data-idx'), 10);
                if (!isNaN(ui)) reorderElemento(ui, ui - 1);
                return;
            }
            var down = e.target.closest('.sgd-form-move-down');
            if (down && !down.disabled) {
                e.preventDefault();
                e.stopPropagation();
                var di = parseInt(down.getAttribute('data-idx'), 10);
                if (!isNaN(di)) reorderElemento(di, di + 1);
            }
        });

        listEl.addEventListener('mousedown', function (e) {
            if (!canEdit || e.button !== 0) return;
            var handle = e.target.closest('.sgd-form-drag-handle');
            if (!handle) return;
            var item = handle.closest('.sgd-form-elemento-item');
            if (!item) return;
            e.preventDefault();
            var fromIdx = parseInt(item.getAttribute('data-idx'), 10);
            if (isNaN(fromIdx)) return;
            pointerDrag = { fromIdx: fromIdx, dropIdx: fromIdx, item: item };
            item.classList.add('is-dragging');
        });

        document.addEventListener('mousemove', function (e) {
            if (!pointerDrag) return;
            e.preventDefault();
            listEl.querySelectorAll('.sgd-form-elemento-item').forEach(function (el) {
                el.classList.remove('is-drag-over-before', 'is-drag-over-after');
                el.classList.toggle('is-dragging', el === pointerDrag.item);
            });
            var over = document.elementFromPoint(e.clientX, e.clientY);
            var target = over && over.closest('.sgd-form-elemento-item');
            if (!target || !listEl.contains(target) || target === pointerDrag.item) {
                pointerDrag.dropIdx = pointerDrag.fromIdx;
                return;
            }
            var rect = target.getBoundingClientRect();
            var after = e.clientY > rect.top + rect.height / 2;
            target.classList.toggle('is-drag-over-before', !after);
            target.classList.toggle('is-drag-over-after', after);
            var to = parseInt(target.getAttribute('data-idx'), 10);
            if (after) to += 1;
            if (pointerDrag.fromIdx < to) to -= 1;
            pointerDrag.dropIdx = to;
        });

        document.addEventListener('mouseup', function () {
            if (!pointerDrag) return;
            var from = pointerDrag.fromIdx;
            var to = pointerDrag.dropIdx;
            listEl.querySelectorAll('.sgd-form-elemento-item').forEach(function (el) {
                el.classList.remove('is-drag-over-before', 'is-drag-over-after', 'is-dragging');
            });
            pointerDrag = null;
            if (from !== to) reorderElemento(from, to);
        });
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
            if (esquema.elementos.some(function (el) { return el.tipo === 'campo' && el.id === id; })) {
                alert('Ya existe un campo con ese identificador.');
                return;
            }
            var campo = {
                tipo: 'campo',
                id: id,
                tipo_campo: tipoInput.value || 'texto',
                label: label,
                requerido: !!(reqInput && reqInput.checked)
            };
            if (insertAfterIdx >= 0 && insertAfterIdx < esquema.elementos.length) {
                esquema.elementos.splice(insertAfterIdx + 1, 0, campo);
            } else {
                esquema.elementos.push(campo);
            }
            idInput.value = '';
            labelInput.value = '';
            if (reqInput) reqInput.checked = false;
            assignOrden();
            renderAll();
        });
    }

    if (btnCargarSemilla && arquetipoSelect) {
        btnCargarSemilla.addEventListener('click', function () {
            var codigo = arquetipoSelect.value || 'libre';
            if (esquema.elementos.length) {
                if (!confirm('¿Reemplazar la plantilla actual con la semilla del arquetipo?')) return;
            }
            esquema = buildSemillaV3(codigo);
            expandedBlockCodigo = null;
            renderAll();
        });
        arquetipoSelect.addEventListener('change', function () {
            esquema.arquetipo = arquetipoSelect.value || 'libre';
            toggleBibliotecaPanel(esquema.arquetipo);
            renderBiblioteca();
            syncHidden();
        });
    }

    var saveForm = document.getElementById('sgd-form-designer-save');
    if (saveForm) saveForm.addEventListener('submit', syncHidden);

    if (arquetipoSelect && esquema.arquetipo) {
        arquetipoSelect.value = esquema.arquetipo;
    }

    bindElementosReorder();
    if (window.SgdChecklistConfig) {
        window.SgdChecklistConfig.bindDesignerHandlers(listEl, function () { return esquema; }, function () {
            renderPlantillaList();
            renderPreview();
            syncHidden();
        });
    }
    renderAll();
})();
