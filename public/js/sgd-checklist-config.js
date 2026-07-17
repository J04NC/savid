(function (global) {
    'use strict';

    var BLOQUE = 'op_items_checklist';
    var TIPOS = ['texto', 'textarea', 'lista', 'fecha', 'numero'];
    var CONTENIDO_IDS = ['numero', 'seccion', 'aspecto', 'referencia'];

    function slugId(raw) {
        return String(raw || '').toLowerCase().trim().replace(/[^a-z0-9_]+/g, '_').replace(/^_+|_+$/g, '');
    }

    function isChecklistBlock(codigo) {
        return String(codigo || '').toLowerCase() === BLOQUE;
    }

    function inferRol(id) {
        return CONTENIDO_IDS.indexOf(id) >= 0 ? 'contenido' : 'respuesta';
    }

    function buildDefaultConfig(definicion) {
        definicion = definicion || {};
        var columnas = [];
        var orden = 10;
        (definicion.columnas || []).forEach(function (col) {
            if (!col || !col.id) return;
            var tipo = col.tipo || 'texto';
            var entry = {
                id: col.id,
                label: col.label || col.id,
                tipo: tipo,
                rol: inferRol(col.id),
                requerido: !!col.requerido,
                visible: true,
                orden: orden,
                opciones: (tipo === 'lista' && col.opciones) ? col.opciones.slice() : []
            };
            columnas.push(entry);
            orden += 10;
        });
        return { modo: 'filas_libres', columnas: columnas, filas: [] };
    }

    function ensureChecklistConfig(el, catalogDef) {
        if (!el.config || !el.config.columnas || !el.config.columnas.length) {
            el.config = Object.assign({}, buildDefaultConfig(catalogDef), el.config || {});
        }
        if (!el.config.modo) el.config.modo = 'filas_libres';
        if (!Array.isArray(el.config.filas)) el.config.filas = [];
        if (!Array.isArray(el.config.columnas)) el.config.columnas = buildDefaultConfig(catalogDef).columnas;
    }

    function visibleColumnas(config) {
        return (config.columnas || []).filter(function (c) { return c.visible !== false; })
            .sort(function (a, b) { return (a.orden || 0) - (b.orden || 0); });
    }

    function contentColumnas(config) {
        return visibleColumnas(config).filter(function (c) { return c.rol === 'contenido'; });
    }

    function responseColumnas(config) {
        return visibleColumnas(config).filter(function (c) { return c.rol === 'respuesta'; });
    }

    function effectiveTipo(col) {
        if ((col.tipo || 'texto') === 'lista') {
            if (!col.opciones || !col.opciones.length) return 'texto';
        }
        return col.tipo || 'texto';
    }

    function renderCellInput(col, name, value, canEdit, esc) {
        value = value == null ? '' : value;
        var tipo = effectiveTipo(col);
        var ro = canEdit ? '' : ' readonly disabled';
        var req = col.requerido ? ' required' : '';
        if (tipo === 'textarea') {
            return '<textarea class="sgd-reg-input sgd-reg-input-sm" name="' + esc(name) + '"' + req + ro + '>' + esc(value) + '</textarea>';
        }
        if (tipo === 'lista') {
            var html = '<select class="sgd-reg-input sgd-reg-input-sm" name="' + esc(name) + '"' + req + ro + '><option value="">—</option>';
            (col.opciones || []).forEach(function (opt) {
                html += '<option value="' + esc(opt) + '"' + (String(value) === String(opt) ? ' selected' : '') + '>' + esc(opt) + '</option>';
            });
            return html + '</select>';
        }
        if (tipo === 'fecha') {
            return '<input type="date" class="sgd-reg-input sgd-reg-input-sm" name="' + esc(name) + '" value="' + esc(value) + '"' + req + ro + '>';
        }
        return '<input type="text" class="sgd-reg-input sgd-reg-input-sm" name="' + esc(name) + '" value="' + esc(value) + '"' + req + ro + '>';
    }

    /**
     * Panel de diseño del bloque checklist (HTML).
     */
    function renderDesignerPanel(el, idx, canEdit, escapeHtml) {
        ensureChecklistConfig(el, el.definicion || {});
        var cfg = el.config;
        var html = '<div class="sgd-form-bloque-config sgd-form-checklist-config" data-idx="' + idx + '">';

        html += '<div class="sgd-form-config-block-meta">';
        html += '<div class="form-group"><label>Título del bloque</label>';
        html += '<input type="text" class="form-input sgd-form-cfg-titulo" data-idx="' + idx + '" maxlength="120" value="' + escapeHtml(cfg.titulo || el.nombre || '') + '"></div>';
        html += '<div class="form-group"><label>Ayuda contextual</label>';
        html += '<textarea class="form-input sgd-form-cfg-ayuda" data-idx="' + idx + '" rows="2" maxlength="500">' + escapeHtml(cfg.ayuda || '') + '</textarea></div>';
        html += '<div class="form-group"><label>Modo de filas</label>';
        html += '<select class="form-input sgd-cl-modo" data-idx="' + idx + '"' + (canEdit ? '' : ' disabled') + '>';
        html += '<option value="filas_libres"' + (cfg.modo === 'filas_libres' ? ' selected' : '') + '>Filas libres (agregar al diligenciar)</option>';
        html += '<option value="filas_fijas"' + (cfg.modo === 'filas_fijas' ? ' selected' : '') + '>Filas fijas (definidas en plantilla)</option>';
        html += '</select></div></div>';

        html += '<p class="sgd-form-config-title">Columnas <button type="button" class="sgd-doc-btn sgd-doc-btn-sm sgd-cl-add-col" data-idx="' + idx + '">+ Columna</button></p>';
        html += '<ul class="sgd-cl-columns-list">';
        (cfg.columnas || []).forEach(function (col, ci) {
            html += renderColumnEditor(col, idx, ci, cfg.columnas.length, canEdit, escapeHtml);
        });
        html += '</ul>';

        if (cfg.modo === 'filas_fijas') {
            html += '<p class="sgd-form-config-title">Filas de plantilla <button type="button" class="sgd-doc-btn sgd-doc-btn-sm sgd-cl-add-row" data-idx="' + idx + '">+ Fila</button></p>';
            html += '<ul class="sgd-cl-rows-list">';
            (cfg.filas || []).forEach(function (fila, ri) {
                html += renderRowEditor(fila, idx, ri, cfg, canEdit, escapeHtml);
            });
            if (!(cfg.filas || []).length) {
                html += '<li class="field-note">Sin filas. Agregue ítems que aparecerán fijos al diligenciar.</li>';
            }
            html += '</ul>';
        }

        html += '</div>';
        return html;
    }

    function renderColumnEditor(col, idx, ci, total, canEdit, esc) {
        var opts = (col.opciones || []).join(', ');
        var html = '<li class="sgd-cl-col-item" data-col-idx="' + ci + '">';
        html += '<div class="sgd-form-config-field-order">';
        html += '<button type="button" class="sgd-form-move-btn sgd-cl-col-up" data-idx="' + idx + '" data-col="' + ci + '"' + (ci === 0 ? ' disabled' : '') + '>↑</button>';
        html += '<button type="button" class="sgd-form-move-btn sgd-cl-col-down" data-idx="' + idx + '" data-col="' + ci + '"' + (ci === total - 1 ? ' disabled' : '') + '>↓</button>';
        html += '</div>';
        html += '<label><input type="checkbox" class="sgd-cl-col-visible" data-idx="' + idx + '" data-col="' + ci + '"' + (col.visible !== false ? ' checked' : '') + '> Visible</label>';
        html += '<input type="text" class="form-input sgd-cl-col-id" data-idx="' + idx + '" data-col="' + ci + '" placeholder="id" value="' + esc(col.id || '') + '" readonly title="Identificador interno">';
        html += '<input type="text" class="form-input sgd-cl-col-label" data-idx="' + idx + '" data-col="' + ci + '" placeholder="Etiqueta" value="' + esc(col.label || '') + '">';
        html += '<select class="form-input sgd-cl-col-tipo" data-idx="' + idx + '" data-col="' + ci + '">';
        TIPOS.forEach(function (t) {
            html += '<option value="' + t + '"' + (col.tipo === t ? ' selected' : '') + '>' + t + '</option>';
        });
        html += '</select>';
        html += '<select class="form-input sgd-cl-col-rol" data-idx="' + idx + '" data-col="' + ci + '">';
        html += '<option value="contenido"' + (col.rol === 'contenido' ? ' selected' : '') + '>Contenido</option>';
        html += '<option value="respuesta"' + (col.rol === 'respuesta' ? ' selected' : '') + '>Respuesta</option>';
        html += '</select>';
        html += '<input type="text" class="form-input sgd-cl-col-opciones" data-idx="' + idx + '" data-col="' + ci + '" placeholder="Opciones (lista, separadas por coma)" value="' + esc(opts) + '">';
        html += '<label class="sgd-form-config-req"><input type="checkbox" class="sgd-cl-col-req" data-idx="' + idx + '" data-col="' + ci + '"' + (col.requerido ? ' checked' : '') + '> Oblig.</label>';
        html += '<button type="button" class="sgd-doc-btn sgd-doc-btn-danger sgd-doc-btn-sm sgd-cl-del-col" data-idx="' + idx + '" data-col="' + ci + '">✕</button>';
        html += '</li>';
        return html;
    }

    function renderRowEditor(fila, idx, ri, cfg, canEdit, esc) {
        var html = '<li class="sgd-cl-row-item" data-row-idx="' + ri + '">';
        html += '<div class="sgd-cl-row-head">';
        html += '<button type="button" class="sgd-form-move-btn sgd-cl-row-up" data-idx="' + idx + '" data-row="' + ri + '"' + (ri === 0 ? ' disabled' : '') + '>↑</button>';
        html += '<button type="button" class="sgd-form-move-btn sgd-cl-row-down" data-idx="' + idx + '" data-row="' + ri + '"' + (ri === cfg.filas.length - 1 ? ' disabled' : '') + '>↓</button>';
        html += '<input type="text" class="form-input sgd-cl-row-numero" data-idx="' + idx + '" data-row="' + ri + '" placeholder="N°" value="' + esc(fila.numero || '') + '">';
        html += '<button type="button" class="sgd-doc-btn sgd-doc-btn-danger sgd-doc-btn-sm sgd-cl-del-row" data-idx="' + idx + '" data-row="' + ri + '">✕</button>';
        html += '</div>';
        html += '<div class="sgd-cl-row-celdas">';
        contentColumnas(cfg).forEach(function (col) {
            var val = (fila.celdas && fila.celdas[col.id]) || '';
            html += '<div class="form-group"><label>' + esc(col.label || col.id) + '</label>';
            if (col.tipo === 'textarea') {
                html += '<textarea class="form-input sgd-cl-row-celda" data-idx="' + idx + '" data-row="' + ri + '" data-col-id="' + esc(col.id) + '" rows="2">' + esc(val) + '</textarea>';
            } else {
                html += '<input type="text" class="form-input sgd-cl-row-celda" data-idx="' + idx + '" data-row="' + ri + '" data-col-id="' + esc(col.id) + '" value="' + esc(val) + '">';
            }
            html += '</div>';
        });
        html += '</div></li>';
        return html;
    }

    function bindDesignerHandlers(listEl, getEsquema, rerender) {
        if (!listEl || listEl._sgdClDesignerInit) return;
        listEl._sgdClDesignerInit = true;

        function elAt(idx) {
            var esquema = getEsquema();
            return esquema.elementos[idx];
        }

        listEl.addEventListener('change', function (e) {
            var modo = e.target.closest('.sgd-cl-modo');
            if (modo) {
                var mi = parseInt(modo.getAttribute('data-idx'), 10);
                var mel = elAt(mi);
                if (!mel) return;
                ensureChecklistConfig(mel, mel.definicion || {});
                mel.config.modo = modo.value;
                if (mel.config.modo === 'filas_fijas' && !mel.config.filas.length) mel.config.filas = [];
                rerender();
            }
        });

        listEl.addEventListener('click', function (e) {
            var addCol = e.target.closest('.sgd-cl-add-col');
            if (addCol) {
                var ai = parseInt(addCol.getAttribute('data-idx'), 10);
                var ael = elAt(ai);
                if (!ael) return;
                ensureChecklistConfig(ael, ael.definicion || {});
                var nid = 'col_' + Date.now();
                ael.config.columnas.push({
                    id: nid, label: 'Nueva columna', tipo: 'texto', rol: 'respuesta',
                    requerido: false, visible: true, orden: (ael.config.columnas.length + 1) * 10, opciones: []
                });
                rerender();
                return;
            }
            var delCol = e.target.closest('.sgd-cl-del-col');
            if (delCol) {
                var di = parseInt(delCol.getAttribute('data-idx'), 10);
                var ci = parseInt(delCol.getAttribute('data-col'), 10);
                var del = elAt(di);
                if (del && del.config.columnas.length > 1) {
                    del.config.columnas.splice(ci, 1);
                    rerender();
                }
                return;
            }
            var addRow = e.target.closest('.sgd-cl-add-row');
            if (addRow) {
                var ri = parseInt(addRow.getAttribute('data-idx'), 10);
                var rel = elAt(ri);
                if (!rel) return;
                ensureChecklistConfig(rel, rel.definicion || {});
                rel.config.filas.push({
                    id: 'fila_' + Date.now(),
                    numero: '',
                    orden: (rel.config.filas.length + 1) * 10,
                    celdas: {}
                });
                rerender();
                return;
            }
            var delRow = e.target.closest('.sgd-cl-del-row');
            if (delRow) {
                var xi = parseInt(delRow.getAttribute('data-idx'), 10);
                var xr = parseInt(delRow.getAttribute('data-row'), 10);
                var xel = elAt(xi);
                if (xel) {
                    xel.config.filas.splice(xr, 1);
                    rerender();
                }
                return;
            }
            var cup = e.target.closest('.sgd-cl-col-up');
            if (cup && !cup.disabled) {
                swapCols(parseInt(cup.getAttribute('data-idx'), 10), parseInt(cup.getAttribute('data-col'), 10), -1);
                rerender();
                return;
            }
            var cdn = e.target.closest('.sgd-cl-col-down');
            if (cdn && !cdn.disabled) {
                swapCols(parseInt(cdn.getAttribute('data-idx'), 10), parseInt(cdn.getAttribute('data-col'), 10), 1);
                rerender();
                return;
            }
            var rup = e.target.closest('.sgd-cl-row-up');
            if (rup && !rup.disabled) {
                swapRows(parseInt(rup.getAttribute('data-idx'), 10), parseInt(rup.getAttribute('data-row'), 10), -1);
                rerender();
                return;
            }
            var rdn = e.target.closest('.sgd-cl-row-down');
            if (rdn && !rdn.disabled) {
                swapRows(parseInt(rdn.getAttribute('data-idx'), 10), parseInt(rdn.getAttribute('data-row'), 10), 1);
                rerender();
            }
        });

        function swapCols(bi, ci, dir) {
            var bel = elAt(bi);
            if (!bel) return;
            var j = ci + dir;
            var cols = bel.config.columnas;
            if (j < 0 || j >= cols.length) return;
            var t = cols[ci]; cols[ci] = cols[j]; cols[j] = t;
        }

        function swapRows(bi, ri, dir) {
            var bel = elAt(bi);
            if (!bel) return;
            var j = ri + dir;
            var rows = bel.config.filas;
            if (j < 0 || j >= rows.length) return;
            var t = rows[ri]; rows[ri] = rows[j]; rows[j] = t;
        }

        listEl.addEventListener('input', function (e) {
            var lbl = e.target.closest('.sgd-cl-col-label');
            if (lbl) {
                patchCol(lbl, 'label', lbl.value.trim());
                return;
            }
            var tipo = e.target.closest('.sgd-cl-col-tipo');
            if (tipo) {
                patchCol(tipo, 'tipo', tipo.value);
                return;
            }
            var rol = e.target.closest('.sgd-cl-col-rol');
            if (rol) {
                patchCol(rol, 'rol', rol.value);
                return;
            }
            var opts = e.target.closest('.sgd-cl-col-opciones');
            if (opts) {
                var arr = opts.value.split(',').map(function (s) { return s.trim(); }).filter(Boolean);
                patchCol(opts, 'opciones', arr);
                return;
            }
            var celda = e.target.closest('.sgd-cl-row-celda');
            if (celda) {
                var bi = parseInt(celda.getAttribute('data-idx'), 10);
                var ri = parseInt(celda.getAttribute('data-row'), 10);
                var cid = celda.getAttribute('data-col-id');
                var bel = elAt(bi);
                if (!bel || !bel.config.filas[ri]) return;
                if (!bel.config.filas[ri].celdas) bel.config.filas[ri].celdas = {};
                bel.config.filas[ri].celdas[cid] = celda.value;
                return;
            }
            var num = e.target.closest('.sgd-cl-row-numero');
            if (num) {
                var ni = parseInt(num.getAttribute('data-idx'), 10);
                var nr = parseInt(num.getAttribute('data-row'), 10);
                var nel = elAt(ni);
                if (nel && nel.config.filas[nr]) nel.config.filas[nr].numero = num.value.trim();
            }
        });

        listEl.addEventListener('change', function (e) {
            var vis = e.target.closest('.sgd-cl-col-visible');
            if (vis) {
                patchCol(vis, 'visible', vis.checked);
                return;
            }
            var req = e.target.closest('.sgd-cl-col-req');
            if (req) {
                patchCol(req, 'requerido', req.checked);
            }
        });

        function patchCol(el, key, val) {
            var bi = parseInt(el.getAttribute('data-idx'), 10);
            var ci = parseInt(el.getAttribute('data-col'), 10);
            var bel = elAt(bi);
            if (bel && bel.config.columnas[ci]) bel.config.columnas[ci][key] = val;
        }
    }

    global.SgdChecklistConfig = {
        BLOQUE: BLOQUE,
        isChecklistBlock: isChecklistBlock,
        buildDefaultConfig: buildDefaultConfig,
        ensureChecklistConfig: ensureChecklistConfig,
        visibleColumnas: visibleColumnas,
        contentColumnas: contentColumnas,
        responseColumnas: responseColumnas,
        effectiveTipo: effectiveTipo,
        renderCellInput: renderCellInput,
        renderDesignerPanel: renderDesignerPanel,
        bindDesignerHandlers: bindDesignerHandlers
    };
}(typeof window !== 'undefined' ? window : this));
