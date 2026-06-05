/**
 * Autocomplete local para pantallas SGD (misma UX que crud-catalog, datos en JSON embebido).
 */
(function (global) {
    'use strict';

    var MAX_ITEMS = 40;
    var catalogs = {};

    function norm(s) {
        return String(s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    }

    function formatLabel(item, key) {
        if (key === 'documentos') {
            var code = item.codigo_display || '';
            var name = item.nombre || '';
            return code ? (code + ' — ' + name) : name;
        }
        var codigo = item.codigo || '';
        var nombre = item.nombre || '';
        return codigo ? (codigo + ' — ' + nombre) : nombre;
    }

    function itemCodigo(item, key) {
        if (key === 'documentos') {
            return String(item.codigo_display || '');
        }
        return String(item.codigo || '');
    }

    function getParentValues(wrap, form) {
        var parents = [];
        try {
            parents = JSON.parse(wrap.getAttribute('data-sgd-catalog-parents') || '[]');
        } catch (e) {
            parents = [];
        }
        if (!Array.isArray(parents)) {
            parents = [];
        }
        var out = {};
        parents.forEach(function (pName) {
            var el = form.querySelector('[name="' + pName.replace(/"/g, '') + '"]');
            out[pName] = el ? String(el.value || '').trim() : '';
        });
        return { names: parents, values: out };
    }

    function filterItems(key, items, query, parentCtx) {
        var q = norm(query);
        var filtered = items.slice();

        if (key === 'subseries' && parentCtx.values.serie_id) {
            var sid = parentCtx.values.serie_id;
            filtered = filtered.filter(function (it) {
                return String(it.serie_id) === sid;
            });
        }

        if (q) {
            filtered = filtered.filter(function (it) {
                var label = norm(formatLabel(it, key));
                var codigo = norm(itemCodigo(it, key));
                return label.indexOf(q) >= 0 || codigo.indexOf(q) >= 0;
            });
        }

        return filtered.slice(0, MAX_ITEMS);
    }

    function getSelectableItems(dd) {
        return Array.prototype.slice.call(dd.querySelectorAll('li:not(.crud-catalog-hint)'));
    }

    function initWrap(wrap, form) {
        var key = wrap.getAttribute('data-sgd-catalog-key');
        var hid = wrap.querySelector('.sgd-local-catalog-id');
        var searchEl = wrap.querySelector('.sgd-local-catalog-search');
        var dd = wrap.querySelector('.crud-catalog-dropdown');
        if (!key || !hid || !searchEl || !dd || !catalogs[key]) {
            return;
        }

        var allowEmpty = wrap.getAttribute('data-sgd-catalog-allow-empty') === '1';
        var submitOnPick = wrap.getAttribute('data-sgd-catalog-submit-on-pick') === '1';
        var items = catalogs[key];
        var activeIdx = -1;
        var debounceTimer = null;

        function clearActive() {
            dd.querySelectorAll('li.crud-catalog-option-active').forEach(function (li) {
                li.classList.remove('crud-catalog-option-active');
            });
        }

        function renderActive() {
            var selectable = getSelectableItems(dd);
            clearActive();
            if (activeIdx >= 0 && activeIdx < selectable.length) {
                selectable[activeIdx].classList.add('crud-catalog-option-active');
                selectable[activeIdx].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            }
        }

        function applyChoice(id, label) {
            hid.value = id ? String(id) : '';
            searchEl.value = label || '';
            dd.hidden = true;
            dd.innerHTML = '';
            activeIdx = -1;
            hid.dispatchEvent(new Event('change', { bubbles: true }));
            if (submitOnPick && form) {
                form.submit();
            }
        }

        function renderDropdown() {
            var parentCtx = getParentValues(wrap, form);
            var missingParent = false;
            if (key === 'subseries' && parentCtx.names.indexOf('serie_id') >= 0 && !parentCtx.values.serie_id) {
                missingParent = true;
            }

            dd.innerHTML = '';
            activeIdx = -1;

            if (missingParent) {
                var hint = document.createElement('li');
                hint.className = 'crud-catalog-hint';
                hint.textContent = 'Seleccione primero la serie documental.';
                dd.appendChild(hint);
                dd.hidden = false;
                return;
            }

            var list = filterItems(key, items, searchEl.value, parentCtx);

            if (allowEmpty && !searchEl.value.trim()) {
                var emptyLi = document.createElement('li');
                emptyLi.setAttribute('data-catalog-id', '');
                emptyLi.setAttribute('data-catalog-nombre', '— Sin selección —');
                emptyLi.textContent = '— Sin selección —';
                dd.appendChild(emptyLi);
            }

            if (!list.length) {
                var noLi = document.createElement('li');
                noLi.className = 'crud-catalog-hint';
                noLi.textContent = searchEl.value.trim() ? 'Sin coincidencias.' : 'Escriba para buscar…';
                dd.appendChild(noLi);
                dd.hidden = false;
                return;
            }

            list.forEach(function (it) {
                var li = document.createElement('li');
                var label = formatLabel(it, key);
                li.setAttribute('data-catalog-id', String(it.id));
                li.setAttribute('data-catalog-nombre', label);
                li.setAttribute('data-catalog-codigo', itemCodigo(it, key));
                li.textContent = label;
                dd.appendChild(li);
            });

            dd.hidden = false;
        }

        function scheduleRender() {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(renderDropdown, 120);
        }

        searchEl.addEventListener('focus', function () {
            scheduleRender();
        });

        searchEl.addEventListener('input', function () {
            if (!searchEl.value.trim() && !allowEmpty) {
                hid.value = '';
                hid.dispatchEvent(new Event('change', { bubbles: true }));
            }
            scheduleRender();
        });

        searchEl.addEventListener('keydown', function (e) {
            var selectable = getSelectableItems(dd);
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (dd.hidden) scheduleRender();
                if (!selectable.length) return;
                activeIdx = activeIdx < selectable.length - 1 ? activeIdx + 1 : 0;
                renderActive();
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (!selectable.length) return;
                activeIdx = activeIdx > 0 ? activeIdx - 1 : selectable.length - 1;
                renderActive();
            } else if (e.key === 'Enter') {
                if (!dd.hidden && activeIdx >= 0 && selectable[activeIdx]) {
                    e.preventDefault();
                    var li = selectable[activeIdx];
                    applyChoice(li.getAttribute('data-catalog-id'), li.getAttribute('data-catalog-nombre'));
                }
            } else if (e.key === 'Escape') {
                dd.hidden = true;
            }
        });

        dd.addEventListener('mousedown', function (e) {
            var li = e.target.closest('li');
            if (!li || li.classList.contains('crud-catalog-hint')) return;
            e.preventDefault();
            applyChoice(li.getAttribute('data-catalog-id'), li.getAttribute('data-catalog-nombre'));
        });

        hid.addEventListener('change', function () {
            if (!hid.value) {
                if (!allowEmpty) {
                    searchEl.value = '';
                }
                return;
            }
            var found = items.find(function (it) {
                return String(it.id) === String(hid.value);
            });
            if (found) {
                searchEl.value = formatLabel(found, key);
            }
        });

        var parentCtx = getParentValues(wrap, form);
        parentCtx.names.forEach(function (pName) {
            var pel = form.querySelector('[name="' + pName.replace(/"/g, '') + '"]');
            if (!pel) return;
            pel.addEventListener('change', function () {
                if (key === 'subseries') {
                    hid.value = '';
                    searchEl.value = '';
                    dd.hidden = true;
                    dd.innerHTML = '';
                    hid.dispatchEvent(new Event('change', { bubbles: true }));
                }
            });
        });
    }

    function initFromJsonElement(jsonElId, rootSelector) {
        var jsonEl = document.getElementById(jsonElId);
        if (!jsonEl) return;

        try {
            catalogs = JSON.parse(jsonEl.textContent || '{}');
        } catch (e) {
            catalogs = {};
        }

        var root = rootSelector ? document.querySelector(rootSelector) : document;
        if (!root) return;

        root.querySelectorAll('.sgd-local-catalog-wrap').forEach(function (wrap) {
            initWrap(wrap, wrap.closest('form') || document);
        });

        if (!global.__sgdLocalCatalogOutside) {
            global.__sgdLocalCatalogOutside = true;
            document.addEventListener('click', function (ev) {
                document.querySelectorAll('.sgd-local-catalog-wrap').forEach(function (w) {
                    if (!w.contains(ev.target)) {
                        var d = w.querySelector('.crud-catalog-dropdown');
                        if (d) d.hidden = true;
                    }
                });
            });
        }
    }

    global.SgdLocalCatalog = {
        init: initFromJsonElement,
        formatLabel: formatLabel,
        itemCodigo: itemCodigo
    };
})(window);
