(function () {
    'use strict';

    var esquemaEl = document.getElementById('sgd-reg-esquema-inicial');
    var datosEl = document.getElementById('sgd-reg-datos-inicial');
    var bodyEl = document.getElementById('sgd-reg-diligenciar-body');
    if (!esquemaEl || !datosEl || !bodyEl) {
        return;
    }

    var metaEl = document.getElementById('sgd-reg-page-meta');
    var pageMeta = {};
    if (metaEl) {
        try {
            pageMeta = JSON.parse(metaEl.textContent || '{}');
        } catch (e) {
            pageMeta = {};
        }
    }

    var canEdit = !!pageMeta.editable;
    var empresaId = pageMeta.empresaId || null;
    var terceroSearchTimer = null;
    var esquema;
    var datos;

    try {
        esquema = JSON.parse(esquemaEl.textContent || '{}');
    } catch (e) {
        esquema = { version: 3, elementos: [] };
    }
    esquema = ensureV3(esquema);

    try {
        datos = JSON.parse(datosEl.textContent || '{}');
    } catch (e) {
        datos = { bloques: {}, campos_sueltos: [] };
    }

    if (!datos.bloques || typeof datos.bloques !== 'object') {
        datos.bloques = {};
    }
    if (!Array.isArray(datos.campos_sueltos)) {
        datos.campos_sueltos = [];
    }

    var hiddenDatos = document.getElementById('sgd_reg_datos_json');
    var hiddenDatosCerrar = document.getElementById('sgd_reg_datos_json_cerrar');
    var formGuardar = document.getElementById('sgd-reg-diligenciar-form');
    var formCerrar = document.getElementById('sgd-reg-cerrar-form');

    function esc(s) {
        if (s === null || s === undefined) {
            return '';
        }
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function bloqueCodigo(bloque) {
        return String(bloque.seccion_codigo || bloque.codigo || '');
    }

    function ensureV3(raw) {
        if (raw.version >= 3 && Array.isArray(raw.elementos)) {
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

    function findBloqueElemento(codigo) {
        var found = null;
        (esquema.elementos || []).forEach(function (el) {
            if (el.tipo === 'bloque' && bloqueCodigo(el) === codigo) {
                found = el;
            }
        });
        return found;
    }

    function applyBlockDefaults(codigo, bloque, def) {
        var widget = String(bloque.widget || 'grupo_campos');
        if (widget !== 'grupo_campos') return;
        var grupo = datos.bloques[codigo];
        if (!grupo || typeof grupo !== 'object' || Array.isArray(grupo)) {
            grupo = {};
            datos.bloques[codigo] = grupo;
        }
        var defaults = window.SgdBloqueConfig ? window.SgdBloqueConfig.resolveDefaults(bloque) : {};
        (def.campos || []).forEach(function (campo) {
            var cid = campo.id;
            if (!cid) return;
            if (grupo[cid] != null && String(grupo[cid]).trim() !== '') return;
            if (defaults[cid]) {
                grupo[cid] = defaults[cid];
            } else if (campo.default) {
                grupo[cid] = campo.default;
            }
        });
    }

    function fillGrupoFromTercero(codigo, item) {
        var grupo = datos.bloques[codigo];
        if (!grupo || typeof grupo !== 'object' || Array.isArray(grupo)) {
            grupo = {};
            datos.bloques[codigo] = grupo;
        }
        grupo._tercero_id = item.tercero_id;
        if (item.razon_social) grupo.razon_social = item.razon_social;
        if (item.nit) grupo.nit = item.nit;
        if (item.telefono) grupo.telefono = item.telefono;
        if (item.direccion) grupo.direccion = item.direccion;
        if (item.municipio) grupo.municipio = item.municipio;
    }

    function renderTerceroLookup(container, codigo, bloque) {
        if (!window.SgdBloqueConfig || window.SgdBloqueConfig.resolveAutocompletar(bloque) !== 'tercero') {
            return;
        }
        if (!canEdit) return;
        var html = '<div class="sgd-reg-tercero-lookup" data-codigo="' + esc(codigo) + '">';
        html += '<label class="sgd-reg-tercero-label">Buscar en maestro de terceros</label>';
        html += '<input type="search" class="sgd-reg-input sgd-reg-tercero-search" placeholder="Razón social o NIT…" autocomplete="off">';
        html += '<ul class="sgd-reg-tercero-results" hidden></ul></div>';
        container.insertAdjacentHTML('afterbegin', html);
    }

    function ensureBloqueValor(codigo, bloque) {
        if (datos.bloques[codigo] !== undefined) {
            return;
        }
        if (window.SgdChecklistConfig && SgdChecklistConfig.isChecklistBlock(bloque.seccion_codigo || codigo)) {
            window.SgdChecklistConfig.ensureChecklistConfig(bloque, bloque.definicion || {});
            datos.bloques[codigo] = bloque.config.modo === 'filas_fijas' ? {} : [];
            return;
        }
        var widget = String(bloque.widget || 'grupo_campos');
        if (widget === 'tabla_repetible' || widget === 'lista_repetible') {
            datos.bloques[codigo] = [];
        } else if (widget === 'grupo_campos') {
            datos.bloques[codigo] = {};
        } else {
            datos.bloques[codigo] = '';
        }
    }

    function syncHidden() {
        var json = JSON.stringify(datos);
        if (hiddenDatos) {
            hiddenDatos.value = json;
        }
        if (hiddenDatosCerrar) {
            hiddenDatosCerrar.value = json;
        }
    }

    function inputAttrs(name, campo, value) {
        var id = name;
        var req = campo.requerido ? ' required' : '';
        var ro = canEdit ? '' : ' readonly disabled';
        var val = esc(value == null ? '' : value);
        return { id: id, req: req, ro: ro, val: val };
    }

    function renderCampoSimple(parent, prefix, campo, value) {
        var tipo = String(campo.tipo || 'texto');
        var label = esc(campo.label || campo.id || '');
        var name = prefix + '[' + campo.id + ']';
        var attrs = inputAttrs(name, campo, value);
        var placeholder = campo.default ? ' placeholder="' + esc(campo.default) + '"' : '';
        var html = '<div class="sgd-reg-field"><label for="' + esc(attrs.id) + '">' + label + '</label>';

        if (tipo === 'textarea') {
            html += '<textarea class="sgd-reg-input" id="' + esc(attrs.id) + '" name="' + esc(name) + '"'
                + attrs.req + attrs.ro + placeholder + '>' + attrs.val + '</textarea>';
        } else if (tipo === 'fecha') {
            html += '<input type="date" class="sgd-reg-input" id="' + esc(attrs.id) + '" name="' + esc(name) + '" value="' + attrs.val + '"'
                + attrs.req + attrs.ro + '>';
        } else if (tipo === 'lista' && Array.isArray(campo.opciones)) {
            html += '<select class="sgd-reg-input" id="' + esc(attrs.id) + '" name="' + esc(name) + '"' + attrs.req + attrs.ro + '>';
            html += '<option value="">—</option>';
            campo.opciones.forEach(function (opt) {
                var sel = String(value) === String(opt) ? ' selected' : '';
                html += '<option value="' + esc(opt) + '"' + sel + '>' + esc(opt) + '</option>';
            });
            html += '</select>';
        } else {
            html += '<input type="text" class="sgd-reg-input" id="' + esc(attrs.id) + '" name="' + esc(name) + '" value="' + attrs.val + '"'
                + attrs.req + attrs.ro + placeholder + '>';
        }
        html += '</div>';
        parent.insertAdjacentHTML('beforeend', html);
    }

    function renderGrupoCampos(container, codigo, def) {
        var campos = def.campos || [];
        var grupo = datos.bloques[codigo];
        if (!grupo || typeof grupo !== 'object' || Array.isArray(grupo)) {
            grupo = {};
            datos.bloques[codigo] = grupo;
        }
        campos.forEach(function (campo) {
            renderCampoSimple(container, 'bloque_' + codigo, campo, grupo[campo.id]);
        });
    }

    function renderTabla(container, codigo, def) {
        var columnas = def.columnas || [];
        var filas = Array.isArray(datos.bloques[codigo]) ? datos.bloques[codigo] : [];
        datos.bloques[codigo] = filas;

        var tableId = 'sgd-reg-tabla-' + codigo;
        var html = '<div class="sgd-reg-tabla-wrap" id="' + esc(tableId) + '">';
        html += '<table class="sgd-reg-tabla"><thead><tr>';
        columnas.forEach(function (col) {
            html += '<th>' + esc(col.label || col.id) + '</th>';
        });
        if (canEdit) {
            html += '<th></th>';
        }
        html += '</tr></thead><tbody>';

        filas.forEach(function (fila, idx) {
            html += '<tr data-row="' + idx + '">';
            columnas.forEach(function (col) {
                var val = fila && fila[col.id] != null ? fila[col.id] : '';
                var cellName = 'tabla_' + codigo + '[' + idx + '][' + col.id + ']';
                html += '<td>';
                if (canEdit) {
                    if (String(col.tipo) === 'textarea') {
                        html += '<textarea class="sgd-reg-input sgd-reg-input-sm" name="' + esc(cellName) + '">' + esc(val) + '</textarea>';
                    } else if (String(col.tipo) === 'fecha') {
                        html += '<input type="date" class="sgd-reg-input sgd-reg-input-sm" name="' + esc(cellName) + '" value="' + esc(val) + '">';
                    } else {
                        html += '<input type="text" class="sgd-reg-input sgd-reg-input-sm" name="' + esc(cellName) + '" value="' + esc(val) + '">';
                    }
                } else {
                    html += esc(val);
                }
                html += '</td>';
            });
            if (canEdit) {
                html += '<td><button type="button" class="sgd-doc-btn sgd-doc-btn-sm sgd-reg-btn-del-row" data-codigo="' + esc(codigo) + '" data-row="' + idx + '">✕</button></td>';
            }
            html += '</tr>';
        });

        html += '</tbody></table>';
        if (canEdit) {
            html += '<button type="button" class="sgd-doc-btn sgd-doc-btn-sm sgd-reg-btn-add-row" data-codigo="' + esc(codigo) + '">+ Fila</button>';
        }
        html += '</div>';
        container.insertAdjacentHTML('beforeend', html);
    }

    function renderChecklist(container, codigo, bloque) {
        var cl = window.SgdChecklistConfig;
        if (!cl) {
            renderTabla(container, codigo, bloque.definicion || bloque);
            return;
        }
        cl.ensureChecklistConfig(bloque, bloque.definicion || {});
        var config = bloque.config;
        var cols = cl.visibleColumnas(config);
        var modo = config.modo || 'filas_libres';
        var html = '<div class="sgd-reg-tabla-wrap sgd-reg-checklist-wrap"><table class="sgd-reg-tabla"><thead><tr>';
        if (modo === 'filas_fijas') html += '<th>N°</th>';
        cols.forEach(function (col) {
            html += '<th>' + esc(col.label || col.id) + '</th>';
        });
        if (modo === 'filas_libres' && canEdit) html += '<th></th>';
        html += '</tr></thead><tbody>';

        if (modo === 'filas_fijas') {
            if (typeof datos.bloques[codigo] !== 'object' || Array.isArray(datos.bloques[codigo])) {
                datos.bloques[codigo] = {};
            }
            var resp = datos.bloques[codigo];
            (config.filas || []).forEach(function (fila) {
                var rid = fila.id;
                if (!resp[rid]) resp[rid] = {};
                html += '<tr data-row-id="' + esc(rid) + '">';
                html += '<td>' + esc(fila.numero || '') + '</td>';
                cols.forEach(function (col) {
                    html += '<td>';
                    if (col.rol === 'contenido') {
                        var cv = (fila.celdas && fila.celdas[col.id]) || '';
                        html += '<span class="sgd-reg-checklist-contenido">' + esc(cv) + '</span>';
                    } else {
                        var val = resp[rid][col.id] || '';
                        var name = 'cl_' + codigo + '[' + rid + '][' + col.id + ']';
                        html += cl.renderCellInput(col, name, val, canEdit, esc);
                    }
                    html += '</td>';
                });
                html += '</tr>';
            });
            if (!(config.filas || []).length) {
                html += '<tr><td colspan="' + (cols.length + 1) + '"><em class="field-note">Sin filas en plantilla</em></td></tr>';
            }
        } else {
            var filas = Array.isArray(datos.bloques[codigo]) ? datos.bloques[codigo] : [];
            datos.bloques[codigo] = filas;
            filas.forEach(function (fila, idx) {
                html += '<tr data-row="' + idx + '">';
                cols.forEach(function (col) {
                    var val = fila && fila[col.id] != null ? fila[col.id] : '';
                    html += '<td>';
                    var name = 'tabla_' + codigo + '[' + idx + '][' + col.id + ']';
                    html += cl.renderCellInput(col, name, val, canEdit, esc);
                    html += '</td>';
                });
                if (canEdit) {
                    html += '<td><button type="button" class="sgd-doc-btn sgd-doc-btn-sm sgd-reg-btn-del-row" data-codigo="' + esc(codigo) + '" data-row="' + idx + '">✕</button></td>';
                }
                html += '</tr>';
            });
        }

        html += '</tbody></table>';
        if (modo === 'filas_libres' && canEdit) {
            html += '<button type="button" class="sgd-doc-btn sgd-doc-btn-sm sgd-reg-btn-add-row" data-codigo="' + esc(codigo) + '">+ Fila</button>';
        }
        html += '</div>';
        container.insertAdjacentHTML('beforeend', html);
    }

    function renderLista(container, codigo, def) {
        var item = def.item || { id: 'item', tipo: 'texto', label: 'Ítem' };
        var filas = Array.isArray(datos.bloques[codigo]) ? datos.bloques[codigo] : [];
        datos.bloques[codigo] = filas;

        var listId = 'sgd-reg-lista-' + codigo;
        var html = '<ol class="sgd-reg-lista" id="' + esc(listId) + '">';
        filas.forEach(function (fila, idx) {
            var val = fila && fila[item.id] != null ? fila[item.id] : '';
            html += '<li data-row="' + idx + '">';
            if (canEdit) {
                html += '<textarea class="sgd-reg-input" name="lista_' + esc(codigo) + '[' + idx + '][' + esc(item.id) + ']">' + esc(val) + '</textarea>';
                html += ' <button type="button" class="sgd-doc-btn sgd-doc-btn-sm sgd-reg-btn-del-row" data-codigo="' + esc(codigo) + '" data-row="' + idx + '">✕</button>';
            } else {
                html += esc(val);
            }
            html += '</li>';
        });
        html += '</ol>';
        if (canEdit) {
            html += '<button type="button" class="sgd-doc-btn sgd-doc-btn-sm sgd-reg-btn-add-row" data-codigo="' + esc(codigo) + '" data-lista="1">+ Ítem</button>';
        }
        container.insertAdjacentHTML('beforeend', html);
    }

    function renderBloque(bloque) {
        var codigo = bloqueCodigo(bloque);
        if (!codigo || (bloque.estado || 'aplica') === 'no_aplica') {
            return;
        }
        ensureBloqueValor(codigo, bloque);

        var bc = window.SgdBloqueConfig;
        var titulo = esc(bc ? bc.resolveTitulo(bloque) : (bloque.nombre || codigo));
        var ayuda = bc ? bc.resolveAyuda(bloque, bloque.definicion || {}) : '';
        var widget = String(bloque.widget || 'grupo_campos');
        var def = bloque.definicion && typeof bloque.definicion === 'object' ? bloque.definicion : bloque;
        if (bc) {
            def = bc.resolveDefinicion(bloque, def);
        }
        applyBlockDefaults(codigo, bloque, def);

        var section = document.createElement('section');
        section.className = 'sgd-panel sgd-reg-bloque';
        section.dataset.codigo = codigo;
        var headHtml = '<header class="sgd-panel-head"><h3 class="sgd-panel-title">' + titulo + '</h3>';
        if (ayuda) {
            headHtml += '<p class="field-note sgd-reg-bloque-ayuda">' + esc(ayuda) + '</p>';
        }
        headHtml += '</header><div class="sgd-reg-bloque-body"></div>';
        section.innerHTML = headHtml;
        var inner = section.querySelector('.sgd-reg-bloque-body');

        if (window.SgdChecklistConfig && SgdChecklistConfig.isChecklistBlock(codigo)) {
            renderChecklist(inner, codigo, bloque);
        } else if (widget === 'tabla_repetible') {
            renderTabla(inner, codigo, def);
        } else if (widget === 'lista_repetible') {
            renderLista(inner, codigo, def);
        } else if (widget === 'texto_enriquecido') {
            var richVal = datos.bloques[codigo] || '';
            if (canEdit) {
                inner.innerHTML = '<textarea class="sgd-reg-input sgd-reg-rich" name="rich_' + esc(codigo) + '" rows="8">' + esc(richVal) + '</textarea>';
            } else {
                inner.innerHTML = '<div class="sgd-reg-rich-read">' + esc(richVal) + '</div>';
            }
        } else if (widget === 'bloque_firmas') {
            inner.innerHTML = '<p class="field-note">Bloque de firmas — disponible en fase de firmas (F5).</p>';
        } else {
            renderTerceroLookup(inner, codigo, bloque);
            renderGrupoCampos(inner, codigo, def);
        }

        bodyEl.appendChild(section);
    }

    function collectFromDom() {
        (esquema.elementos || []).forEach(function (el) {
            if (el.tipo !== 'bloque') {
                return;
            }
            var codigo = bloqueCodigo(el);
            if (!codigo) {
                return;
            }
            var widget = String(el.widget || 'grupo_campos');
            if (window.SgdChecklistConfig && SgdChecklistConfig.isChecklistBlock(codigo)) {
                window.SgdChecklistConfig.ensureChecklistConfig(el, el.definicion || {});
                if (el.config.modo === 'filas_fijas') {
                    var resp = {};
                    bodyEl.querySelectorAll('[name^="cl_' + codigo + '["]').forEach(function (inp) {
                        var m = inp.name.match(/^cl_([^[]+)\[([^[]+)\]\[([^\]]+)\]$/);
                        if (!m) return;
                        if (!resp[m[2]]) resp[m[2]] = {};
                        resp[m[2]][m[3]] = inp.value;
                    });
                    datos.bloques[codigo] = resp;
                } else {
                    var filasCl = [];
                    bodyEl.querySelectorAll('[name^="tabla_' + codigo + '["]').forEach(function (inp) {
                        var m = inp.name.match(/^tabla_([^[]+)\[(\d+)\]\[([^\]]+)\]$/);
                        if (!m) return;
                        var idx = parseInt(m[2], 10);
                        if (!filasCl[idx]) filasCl[idx] = {};
                        filasCl[idx][m[3]] = inp.value;
                    });
                    datos.bloques[codigo] = filasCl.filter(function (f) { return f && Object.keys(f).length; });
                }
            } else if (widget === 'tabla_repetible') {
                var filas = [];
                bodyEl.querySelectorAll('[name^="tabla_' + codigo + '["]').forEach(function (inp) {
                    var m = inp.name.match(/^tabla_([^[]+)\[(\d+)\]\[([^\]]+)\]$/);
                    if (!m) {
                        return;
                    }
                    var idx = parseInt(m[2], 10);
                    if (!filas[idx]) {
                        filas[idx] = {};
                    }
                    filas[idx][m[3]] = inp.value;
                });
                datos.bloques[codigo] = filas.filter(function (f) { return f && Object.keys(f).length; });
            } else if (widget === 'lista_repetible') {
                var items = [];
                bodyEl.querySelectorAll('[name^="lista_' + codigo + '["]').forEach(function (inp) {
                    var m = inp.name.match(/^lista_([^[]+)\[(\d+)\]\[([^\]]+)\]$/);
                    if (!m) {
                        return;
                    }
                    var idx = parseInt(m[2], 10);
                    if (!items[idx]) {
                        items[idx] = {};
                    }
                    items[idx][m[3]] = inp.value;
                });
                datos.bloques[codigo] = items.filter(function (f) { return f && Object.values(f).some(function (v) { return String(v).trim() !== ''; }); });
            } else if (widget === 'texto_enriquecido') {
                var rich = bodyEl.querySelector('[name="rich_' + codigo + '"]');
                datos.bloques[codigo] = rich ? rich.value : datos.bloques[codigo];
            } else if (widget === 'grupo_campos') {
                var grupo = {};
                var prev = datos.bloques[codigo];
                bodyEl.querySelectorAll('[name^="bloque_' + codigo + '["]').forEach(function (inp) {
                    var m = inp.name.match(/^bloque_([^[]+)\[([^\]]+)\]$/);
                    if (m) {
                        grupo[m[2]] = inp.value;
                    }
                });
                if (prev && prev._tercero_id) {
                    grupo._tercero_id = prev._tercero_id;
                }
                datos.bloques[codigo] = grupo;
            }
        });

        (esquema.elementos || []).forEach(function (el) {
            if (el.tipo !== 'campo') {
                return;
            }
            var inp = bodyEl.querySelector('[name="suelto[' + el.id + ']"]');
            if (!inp) {
                return;
            }
            var suelto = datos.campos_sueltos.find(function (c) { return c.id === el.id; });
            if (suelto) {
                suelto.valor = inp.value;
            } else {
                datos.campos_sueltos.push({ id: el.id, valor: inp.value });
            }
        });
        syncHidden();
    }

    function renderCampoSuelto(campo) {
        var section = document.createElement('section');
        section.className = 'sgd-panel sgd-reg-bloque sgd-reg-campo-suelto';
        var inner = document.createElement('div');
        inner.className = 'sgd-reg-bloque-body';
        section.appendChild(document.createElement('header')).className = 'sgd-panel-head';
        section.querySelector('header').innerHTML = '<h3 class="sgd-panel-title">' + esc(campo.label || campo.id) + '</h3>';
        section.appendChild(inner);
        var suelto = datos.campos_sueltos.find(function (c) { return c.id === campo.id; });
        renderCampoSimple(inner, 'suelto', {
            id: campo.id,
            tipo: campo.tipo_campo || campo.tipo,
            label: campo.label,
            requerido: campo.requerido
        }, suelto ? suelto.valor : '');
        bodyEl.appendChild(section);
    }

    function rerender() {
        bodyEl.innerHTML = '';
        var elementos = (esquema.elementos || []).slice().sort(function (a, b) {
            return (a.orden || 0) - (b.orden || 0);
        });
        elementos.forEach(function (el) {
            if (el.tipo === 'bloque') {
                renderBloque(el);
            } else if (el.tipo === 'campo') {
                renderCampoSuelto(el);
            }
        });
        syncHidden();
    }

    bodyEl.addEventListener('input', function (ev) {
        var search = ev.target.closest('.sgd-reg-tercero-search');
        if (!search) return;
        var wrap = search.closest('.sgd-reg-tercero-lookup');
        var codigo = wrap ? wrap.getAttribute('data-codigo') : '';
        var results = wrap ? wrap.querySelector('.sgd-reg-tercero-results') : null;
        if (!codigo || !results) return;
        clearTimeout(terceroSearchTimer);
        var q = search.value.trim();
        if (q.length < 2) {
            results.hidden = true;
            results.innerHTML = '';
            return;
        }
        terceroSearchTimer = setTimeout(function () {
            var url = '?url=sgd/lookupTerceros&q=' + encodeURIComponent(q);
            if (empresaId) url += '&empresa_id=' + empresaId;
            fetch(url, { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    var items = data.items || [];
                    if (!items.length) {
                        results.innerHTML = '<li class="sgd-reg-tercero-empty">Sin resultados</li>';
                        results.hidden = false;
                        return;
                    }
                    var html = '';
                    items.forEach(function (item) {
                        html += '<li><button type="button" class="sgd-reg-tercero-pick" data-codigo="' + esc(codigo) + '"';
                        html += ' data-id="' + item.tercero_id + '"';
                        html += ' data-razon="' + esc(item.razon_social || '') + '"';
                        html += ' data-nit="' + esc(item.nit || '') + '"';
                        html += ' data-tel="' + esc(item.telefono || '') + '"';
                        html += ' data-dir="' + esc(item.direccion || '') + '"';
                        html += ' data-mun="' + esc(item.municipio || '') + '">';
                        html += esc(item.razon_social || '') + (item.nit ? ' · ' + esc(item.nit) : '') + '</button></li>';
                    });
                    results.innerHTML = html;
                    results.hidden = false;
                })
                .catch(function () {
                    results.hidden = true;
                });
        }, 300);
    });

    bodyEl.addEventListener('click', function (ev) {
        var pick = ev.target.closest('.sgd-reg-tercero-pick');
        if (pick) {
            fillGrupoFromTercero(pick.getAttribute('data-codigo'), {
                tercero_id: parseInt(pick.getAttribute('data-id'), 10),
                razon_social: pick.getAttribute('data-razon') || '',
                nit: pick.getAttribute('data-nit') || '',
                telefono: pick.getAttribute('data-tel') || '',
                direccion: pick.getAttribute('data-dir') || '',
                municipio: pick.getAttribute('data-mun') || ''
            });
            rerender();
            return;
        }

        var btn = ev.target.closest('.sgd-reg-btn-add-row');
        if (btn) {
            var codigo = btn.getAttribute('data-codigo');
            if (!Array.isArray(datos.bloques[codigo])) {
                datos.bloques[codigo] = [];
            }
            if (btn.getAttribute('data-lista') === '1') {
                var bloque = findBloqueElemento(codigo);
                var itemId = bloque && bloque.definicion && bloque.definicion.item ? bloque.definicion.item.id : 'item';
                var row = {};
                row[itemId] = '';
                datos.bloques[codigo].push(row);
            } else {
                datos.bloques[codigo].push({});
            }
            rerender();
            return;
        }
        btn = ev.target.closest('.sgd-reg-btn-del-row');
        if (btn) {
            var cod = btn.getAttribute('data-codigo');
            var rowIdx = parseInt(btn.getAttribute('data-row'), 10);
            if (Array.isArray(datos.bloques[cod])) {
                datos.bloques[cod].splice(rowIdx, 1);
                rerender();
            }
        }
    });

    if (formGuardar) {
        formGuardar.addEventListener('submit', function () {
            collectFromDom();
        });
    }
    if (formCerrar) {
        formCerrar.addEventListener('submit', function () {
            collectFromDom();
        });
    }

    rerender();
})();
