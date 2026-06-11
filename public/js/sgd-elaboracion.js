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
    var tableSelAnchor = null;
    var tableBorderMode = null;
    var dirty = false;
    var saveInProgress = false;
    var autoSaveTimer = null;
    var lastSaveAt = 0;
    var undoStack = [];
    var AUTO_SAVE_DELAY_MS = 45000;
    var AUTO_SAVE_MIN_GAP_MS = 20000;
    var statusEl = document.getElementById('sgd-word-status');
    var ribbon = document.getElementById('sgd-word-ribbon');
    var headingSelect = document.getElementById('sgd-word-heading');
    var headingTags = config.headingTags || ['h2', 'h3', 'h4', 'h5', 'h6'];
    var outlineMinIndex = 1;
    var outlineTimer = null;

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
        scheduleOutlineRebuild();
        scheduleAutoSave();
    }

    function markClean(isAuto) {
        dirty = false;
        setStatus(isAuto ? 'Autoguardado' : 'Guardado', 'ok');
        window.setTimeout(function () {
            if (!dirty && statusEl) statusEl.textContent = '';
        }, isAuto ? 2000 : 2500);
    }

    function scrollStateKey() {
        return 'sgd_elab_scroll_' + (config.documentoId || 0);
    }

    function captureScrollState() {
        var state = {
            y: window.scrollY || 0,
            hash: location.hash || '',
            seccion: ''
        };
        if (activeEditor) {
            var block = activeEditor.closest('.sgd-word-block[data-seccion]');
            if (block) state.seccion = block.getAttribute('data-seccion') || '';
        }
        if (!state.seccion) {
            var mid = document.elementFromPoint(Math.min(window.innerWidth - 20, 400), Math.min(window.innerHeight / 3, 200));
            if (mid) {
                var secBlock = mid.closest('.sgd-word-block[data-seccion]');
                if (secBlock) state.seccion = secBlock.getAttribute('data-seccion') || '';
            }
        }
        try {
            sessionStorage.setItem(scrollStateKey(), JSON.stringify(state));
        } catch (e) { /* ignore */ }
    }

    function restoreScrollState() {
        try {
            var raw = sessionStorage.getItem(scrollStateKey());
            if (!raw) return;
            var state = JSON.parse(raw);
            window.requestAnimationFrame(function () {
                if (state.hash) {
                    var hashEl = document.getElementById(String(state.hash).replace(/^#/, ''));
                    if (hashEl) {
                        hashEl.scrollIntoView({ block: 'start' });
                        return;
                    }
                }
                if (state.seccion) {
                    var sec = form.querySelector('.sgd-word-block[data-seccion="' + state.seccion + '"]');
                    if (sec) {
                        sec.scrollIntoView({ block: 'start' });
                        return;
                    }
                }
                if (state.y) window.scrollTo(0, state.y);
            });
        } catch (e) { /* ignore */ }
    }

    function scheduleAutoSave() {
        if (!config.canEdit) return;
        if (autoSaveTimer) window.clearTimeout(autoSaveTimer);
        autoSaveTimer = window.setTimeout(function () {
            if (!dirty || saveInProgress) return;
            if (Date.now() - lastSaveAt < AUTO_SAVE_MIN_GAP_MS) {
                scheduleAutoSave();
                return;
            }
            saveDocument(true);
        }, AUTO_SAVE_DELAY_MS);
    }

    function hasEditorContent(el) {
        if (!el) return false;
        var html = String(el.innerHTML || '')
            .replace(/<br\s*\/?>/gi, '')
            .replace(/&nbsp;|\u00a0/gi, '')
            .replace(/<[^>]+>/g, '')
            .trim();
        if (html !== '') return true;
        return /<img\b/i.test(el.innerHTML || '') || /<table\b/i.test(el.innerHTML || '');
    }

    function estimateEditorContentChars() {
        var total = 0;
        form.querySelectorAll('.sgd-word-editor[contenteditable="true"]').forEach(function (ed) {
            if (!hasEditorContent(ed)) return;
            total += String(ed.textContent || '').replace(/\s+/g, ' ').trim().length;
        });
        return total;
    }

    function captureEditorsSnapshot() {
        var snap = { editors: {}, at: Date.now() };
        form.querySelectorAll('.sgd-word-editor[contenteditable="true"]').forEach(function (ed) {
            var key = ed.getAttribute('data-codigo')
                || ('anexo-' + (ed.getAttribute('data-anexo-index') || '0'));
            snap.editors[key] = ed.innerHTML;
        });
        return snap;
    }

    function pushUndoSnapshot(skipCompare) {
        var snap = captureEditorsSnapshot();
        if (!skipCompare) {
            var prev = undoStack.length ? undoStack[undoStack.length - 1] : null;
            if (prev && JSON.stringify(prev.editors) === JSON.stringify(snap.editors)) return;
        }
        undoStack.push(snap);
        if (undoStack.length > 30) undoStack.shift();
        try {
            sessionStorage.setItem('sgd_elab_backup_' + (config.documentoId || 0), JSON.stringify(snap));
        } catch (e) { /* ignore */ }
    }

    function restoreEditorsSnapshot(snap) {
        if (!snap || !snap.editors) return;
        Object.keys(snap.editors).forEach(function (key) {
            var html = snap.editors[key];
            if (key.indexOf('anexo-') === 0) {
                var idx = key.replace('anexo-', '');
                var ed = form.querySelector('.sgd-word-editor-anexo[data-anexo-index="' + idx + '"]');
                if (ed) ed.innerHTML = html;
                return;
            }
            var editor = form.querySelector('.sgd-word-editor[data-codigo="' + key + '"]');
            if (editor) editor.innerHTML = html;
        });
        syncAllEditors();
        markDirty();
    }

    function restoreUndoSnapshot() {
        if (undoStack.length < 2) return false;
        undoStack.pop();
        var snap = undoStack[undoStack.length - 1];
        restoreEditorsSnapshot(snap);
        setStatus('Cambio deshecho', 'hint');
        return true;
    }

    function tryRestoreBackupOnLoad() {
        try {
            var raw = sessionStorage.getItem('sgd_elab_backup_' + (config.documentoId || 0));
            if (!raw) return;
            var snap = JSON.parse(raw);
            if (!snap || !snap.editors) return;

            var backupChars = 0;
            Object.keys(snap.editors).forEach(function (key) {
                backupChars += String(snap.editors[key] || '').replace(/<[^>]+>/g, '').trim().length;
            });
            if (backupChars < 80) return;

            if (estimateEditorContentChars() >= Math.min(80, backupChars * 0.5)) return;

            restoreEditorsSnapshot(snap);
            setStatus('Se restauró una copia local de seguridad. Revise y guarde.', 'warn');
        } catch (e) { /* ignore */ }
    }

    var undoInputTimer = null;
    function scheduleUndoCapture() {
        if (undoInputTimer) window.clearTimeout(undoInputTimer);
        undoInputTimer = window.setTimeout(pushUndoSnapshot, 1000);
    }

    function buildSaveFormData() {
        syncAllEditors();
        var fd = new FormData(form);
        form.querySelectorAll('.sgd-word-editor[data-codigo]').forEach(function (ed) {
            var cod = ed.getAttribute('data-codigo');
            if (!cod) return;
            fd.set('texto[' + cod + ']', hasEditorContent(ed) ? ed.innerHTML : '');
        });
        form.querySelectorAll('.sgd-word-editor-anexo').forEach(function (ed) {
            var idx = ed.getAttribute('data-anexo-index');
            if (idx === null || idx === '') return;
            var block = ed.closest('.sgd-anexo-bloque');
            var title = block && block.querySelector('.sgd-word-anexo-title');
            if (title) fd.set('anexos[' + idx + '][titulo]', title.value);
            fd.set('anexos[' + idx + '][cuerpo]', hasEditorContent(ed) ? ed.innerHTML : '');
        });
        fd.set('_ajax', '1');
        return fd;
    }

    function saveDocument(isAuto) {
        if (!config.canEdit || saveInProgress) {
            return Promise.resolve(false);
        }
        if (isAuto && !dirty) {
            return Promise.resolve(false);
        }

        var editorChars = estimateEditorContentChars();
        if (!isAuto && editorChars < 1) {
            setStatus('No hay contenido para guardar', 'warn');
            return Promise.resolve(false);
        }

        pushUndoSnapshot();
        captureScrollState();
        saveInProgress = true;
        if (!isAuto) setStatus('Guardando…', 'hint');

        var fd = buildSaveFormData();

        return fetch(form.action, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (res) {
                var ct = res.headers.get('content-type') || '';
                if (!res.ok || ct.indexOf('application/json') < 0) {
                    throw new Error('Respuesta inválida del servidor');
                }
                return res.json();
            })
            .then(function (data) {
                if (!data.ok) {
                    setStatus(data.message || 'Error al guardar', 'warn');
                    return false;
                }
                var savedChars = parseInt(data.content_chars, 10) || 0;
                if (editorChars > 120 && savedChars < 20) {
                    setStatus('El servidor no recibió el contenido. No recargue: use Ctrl+Z o restaure.', 'warn');
                    return false;
                }
                markClean(!!isAuto);
                lastSaveAt = Date.now();
                return true;
            })
            .catch(function () {
                setStatus('Error al guardar. El contenido sigue en pantalla; no recargue.', 'warn');
                return false;
            })
            .finally(function () {
                saveInProgress = false;
            });
    }

    function escapeHtml(s) {
        return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function escapeAttr(s) {
        return escapeHtml(s);
    }

    var activeImage = null;

    function normalizeCellText(text) {
        return String(text || '').replace(/\u00a0/g, ' ').replace(/\s+/g, ' ').trim();
    }

    var LIST_MARKER_CLASS = (function () {
        var chars = [
            '\u2022', '\u00b7', '\u25cf', '\u25cb', '\u2013', '\u2014',
            '*', '\u2713', '\u2714', '\u2611', '\u221a', '•', '√', '✓', '✔', '☑'
        ];
        var seen = {};
        var parts = [];
        chars.forEach(function (ch) {
            if (seen[ch]) return;
            seen[ch] = true;
            if (ch === '\\' || ch === ']' || ch === '^') {
                parts.push('\\' + ch);
            } else {
                parts.push(ch);
            }
        });
        parts.push('-');
        return parts.join('');
    })();

    function isSingleListMarkerChar(str) {
        var s = String(str || '').replace(/\s/g, '');
        if (!s) return false;
        return new RegExp('^[' + LIST_MARKER_CLASS + ']$', 'iu').test(s);
    }

    function stripLeadingListMarker(text) {
        var t = String(text || '').replace(/\u00a0/g, ' ');
        var markerRe = new RegExp('^\\s*([' + LIST_MARKER_CLASS + ']|\\d+[\\.\\)\\-])[\\s\\t]*', 'iu');
        var prev;
        do {
            prev = t;
            t = t.replace(markerRe, '');
        } while (t !== prev);
        return normalizeCellText(t);
    }

    function liTextLooksNumbered(li) {
        var parsed = parsePlainListLine(li.textContent);
        return !!(parsed && parsed.ordered);
    }

    function removeLeadingBulletFromNode(root) {
        var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null);
        var node = walker.nextNode();
        if (!node) return;
        var cleaned = stripLeadingListMarker(node.textContent);
        if (cleaned) node.textContent = cleaned;
    }

    function cleanLiInPlace(li) {
        var wrap = document.createElement('div');
        Array.prototype.forEach.call(li.childNodes, function (n) {
            if (n.nodeType === 1 && /^(UL|OL)$/i.test(n.tagName)) return;
            wrap.appendChild(n);
        });
        removeLeadingBulletFromNode(wrap);
        Array.prototype.forEach.call(li.childNodes, function (n) {
            if (n.nodeType === 1 && /^(UL|OL)$/i.test(n.tagName)) return;
            li.removeChild(n);
        });
        while (wrap.firstChild) {
            var ref = li.querySelector(':scope > ul, :scope > ol');
            if (ref) li.insertBefore(wrap.firstChild, ref);
            else li.appendChild(wrap.firstChild);
        }
        li.querySelectorAll(':scope > ul > li, :scope > ol > li').forEach(cleanLiInPlace);
    }

    function promoteNumberedList(list) {
        if (!list || list.tagName !== 'UL') return list;
        var lis = list.querySelectorAll(':scope > li');
        var numbered = 0;
        lis.forEach(function (li) {
            if (liTextLooksNumbered(li)) numbered += 1;
        });
        if (numbered < Math.max(2, lis.length * 0.5)) return list;

        var ol = document.createElement('ol');
        ol.className = list.className;
        while (list.firstChild) ol.appendChild(list.firstChild);
        list.parentNode.replaceChild(ol, list);
        return ol;
    }

    function cleanEditorLists(root) {
        if (!root) return;
        root.querySelectorAll('ul, ol').forEach(function (list) {
            if (list.parentElement && list.parentElement.closest('ul, ol')) return;
            list = promoteNumberedList(list);
            list.querySelectorAll(':scope > li').forEach(cleanLiInPlace);
        });
    }

    function cleanListItemContent(html) {
        if (!html) return '\u00a0';
        var div = document.createElement('div');
        div.innerHTML = html;
        var cleaned = stripLeadingListMarker(div.textContent);
        if (!cleaned) return '\u00a0';
        if (!div.querySelector('strong, b, em, i, u')) {
            return escapeHtml(cleaned);
        }
        removeLeadingBulletFromNode(div);
        var out = inlineHtmlFromElement(div).trim();
        return out || escapeHtml(cleaned);
    }

    function buildTableHtml(rows, headerRow) {
        if (!rows || !rows.length) return '';
        var html = '<table class="sgd-word-table no-datatable">';
        rows.forEach(function (row, rowIndex) {
            html += '<tr>';
            row.forEach(function (cell) {
                var tag = (headerRow && rowIndex === 0) ? 'th' : 'td';
                html += '<' + tag + '>' + escapeHtml(normalizeCellText(cell)) + '</' + tag + '>';
            });
            html += '</tr>';
        });
        html += '</table>';
        return html;
    }

    function isListTabRow(row) {
        if (!row || row.length !== 2) return false;
        var first = String(row[0] || '').replace(/\s/g, '');
        var second = normalizeCellText(row[1]);
        if (!second) return false;
        if (isSingleListMarkerChar(first)) return true;
        return /^\d+[\.\)\-]$/.test(first);
    }

    function tableRowsLookLikeList(rows) {
        if (!rows || rows.length < 2) return false;
        var listRows = 0;
        rows.forEach(function (row) {
            if (isListTabRow(row)) listRows += 1;
        });
        return listRows >= 2 && listRows >= rows.length * 0.5;
    }

    function plainTextLooksLikeList(text) {
        var lines = String(text || '').split(/\r\n|\n|\r/);
        var listCount = 0;
        lines.forEach(function (line) {
            if (normalizeCellText(line) === '') return;
            if (parsePlainListLine(line)) listCount += 1;
        });
        return listCount >= 2;
    }

    function plainTextToTableRows(text) {
        if (plainTextLooksLikeList(text)) return null;

        var lines = String(text || '').split(/\r\n|\n|\r/).filter(function (line) {
            return normalizeCellText(line) !== '';
        });
        if (lines.length < 2) return null;

        var rows = lines.map(function (line) {
            return line.split('\t').map(normalizeCellText);
        });

        var maxCols = 0;
        rows.forEach(function (row) {
            if (row.length > maxCols) maxCols = row.length;
        });
        if (maxCols < 2) return null;

        var tabbedLines = 0;
        var listTabLines = 0;
        rows.forEach(function (row) {
            if (row.length >= 2) tabbedLines += 1;
            if (isListTabRow(row)) listTabLines += 1;
        });
        if (tabbedLines < 2) return null;
        if (listTabLines >= 2 && listTabLines >= tabbedLines * 0.5) return null;

        return rows.map(function (row) {
            while (row.length < maxCols) row.push('');
            return row.slice(0, maxCols);
        });
    }

    function sanitizeTableElement(sourceTable) {
        var out = document.createElement('table');
        out.className = 'sgd-word-table no-datatable';

        var body = document.createElement('tbody');
        var sawHeader = false;

        sourceTable.querySelectorAll('tr').forEach(function (tr) {
            var newTr = document.createElement('tr');
            var cells = tr.querySelectorAll('th, td');
            if (!cells.length) return;

            cells.forEach(function (cell) {
                var text = normalizeCellText(cell.textContent);
                var isHeader = cell.tagName.toLowerCase() === 'th';
                var el = document.createElement(isHeader && !sawHeader ? 'th' : 'td');
                el.textContent = text;

                var colspan = parseInt(cell.getAttribute('colspan'), 10);
                var rowspan = parseInt(cell.getAttribute('rowspan'), 10);
                if (colspan > 1) el.setAttribute('colspan', String(Math.min(colspan, 50)));
                if (rowspan > 1) el.setAttribute('rowspan', String(Math.min(rowspan, 50)));

                var cellStyle = sanitizeCellStyleAttr(cell.getAttribute('style'));
                if (cellStyle) el.setAttribute('style', cellStyle);

                newTr.appendChild(el);
            });

            if (newTr.children.length) {
                if (!sawHeader && tr.querySelector('th')) {
                    var thead = out.querySelector('thead');
                    if (!thead) {
                        thead = document.createElement('thead');
                        out.appendChild(thead);
                    }
                    thead.appendChild(newTr);
                    sawHeader = true;
                } else {
                    body.appendChild(newTr);
                }
            }
        });

        if (body.children.length) {
            out.appendChild(body);
        }
        if (!out.querySelector('tr')) return null;

        return out.outerHTML;
    }

    function isLayoutOnlyTable(table) {
        if (!table) return true;
        var rows = table.querySelectorAll('tr');
        if (!rows.length) return true;
        var maxCols = 0;
        rows.forEach(function (tr) {
            var n = tr.querySelectorAll('td, th').length;
            if (n > maxCols) maxCols = n;
        });
        return maxCols <= 1;
    }

    function extractTableHtmlFromClipboard(html) {
        if (!html) return null;
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var table = doc.querySelector('table');
        if (!table || isLayoutOnlyTable(table)) return null;

        var plainRows = [];
        table.querySelectorAll('tr').forEach(function (tr) {
            var cells = [];
            tr.querySelectorAll('td, th').forEach(function (c) {
                cells.push(normalizeCellText(c.textContent));
            });
            if (cells.length) plainRows.push(cells);
        });
        if (tableRowsLookLikeList(plainRows)) return null;

        return sanitizeTableElement(table);
    }

    function parseInlineFormatFromStyle(style) {
        var s = String(style || '').toLowerCase();
        return {
            bold: /font-weight\s*:\s*(bold|bolder|[7-9]00)/.test(s)
                || /mso-[^:;]*font-weight\s*:\s*bold/.test(s),
            italic: /font-style\s*:\s*italic/.test(s)
                || /mso-[^:;]*font-style\s*:\s*italic/.test(s),
            underline: /text-decoration(?:-line)?\s*:\s*[^;]*underline/.test(s)
                || /mso-text-underline\s*:\s*[^;]*(single|words)/.test(s)
        };
    }

    function wrapInlineFormat(html, fmt) {
        var out = html;
        if (!out) return out;
        if (fmt.underline) out = '<u>' + out + '</u>';
        if (fmt.italic) out = '<em>' + out + '</em>';
        if (fmt.bold) out = '<strong>' + out + '</strong>';
        return out;
    }

    function htmlClipHasInlineFormat(html) {
        if (!html) return false;
        return /<(strong|b|em|i|u)\b/i.test(html)
            || /font-weight\s*:\s*(bold|bolder|[7-9]00)/i.test(html)
            || /font-style\s*:\s*italic/i.test(html)
            || /text-decoration(?:-line)?\s*:\s*[^;]*underline/i.test(html);
    }

    function inlineHtmlFromElement(el) {
        var out = '';
        el.childNodes.forEach(function (n) {
            if (n.nodeType === 3) {
                out += escapeHtml(n.textContent);
            } else if (n.nodeType === 1) {
                var t = n.tagName.toLowerCase();
                if (t === 'strong' || t === 'b') {
                    out += '<strong>' + inlineHtmlFromElement(n) + '</strong>';
                } else if (t === 'em' || t === 'i') {
                    out += '<em>' + inlineHtmlFromElement(n) + '</em>';
                } else if (t === 'u') {
                    out += '<u>' + inlineHtmlFromElement(n) + '</u>';
                } else if (t === 'br') {
                    out += '<br>';
                } else if (t === 'span') {
                    var inner = inlineHtmlFromElement(n);
                    out += wrapInlineFormat(inner, parseInlineFormatFromStyle(n.getAttribute('style')));
                } else if (t === 'img') {
                    var src = n.getAttribute('src') || '';
                    if (src.indexOf('/uploads/sgd/') === 0) {
                        var imgCls = 'sgd-word-img';
                        var imgClassName = n.className || '';
                        if (imgClassName.indexOf('is-left') >= 0) imgCls += ' is-left';
                        else if (imgClassName.indexOf('is-right') >= 0) imgCls += ' is-right';
                        out += '<img src="' + escapeAttr(src) + '" alt="" class="' + imgCls + '">';
                    }
                } else if (t === 'p' || t === 'div') {
                    var blockInner = inlineHtmlFromElement(n);
                    var blockFmt = parseInlineFormatFromStyle(n.getAttribute('style'));
                    out += wrapInlineFormat(blockInner, blockFmt);
                }
            }
        });
        return out;
    }

    function getLiInnerHtml(li) {
        var wrap = document.createElement('div');
        li.childNodes.forEach(function (n) {
            if (n.nodeType === 1) {
                var t = n.tagName.toLowerCase();
                if (t === 'ul' || t === 'ol') return;
            }
            wrap.appendChild(n.cloneNode(true));
        });
        return cleanListItemContent(inlineHtmlFromElement(wrap).trim());
    }

    function sanitizeListNode(listEl, depth) {
        if (!listEl || depth > 1) return null;
        var tag = listEl.tagName.toLowerCase();
        if (tag !== 'ul' && tag !== 'ol') return null;

        var lis = listEl.querySelectorAll(':scope > li');
        var numbered = 0;
        lis.forEach(function (li) {
            if (liTextLooksNumbered(li)) numbered += 1;
        });
        var outTag = tag === 'ol' ? 'ol' : (numbered >= Math.max(2, lis.length * 0.5) ? 'ol' : 'ul');
        var out = document.createElement(outTag);
        out.className = 'sgd-word-list';

        lis.forEach(function (li) {
            var newLi = document.createElement('li');
            newLi.innerHTML = getLiInnerHtml(li);

            if (depth < 1) {
                li.querySelectorAll(':scope > ul, :scope > ol').forEach(function (nested) {
                    var nestedOut = sanitizeListNode(nested, depth + 1);
                    if (nestedOut) newLi.appendChild(nestedOut);
                });
            }

            out.appendChild(newLi);
        });

        return out.querySelector('li') ? out : null;
    }

    function extractWordParagraphLists(doc) {
        var items = [];
        doc.body.querySelectorAll('p').forEach(function (p) {
            var cls = p.className || '';
            var style = p.getAttribute('style') || '';
            if (!/MsoListParagraph/i.test(cls) && !/mso-list:/i.test(style)) return;

            var level = 0;
            var lvlMatch = style.match(/level(\d+)/i);
            if (lvlMatch) level = Math.min(1, Math.max(0, parseInt(lvlMatch[1], 10) - 1));

            var parsed = parsePlainListLine(p.textContent);
            if (!parsed) return;

            items.push({ level: level, text: parsed.text, ordered: parsed.ordered });
        });

        return buildNestedListHtml(items);
    }

    function extractListHtmlFromClipboard(html) {
        if (!html || html.indexOf('<') < 0) return null;
        var doc = new DOMParser().parseFromString(html, 'text/html');
        if (doc.querySelector('table')) return null;

        var parts = [];
        var body = doc.body;

        function collectLists(node) {
            if (!node || !node.querySelectorAll) return;
            node.querySelectorAll(':scope > ul, :scope > ol').forEach(function (list) {
                var clean = sanitizeListNode(list, 0);
                if (clean) parts.push(clean.outerHTML);
            });
        }

        collectLists(body);
        if (!parts.length) {
            body.querySelectorAll('ul, ol').forEach(function (list) {
                if (list.parentElement && list.parentElement.closest('ul, ol')) return;
                var clean = sanitizeListNode(list, 0);
                if (clean) parts.push(clean.outerHTML);
            });
        }

        if (!parts.length) {
            return extractWordParagraphLists(doc);
        }

        return parts.join('');
    }

    function parsePlainListLine(line) {
        var m = line.match(/^(\s*)(.+)$/);
        if (!m) return null;

        var indent = m[1].length;
        var rest = String(m[2] || '').replace(/\u00a0/g, ' ');

        var numbered = rest.match(/^(\d+[\.\)\-])[\s\t]*(.+)$/);
        if (numbered) {
            var numText = stripLeadingListMarker(numbered[2]);
            if (!numText) return null;
            return {
                level: indent >= 2 ? 1 : 0,
                text: numText,
                ordered: true
            };
        }

        var bullet = rest.match(new RegExp('^([' + LIST_MARKER_CLASS + '])[\\s\\t]*(.+)$', 'iu'));
        if (!bullet) return null;

        var text = stripLeadingListMarker(bullet[2]);
        if (!text) return null;

        return {
            level: indent >= 2 ? 1 : 0,
            text: text,
            ordered: false
        };
    }

    function replaceListParagraphRuns(parent) {
        if (!parent || !parent.children) return;

        var i = 0;
        while (i < parent.children.length) {
            var run = [];
            var runOrdered = null;
            var j = i;
            while (j < parent.children.length && parent.children[j].tagName === 'P') {
                var parsed = parsePlainListLine(parent.children[j].textContent);
                if (!parsed) break;
                if (run.length && parsed.ordered !== runOrdered) break;
                runOrdered = parsed.ordered;
                run.push({ el: parent.children[j], text: parsed.text });
                j += 1;
            }
            if (run.length >= 2) {
                var list = document.createElement(runOrdered ? 'ol' : 'ul');
                list.className = 'sgd-word-list';
                run.forEach(function (item) {
                    var li = document.createElement('li');
                    li.textContent = item.text;
                    list.appendChild(li);
                });
                parent.insertBefore(list, run[0].el);
                run.forEach(function (item) {
                    parent.removeChild(item.el);
                });
                i += 1;
            } else {
                i += 1;
            }
        }

        Array.prototype.forEach.call(parent.children, function (child) {
            if (child.tagName !== 'OL' && child.tagName !== 'UL' && child.tagName !== 'LI') {
                replaceListParagraphRuns(child);
            }
        });
    }

    function plainTextToListHtml(text) {
        var lines = String(text || '').split(/\r\n|\n|\r/);
        var items = [];

        lines.forEach(function (line) {
            if (normalizeCellText(line) === '') return;
            var parsed = parsePlainListLine(line);
            if (parsed) {
                items.push(parsed);
            } else if (items.length) {
                items[items.length - 1].text += ' ' + normalizeCellText(line);
            }
        });

        return buildNestedListHtml(items);
    }

    function buildNestedListHtml(items) {
        if (!items || items.length < 2) return null;

        var segments = [];
        var seg = [];
        var segOrdered = items[0].ordered;

        items.forEach(function (item) {
            if (seg.length && item.ordered !== segOrdered) {
                segments.push({ ordered: segOrdered, items: seg });
                seg = [];
                segOrdered = item.ordered;
            }
            seg.push(item);
        });
        if (seg.length) segments.push({ ordered: segOrdered, items: seg });

        var html = '';
        segments.forEach(function (segment) {
            var tag = segment.ordered ? 'ol' : 'ul';
            html += '<' + tag + ' class="sgd-word-list">';
            var i = 0;
            var listItems = segment.items;
            while (i < listItems.length) {
                var item = listItems[i];
                if (item.level === 0) {
                    html += '<li>' + escapeHtml(item.text);
                    i += 1;
                    if (i < listItems.length && listItems[i].level === 1) {
                        var subTag = listItems[i].ordered ? 'ol' : 'ul';
                        html += '<' + subTag + ' class="sgd-word-list">';
                        while (i < listItems.length && listItems[i].level === 1) {
                            html += '<li>' + escapeHtml(listItems[i].text) + '</li>';
                            i += 1;
                        }
                        html += '</' + subTag + '>';
                    }
                    html += '</li>';
                } else {
                    html += '<li>' + escapeHtml(item.text) + '</li>';
                    i += 1;
                }
            }
            html += '</' + tag + '>';
        });

        return html;
    }

    function htmlFromBodyChildren(root) {
        var html = '';
        var listBuffer = [];

        function flushList() {
            var built = buildNestedListHtml(listBuffer);
            if (built) html += built;
            else if (listBuffer.length === 1) {
                html += '<p>' + escapeHtml(listBuffer[0].text) + '</p>';
            }
            listBuffer = [];
        }

        function walk(node) {
            if (!node) return;
            if (node.nodeType === 3) {
                var t = normalizeCellText(node.textContent);
                if (t) {
                    flushList();
                    html += '<p>' + escapeHtml(t) + '</p>';
                }
                return;
            }
            if (node.nodeType !== 1) return;

            var tag = node.tagName.toLowerCase();
            if (tag === 'ul' || tag === 'ol') {
                flushList();
                var clean = sanitizeListNode(node, 0);
                if (clean) html += clean.outerHTML;
                return;
            }
            if (tag === 'img') {
                flushList();
                var imgSrc = node.getAttribute('src') || '';
                if (imgSrc.indexOf('data:image/') === 0 || imgSrc.indexOf('/uploads/sgd/') === 0) {
                    html += '<p class="sgd-word-img-wrap"><img src="' + escapeAttr(imgSrc) + '" alt="" class="sgd-word-img"></p>';
                }
                return;
            }
            if (tag === 'p') {
                var parsed = parsePlainListLine(node.textContent);
                if (parsed) {
                    listBuffer.push(parsed);
                    return;
                }
                flushList();
                var pt = normalizeCellText(node.textContent);
                if (pt) {
                    var inner = inlineHtmlFromElement(node).trim();
                    html += '<p>' + (inner || escapeHtml(pt)) + '</p>';
                }
                return;
            }
            if (tag === 'div' || tag === 'body' || tag === 'td' || tag === 'th' || tag === 'span') {
                node.childNodes.forEach(walk);
                return;
            }
            if (tag === 'br') return;
            node.childNodes.forEach(walk);
        }

        if (root && root.childNodes) {
            root.childNodes.forEach(walk);
        }
        flushList();
        return html;
    }

    function extractDocumentHtmlFromClipboard(html) {
        if (!html || html.indexOf('<') < 0) return null;
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var table = doc.querySelector('table');
        if (table && !isLayoutOnlyTable(table)) {
            var plainRows = [];
            table.querySelectorAll('tr').forEach(function (tr) {
                var cells = [];
                tr.querySelectorAll('td, th').forEach(function (c) {
                    cells.push(normalizeCellText(c.textContent));
                });
                if (cells.length) plainRows.push(cells);
            });
            if (tableRowsLookLikeList(plainRows)) {
                var items = [];
                plainRows.forEach(function (row) {
                    if (!isListTabRow(row)) return;
                    var first = String(row[0] || '').replace(/\s/g, '');
                    items.push({
                        level: 0,
                        text: normalizeCellText(row[1]),
                        ordered: /^\d+[\.\)\-]$/.test(first)
                    });
                });
                var listHtml = buildNestedListHtml(items);
                if (listHtml) return listHtml;
            }
            return null;
        }

        var root = doc.body;
        if (table && isLayoutOnlyTable(table)) {
            var unwrap = document.createElement('div');
            table.querySelectorAll('tr').forEach(function (tr) {
                tr.querySelectorAll('td, th').forEach(function (cell) {
                    cell.childNodes.forEach(function (child) {
                        unwrap.appendChild(child.cloneNode(true));
                    });
                });
            });
            root = unwrap;
        }

        var out = htmlFromBodyChildren(root);
        return out.trim() ? out : null;
    }

    function plainTextToDocumentHtml(text) {
        var lines = String(text || '').split(/\r\n|\n|\r/);
        var html = '';
        var listBuffer = [];

        function flushList() {
            var built = buildNestedListHtml(listBuffer);
            if (built) html += built;
            else if (listBuffer.length === 1) {
                html += '<p>• ' + escapeHtml(listBuffer[0].text) + '</p>';
            }
            listBuffer = [];
        }

        lines.forEach(function (line) {
            if (normalizeCellText(line) === '') {
                flushList();
                return;
            }
            var parsed = parsePlainListLine(line);
            if (parsed) {
                listBuffer.push(parsed);
            } else {
                flushList();
                html += '<p>' + escapeHtml(normalizeCellText(line)) + '</p>';
            }
        });
        flushList();

        return html.trim() ? html : null;
    }

    var INSERT_HTML_EXEC_MAX = 12000;

    function insertHtmlIntoEditor(editor, html) {
        if (!editor || !html) return;

        var useFragment = html.length > INSERT_HTML_EXEC_MAX
            || /<table\b/i.test(html)
            || (html.match(/<img\b/gi) || []).length > 2;

        if (!useFragment) {
            editor.focus();
            try {
                if (document.queryCommandSupported('insertHTML')) {
                    document.execCommand('insertHTML', false, html);
                    return;
                }
            } catch (e) { /* fallback below */ }
        }

        var sel = window.getSelection();
        var range = null;
        if (sel && sel.rangeCount && editor.contains(sel.anchorNode)) {
            range = sel.getRangeAt(0);
        }

        if (range) {
            range.deleteContents();
            var frag = range.createContextualFragment(html);
            range.insertNode(frag);
            range.collapse(false);
            sel.removeAllRanges();
            sel.addRange(range);
            return;
        }

        var tmp = document.createElement('div');
        tmp.innerHTML = html;
        while (tmp.firstChild) {
            editor.appendChild(tmp.firstChild);
        }
    }

    function sanitizeCssColorJs(raw) {
        raw = String(raw || '').trim();
        if (/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(raw)) return raw.toLowerCase();
        var rgb = raw.match(/^rgb\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*\)$/i);
        if (rgb) {
            return 'rgb(' + Math.min(255, parseInt(rgb[1], 10)) + ',' +
                Math.min(255, parseInt(rgb[2], 10)) + ',' +
                Math.min(255, parseInt(rgb[3], 10)) + ')';
        }
        return '';
    }

    var TABLE_BORDER_DEFAULT = '1px solid #444444';

    function sanitizeCssBorderSideJs(raw) {
        raw = String(raw || '').trim().toLowerCase();
        if (!raw || raw === 'none' || raw === 'hidden' || raw === '0') return 'none';
        var m = raw.match(/^(\d+)px\s+solid\s+(.+)$/i);
        if (!m) return '';
        var width = Math.min(4, Math.max(1, parseInt(m[1], 10) || 1));
        var color = sanitizeCssColorJs(m[2].trim());
        return color ? (width + 'px solid ' + color) : '';
    }

    function sanitizeCellStyleAttr(style) {
        if (!style) return '';
        var parts = [];
        var src = String(style);
        var bg = src.match(/background-color\s*:\s*([^;]+)/i);
        var fg = src.match(/(?:^|;)\s*color\s*:\s*([^;]+)/i);
        if (bg) {
            var bgc = sanitizeCssColorJs(bg[1].trim());
            if (bgc) parts.push('background-color:' + bgc);
        }
        if (fg) {
            var fgc = sanitizeCssColorJs(fg[1].trim());
            if (fgc) parts.push('color:' + fgc);
        }
        ['top', 'right', 'bottom', 'left'].forEach(function (side) {
            var re = new RegExp('border-' + side + '\\s*:\\s*([^;]+)', 'i');
            var m = src.match(re);
            if (!m) return;
            var border = sanitizeCssBorderSideJs(m[1].trim());
            if (border) parts.push('border-' + side + ':' + border);
        });
        var ta = src.match(/text-align\s*:\s*(left|center|right|justify)/i);
        if (ta) parts.push('text-align:' + ta[1].toLowerCase());
        var va = src.match(/vertical-align\s*:\s*(top|middle|bottom)/i);
        if (va) parts.push('vertical-align:' + va[1].toLowerCase());
        return parts.join(';');
    }

    function buildEmptyTableHtml(rows, cols, withHeader) {
        rows = Math.max(1, Math.min(20, parseInt(rows, 10) || 3));
        cols = Math.max(1, Math.min(12, parseInt(cols, 10) || 3));
        var bodyRows = rows;
        var html = '<table class="sgd-word-table no-datatable">';
        if (withHeader) {
            html += '<thead><tr>';
            for (var hc = 0; hc < cols; hc++) html += '<th>&nbsp;</th>';
            html += '</tr></thead>';
            bodyRows = Math.max(0, rows - 1);
        }
        html += '<tbody>';
        for (var r = 0; r < bodyRows; r++) {
            html += '<tr>';
            for (var c = 0; c < cols; c++) html += '<td>&nbsp;</td>';
            html += '</tr>';
        }
        html += '</tbody></table>';
        return html;
    }

    function getActiveCell() {
        var sel = window.getSelection();
        if (!sel || !sel.rangeCount) return null;
        var node = sel.anchorNode;
        if (node && node.nodeType === 3) node = node.parentNode;
        if (!node || !node.closest) return null;
        return node.closest('td, th');
    }

    function getActiveTable() {
        var cell = getActiveCell();
        return cell ? cell.closest('table.sgd-word-table') : null;
    }

    function clearCellFocusMarkers() {
        form.querySelectorAll('.sgd-word-cell-focus').forEach(function (c) {
            c.classList.remove('sgd-word-cell-focus');
        });
    }

    function clearCellSelection() {
        form.querySelectorAll('.sgd-word-cell-selected').forEach(function (c) {
            c.classList.remove('sgd-word-cell-selected');
        });
    }

    function getSelectedCells() {
        var cells = form.querySelectorAll('.sgd-word-cell-selected');
        return cells.length ? Array.prototype.slice.call(cells) : [];
    }

    function markActiveCell(cell) {
        clearCellFocusMarkers();
        if (cell) cell.classList.add('sgd-word-cell-focus');
    }

    function buildTableGrid(table) {
        var grid = [];
        if (!table) return grid;
        table.querySelectorAll('tr').forEach(function (tr, ri) {
            if (!grid[ri]) grid[ri] = [];
            var col = 0;
            Array.prototype.forEach.call(tr.cells, function (cell) {
                while (grid[ri][col]) col += 1;
                var colspan = parseInt(cell.getAttribute('colspan'), 10) || 1;
                var rowspan = parseInt(cell.getAttribute('rowspan'), 10) || 1;
                for (var r = 0; r < rowspan; r += 1) {
                    for (var c = 0; c < colspan; c += 1) {
                        if (!grid[ri + r]) grid[ri + r] = [];
                        grid[ri + r][col + c] = cell;
                    }
                }
                col += colspan;
            });
        });
        return grid;
    }

    function findCellPosition(grid, cell) {
        for (var r = 0; r < grid.length; r += 1) {
            if (!grid[r]) continue;
            for (var c = 0; c < grid[r].length; c += 1) {
                if (grid[r][c] === cell) return { r: r, c: c };
            }
        }
        return null;
    }

    function selectSingleCell(cell) {
        tableSelAnchor = cell || null;
        clearCellSelection();
        if (cell) cell.classList.add('sgd-word-cell-selected');
    }

    function selectCellRange(anchor, target) {
        var table = getActiveTable();
        if (!table || !anchor || !target) return;
        if (anchor.closest('table') !== table || target.closest('table') !== table) return;

        var grid = buildTableGrid(table);
        var a = findCellPosition(grid, anchor);
        var b = findCellPosition(grid, target);
        if (!a || !b) return;

        var minR = Math.min(a.r, b.r);
        var maxR = Math.max(a.r, b.r);
        var minC = Math.min(a.c, b.c);
        var maxC = Math.max(a.c, b.c);

        clearCellSelection();
        var seen = new Set();
        for (var r = minR; r <= maxR; r += 1) {
            for (var c = minC; c <= maxC; c += 1) {
                var cell = grid[r] && grid[r][c];
                if (cell && !seen.has(cell)) {
                    seen.add(cell);
                    cell.classList.add('sgd-word-cell-selected');
                }
            }
        }
    }

    function getCellsForTableAction() {
        var selected = getSelectedCells();
        if (selected.length) return selected;
        var cell = getActiveCell();
        return cell ? [cell] : [];
    }

    function getCellStyleParts(cell) {
        return sanitizeCellStyleAttr(cell.getAttribute('style') || '').split(';').filter(Boolean);
    }

    function setCellStyleParts(cell, parts) {
        var style = sanitizeCellStyleAttr(parts.join(';'));
        if (style) cell.setAttribute('style', style);
        else cell.removeAttribute('style');
    }

    function setCellBorderSide(cell, side, visible) {
        var parts = getCellStyleParts(cell).filter(function (p) {
            return p.indexOf('border-' + side + ':') !== 0;
        });
        parts.push('border-' + side + ':' + (visible ? TABLE_BORDER_DEFAULT : 'none'));
        setCellStyleParts(cell, parts);
    }

    function setCellAllBorders(cell, visible) {
        ['top', 'right', 'bottom', 'left'].forEach(function (side) {
            setCellBorderSide(cell, side, visible);
        });
    }

    function setTableBorderMode(mode) {
        tableBorderMode = mode || null;
        form.querySelectorAll('[data-table-border-mode]').forEach(function (btn) {
            btn.classList.toggle('is-active', btn.getAttribute('data-table-border-mode') === tableBorderMode);
        });
        if (activeEditor) {
            activeEditor.classList.toggle('sgd-table-border-draw', tableBorderMode === 'draw');
            activeEditor.classList.toggle('sgd-table-border-erase', tableBorderMode === 'erase');
        }
    }

    function applyBorderPresetToCells(cells, preset) {
        cells.forEach(function (cell) {
            if (preset === 'all') {
                setCellAllBorders(cell, true);
            } else if (preset === 'none') {
                setCellAllBorders(cell, false);
            } else if (preset === 'top') {
                setCellBorderSide(cell, 'top', true);
            } else if (preset === 'bottom') {
                setCellBorderSide(cell, 'bottom', true);
            } else if (preset === 'left') {
                setCellBorderSide(cell, 'left', true);
            } else if (preset === 'right') {
                setCellBorderSide(cell, 'right', true);
            }
        });
        syncTableFromEditor();
    }

    function applyCellAlign(prop, value) {
        var cells = getCellsForTableAction();
        if (!cells.length) return;
        cells.forEach(function (cell) {
            var parts = getCellStyleParts(cell).filter(function (p) {
                return p.indexOf(prop + ':') !== 0;
            });
            parts.push(prop + ':' + value);
            setCellStyleParts(cell, parts);
        });
        syncTableFromEditor();
    }

    function insertCellAtVisualColumn(tr, colIndex, tagName) {
        var visual = 0;
        var cells = Array.prototype.slice.call(tr.cells);
        var i = 0;
        for (; i < cells.length; i += 1) {
            if (visual === colIndex) {
                var before = document.createElement(tagName);
                before.innerHTML = '&nbsp;';
                tr.insertBefore(before, cells[i]);
                return before;
            }
            visual += parseInt(cells[i].getAttribute('colspan'), 10) || 1;
        }
        var after = document.createElement(tagName);
        after.innerHTML = '&nbsp;';
        tr.appendChild(after);
        return after;
    }

    function mergeSelectedCells() {
        var cells = getSelectedCells();
        if (cells.length < 2) {
            setStatus('Seleccione varias celdas (Shift+clic) para combinar', 'warn');
            return;
        }

        var table = getActiveTable();
        if (!table) return;
        var grid = buildTableGrid(table);
        var positions = [];
        var seenCells = new Set();

        cells.forEach(function (cell) {
            if (seenCells.has(cell)) return;
            seenCells.add(cell);
            var pos = findCellPosition(grid, cell);
            if (pos) positions.push({ r: pos.r, c: pos.c, cell: cell });
        });

        if (!positions.length) return;

        var minR = positions[0].r;
        var maxR = positions[0].r;
        var minC = positions[0].c;
        var maxC = positions[0].c;
        positions.forEach(function (p) {
            minR = Math.min(minR, p.r);
            maxR = Math.max(maxR, p.r);
            minC = Math.min(minC, p.c);
            maxC = Math.max(maxC, p.c);
        });

        for (var r = minR; r <= maxR; r += 1) {
            for (var c = minC; c <= maxC; c += 1) {
                if (!grid[r] || !grid[r][c] || cells.indexOf(grid[r][c]) < 0) {
                    setStatus('La selección debe ser un bloque rectangular', 'warn');
                    return;
                }
            }
        }

        var anchor = grid[minR][minC];
        var mergedHtml = [];
        var removed = new Set();
        for (var r2 = minR; r2 <= maxR; r2 += 1) {
            for (var c2 = minC; c2 <= maxC; c2 += 1) {
                var cell = grid[r2][c2];
                if (cell === anchor || removed.has(cell)) continue;
                removed.add(cell);
                if (normalizeCellText(cell.textContent)) mergedHtml.push(cell.innerHTML);
                cell.parentNode.removeChild(cell);
            }
        }
        if (mergedHtml.length) {
            anchor.innerHTML = anchor.innerHTML + mergedHtml.map(function (h) { return ' ' + h; }).join('');
        }
        anchor.setAttribute('colspan', String(maxC - minC + 1));
        anchor.setAttribute('rowspan', String(maxR - minR + 1));
        selectSingleCell(anchor);
        syncTableFromEditor();
    }

    function splitActiveCell() {
        var cell = getActiveCell();
        var table = getActiveTable();
        if (!cell || !table) return;

        var colspan = parseInt(cell.getAttribute('colspan'), 10) || 1;
        var rowspan = parseInt(cell.getAttribute('rowspan'), 10) || 1;
        if (colspan === 1 && rowspan === 1) {
            setStatus('La celda no está combinada', 'warn');
            return;
        }

        var grid = buildTableGrid(table);
        var pos = findCellPosition(grid, cell);
        if (!pos) return;

        var tagName = cell.tagName.toLowerCase();
        cell.removeAttribute('colspan');
        cell.removeAttribute('rowspan');

        var ref = cell;
        var c = 1;
        for (; c < colspan; c += 1) {
            var right = document.createElement(tagName);
            right.innerHTML = '&nbsp;';
            ref.parentNode.insertBefore(right, ref.nextSibling);
            ref = right;
        }

        var rows = table.querySelectorAll('tr');
        var r = 1;
        for (; r < rowspan; r += 1) {
            var tr = rows[pos.r + r];
            if (!tr) continue;
            var c2 = 0;
            for (; c2 < colspan; c2 += 1) {
                insertCellAtVisualColumn(tr, pos.c + c2, tagName);
            }
        }

        selectSingleCell(cell);
        syncTableFromEditor();
    }

    function syncTableFromEditor() {
        if (activeEditor) syncEditor(activeEditor);
        markDirty();
        updateTableToolbar();
    }

    function updateTableToolbar() {
        var tools = document.getElementById('sgd-table-edit-tools');
        var cell = getActiveCell();
        var inTable = !!(cell && activeEditor && activeEditor.contains(cell));
        if (tools) tools.hidden = !inTable;
        if (!inTable) {
            clearCellSelection();
            setTableBorderMode(null);
            return;
        }

        markActiveCell(cell);
        var bgInput = document.getElementById('sgd-cell-bg');
        var colorInput = document.getElementById('sgd-cell-color');
        var style = cell.getAttribute('style') || '';
        var bg = style.match(/background-color\s*:\s*([^;]+)/i);
        var fg = style.match(/(?:^|;)\s*color\s*:\s*([^;]+)/i);
        if (bgInput && bg) {
            var bgVal = sanitizeCssColorJs(bg[1].trim());
            if (bgVal.indexOf('#') === 0) bgInput.value = bgVal;
        }
        if (colorInput && fg) {
            var fgVal = sanitizeCssColorJs(fg[1].trim());
            if (fgVal.indexOf('#') === 0) colorInput.value = fgVal;
        }
    }

    function insertEmptyTable(rows, cols, withHeader) {
        if (!activeEditor) return;
        insertHtmlIntoEditor(activeEditor, buildEmptyTableHtml(rows, cols, withHeader));
        syncTableFromEditor();
    }

    function tableRowCount(table) {
        return table.querySelectorAll('tr').length;
    }

    function tableColCount(table) {
        var max = 0;
        table.querySelectorAll('tr').forEach(function (tr) {
            if (tr.cells.length > max) max = tr.cells.length;
        });
        return max;
    }

    function addTableRow(where) {
        var cell = getActiveCell();
        var table = getActiveTable();
        if (!cell || !table) return;
        var row = cell.parentElement;
        if (!row || row.tagName !== 'TR') return;
        var newRow = row.cloneNode(true);
        newRow.querySelectorAll('th, td').forEach(function (c) {
            c.textContent = '\u00a0';
            c.removeAttribute('style');
            c.removeAttribute('colspan');
            c.removeAttribute('rowspan');
            c.classList.remove('sgd-word-cell-focus');
        });
        if (where === 'above') row.parentNode.insertBefore(newRow, row);
        else row.parentNode.insertBefore(newRow, row.nextSibling);
        syncTableFromEditor();
    }

    function addTableCol(where) {
        var cell = getActiveCell();
        var table = getActiveTable();
        if (!cell || !table) return;
        var colIndex = cell.cellIndex;
        table.querySelectorAll('tr').forEach(function (tr) {
            if (!tr.cells.length) return;
            var ref = tr.cells[colIndex];
            if (!ref) return;
            var newCell = document.createElement(ref.tagName.toLowerCase());
            newCell.innerHTML = '&nbsp;';
            if (where === 'left') tr.insertBefore(newCell, ref);
            else tr.insertBefore(newCell, ref.nextSibling);
        });
        syncTableFromEditor();
    }

    function deleteTableRow() {
        var cell = getActiveCell();
        var table = getActiveTable();
        if (!cell || !table || tableRowCount(table) < 2) return;
        var row = cell.parentElement;
        if (row && row.tagName === 'TR') row.remove();
        syncTableFromEditor();
    }

    function deleteTableCol() {
        var cell = getActiveCell();
        var table = getActiveTable();
        if (!cell || !table || tableColCount(table) < 2) return;
        var colIndex = cell.cellIndex;
        table.querySelectorAll('tr').forEach(function (tr) {
            if (tr.cells[colIndex]) tr.cells[colIndex].remove();
        });
        syncTableFromEditor();
    }

    function deleteActiveTable() {
        var table = getActiveTable();
        if (!table) return;
        table.remove();
        clearCellFocusMarkers();
        syncTableFromEditor();
    }

    function toggleTableHeader() {
        var table = getActiveTable();
        if (!table) return;
        var thead = table.querySelector('thead');
        if (thead) {
            var tbody = table.querySelector('tbody') || table.appendChild(document.createElement('tbody'));
            thead.querySelectorAll('tr').forEach(function (tr) {
                var newTr = document.createElement('tr');
                tr.querySelectorAll('th, td').forEach(function (c) {
                    var td = document.createElement('td');
                    td.innerHTML = c.innerHTML;
                    var st = sanitizeCellStyleAttr(c.getAttribute('style'));
                    if (st) td.setAttribute('style', st);
                    newTr.appendChild(td);
                });
                tbody.insertBefore(newTr, tbody.firstChild);
            });
            thead.remove();
        } else {
            var firstBody = table.querySelector('tbody tr, tr');
            if (!firstBody) return;
            var row = firstBody.tagName === 'TR' ? firstBody : firstBody.closest('tr');
            if (!row) return;
            thead = document.createElement('thead');
            var headTr = document.createElement('tr');
            row.querySelectorAll('th, td').forEach(function (c) {
                var th = document.createElement('th');
                th.innerHTML = c.innerHTML;
                var st = sanitizeCellStyleAttr(c.getAttribute('style'));
                if (st) th.setAttribute('style', st);
                headTr.appendChild(th);
            });
            thead.appendChild(headTr);
            table.insertBefore(thead, table.firstChild);
            row.remove();
        }
        syncTableFromEditor();
    }

    function applyCellColor(prop, value) {
        var cells = getCellsForTableAction();
        if (!cells.length) return;
        var color = sanitizeCssColorJs(value);
        if (!color) return;
        cells.forEach(function (cell) {
            var parts = getCellStyleParts(cell).filter(function (p) {
                return prop === 'background-color'
                    ? p.indexOf('background-color:') !== 0
                    : p.indexOf('color:') !== 0;
            });
            parts.push(prop + ':' + color);
            setCellStyleParts(cell, parts);
        });
        syncTableFromEditor();
    }

    function clearCellColors() {
        var cells = getCellsForTableAction();
        if (!cells.length) return;
        cells.forEach(function (cell) {
            var parts = getCellStyleParts(cell).filter(function (p) {
                return p.indexOf('background-color:') !== 0 && p.indexOf('color:') !== 0;
            });
            setCellStyleParts(cell, parts);
        });
        syncTableFromEditor();
    }

    function runTableCommand(cmd) {
        if (!config.canEdit) return;
        setTableBorderMode(null);
        switch (cmd) {
            case 'row-above': addTableRow('above'); break;
            case 'row-below': addTableRow('below'); break;
            case 'col-left': addTableCol('left'); break;
            case 'col-right': addTableCol('right'); break;
            case 'del-row': deleteTableRow(); break;
            case 'del-col': deleteTableCol(); break;
            case 'toggle-header': toggleTableHeader(); break;
            case 'del-table': deleteActiveTable(); break;
            case 'clear-colors': clearCellColors(); break;
            case 'merge-cells': mergeSelectedCells(); break;
            case 'split-cell': splitActiveCell(); break;
            case 'border-all':
                applyBorderPresetToCells(getCellsForTableAction(), 'all');
                break;
            case 'border-none':
                applyBorderPresetToCells(getCellsForTableAction(), 'none');
                break;
            case 'border-top':
                applyBorderPresetToCells(getCellsForTableAction(), 'top');
                break;
            case 'border-bottom':
                applyBorderPresetToCells(getCellsForTableAction(), 'bottom');
                break;
            case 'border-left':
                applyBorderPresetToCells(getCellsForTableAction(), 'left');
                break;
            case 'border-right':
                applyBorderPresetToCells(getCellsForTableAction(), 'right');
                break;
            case 'align-left':
                applyCellAlign('text-align', 'left');
                break;
            case 'align-center':
                applyCellAlign('text-align', 'center');
                break;
            case 'align-right':
                applyCellAlign('text-align', 'right');
                break;
            case 'valign-top':
                applyCellAlign('vertical-align', 'top');
                break;
            case 'valign-middle':
                applyCellAlign('vertical-align', 'middle');
                break;
            case 'valign-bottom':
                applyCellAlign('vertical-align', 'bottom');
                break;
        }
    }

    function initTableToolbar() {
        var insertBtn = document.getElementById('sgd-table-insert-btn');
        var panel = document.getElementById('sgd-table-insert-panel');
        var borderPanel = document.getElementById('sgd-table-border-panel');
        var confirmBtn = document.getElementById('sgd-table-insert-confirm');
        var bgInput = document.getElementById('sgd-cell-bg');
        var colorInput = document.getElementById('sgd-cell-color');

        if (insertBtn && panel) {
            insertBtn.addEventListener('mousedown', function (ev) { ev.preventDefault(); });
            insertBtn.addEventListener('click', function (ev) {
                ev.stopPropagation();
                panel.hidden = !panel.hidden;
                if (borderPanel) borderPanel.hidden = true;
            });
        }

        var borderBtn = document.getElementById('sgd-table-border-btn');
        if (borderBtn && borderPanel) {
            borderBtn.addEventListener('mousedown', function (ev) { ev.preventDefault(); });
            borderBtn.addEventListener('click', function (ev) {
                ev.stopPropagation();
                borderPanel.hidden = !borderPanel.hidden;
                if (panel) panel.hidden = true;
            });
        }

        if (confirmBtn) {
            confirmBtn.addEventListener('click', function () {
                var rowsEl = document.getElementById('sgd-table-rows');
                var colsEl = document.getElementById('sgd-table-cols');
                var headerEl = document.getElementById('sgd-table-header');
                insertEmptyTable(
                    rowsEl ? rowsEl.value : 3,
                    colsEl ? colsEl.value : 3,
                    headerEl ? headerEl.checked : true
                );
                if (panel) panel.hidden = true;
            });
        }

        form.querySelectorAll('[data-table-cmd]').forEach(function (btn) {
            btn.addEventListener('mousedown', function (ev) { ev.preventDefault(); });
            btn.addEventListener('click', function () {
                runTableCommand(btn.getAttribute('data-table-cmd'));
                if (borderPanel) borderPanel.hidden = true;
            });
        });

        form.querySelectorAll('[data-table-border-mode]').forEach(function (btn) {
            btn.addEventListener('mousedown', function (ev) { ev.preventDefault(); });
            btn.addEventListener('click', function () {
                var mode = btn.getAttribute('data-table-border-mode');
                setTableBorderMode(tableBorderMode === mode ? null : mode);
            });
        });

        if (bgInput) {
            bgInput.addEventListener('input', function () {
                applyCellColor('background-color', bgInput.value);
            });
        }
        if (colorInput) {
            colorInput.addEventListener('input', function () {
                applyCellColor('color', colorInput.value);
            });
        }

        document.addEventListener('click', function (ev) {
            if (panel && !panel.hidden && !ev.target.closest('.sgd-word-table-insert-group')) {
                panel.hidden = true;
            }
            if (borderPanel && !borderPanel.hidden && !ev.target.closest('.sgd-table-border-group')) {
                borderPanel.hidden = true;
            }
        });

        document.addEventListener('selectionchange', function () {
            if (activeEditor) updateTableToolbar();
        });

        form.addEventListener('click', function (ev) {
            var cell = ev.target.closest('td, th');
            if (!cell || !activeEditor || !activeEditor.contains(cell)) return;

            if (tableBorderMode === 'draw') {
                ev.preventDefault();
                setCellAllBorders(cell, true);
                syncTableFromEditor();
                return;
            }
            if (tableBorderMode === 'erase') {
                ev.preventDefault();
                setCellAllBorders(cell, false);
                syncTableFromEditor();
                return;
            }

            ev.preventDefault();

            if (ev.shiftKey && tableSelAnchor) {
                selectCellRange(tableSelAnchor, cell);
            } else {
                selectSingleCell(cell);
            }
            markActiveCell(cell);
            updateTableToolbar();
        });
    }

    function clearActiveImage() {
        if (activeImage) {
            activeImage.classList.remove('is-active');
            activeImage = null;
        }
    }

    function getActiveImage() {
        if (activeImage && activeEditor && activeEditor.contains(activeImage)) {
            return activeImage;
        }
        return null;
    }

    function markActiveImage(img) {
        clearActiveImage();
        activeImage = img || null;
        if (activeImage) activeImage.classList.add('is-active');
    }

    function buildImageHtml(path) {
        return '<p class="sgd-word-img-wrap"><img src="' + escapeAttr(path) + '" alt="" class="sgd-word-img"></p>';
    }

    function insertImageHtml(editor, path) {
        if (!editor || !path) return;
        insertHtmlIntoEditor(editor, buildImageHtml(path));
        syncEditor(editor);
        markDirty();
        scheduleOutlineRebuild();
    }

    function uploadElabImageFile(file) {
        if (!config.uploadMediaUrl || !file) {
            return Promise.resolve({ ok: false, error: 'Subida no disponible' });
        }
        var fd = new FormData();
        fd.append('archivo', file);
        fd.append('documento_id', String(config.documentoId || ''));
        return fetch(config.uploadMediaUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .catch(function () { return { ok: false, error: 'Error de red al subir' }; });
    }

    function dataUrlToBlob(dataUrl) {
        var m = String(dataUrl || '').match(/^data:([^;,]+)(?:;[^,]*)?;base64,(.+)$/);
        if (!m) return null;
        try {
            var binary = atob(m[2]);
            var len = binary.length;
            var bytes = new Uint8Array(len);
            for (var i = 0; i < len; i++) bytes[i] = binary.charCodeAt(i);
            return new Blob([bytes], { type: m[1] });
        } catch (e) {
            return null;
        }
    }

    function getClipboardImageFile(cd) {
        if (!cd || !cd.items) return null;
        for (var i = 0; i < cd.items.length; i++) {
            if (cd.items[i].kind === 'file' && cd.items[i].type.indexOf('image/') === 0) {
                return cd.items[i].getAsFile();
            }
        }
        return null;
    }

    function clipboardHasOnlyImage(cd, plain) {
        if (!getClipboardImageFile(cd)) return false;
        return normalizeCellText(plain) === '';
    }

    function processImagesInHtml(html) {
        if (!html || html.indexOf('<img') < 0 || !config.uploadMediaUrl) {
            return Promise.resolve(html);
        }
        var doc = new DOMParser().parseFromString('<div id="sgd-img-wrap">' + html + '</div>', 'text/html');
        var wrap = doc.getElementById('sgd-img-wrap');
        if (!wrap) return Promise.resolve(html);

        var tasks = [];
        wrap.querySelectorAll('img').forEach(function (img) {
            var src = img.getAttribute('src') || '';
            if (src.indexOf('data:image/') === 0) {
                var blob = dataUrlToBlob(src);
                if (!blob) {
                    img.remove();
                    return;
                }
                var ext = blob.type.indexOf('png') >= 0 ? 'png' : (blob.type.indexOf('webp') >= 0 ? 'webp' : 'jpg');
                var file = new File([blob], 'pegado.' + ext, { type: blob.type });
                tasks.push(uploadElabImageFile(file).then(function (res) {
                    if (res.ok && res.path) {
                        img.setAttribute('src', res.path);
                        img.setAttribute('class', 'sgd-word-img');
                        img.removeAttribute('style');
                    } else {
                        img.remove();
                    }
                }));
            } else if (src && (/^https?:/i.test(src) || /^file:/i.test(src) || src.indexOf('//') === 0)) {
                img.remove();
            } else if (src.indexOf('/uploads/sgd/') === 0) {
                img.setAttribute('class', 'sgd-word-img');
            }
        });

        if (!tasks.length) return Promise.resolve(wrap.innerHTML);
        return Promise.all(tasks).then(function () { return wrap.innerHTML; });
    }

    function finishPasteHtml(editor, html) {
        processImagesInHtml(html).then(function (processed) {
            insertHtmlIntoEditor(editor, processed);
            replaceListParagraphRuns(editor);
            cleanEditorLists(editor);
            syncEditor(editor);
            markDirty();
            scheduleOutlineRebuild();
        });
    }

    function yieldToMainThread() {
        return new Promise(function (resolve) {
            window.requestAnimationFrame(function () {
                window.setTimeout(resolve, 0);
            });
        });
    }

    function lightPrepareImportedHtml(html) {
        if (!html || String(html).trim() === '') return '';
        try {
            var doc = new DOMParser().parseFromString('<div id="sgd-light-root">' + html + '</div>', 'text/html');
            var root = doc.getElementById('sgd-light-root');
            if (!root) return html;
            root.querySelectorAll('table').forEach(function (t) {
                if (!t.classList.contains('sgd-word-table')) t.classList.add('sgd-word-table');
                t.classList.add('no-datatable');
            });
            root.querySelectorAll('ul, ol').forEach(function (l) {
                if (!l.classList.contains('sgd-word-list')) l.classList.add('sgd-word-list');
            });
            unwrapSectionTitleLists(root);
            root.querySelectorAll('p').forEach(function (p) {
                var text = normalizeCellText(p.textContent || '');
                var titleTag = getNumberedTitleHeadingTag(text);
                if (titleTag) {
                    var titleEl = doc.createElement(titleTag);
                    titleEl.innerHTML = p.innerHTML;
                    p.replaceWith(titleEl);
                    return;
                }
                if (p.classList.contains('sgd-word-section-title')) {
                    var fixTag = getNumberedTitleHeadingTag(text);
                    if (fixTag) {
                        var fixEl = doc.createElement(fixTag);
                        fixEl.innerHTML = p.innerHTML;
                        p.replaceWith(fixEl);
                    }
                    return;
                }
                if (!text || !findSectionCodigoByHeading(text)) return;
                if (looksLikeMainSectionTitleText(text)) {
                    p.classList.add('sgd-word-section-title');
                }
            });
            root.querySelectorAll('img').forEach(function (img) {
                if (!img.classList.contains('sgd-word-img')) img.classList.add('sgd-word-img');
            });
            return root.innerHTML;
        } catch (e) {
            return html;
        }
    }

    function importNodeHtml(node) {
        if (!node || node.nodeType !== 1) return '';
        var tag = node.tagName.toLowerCase();
        if (tag === 'table') {
            return sanitizeTableElement(node) || node.outerHTML;
        }
        if (tag === 'ul' || tag === 'ol') {
            var cleanList = sanitizeListNode(node, 0);
            return cleanList ? cleanList.outerHTML : node.outerHTML;
        }
        if (/^h[1-6]$/.test(tag) || tag === 'p') {
            return node.outerHTML;
        }
        var wrap = document.createElement('div');
        wrap.appendChild(node.cloneNode(true));
        return htmlFromBodyChildren(wrap);
    }

    function prepareImportedHtmlForInsert(html, options) {
        options = options || {};
        var onProgress = options.onProgress || function () {};

        if (options.source === 'server') {
            return yieldToMainThread().then(function () {
                onProgress(70, 'Preparando contenido del servidor…');
                return lightPrepareImportedHtml(html);
            }).then(function (result) {
                onProgress(100, 'Listo para insertar');
                return result;
            });
        }

        return normalizeImportedDocumentHtmlAsync(html, onProgress);
    }

    function normalizeImportedDocumentHtml(html) {
        if (!html || String(html).trim() === '') return '';

        var tableParts = [];
        var doc = new DOMParser().parseFromString('<div id="sgd-import-root">' + html + '</div>', 'text/html');
        var root = doc.getElementById('sgd-import-root');
        if (!root) return extractDocumentHtmlFromClipboard(html) || html;

        var bodyHtml = '';
        Array.prototype.forEach.call(root.childNodes, function (node) {
            if (node.nodeType !== 1) return;
            var tag = node.tagName.toLowerCase();
            if (tag === 'table') {
                var tbl = sanitizeTableElement(node);
                if (tbl) tableParts.push(tbl);
                return;
            }
            var wrap = document.createElement('div');
            wrap.appendChild(node.cloneNode(true));
            var part = htmlFromBodyChildren(wrap);
            if (part) bodyHtml += part;
        });

        var combined = bodyHtml + tableParts.join('');
        if (!combined.trim()) {
            combined = extractDocumentHtmlFromClipboard(html) || html;
        }

        var listDoc = new DOMParser().parseFromString('<div id="sgd-import-list">' + combined + '</div>', 'text/html');
        var listRoot = listDoc.getElementById('sgd-import-list');
        if (listRoot) {
            replaceListParagraphRuns(listRoot);
            combined = listRoot.innerHTML;
        }

        return combined;
    }

    function normalizeImportedDocumentHtmlAsync(html, onProgress) {
        onProgress = onProgress || function () {};
        if (!html || String(html).trim() === '') {
            return Promise.resolve('');
        }

        if (html.length < 6000) {
            return yieldToMainThread().then(function () {
                onProgress(70, 'Normalizando contenido breve…');
                return normalizeImportedDocumentHtml(html);
            }).then(function (result) {
                onProgress(100, 'Normalización completada');
                return result;
            });
        }

        var sizeKb = Math.round(html.length / 1024);

        return yieldToMainThread().then(function () {
            onProgress(8, 'Analizando documento (' + sizeKb + ' KB)…');
            return yieldToMainThread();
        }).then(function () {
            return new Promise(function (resolve) {
                var doc = new DOMParser().parseFromString(
                    '<div id="sgd-import-root">' + html + '</div>',
                    'text/html'
                );
                var root = doc.getElementById('sgd-import-root');
                if (!root) {
                    onProgress(55, 'Normalizando contenido…');
                    yieldToMainThread().then(function () {
                        resolve(normalizeImportedDocumentHtml(html));
                    });
                    return;
                }

                var nodes = Array.prototype.slice.call(root.childNodes).filter(function (n) {
                    return n.nodeType === 1;
                });
                var bodyHtmlParts = [];
                var index = 0;
                var total = Math.max(1, nodes.length);
                var frameBudgetMs = 40;

                function step() {
                    var frameStart = (typeof performance !== 'undefined' && performance.now)
                        ? performance.now()
                        : Date.now();

                    while (index < nodes.length) {
                        var part = importNodeHtml(nodes[index]);
                        if (part) bodyHtmlParts.push(part);
                        index += 1;

                        var now = (typeof performance !== 'undefined' && performance.now)
                            ? performance.now()
                            : Date.now();
                        if ((now - frameStart) >= frameBudgetMs) {
                            break;
                        }
                    }

                    var pct = 12 + Math.round((index / total) * 76);
                    onProgress(
                        pct,
                        'Normalizando bloques ' + index + '/' + total + ' (' + sizeKb + ' KB)…'
                    );

                    if (index < nodes.length) {
                        window.requestAnimationFrame(step);
                        return;
                    }

                    var combined = bodyHtmlParts.join('');
                    if (!combined.trim()) {
                        combined = lightPrepareImportedHtml(html);
                    }

                    onProgress(90, 'Ajustando listas…');
                    window.requestAnimationFrame(function () {
                        yieldToMainThread().then(function () {
                            var listDoc = new DOMParser().parseFromString(
                                '<div id="sgd-import-list">' + combined + '</div>',
                                'text/html'
                            );
                            var listRoot = listDoc.getElementById('sgd-import-list');
                            if (listRoot) {
                                replaceListParagraphRuns(listRoot);
                                combined = listRoot.innerHTML;
                            }
                            onProgress(100, 'Normalización completada');
                            resolve(combined);
                        });
                    });
                }

                onProgress(10, 'Normalizando en el navegador (' + total + ' bloques)…');
                window.requestAnimationFrame(step);
            });
        });
    }

    var COMBINING_MARKS_RE = /[\u0300-\u036f]/g;

    function stripAccentsForMatch(text) {
        var s = String(text || '');
        if (!s.normalize) return s;
        return s.normalize('NFD').replace(COMBINING_MARKS_RE, '');
    }

    var IMPORT_HEADING_SKIP_PATTERNS = [
        /^manual de\b/,
        /^procedimiento\b/,
        /^plan de\b/,
        /^programa de\b/,
        /^(elaborado|revisado|aprobado)\s+por$/,
        /^tabla de contenido$/,
        /^capitulo\s+[ivxlcdm\d]+$/,
        /^indice$/,
        /^laboratorio\b/,
        /^sistema de gestion\b/,
        /^version\s+\d/,
        /^codigo\s+/,
        /^pagina\s+\d/
    ];

    var IMPORT_HEADING_ALIASES = {
        'objetivos especificos': 'objetivo',
        'objetivo general': 'objetivo',
        'objetivos': 'objetivo',
        'responsables': 'responsable',
        'terminos y definiciones': 'definiciones',
        'definicion de terminos': 'definiciones',
        'definiciones y abreviaturas': 'definiciones',
        'abreviaturas y definiciones': 'definiciones',
        'condiciones tecnicas': 'condiciones tecnicas generales',
        'descripcion de las actividades': 'descripcion de actividades',
        'descripcion de actividad': 'descripcion de actividades',
        'descripcion de actividades': 'descripcion de actividades',
        'recursos humanos y materiales': 'talento humano equipos e insumos',
        'recursos': 'talento humano equipos e insumos',
        'talento humano': 'talento humano equipos e insumos',
        'talento humano equipos biometricos dispositivos medicos e insumos requeridos': 'talento humano equipos e insumos',
        'talento humano equipos e insumos requeridos': 'talento humano equipos e insumos',
        'documentos de referencia': 'documentos referenciados',
        'documentacion de referencia': 'documentos referenciados',
        'marco normativo': 'normatividad',
        'marco legal': 'normatividad',
        'normativa': 'normatividad',
        'enfoque de genero': 'enfoque diferencial',
        'enfoque diferencial de genero': 'enfoque diferencial'
    };

    function normalizeHeadingMatch(text) {
        var norm = stripAccentsForMatch(normalizeCellText(text))
            .toLowerCase()
            .replace(/^\d+(?:\.\d+)*[\.\)\-]?\s*/, '')
            .replace(/^(?:seccion|capitulo)\s+\d+(?:\.\d+)*[\.\)\-]?\s*/i, '')
            .replace(/[^a-z0-9\s]/g, '')
            .replace(/\s+/g, ' ')
            .trim();
        if (!norm) return '';
        if (IMPORT_HEADING_ALIASES[norm]) {
            norm = IMPORT_HEADING_ALIASES[norm];
        }
        return norm;
    }

    function shouldSkipImportedHeading(text) {
        var norm = normalizeHeadingMatch(text);
        if (!norm) return true;
        return IMPORT_HEADING_SKIP_PATTERNS.some(function (re) {
            return re.test(norm);
        });
    }

    function levenshteinDistance(a, b) {
        if (a === b) return 0;
        if (!a.length) return b.length;
        if (!b.length) return a.length;
        var row = [];
        var i;
        var j;
        for (i = 0; i <= b.length; i += 1) row[i] = i;
        for (i = 1; i <= a.length; i += 1) {
            var prev = i;
            for (j = 1; j <= b.length; j += 1) {
                var val = a[i - 1] === b[j - 1]
                    ? row[j - 1]
                    : Math.min(row[j - 1], prev, row[j]) + 1;
                row[j - 1] = prev;
                prev = val;
            }
            row[b.length] = prev;
        }
        return row[b.length];
    }

    function headingTokenOverlapScore(norm, name) {
        var stop = { e: 1, y: 1, de: 1, la: 1, el: 1, los: 1, las: 1, del: 1, en: 1 };
        var nameTokens = name.split(/\s+/).filter(function (t) {
            return t.length > 2 && !stop[t];
        });
        if (nameTokens.length < 2) return 0;
        var found = 0;
        var pos = 0;
        nameTokens.forEach(function (tok) {
            var i = norm.indexOf(tok, pos);
            if (i >= 0) {
                found += 1;
                pos = i + tok.length;
            }
        });
        return found / nameTokens.length;
    }

    function headingMatchScore(norm, name) {
        if (!norm || !name) return 0;
        if (norm === name) return 1;
        if (norm.indexOf(name) >= 0 && name.length >= 4) return 0.96;
        if (name.indexOf(norm) >= 0 && norm.length >= 4) return 0.92;
        if (norm.length > 28 || name.length > 28) {
            var overlap = headingTokenOverlapScore(norm, name);
            if (overlap >= 0.75) return Math.max(0.85, overlap);
            if (norm.indexOf('talento humano') >= 0 && name.indexOf('talento humano') === 0) return 0.9;
            return 0;
        }
        var dist = levenshteinDistance(norm, name);
        var maxLen = Math.max(norm.length, name.length);
        if (!maxLen) return 0;
        var sim = 1 - dist / maxLen;
        return sim >= 0.8 ? sim : 0;
    }

    function findSectionCodigoByHeading(text) {
        if (shouldSkipImportedHeading(text)) return null;
        var norm = normalizeHeadingMatch(text);
        if (!norm || !config.secciones || !config.secciones.length) return null;

        if (/talento\s+humano/.test(norm) && /(equipos|insumos)/.test(norm)) {
            var hasRecursos = (config.secciones || []).some(function (sec) {
                return sec.codigo === 'recursos' && (sec.clase || '') === 'contenido';
            });
            if (hasRecursos) return 'recursos';
        }

        var bestCodigo = null;
        var bestScore = 0;

        config.secciones.forEach(function (sec) {
            if ((sec.clase || '') !== 'contenido') return;
            var candidates = [normalizeHeadingMatch(sec.nombre || '')];
            var codigoNorm = normalizeHeadingMatch(String(sec.codigo || '').replace(/_/g, ' '));
            if (codigoNorm && candidates.indexOf(codigoNorm) < 0) candidates.push(codigoNorm);
            candidates.forEach(function (name) {
                if (!name) return;
                var score = headingMatchScore(norm, name);
                if (score > bestScore) {
                    bestScore = score;
                    bestCodigo = sec.codigo;
                }
            });
        });

        return bestScore >= 0.8 ? bestCodigo : null;
    }

    function tituloEjemploDepth(ejemplo) {
        ejemplo = normalizeCellText(ejemplo);
        if (!ejemplo) return 0;
        var m = ejemplo.match(/^(\d+(?:\.\d+)*)/);
        if (!m) return 0;
        return m[1].split('.').length;
    }

    function extractLeadingNumberingDepth(text) {
        text = normalizeCellText(text);
        if (!text) return 0;
        var multi = text.match(/^(\d+(?:\.\d+)+)/);
        if (multi) return multi[1].split('.').length;
        if (/^\d+[\.\)\-]\s+\S/.test(text)) return 1;
        if (/^\d+\.\S/.test(text) && !/^\d+\.\d/.test(text)) return 1;
        if (/^\d+\s+\S/.test(text)) return 1;
        return 0;
    }

    function buildTituloDepthMap() {
        var niveles = (config.titulos && config.titulos.niveles) || [];
        var map = [];
        niveles.forEach(function (nivel, i) {
            var depth = tituloEjemploDepth(nivel.ejemplo || '');
            var tag = headingTags[i];
            if (depth > 0 && tag) {
                map.push({ depth: depth, tag: tag });
            }
        });
        return map;
    }

    var tituloDepthMapCache = null;

    function getTituloDepthMap() {
        if (!tituloDepthMapCache) {
            tituloDepthMapCache = buildTituloDepthMap();
        }
        return tituloDepthMapCache;
    }

    function headingTagForNumberingDepth(depth) {
        if (depth < 1) return '';
        var map = getTituloDepthMap();
        if (!map.length) {
            var idx = Math.min(depth - 1, headingTags.length - 1);
            return headingTags[idx] || '';
        }
        var exact = null;
        map.forEach(function (entry) {
            if (entry.depth === depth) exact = entry;
        });
        if (exact) return exact.tag;
        var best = null;
        map.forEach(function (entry) {
            if (entry.depth <= depth && (!best || entry.depth > best.depth)) {
                best = entry;
            }
        });
        return best ? best.tag : map[0].tag;
    }

    function isNumberedMainSectionMarker(text) {
        text = normalizeCellText(text);
        if (!text || extractLeadingNumberingDepth(text) !== 1) return false;
        if (!looksNumberedMainSectionLine(text)) return false;
        if (findSectionCodigoByHeading(text)) return true;
        var letters = text.replace(/[^A-Za-zÁÉÍÓÚáéíóúÑñ]/g, '');
        return letters.length >= 4 && letters === letters.toUpperCase();
    }

    function getNumberedTitleHeadingTag(text) {
        var depth = extractLeadingNumberingDepth(text);
        if (depth < 1) return '';
        if (isNumberedMainSectionMarker(text)) return '';
        return headingTagForNumberingDepth(depth);
    }

    function isNumberedInlineTitle(text) {
        return !!getNumberedTitleHeadingTag(text);
    }

    function looksNumberedMainSectionLine(text) {
        return looksNumberedSectionLine(text) && extractLeadingNumberingDepth(text) <= 1;
    }

    function looksLikeMainSectionTitleText(text) {
        text = normalizeCellText(text);
        if (!text || text.length > 130) return false;
        if (extractLeadingNumberingDepth(text) >= 2) return false;
        if (/^(?!\d+(?:\.\d+)*[\.\)\-]\s).+:\s*\S/.test(text)) return false;
        if (looksNumberedMainSectionLine(text)) return true;
        var letters = text.replace(/[^A-Za-zÁÉÍÓÚáéíóúÑñ]/g, '');
        if (letters.length >= 4 && letters === letters.toUpperCase()) return true;
        var caps = (text.match(/[A-ZÁÉÍÓÚÑ]/g) || []).length;
        var lowers = (text.match(/[a-záéíóúñ]/g) || []).length;
        return caps >= 4 && caps >= lowers * 2 && text.length <= 80;
    }

    function paragraphBoldRatio(el) {
        var total = normalizeCellText(el.textContent || '');
        if (!total) return 0;
        var bold = '';
        el.querySelectorAll('strong, b').forEach(function (node) {
            bold += normalizeCellText(node.textContent || '');
        });
        el.querySelectorAll('span').forEach(function (sp) {
            var style = (sp.getAttribute('style') || '').toLowerCase();
            if (/font-weight\s*:\s*(bold|bolder|[7-9]00)/.test(style)) {
                bold += normalizeCellText(sp.textContent || '');
            }
        });
        return bold.length / total.length;
    }

    function looksNumberedSectionLine(text) {
        return /^\d+(?:\.\d+)*[\.\)\-]?\s*\S/.test(text);
    }

    function elementLooksLikeSectionHeading(el) {
        if (!el || el.nodeType !== 1) return false;
        var tag = el.tagName.toLowerCase();
        if (/^h[1-6]$/.test(tag)) return true;

        var text = normalizeCellText(el.textContent || '');
        if (!text || text.length > 160) return false;
        if (!findSectionCodigoByHeading(text)) return false;

        if (tag !== 'p' && tag !== 'div') return false;
        if (el.classList.contains('sgd-word-section-title')) return true;

        var boldRatio = paragraphBoldRatio(el);
        if (boldRatio >= 0.65) return true;
        if (looksNumberedSectionLine(text) && boldRatio >= 0.35) return true;
        if (looksNumberedSectionLine(text) && text.length <= 100) return true;

        return false;
    }

    function resolveSectionCodigoFromListItem(el) {
        if (!el || el.nodeType !== 1) return null;
        var text = normalizeCellText(el.textContent || '');
        if (shouldSkipImportedHeading(text)) return null;
        if (isNumberedInlineTitle(text)) return null;
        if (!looksLikeMainSectionTitleText(text)) return null;
        return findSectionCodigoByHeading(text);
    }

    function unwrapSectionTitleLists(root) {
        if (!root) return;
        root.querySelectorAll('ol.sgd-word-list, ul.sgd-word-list, ol, ul').forEach(function (list) {
            if (list.closest('table')) return;
            var lis = Array.prototype.slice.call(list.children).filter(function (n) {
                return n.tagName && n.tagName.toLowerCase() === 'li';
            });
            if (lis.length < 2) return;
            var sectionLis = lis.filter(function (li) {
                return resolveSectionCodigoFromListItem(li);
            });
            if (sectionLis.length < 2 || sectionLis.length !== lis.length) return;
            var ownerDoc = root.ownerDocument || document;
            var frag = ownerDoc.createDocumentFragment();
            sectionLis.forEach(function (li) {
                var p = ownerDoc.createElement('p');
                p.className = 'sgd-word-section-title';
                p.innerHTML = li.innerHTML;
                frag.appendChild(p);
            });
            list.replaceWith(frag);
        });
    }

    function resolveSectionCodigoFromElement(el) {
        if (!el || el.nodeType !== 1) return null;
        var tag = el.tagName.toLowerCase();
        var text = normalizeCellText(el.textContent || '');
        if (shouldSkipImportedHeading(text)) return null;
        if (isNumberedInlineTitle(text)) return null;

        if (/^h[1-6]$/.test(tag)) {
            var codFromHeading = findSectionCodigoByHeading(text);
            return codFromHeading || null;
        }

        if (el.classList.contains('sgd-word-section-title')) {
            return findSectionCodigoByHeading(text);
        }

        if ((tag === 'p' || tag === 'div') && looksLikeMainSectionTitleText(text)) {
            var cod = findSectionCodigoByHeading(text);
            if (cod) return cod;
        }

        if (elementLooksLikeSectionHeading(el)) {
            return findSectionCodigoByHeading(text);
        }
        return null;
    }

    function mapImportedHtmlToSections(html) {
        var chunks = {};
        var doc = new DOMParser().parseFromString('<div id="sgd-map-root">' + html + '</div>', 'text/html');
        var root = doc.getElementById('sgd-map-root');
        if (!root) return chunks;

        var currentCodigo = null;
        var buffer = [];
        var seenSection = false;

        function flush() {
            if (!currentCodigo || !buffer.length) {
                buffer = [];
                return;
            }
            chunks[currentCodigo] = (chunks[currentCodigo] || '') + buffer.join('');
            buffer = [];
        }

        Array.prototype.forEach.call(root.children, function (el) {
            var tag = el.tagName.toLowerCase();

            if (tag === 'ol' || tag === 'ul') {
                var listTag = tag;
                var listClass = el.classList.contains('sgd-word-list') ? 'sgd-word-list' : 'sgd-word-list';
                var listBuf = [];
                function flushListBuf() {
                    if (!listBuf.length) return;
                    buffer.push(
                        '<' + listTag + ' class="' + listClass + '">' + listBuf.join('') + '</' + listTag + '>'
                    );
                    listBuf = [];
                }
                Array.prototype.forEach.call(el.children, function (child) {
                    if (!child || child.nodeType !== 1 || child.tagName.toLowerCase() !== 'li') return;
                    var codLi = resolveSectionCodigoFromListItem(child);
                    if (codLi) {
                        flushListBuf();
                        if (!seenSection) {
                            buffer = [];
                            seenSection = true;
                            currentCodigo = codLi;
                            return;
                        }
                        flush();
                        currentCodigo = codLi;
                        return;
                    }
                    listBuf.push(child.outerHTML);
                });
                flushListBuf();
                return;
            }

            var cod = resolveSectionCodigoFromElement(el);
            if (cod) {
                if (!seenSection) {
                    buffer = [];
                    seenSection = true;
                    currentCodigo = cod;
                    return;
                }
                flush();
                currentCodigo = cod;
                return;
            }
            if (tag === 'table') {
                var tbl = sanitizeTableElement(el);
                buffer.push(tbl || el.outerHTML);
            } else {
                buffer.push(el.outerHTML);
            }
        });
        flush();

        return chunks;
    }

    function sectionLabelByCodigo(codigo) {
        var label = codigo;
        (config.secciones || []).forEach(function (sec) {
            if (sec.codigo === codigo) label = sec.nombre || codigo;
        });
        return label;
    }

    function getEditorByCodigo(codigo) {
        return form.querySelector('.sgd-word-editor[data-codigo="' + codigo + '"]');
    }

    function applyHtmlToEditor(editor, html, replaceAll, options) {
        options = options || {};
        if (!editor || !html) return Promise.resolve();
        return processImagesInHtml(html).then(function (processed) {
            return yieldToMainThread().then(function () {
                if (replaceAll) {
                    editor.innerHTML = processed;
                } else {
                    insertHtmlIntoEditor(editor, processed);
                }
                if (!options.skipListNormalize) {
                    replaceListParagraphRuns(editor);
                    cleanEditorLists(editor);
                }
                syncEditor(editor);
            });
        });
    }

    function finishWordImportUi(count, message) {
        return yieldToMainThread().then(function () {
            markDirty();
            scheduleOutlineRebuild();
            renumberAll();
            rebuildOutlineNav();
            updateNavFilled();
            setStatus(message, 'ok');
            return true;
        });
    }

    function applyImportedWordContent(html, mode, sectionCodigo, skipNormalize, onProgress) {
        onProgress = onProgress || function () {};
        var importSource = (wordImportState && wordImportState.source) || 'browser';
        var editorOpts = {
            skipListNormalize: importSource === 'server'
        };
        var work = skipNormalize
            ? Promise.resolve(html)
            : prepareImportedHtmlForInsert(html, {
                source: importSource,
                onProgress: onProgress
            });

        return work.then(function (normalized) {
        if (!normalized.trim()) {
            setStatus('No se pudo normalizar el contenido del Word', 'warn');
            return false;
        }

        onProgress(100, 'Guardando copia para deshacer…');
        return yieldToMainThread().then(function () {
            pushUndoSnapshot(true);
            return normalized;
        }).then(function (normalizedHtml) {
        if (mode === 'map') {
            onProgress(100, 'Distribuyendo por secciones…');
            var chunks = mapImportedHtmlToSections(normalizedHtml);
            var codes = Object.keys(chunks);
            var count = 0;
            var index = 0;
            var importedNames = [];

            function applyNextChunk() {
                if (index >= codes.length) {
                    if (!count) {
                        setStatus(
                            'No se detectaron títulos que coincidan con las secciones. '
                            + 'Use títulos en MAYÚSCULAS y negrilla (INTRODUCCIÓN, OBJETIVO…) o estilos Título en Word.',
                            'warn'
                        );
                        return Promise.resolve(false);
                    }
                    return finishWordImportUi(
                        count,
                        'Importado en ' + count + ' sección(es): '
                            + importedNames.join(', ')
                            + '. Guarde el documento.'
                    );
                }
                var cod = codes[index];
                index += 1;
                var ed = getEditorByCodigo(cod);
                if (!ed) return applyNextChunk();
                count += 1;
                importedNames.push(sectionLabelByCodigo(cod));
                onProgress(100, 'Insertando ' + sectionLabelByCodigo(cod) + '…');
                return applyHtmlToEditor(ed, chunks[cod], true, editorOpts).then(applyNextChunk);
            }

            return applyNextChunk();
        }

        var targetCodigo = sectionCodigo;
        if (mode === 'cursor' && activeEditor) {
            targetCodigo = activeEditor.getAttribute('data-codigo') || targetCodigo;
        }
        var editor = targetCodigo ? getEditorByCodigo(targetCodigo) : activeEditor;
        if (!editor) {
            setStatus('Seleccione una sección de contenido antes de importar', 'warn');
            return false;
        }

        onProgress(100, mode === 'replace'
            ? 'Reemplazando contenido de la sección…'
            : 'Insertando en la sección…');

        return applyHtmlToEditor(editor, normalizedHtml, mode === 'replace', editorOpts).then(function () {
            return finishWordImportUi(
                1,
                mode === 'replace'
                    ? 'Sección reemplazada desde Word. Guarde.'
                    : 'Contenido insertado desde Word. Guarde.'
            );
        });
        });
        });
    }

    var wordImportState = null;

    var wordImportProgressTimer = null;
    var wordImportProgressWaitTimer = null;
    var wordImportProgressLastPct = 0;
    var wordImportProgressLastLabel = '';
    var wordImportProgressStartedAt = 0;

    function clearWordImportProgressTimer() {
        if (wordImportProgressTimer) {
            window.clearInterval(wordImportProgressTimer);
            wordImportProgressTimer = null;
        }
        if (wordImportProgressWaitTimer) {
            window.clearInterval(wordImportProgressWaitTimer);
            wordImportProgressWaitTimer = null;
        }
    }

    function formatWordImportElapsed(seconds) {
        if (seconds < 60) return seconds + ' s';
        var mins = Math.floor(seconds / 60);
        var secs = seconds % 60;
        return mins + ' min' + (secs ? ' ' + secs + ' s' : '');
    }

    function setWordImportProgress(percent, label) {
        var wrap = document.getElementById('sgd-word-import-progress');
        var bar = document.getElementById('sgd-word-import-progress-bar');
        var track = document.getElementById('sgd-word-import-progress-track');
        var pctEl = document.getElementById('sgd-word-import-progress-pct');
        var lead = document.getElementById('sgd-word-import-lead');
        var value = Math.max(0, Math.min(100, Math.round(percent)));

        if (wrap) wrap.hidden = false;
        if (bar) bar.style.width = value + '%';
        if (pctEl) pctEl.textContent = value + '%';
        if (track) track.setAttribute('aria-valuenow', String(value));
        if (lead && label) {
            lead.textContent = label;
            wordImportProgressLastLabel = label;
        }

        if (value !== wordImportProgressLastPct) {
            wordImportProgressLastPct = value;
            if (value > 0 && value < 100 && !wordImportProgressStartedAt) {
                wordImportProgressStartedAt = Date.now();
            }
            if (value >= 100 || value <= 0) {
                wordImportProgressStartedAt = 0;
            }
        }
    }

    function hideWordImportProgress() {
        clearWordImportProgressTimer();
        wordImportProgressStartedAt = 0;
        wordImportProgressLastPct = 0;
        wordImportProgressLastLabel = '';
        var wrap = document.getElementById('sgd-word-import-progress');
        if (wrap) wrap.hidden = true;
    }

    function startWordImportWaitPulse() {
        if (wordImportProgressWaitTimer) return;
        wordImportProgressWaitTimer = window.setInterval(function () {
            if (!wordImportProgressStartedAt || !wordImportProgressLastLabel) return;
            var elapsed = Math.max(1, Math.round((Date.now() - wordImportProgressStartedAt) / 1000));
            var lead = document.getElementById('sgd-word-import-lead');
            if (lead) {
                lead.textContent = wordImportProgressLastLabel
                    + ' (' + formatWordImportElapsed(elapsed) + ' transcurridos; no cierre la pestaña)';
            }
        }, 15000);
    }

    function startWordImportProgressPulse(from, to, label) {
        clearWordImportProgressTimer();
        var current = from;
        setWordImportProgress(current, label);
        wordImportProgressTimer = window.setInterval(function () {
            current = Math.min(to, current + 1);
            setWordImportProgress(current, label);
            if (current >= to) {
                clearWordImportProgressTimer();
            }
        }, 120);
    }

    function closeWordImportModal() {
        var modal = document.getElementById('sgd-word-import-modal');
        if (!modal) return;
        hideWordImportProgress();
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        wordImportState = null;
    }

    function openWordImportModal() {
        var modal = document.getElementById('sgd-word-import-modal');
        if (modal) {
            modal.hidden = false;
            modal.setAttribute('aria-hidden', 'false');
        }
    }

    function formatWordImportStats(stats, messages, plain) {
        var lines = [];
        if (stats) {
            lines.push(
                'Párrafos: ' + (stats.paragraphs || 0)
                + ' · Títulos: ' + (stats.headings || 0)
                + ' · Listas: ' + (stats.lists || 0)
                + ' · Tablas: ' + (stats.tables || 0)
                + ' · Imágenes: ' + (stats.images || 0)
            );
        }
        if (plain) {
            lines.push('Texto plano extraído: ' + plain.length + ' caracteres');
        }
        if (messages && messages.length) {
            lines.push('Avisos:\n- ' + messages.join('\n- '));
        }
        lines.push('El contenido pasa por el mismo normalizador que pegar desde Word.');
        return lines.join('\n\n');
    }

    function populateWordImportSections() {
        var sel = document.getElementById('sgd-word-import-section');
        if (!sel) return;
        sel.innerHTML = '';
        var blank = document.createElement('option');
        blank.value = '';
        blank.textContent = '—';
        sel.appendChild(blank);
        (config.secciones || []).forEach(function (sec) {
            var opt = document.createElement('option');
            opt.value = sec.codigo;
            opt.textContent = sec.nombre || sec.codigo;
            sel.appendChild(opt);
        });
        updateWordImportModeUi();
    }

    function updateWordImportModeUi() {
        var modeEl = document.getElementById('sgd-word-import-mode');
        var wrap = document.getElementById('sgd-word-import-section-wrap');
        var sectionSel = document.getElementById('sgd-word-import-section');
        if (!modeEl || !wrap) return;
        var mode = modeEl.value;
        var isMap = mode === 'map';
        wrap.hidden = isMap;
        wrap.setAttribute('aria-hidden', isMap ? 'true' : 'false');
        if (!sectionSel) return;
        if (isMap) {
            sectionSel.value = '';
            sectionSel.disabled = true;
            return;
        }
        sectionSel.disabled = false;
        if (!sectionSel.value && activeEditor) {
            var cod = activeEditor.getAttribute('data-codigo');
            if (cod) sectionSel.value = cod;
        }
    }

    function showWordImportResult(data, onProgress) {
        onProgress = onProgress || setWordImportProgress;
        var lead = document.getElementById('sgd-word-import-lead');
        var options = document.getElementById('sgd-word-import-options');
        var statsEl = document.getElementById('sgd-word-import-stats');
        var preview = document.getElementById('sgd-word-import-preview');
        var confirmBtn = document.getElementById('sgd-word-import-confirm');
        var rawHtml = data.html || '';

        onProgress(86, 'Generando vista previa…');

        return yieldToMainThread().then(function () {
            var previewHtml = lightPrepareImportedHtml(rawHtml) || rawHtml;
            wordImportState = {
                rawHtml: rawHtml,
                previewHtml: previewHtml,
                plain: data.plain || '',
                stats: data.stats,
                messages: data.messages,
                source: data.source || 'browser',
                converter: data.converter || ''
            };

            onProgress(100, 'Listo para revisar');
            window.setTimeout(hideWordImportProgress, 400);

            if (lead) {
                var serverHint = (data.source === 'server')
                    ? ' (convertido en servidor; al importar será casi inmediato).'
                    : (rawHtml.length > 120000
                        ? ' (documento grande: la normalización en el navegador ocurre al pulsar Importar).'
                        : '');
                lead.textContent = 'Archivo convertido. Revise la vista previa y elija cómo importarlo.' + serverHint;
            }
            if (options) options.hidden = false;
            if (statsEl) {
                statsEl.textContent = formatWordImportStats(data.stats, data.messages, data.plain);
            }
            if (preview) preview.innerHTML = previewHtml;
            if (confirmBtn) confirmBtn.disabled = !previewHtml.trim();

            populateWordImportSections();
            updateWordImportModeUi();
        });
    }

    function wordImportShouldTryClient(data) {
        var msg = String((data && (data.error || data.message)) || '').toLowerCase();
        return msg.indexOf('zip') >= 0
            || msg.indexOf('unzip') >= 0
            || msg.indexOf('leer el word') >= 0
            || msg.indexOf('no se pudo abrir') >= 0;
    }

    function readFileArrayBufferWithProgress(file, onPartial) {
        return new Promise(function (resolve, reject) {
            var reader = new FileReader();
            reader.onprogress = function (ev) {
                if (ev.lengthComputable && onPartial) {
                    onPartial(ev.loaded / ev.total);
                }
            };
            reader.onload = function () {
                resolve(reader.result);
            };
            reader.onerror = function () {
                reject(reader.error || new Error('No se pudo leer el archivo'));
            };
            reader.readAsArrayBuffer(file);
        });
    }

    function mammothOptionsForFile() {
        return {
            ignoreEmptyParagraphs: true,
            convertImage: window.mammoth.images.imgElement(function () {
                return Promise.resolve({ src: '', alt: '' });
            })
        };
    }

    var mammothLoadPromise = null;

    function ensureMammothLoaded() {
        if (typeof window.mammoth !== 'undefined') {
            return Promise.resolve();
        }
        if (mammothLoadPromise) {
            return mammothLoadPromise;
        }
        mammothLoadPromise = new Promise(function (resolve, reject) {
            var script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/mammoth@1.8.0/mammoth.browser.min.js';
            script.crossOrigin = 'anonymous';
            script.onload = function () { resolve(); };
            script.onerror = function () {
                mammothLoadPromise = null;
                reject(new Error('No se pudo cargar el convertidor Word del navegador'));
            };
            document.head.appendChild(script);
        });
        return mammothLoadPromise;
    }

    function convertWordFileInBrowser(file, onProgress) {
        onProgress = onProgress || function () {};
        if (!file) {
            return Promise.resolve({
                ok: false,
                error: 'Archivo no válido.'
            });
        }

        onProgress(8, 'Cargando convertidor en el navegador…');
        return ensureMammothLoaded().then(function () {
            if (typeof window.mammoth === 'undefined') {
                return {
                    ok: false,
                    error: 'El navegador no puede convertir el Word (falta el convertidor local).'
                };
            }
            return convertWordFileInBrowserWithMammoth(file, onProgress);
        }).catch(function () {
            return { ok: false, error: 'No se pudo cargar el convertidor Word en el navegador.' };
        });
    }

    function convertWordFileInBrowserWithMammoth(file, onProgress) {
        onProgress = onProgress || function () {};

        var largeFile = file.size > 400000;
        onProgress(6, 'Leyendo archivo en el navegador…');
        return readFileArrayBufferWithProgress(file, function (ratio) {
            onProgress(6 + Math.round(ratio * 30), 'Leyendo archivo…');
        }).then(function (buffer) {
            onProgress(40, largeFile ? 'Convirtiendo documento grande…' : 'Convirtiendo Word a HTML…');
            startWordImportProgressPulse(40, largeFile ? 82 : 72, largeFile ? 'Convirtiendo documento grande…' : 'Convirtiendo Word a HTML…');
            return window.mammoth.convertToHtml({ arrayBuffer: buffer }, mammothOptionsForFile());
        }).then(function (result) {
            clearWordImportProgressTimer();
            onProgress(84, 'Preparando resultado…');
            var html = result.value || '';
            var plain = String(html).replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
            var messages = (result.messages || []).map(function (m) {
                return m.message || String(m);
            });
            messages.unshift('Convertido en el navegador (modo rápido; imágenes se procesan al importar).');
            if (largeFile) {
                messages.push('Documento grande: omitimos imágenes embebidas en esta fase para acelerar.');
            }

            return {
                ok: html.trim() !== '',
                html: html,
                plain: plain,
                stats: {
                    paragraphs: (html.match(/<p\b/gi) || []).length,
                    headings: (html.match(/<h[1-6]\b/gi) || []).length,
                    lists: (html.match(/<[uo]l\b/gi) || []).length,
                    tables: (html.match(/<table\b/gi) || []).length,
                    images: (html.match(/<img\b/gi) || []).length
                },
                messages: messages,
                message: 'Word convertido en el navegador.',
                source: 'browser'
            };
        }).catch(function () {
            return { ok: false, error: 'No se pudo leer el archivo Word en el navegador.' };
        });
    }

    function resolveWordImportUrl() {
        if (!config.importWordUrl) return '';
        try {
            return new URL(config.importWordUrl, window.location.href).href;
        } catch (e) {
            return config.importWordUrl;
        }
    }

    function resolveWordImportFetchUrl(token) {
        if (!token) return '';
        var base = config.importWordFetchUrl || '';
        if (!base) {
            base = resolveWordImportUrl().replace('elaboracionImportWord', 'elaboracionImportWordFetch');
        }
        if (!base) return '';
        try {
            var url = new URL(base, window.location.href);
            url.searchParams.set('token', token);
            return url.href;
        } catch (e) {
            var sep = base.indexOf('?') >= 0 ? '&' : '?';
            return base + sep + 'token=' + encodeURIComponent(token);
        }
    }

    function parseServerJsonResponse(text, status) {
        var trimmed = String(text || '').replace(/^\uFEFF/, '').trim();
        if (trimmed === '') return null;
        try {
            return JSON.parse(trimmed);
        } catch (e1) {
            var start = trimmed.indexOf('{');
            var end = trimmed.lastIndexOf('}');
            if (start >= 0 && end > start) {
                try {
                    return JSON.parse(trimmed.substring(start, end + 1));
                } catch (e2) { /* ignore */ }
            }
        }
        if (status === 403) {
            return { ok: false, error: 'Sin permiso para importar Word', _tryBrowser: true };
        }
        return null;
    }

    function fetchStagedWordHtml(meta, onProgress) {
        onProgress = onProgress || function () {};
        var fetchUrl = resolveWordImportFetchUrl(meta.import_token);
        if (!fetchUrl) {
            return Promise.resolve({ ok: false, error: 'No se pudo obtener el contenido convertido', _tryBrowser: true });
        }

        onProgress(94, 'Descargando contenido convertido…');

        return fetch(fetchUrl, {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (res) {
            if (!res.ok) {
                throw new Error('fetch-staged-' + res.status);
            }
            return res.text();
        }).then(function (html) {
            onProgress(98, 'Contenido listo');
            return {
                ok: html.trim() !== '',
                html: html,
                plain: meta.plain || '',
                stats: meta.stats || {},
                messages: meta.messages || [],
                message: meta.message || 'Word convertido correctamente.',
                source: 'server',
                converter: meta.converter || 'php-zip',
                staged: true,
                content_chars: meta.content_chars || 0
            };
        }).catch(function () {
            return { ok: false, error: 'No se pudo descargar el contenido convertido', _tryBrowser: true };
        });
    }

    function uploadWordFileToServer(file, onProgress) {
        onProgress = onProgress || function () {};
        var importUrl = resolveWordImportUrl();
        if (!importUrl || !file) {
            return Promise.resolve({ ok: false, error: 'Importación no disponible', _tryBrowser: true });
        }

        onProgress(4, config.wordImportServerZip
            ? 'Enviando al servidor (PHP Zip)…'
            : 'Preparando envío…');

        return new Promise(function (resolve) {
            var xhr = new XMLHttpRequest();
            var fd = new FormData();
            fd.append('archivo', file);
            fd.append('documento_id', String(config.documentoId || ''));

            xhr.open('POST', importUrl, true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.withCredentials = true;

            xhr.upload.addEventListener('progress', function (ev) {
                if (!ev.lengthComputable) return;
                clearWordImportProgressTimer();
                var uploadPct = 8 + Math.round((ev.loaded / ev.total) * 64);
                onProgress(uploadPct, config.wordImportServerZip
                    ? 'Subiendo al servidor (PHP Zip)…'
                    : 'Subiendo archivo…');
            });

            xhr.upload.addEventListener('loadend', function () {
                if (xhr.readyState < XMLHttpRequest.DONE) {
                    var waitLabel = config.wordImportServerZip
                        ? 'Convirtiendo Word en servidor (PHP Zip)…'
                        : 'Procesando documento en el servidor…';
                    startWordImportProgressPulse(76, 94, waitLabel);
                }
            });

            xhr.addEventListener('load', function () {
                clearWordImportProgressTimer();
                onProgress(92, config.wordImportServerZip
                    ? 'Finalizando conversión en servidor…'
                    : 'Procesando en el servidor…');
                var text = xhr.responseText || '';
                var status = xhr.status;
                var data = parseServerJsonResponse(text, status);
                if (!data) {
                    var snippet = text.replace(/\s+/g, ' ').trim().substring(0, 120);
                    resolve({
                        ok: false,
                        error: 'Respuesta inválida del servidor (' + status + ')'
                            + (snippet ? ': ' + snippet : ''),
                        _tryBrowser: config.wordImportServerZip !== true
                    });
                    return;
                }
                if (status < 200 || status >= 300) {
                    if (!data.error && !data.message) {
                        data.error = 'Error del servidor (' + status + ')';
                    }
                }
                if (data.ok && data.staged && data.import_token) {
                    fetchStagedWordHtml(data, onProgress).then(resolve);
                    return;
                }
                if (data.ok) {
                    onProgress(96, 'Finalizando…');
                }
                data._tryBrowser = !data.ok;
                resolve(data);
            });

            xhr.addEventListener('error', function () {
                clearWordImportProgressTimer();
                resolve({ ok: false, error: 'No se pudo contactar al servidor', _tryBrowser: true });
            });

            xhr.addEventListener('abort', function () {
                clearWordImportProgressTimer();
                resolve({ ok: false, error: 'Importación cancelada', _tryBrowser: true });
            });

            xhr.send(fd);
        });
    }

    function uploadWordFile(file, onProgress) {
        onProgress = onProgress || function () {};
        return uploadWordFileToServer(file, onProgress).then(function (data) {
            if (data.ok) {
                if (data.source === 'server') {
                    data.converter = data.converter || (config.wordImportServerZip ? 'php-zip' : 'server');
                }
                return data;
            }
            if (config.wordImportServerZip && !wordImportShouldTryClient(data)) {
                return {
                    ok: false,
                    error: data.error || data.message || 'El servidor no pudo procesar el Word.'
                };
            }
            if (!data._tryBrowser && !wordImportShouldTryClient(data)) {
                return data;
            }
            onProgress(12, 'Respaldo: convirtiendo en el navegador…');
            return convertWordFileInBrowser(file, onProgress).then(function (clientData) {
                if (clientData.ok) {
                    return clientData;
                }
                return {
                    ok: false,
                    error: clientData.error || data.error || data.message || 'No se pudo importar el Word'
                };
            });
        });
    }

    function initWordImport() {
        var trigger = document.getElementById('sgd-word-import-btn');
        var input = document.getElementById('sgd-word-import-input');
        var modal = document.getElementById('sgd-word-import-modal');
        var confirmBtn = document.getElementById('sgd-word-import-confirm');
        var modeEl = document.getElementById('sgd-word-import-mode');

        if (!input) return;

        if (trigger && trigger.tagName === 'BUTTON') {
            trigger.addEventListener('mousedown', function (ev) { ev.preventDefault(); });
            trigger.addEventListener('click', function (ev) {
                ev.preventDefault();
                if (!config.importWordUrl) {
                    setStatus('Importación Word no disponible para este documento.', 'warn');
                    return;
                }
                input.click();
            });
        } else if (trigger && !config.importWordUrl) {
            trigger.addEventListener('click', function (ev) {
                ev.preventDefault();
                setStatus('Importación Word no disponible para este documento.', 'warn');
            });
        }

        input.addEventListener('change', function () {
            var file = input.files && input.files[0];
            input.value = '';
            if (!file) return;

            if (!config.importWordUrl) {
                setStatus('Importación Word no disponible para este documento.', 'warn');
                return;
            }

            openWordImportModal();
            var lead = document.getElementById('sgd-word-import-lead');
            var options = document.getElementById('sgd-word-import-options');
            var confirmBtnLocal = document.getElementById('sgd-word-import-confirm');
            if (options) options.hidden = true;
            if (confirmBtnLocal) confirmBtnLocal.disabled = true;
            setWordImportProgress(2, 'Leyendo ' + file.name + '…');

            uploadWordFile(file, setWordImportProgress).then(function (data) {
                if (!data.ok) {
                    hideWordImportProgress();
                    closeWordImportModal();
                    setStatus(data.error || data.message || 'No se pudo importar el Word', 'warn');
                    return;
                }
                if (data.source === 'server' && data.converter === 'php-zip') {
                    setStatus('Word convertido en servidor (PHP Zip)', 'hint');
                } else if (data.source === 'browser') {
                    setStatus('Word leído en el navegador (respaldo)', 'hint');
                }
                showWordImportResult(data, setWordImportProgress);
            });
        });

        if (modeEl) {
            modeEl.addEventListener('change', updateWordImportModeUi);
        }

        if (confirmBtn) {
            confirmBtn.addEventListener('click', function () {
                if (!wordImportState || !wordImportState.rawHtml) return;
                var mode = modeEl ? modeEl.value : 'cursor';
                var sectionSel = document.getElementById('sgd-word-import-section');
                var sectionCodigo = sectionSel ? sectionSel.value : '';
                var options = document.getElementById('sgd-word-import-options');
                confirmBtn.disabled = true;
                if (options) options.hidden = true;
                wordImportProgressStartedAt = Date.now();
                wordImportProgressLastPct = 0;
                setWordImportProgress(5, 'Normalizando para importar…');
                startWordImportWaitPulse();

                prepareImportedHtmlForInsert(wordImportState.rawHtml, {
                    source: wordImportState.source || 'browser',
                    onProgress: setWordImportProgress
                })
                    .then(function (normalized) {
                        return applyImportedWordContent(
                            normalized,
                            mode,
                            sectionCodigo,
                            true,
                            setWordImportProgress
                        );
                    })
                    .then(function (ok) {
                        confirmBtn.disabled = false;
                        hideWordImportProgress();
                        if (ok) closeWordImportModal();
                        else if (options) options.hidden = false;
                    })
                    .catch(function (err) {
                        confirmBtn.disabled = false;
                        hideWordImportProgress();
                        if (options) options.hidden = false;
                        setStatus(
                            'Error al importar el Word'
                                + (err && err.message ? ': ' + err.message : ''),
                            'warn'
                        );
                    });
            });
        }

        if (modal) {
            modal.querySelectorAll('[data-word-import-close]').forEach(function (el) {
                el.addEventListener('click', closeWordImportModal);
            });
        }
    }

    function initImageToolbar() {
        var btn = document.getElementById('sgd-img-insert-btn');
        var input = document.getElementById('sgd-img-file-input');
        if (btn && input) {
            btn.addEventListener('mousedown', function (ev) { ev.preventDefault(); });
            btn.addEventListener('click', function () {
                if (!activeEditor) {
                    setStatus('Coloque el cursor en una sección antes de insertar', 'warn');
                    return;
                }
                input.click();
            });
            input.addEventListener('change', function () {
                var file = input.files && input.files[0];
                input.value = '';
                if (!file || !activeEditor) return;
                uploadElabImageFile(file).then(function (res) {
                    if (res.ok && res.path) insertImageHtml(activeEditor, res.path);
                    else setStatus(res.error || res.message || 'Error al subir imagen', 'warn');
                });
            });
        }

        form.querySelectorAll('[data-img-align]').forEach(function (alignBtn) {
            alignBtn.addEventListener('mousedown', function (ev) { ev.preventDefault(); });
            alignBtn.addEventListener('click', function () {
                var img = getActiveImage();
                if (!img) {
                    setStatus('Haga clic en una imagen para alinearla', 'warn');
                    return;
                }
                var align = alignBtn.getAttribute('data-img-align');
                img.classList.remove('is-left', 'is-right');
                if (align === 'left') img.classList.add('is-left');
                else if (align === 'right') img.classList.add('is-right');
                syncEditor(activeEditor);
                markDirty();
            });
        });

        form.addEventListener('click', function (ev) {
            var img = ev.target.closest('img.sgd-word-img');
            if (img) {
                var ed = img.closest('.sgd-word-editor[contenteditable="true"]');
                if (ed) {
                    focusEditor(ed);
                    markActiveImage(img);
                }
                return;
            }
            if (!ev.target.closest('[data-img-align]')) {
                clearActiveImage();
            }
        });
    }

    function handleEditorPaste(ev) {
        if (!config.canEdit) return;
        var editor = ev.target.closest('.sgd-word-editor[contenteditable="true"]');
        if (!editor) return;

        ev.preventDefault();
        var cd = ev.clipboardData || window.clipboardData;
        if (!cd) return;

        var htmlClip = cd.getData('text/html');
        var plain = cd.getData('text/plain');

        var clipImage = getClipboardImageFile(cd);
        if (clipImage && clipboardHasOnlyImage(cd, plain)) {
            uploadElabImageFile(clipImage).then(function (res) {
                if (res.ok && res.path) insertImageHtml(editor, res.path);
                else setStatus(res.error || res.message || 'No se pudo subir la imagen', 'warn');
            });
            return;
        }

        var tableHtml = extractTableHtmlFromClipboard(htmlClip);
        if (!tableHtml) {
            var rows = plainTextToTableRows(plain);
            if (rows) {
                tableHtml = buildTableHtml(rows, true);
            }
        }

        if (tableHtml) {
            insertHtmlIntoEditor(editor, tableHtml);
            syncEditor(editor);
            markDirty();
            scheduleOutlineRebuild();
            return;
        }

        var docHtml = null;
        if (plainTextLooksLikeList(plain) && !htmlClipHasInlineFormat(htmlClip)) {
            docHtml = plainTextToDocumentHtml(plain);
        }
        if (!docHtml) {
            docHtml = extractDocumentHtmlFromClipboard(htmlClip);
        }
        if (!docHtml) {
            docHtml = plainTextToDocumentHtml(plain);
        }

        if (docHtml) {
            finishPasteHtml(editor, docHtml);
            return;
        }

        insertHtmlIntoEditor(editor, escapeHtml(plain).replace(/\r\n|\n|\r/g, '<br>'));
        syncEditor(editor);
        markDirty();
    }

    function isEditorEmpty(el) {
        return !hasEditorContent(el);
    }

    function syncEditor(el) {
        if (!el) return;
        var wrap = el.closest('.sgd-word-editor-wrap') || el.closest('.sgd-anexo-bloque');
        if (!wrap) return;
        var hidden = wrap.querySelector('.sgd-word-hidden, .sgd-word-hidden-anexo');
        if (!hidden) return;
        hidden.value = hasEditorContent(el) ? el.innerHTML : '';
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
        updateTableToolbar();
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

    form.addEventListener('paste', handleEditorPaste);

    form.querySelectorAll('.sgd-word-editor[contenteditable="true"]').forEach(function (ed) {
        cleanEditorLists(ed);
        ed.addEventListener('focus', function () { focusEditor(ed); });
        ed.addEventListener('input', function () {
            syncEditor(ed);
            markDirty();
            scheduleUndoCapture();
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
        var tag = headingTags[nivelIndex] || 'p';

        activeEditor.focus();
        try {
            document.execCommand('formatBlock', false, tag);
        } catch (e) { /* ignore */ }
        syncEditor(activeEditor);
        markDirty();
        scheduleOutlineRebuild();
    }

    function ensureEditorHeadingIds(editor, secCod, editorIndex) {
        if (!editor) return;
        var selector = headingTags.join(',');
        if (!selector) return;
        editor.querySelectorAll(selector).forEach(function (heading, hi) {
            heading.id = 'sgd-h-' + secCod + '-' + editorIndex + '-' + hi;
        });
    }

    function rebuildOutlineNav() {
        var outlineTags = headingTags.slice(outlineMinIndex);
        form.querySelectorAll('.sgd-word-nav-sublist').forEach(function (ul) {
            if (!outlineTags.length) {
                ul.innerHTML = '';
                return;
            }
        });
        if (!outlineTags.length) return;

        var selector = outlineTags.join(',');

        form.querySelectorAll('.sgd-word-nav-group[data-nav-group]').forEach(function (group) {
            var cod = group.getAttribute('data-nav-group');
            var sublist = group.querySelector('.sgd-word-nav-sublist');
            var block = document.getElementById('sec-' + cod);
            if (!sublist || !block) return;

            sublist.innerHTML = '';
            var editors = block.querySelectorAll('.sgd-word-editor');
            if (!editors.length) return;

            editors.forEach(function (editor, editorIndex) {
                ensureEditorHeadingIds(editor, cod, editorIndex);
                editor.querySelectorAll(selector).forEach(function (heading) {
                    var text = (heading.textContent || '').replace(/\u00a0/g, ' ').trim();
                    if (!text) return;

                    var tag = heading.tagName.toLowerCase();
                    var levelIndex = headingTags.indexOf(tag);
                    if (levelIndex < outlineMinIndex) return;

                    var li = document.createElement('li');
                    var link = document.createElement('a');
                    link.href = '#' + heading.id;
                    link.className = 'sgd-word-nav-subitem sgd-word-nav-subitem--l' + (levelIndex + 1);
                    link.textContent = text;
                    li.appendChild(link);
                    sublist.appendChild(li);
                });
            });
        });
    }

    function scheduleOutlineRebuild() {
        if (outlineTimer) window.clearTimeout(outlineTimer);
        outlineTimer = window.setTimeout(rebuildOutlineNav, 150);
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

    form.addEventListener('submit', function (ev) {
        if (!config.canEdit) return;
        ev.preventDefault();
        saveDocument(false);
    });

    document.addEventListener('keydown', function (ev) {
        if (!config.canEdit) return;
        if ((ev.ctrlKey || ev.metaKey) && ev.key === 's') {
            ev.preventDefault();
            saveDocument(false);
            return;
        }
        if ((ev.ctrlKey || ev.metaKey) && ev.key === 'z' && !ev.shiftKey) {
            if (restoreUndoSnapshot()) {
                ev.preventDefault();
            }
            return;
        }
        if (!activeEditor) return;
        if (ev.ctrlKey || ev.metaKey) {
            if (ev.key === 'b') { ev.preventDefault(); exec('bold'); }
            if (ev.key === 'i') { ev.preventDefault(); exec('italic'); }
            if (ev.key === 'u') { ev.preventDefault(); exec('underline'); }
        }
    });

    /* Navegación — scroll suave a sección o título del texto */
    var navList = document.getElementById('sgd-word-nav-list');
    if (navList) {
        navList.addEventListener('click', function (ev) {
            var sub = ev.target.closest('.sgd-word-nav-subitem');
            if (sub) {
                ev.preventDefault();
                var subId = (sub.getAttribute('href') || '').replace('#', '');
                var subTarget = document.getElementById(subId);
                if (!subTarget) return;
                subTarget.scrollIntoView({ behavior: 'smooth', block: 'start' });
                navList.querySelectorAll('.sgd-word-nav-item.is-current, .sgd-word-nav-subitem.is-current').forEach(function (n) {
                    n.classList.remove('is-current');
                });
                sub.classList.add('is-current');
                return;
            }

            var link = ev.target.closest('.sgd-word-nav-item');
            if (!link) return;
            var id = (link.getAttribute('href') || '').replace('#', '');
            var target = document.getElementById(id);
            if (!target) return;
            ev.preventDefault();
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            navList.querySelectorAll('.sgd-word-nav-item.is-current, .sgd-word-nav-subitem.is-current').forEach(function (n) {
                n.classList.remove('is-current');
            });
            link.classList.add('is-current');
        });
    }

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

        if (hiddenWrap) {
            hiddenWrap.querySelectorAll('input[name="referenciados[]"]').forEach(function (inp) {
                selected[inp.value] = true;
            });
        }

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
            scheduleUndoCapture();
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
    rebuildOutlineNav();

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
    initTableToolbar();
    initImageToolbar();
    initWordImport();
    syncAllEditors();
    tryRestoreBackupOnLoad();
    pushUndoSnapshot();
    restoreScrollState();

    if (config.canEdit) {
        setStatus('Ctrl+S guardar · autoguardado ~45 s', 'hint');
        window.setTimeout(function () {
            if (!dirty && statusEl && statusEl.classList.contains('sgd-word-status-hint')) {
                statusEl.textContent = '';
            }
        }, 4000);

        window.addEventListener('beforeunload', function (ev) {
            if (dirty && !saveInProgress) {
                captureScrollState();
                ev.preventDefault();
                ev.returnValue = '';
            }
        });

        var scrollCaptureTimer = null;
        window.addEventListener('scroll', function () {
            if (scrollCaptureTimer) window.clearTimeout(scrollCaptureTimer);
            scrollCaptureTimer = window.setTimeout(captureScrollState, 300);
        }, { passive: true });
    }
})();
