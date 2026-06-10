(function () {
    'use strict';

    var form = document.getElementById('sgd-elaboracion-form');
    if (!form) return;

    var configEl = document.getElementById('sgd-elab-config');
    var config = { canEdit: false, piePagina: '' };
    if (configEl) {
        try { config = JSON.parse(configEl.textContent || '{}'); } catch (e) { /* ignore */ }
    }

    var activeEditor = null;
    var dirty = false;
    var statusEl = document.getElementById('sgd-word-status');
    var ribbon = document.getElementById('sgd-word-ribbon');
    var headingSelect = document.getElementById('sgd-word-heading');

    function setStatus(msg, type) {
        if (!statusEl) return;
        statusEl.textContent = msg || '';
        statusEl.className = 'sgd-word-status' + (type ? ' sgd-word-status-' + type : '');
    }

    function markDirty() {
        if (!dirty) {
            dirty = true;
            setStatus('Cambios sin guardar', 'warn');
        }
        updateNavFilled();
    }

    function markClean() {
        dirty = false;
        setStatus('Guardado', 'ok');
        window.setTimeout(function () {
            if (!dirty && statusEl) statusEl.textContent = '';
        }, 2500);
    }

    function escapeHtml(s) {
        return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function isEditorEmpty(el) {
        var t = (el.textContent || '').replace(/\u00a0/g, ' ').trim();
        return t === '';
    }

    function syncEditor(el) {
        if (!el) return;
        var wrap = el.closest('.sgd-word-editor-wrap') || el.closest('.sgd-anexo-bloque');
        if (!wrap) return;
        var hidden = wrap.querySelector('.sgd-word-hidden, .sgd-word-hidden-anexo');
        if (!hidden) return;
        hidden.value = isEditorEmpty(el) ? '' : el.innerHTML;
    }

    function syncAllEditors() {
        form.querySelectorAll('.sgd-word-editor[contenteditable="true"]').forEach(syncEditor);
    }

    function focusEditor(el) {
        activeEditor = el;
        form.querySelectorAll('.sgd-word-editor.is-active').forEach(function (n) {
            n.classList.remove('is-active');
        });
        if (el) el.classList.add('is-active');
    }

    function exec(cmd, val) {
        if (!config.canEdit || !activeEditor) return;
        activeEditor.focus();
        try {
            document.execCommand(cmd, false, val || null);
        } catch (e) { /* ignore */ }
        syncEditor(activeEditor);
        markDirty();
    }

    form.querySelectorAll('.sgd-word-editor[contenteditable="true"]').forEach(function (ed) {
        ed.addEventListener('focus', function () { focusEditor(ed); });
        ed.addEventListener('input', function () {
            syncEditor(ed);
            markDirty();
        });
        ed.addEventListener('paste', function (ev) {
            ev.preventDefault();
            var text = (ev.clipboardData || window.clipboardData).getData('text/plain');
            document.execCommand('insertText', false, text);
        });
    });

    if (ribbon) {
        ribbon.querySelectorAll('[data-cmd]').forEach(function (btn) {
            btn.addEventListener('mousedown', function (ev) { ev.preventDefault(); });
            btn.addEventListener('click', function () {
                exec(btn.getAttribute('data-cmd'));
            });
        });
    }

    function applyHeadingLevel(nivelIndex) {
        if (!config.canEdit || !activeEditor) return;
        var tags = config.headingTags || ['h2', 'h3', 'h4', 'h5', 'h6'];
        var tag = tags[nivelIndex] || 'p';

        activeEditor.focus();
        try {
            document.execCommand('formatBlock', false, tag);
        } catch (e) { /* ignore */ }
        syncEditor(activeEditor);
        markDirty();
    }

    if (headingSelect) {
        headingSelect.addEventListener('change', function () {
            var val = headingSelect.value;
            if (val === '') {
                exec('formatBlock', 'p');
            } else {
                var idx = parseInt(val, 10);
                if (!isNaN(idx)) {
                    applyHeadingLevel(idx);
                }
            }
            headingSelect.value = '';
        });
    }

    form.addEventListener('submit', function () {
        syncAllEditors();
        markClean();
    });

    document.addEventListener('keydown', function (ev) {
        if (!config.canEdit) return;
        if ((ev.ctrlKey || ev.metaKey) && ev.key === 's') {
            ev.preventDefault();
            syncAllEditors();
            form.requestSubmit();
            return;
        }
        if (!activeEditor) return;
        if (ev.ctrlKey || ev.metaKey) {
            if (ev.key === 'b') { ev.preventDefault(); exec('bold'); }
            if (ev.key === 'i') { ev.preventDefault(); exec('italic'); }
            if (ev.key === 'u') { ev.preventDefault(); exec('underline'); }
        }
    });

    /* Navegación — scroll suave a sección */
    form.querySelectorAll('.sgd-word-nav-item').forEach(function (link) {
        link.addEventListener('click', function (ev) {
            var id = (link.getAttribute('href') || '').replace('#', '');
            var target = document.getElementById(id);
            if (!target) return;
            ev.preventDefault();
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            form.querySelectorAll('.sgd-word-nav-item.is-current').forEach(function (n) {
                n.classList.remove('is-current');
            });
            link.classList.add('is-current');
        });
    });

    function updateNavFilled() {
        form.querySelectorAll('.sgd-word-nav-item').forEach(function (link) {
            var cod = link.getAttribute('data-nav');
            var block = document.getElementById('sec-' + cod);
            if (!block) return;
            var filled = false;
            var ed = block.querySelector('.sgd-word-editor[contenteditable="true"]');
            if (ed) {
                filled = !isEditorEmpty(ed);
            }
            if (block.querySelector('.sgd-word-ref-selected .sgd-word-ref-chip')) {
                filled = true;
            }
            block.querySelectorAll('.sgd-anexo-bloque').forEach(function (an) {
                var t = an.querySelector('.sgd-word-anexo-title');
                var ae = an.querySelector('.sgd-word-editor');
                if ((t && t.value.trim()) || (ae && !isEditorEmpty(ae))) filled = true;
            });
            link.classList.toggle('is-filled', filled);
        });
    }

    /* Documentos referenciados — buscador + chips */
    var refDocs = [];
    var refEl = document.getElementById('sgd-elab-ref-docs');
    if (refEl) {
        try { refDocs = JSON.parse(refEl.textContent || '[]'); } catch (e) { refDocs = []; }
    }

    var refPanel = form.querySelector('[data-ref-panel]');
    if (refPanel && refDocs.length) {
        var searchInput = refPanel.querySelector('.sgd-word-ref-search');
        var listEl = document.getElementById('sgd-ref-list');
        var chipsEl = document.getElementById('sgd-ref-chips');
        var hiddenWrap = document.getElementById('sgd-ref-hidden');
        var selected = {};

        hiddenWrap.querySelectorAll('input[name="referenciados[]"]').forEach(function (inp) {
            selected[inp.value] = true;
        });

        function labelFor(id) {
            for (var i = 0; i < refDocs.length; i++) {
                if (String(refDocs[i].id) === String(id)) return refDocs[i].label;
            }
            return '#' + id;
        }

        function renderChips() {
            if (!chipsEl) return;
            chipsEl.innerHTML = '';
            Object.keys(selected).forEach(function (id) {
                if (!selected[id]) return;
                var chip = document.createElement('span');
                chip.className = 'sgd-word-ref-chip';
                chip.innerHTML = escapeHtml(labelFor(id));
                if (config.canEdit) {
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'sgd-word-ref-chip-remove';
                    btn.innerHTML = '×';
                    btn.title = 'Quitar';
                    btn.addEventListener('click', function () {
                        delete selected[id];
                        renderAll();
                        markDirty();
                    });
                    chip.appendChild(btn);
                }
                chipsEl.appendChild(chip);
            });
        }

        function renderList(filter) {
            if (!listEl) return;
            var q = (filter || '').toLowerCase().trim();
            listEl.innerHTML = '';
            refDocs.forEach(function (doc) {
                if (q && doc.label.toLowerCase().indexOf(q) < 0) return;
                var li = document.createElement('li');
                var lbl = document.createElement('label');
                var cb = document.createElement('input');
                cb.type = 'checkbox';
                cb.checked = !!selected[String(doc.id)];
                cb.addEventListener('change', function () {
                    if (cb.checked) selected[String(doc.id)] = true;
                    else delete selected[String(doc.id)];
                    renderAll();
                    markDirty();
                });
                lbl.appendChild(cb);
                lbl.appendChild(document.createTextNode(' ' + doc.label));
                li.appendChild(lbl);
                listEl.appendChild(li);
            });
        }

        function renderHidden() {
            hiddenWrap.innerHTML = '';
            Object.keys(selected).forEach(function (id) {
                if (!selected[id]) return;
                var inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = 'referenciados[]';
                inp.value = id;
                hiddenWrap.appendChild(inp);
            });
        }

        function renderAll() {
            renderChips();
            renderList(searchInput ? searchInput.value : '');
            renderHidden();
            updateNavFilled();
        }

        if (searchInput) {
            searchInput.addEventListener('input', function () {
                renderList(searchInput.value);
            });
        }
        renderAll();
    }

    /* Anexos */
    var btnAdd = document.getElementById('sgd-btn-add-anexo');
    var anexosList = document.getElementById('sgd-anexos-list');

    function bindAnexoEditor(ed) {
        ed.addEventListener('focus', function () { focusEditor(ed); });
        ed.addEventListener('input', function () {
            syncEditor(ed);
            markDirty();
        });
    }

    if (anexosList) {
        anexosList.querySelectorAll('.sgd-word-editor-anexo').forEach(bindAnexoEditor);
    }

    if (btnAdd && anexosList) {
        btnAdd.addEventListener('click', function () {
            var idx = anexosList.querySelectorAll('.sgd-anexo-bloque').length;
            var div = document.createElement('div');
            div.className = 'sgd-anexo-bloque';
            div.dataset.index = String(idx);
            div.innerHTML =
                '<input type="text" class="sgd-word-anexo-title form-input" name="anexos[' + idx + '][titulo]" placeholder="Título del anexo">' +
                '<div class="sgd-word-editor sgd-word-editor-anexo" contenteditable="true" data-placeholder="Contenido del anexo…" data-anexo-index="' + idx + '"></div>' +
                '<input type="hidden" name="anexos[' + idx + '][cuerpo]" class="sgd-word-hidden-anexo" value="">';
            anexosList.appendChild(div);
            var ed = div.querySelector('.sgd-word-editor');
            bindAnexoEditor(ed);
            ed.focus();
            markDirty();
        });
    }

    form.querySelectorAll('.sgd-word-anexo-title').forEach(function (inp) {
        inp.addEventListener('input', markDirty);
    });

    form.querySelectorAll('input[name^="opciones"]').forEach(function (cb) {
        cb.addEventListener('change', markDirty);
    });

    /* Numeración configurable por sección */
    function renumberAll() {
        var num = 0;
        form.querySelectorAll('.sgd-word-block[data-numerable="1"]').forEach(function (block) {
            var input = block.querySelector('.sgd-numeracion-input');
            var numerar = input && input.value === '1';
            var wrap = block.querySelector('.sgd-word-sec-num-wrap');
            var numBtn = block.querySelector('.sgd-word-sec-num-btn');
            var addBtn = block.querySelector('.sgd-word-sec-num-add');
            var cod = block.getAttribute('data-seccion');

            if (numerar) {
                num += 1;
                if (wrap) wrap.classList.remove('is-off');
                if (numBtn) {
                    numBtn.textContent = num + '.';
                    numBtn.style.display = '';
                }
                if (addBtn) addBtn.style.display = 'none';
            } else {
                if (wrap) wrap.classList.add('is-off');
                if (numBtn) numBtn.style.display = 'none';
                if (addBtn) addBtn.style.display = '';
            }

            var nav = form.querySelector('.sgd-word-nav-item[data-nav="' + cod + '"]');
            if (nav) {
                var base = nav.getAttribute('data-nav-name') || '';
                nav.textContent = numerar ? (num + '. ' + base) : base;
            }
        });
    }

    form.querySelectorAll('.sgd-word-sec-num-btn').forEach(function (btn) {
        btn.addEventListener('click', function (ev) {
            ev.preventDefault();
            var block = btn.closest('.sgd-word-block');
            var input = block && block.querySelector('.sgd-numeracion-input');
            if (!input) return;
            input.value = '0';
            renumberAll();
            markDirty();
        });
    });

    form.querySelectorAll('.sgd-word-sec-num-add').forEach(function (btn) {
        btn.addEventListener('click', function (ev) {
            ev.preventDefault();
            var block = btn.closest('.sgd-word-block');
            var input = block && block.querySelector('.sgd-numeracion-input');
            if (!input) return;
            input.value = '1';
            renumberAll();
            markDirty();
        });
    });

    renumberAll();

    /* Scroll spy navegación */
    var blocks = form.querySelectorAll('.sgd-word-block[id]');
    if (blocks.length && 'IntersectionObserver' in window) {
        var navLinks = form.querySelectorAll('.sgd-word-nav-item');
        var obs = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) return;
                var id = entry.target.id;
                navLinks.forEach(function (link) {
                    link.classList.toggle('is-current', link.getAttribute('href') === '#' + id);
                });
            });
        }, { root: null, rootMargin: '-20% 0px -60% 0px', threshold: 0 });
        blocks.forEach(function (b) { obs.observe(b); });
    }

    updateNavFilled();
    if (config.canEdit) {
        setStatus('Ctrl+S para guardar', 'hint');
    }
})();
