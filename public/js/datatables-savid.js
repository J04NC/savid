/**
 * SAVID — DataTables 2 + jQuery (dom, botones, filtros por columna)
 */
(function (global) {
    'use strict';

    if (typeof jQuery === 'undefined' || typeof DataTable === 'undefined') {
        console.warn('[SAVID DataTables] Falta jQuery o DataTables en el layout.');
        return;
    }

    var INIT_FLAG = 'savidDtInit';
    var DEBOUNCE_MS = 150;
    var debounceTimer = null;

    var SPANISH = {
        emptyTable: 'No hay datos disponibles',
        info: 'Mostrando _START_ a _END_ de _TOTAL_ registros',
        infoEmpty: 'Sin registros',
        infoFiltered: '(filtrados de _MAX_ en total)',
        lengthMenu: 'Mostrar _MENU_ registros',
        loadingRecords: 'Cargando…',
        processing: 'Procesando…',
        search: 'Buscar:',
        searchPlaceholder: 'Buscar en la tabla…',
        zeroRecords: 'No se encontraron coincidencias',
        paginate: {
            first: 'Primera',
            last: 'Última',
            next: 'Siguiente ›',
            previous: '‹ Anterior'
        },
        buttons: {
            copy: 'Copiar',
            copyTitle: 'Copiado al portapapeles',
            csv: 'CSV',
            excel: 'Excel',
            print: 'Imprimir',
            colvis: 'Columnas',
            colvisRestore: 'Restaurar columnas'
        }
    };

    function hasButtonsPlugin() {
        return !!(jQuery.fn.dataTable && jQuery.fn.dataTable.Buttons);
    }

    function hasColVisButton() {
        var ext = jQuery.fn.dataTable && jQuery.fn.dataTable.ext;
        return !!(ext && ext.buttons && ext.buttons.colvis);
    }

    function isDataTableInstance(tableEl) {
        if (typeof DataTable.isDataTable === 'function' && DataTable.isDataTable(tableEl)) {
            return true;
        }
        return !!(jQuery.fn.dataTable && jQuery.fn.dataTable.isDataTable(tableEl));
    }

    function getApi(tableEl) {
        if (typeof DataTable.api === 'function') {
            return DataTable.api(tableEl);
        }
        return jQuery(tableEl).DataTable();
    }

    function isNoFilterTh(th) {
        if (!th) return true;
        return th.classList.contains('no-dt-filter')
            || th.classList.contains('crud-table-actions')
            || th.classList.contains('auditoria-th-actions')
            || th.getAttribute('aria-label') === 'Acciones';
    }

    function hideLegacySearch(tableEl) {
        var wrap = tableEl.closest('.module-container')
            || tableEl.closest('.sgd-documentos-page')
            || tableEl.closest('.auditoria-page')
            || document;
        wrap.querySelectorAll('.savid-dt-legacy-search').forEach(function (el) {
            el.hidden = true;
        });
        var legacy = document.getElementById('crudTableSearch');
        if (legacy && tableEl.closest('.crud-table')) {
            var parent = legacy.closest('.crud-list-search');
            if (parent) parent.hidden = true;
        }
    }

    function addColumnFilters(api, tableEl) {
        var thead = tableEl.querySelector('thead');
        if (!thead || thead.querySelector('.savid-dt-filters')) {
            return;
        }

        var headerRow = thead.querySelector('tr');
        if (!headerRow) return;

        var headers = headerRow.querySelectorAll('th');
        var filterRow = document.createElement('tr');
        filterRow.className = 'savid-dt-filters';

        headers.forEach(function (th, index) {
            var fth = document.createElement('th');
            if (!isNoFilterTh(th)) {
                var input = document.createElement('input');
                input.type = 'search';
                input.className = 'savid-dt-col-filter form-input';
                input.placeholder = 'Filtrar…';
                input.setAttribute('aria-label', 'Filtrar columna');
                input.addEventListener('input', function () {
                    api.column(index).search(input.value).draw();
                });
                fth.appendChild(input);
            }
            filterRow.appendChild(fth);
        });

        thead.appendChild(filterRow);
    }

    /**
     * dom: B=botones, l=mostrar N, f=búsqueda global, t=tabla, i=info, p=paginación
     */
    function buildDom(paging, withButtons) {
        if (!paging) {
            return withButtons ? 'Bft' : 'ft';
        }
        if (withButtons) {
            return '<"savid-dt-bar-top"B><"savid-dt-bar-top2"lf>rt<"savid-dt-bar-bottom"ip>';
        }
        return '<"savid-dt-bar-top2"lf>rt<"savid-dt-bar-bottom"ip>';
    }

    /** Scroll vertical del recuadro solo con muchas filas por página (p. ej. 50+). */
    function updateShellScroll(tableEl, api) {
        var shell = tableEl.closest('.savid-dt-shell');
        if (!shell || !api) return;
        var at = parseInt(tableEl.getAttribute('data-dt-scroll-at') || '50', 10);
        if (isNaN(at) || at < 1) {
            at = 50;
        }
        shell.classList.toggle('savid-dt-scrollable', api.page.len() >= at);
    }

    function buildOptions(tableEl) {
        var paging = tableEl.getAttribute('data-dt-paging') !== 'false';
        var pageLength = parseInt(tableEl.getAttribute('data-dt-page-length') || '10', 10);
        if (![10, 50, 100, 1000].includes(pageLength)) {
            pageLength = 10;
        }

        var withButtons = hasButtonsPlugin();
        var order = [[0, 'asc']];
        var orderAttr = tableEl.getAttribute('data-dt-order');
        if (orderAttr) {
            try {
                order = JSON.parse(orderAttr);
            } catch (e) {
                order = [[0, 'asc']];
            }
        }

        var opts = {
            language: SPANISH,
            pageLength: pageLength,
            lengthMenu: [[10, 50, 100, 1000], [10, 50, 100, 1000]],
            paging: paging,
            searching: true,
            ordering: true,
            info: paging,
            lengthChange: paging,
            autoWidth: false,
            order: order,
            dom: buildDom(paging, withButtons),
            columnDefs: [
                { targets: 'no-dt-order', orderable: false },
                { targets: '.crud-table-actions', orderable: false, searchable: false },
                { targets: '.auditoria-th-actions', orderable: false, searchable: false }
            ]
        };

        if (withButtons) {
            opts.buttons = [
                {
                    extend: 'excelHtml5',
                    text: '📊 Excel',
                    className: 'savid-dt-btn',
                    title: document.title || 'SAVID',
                    exportOptions: { columns: ':visible:not(.no-export)' }
                },
                {
                    extend: 'csvHtml5',
                    text: '📄 CSV',
                    className: 'savid-dt-btn',
                    exportOptions: { columns: ':visible:not(.no-export)' }
                },
                {
                    extend: 'copyHtml5',
                    text: '📋 Copiar',
                    className: 'savid-dt-btn',
                    exportOptions: { columns: ':visible:not(.no-export)' }
                },
                {
                    extend: 'print',
                    text: '🖨 Imprimir',
                    className: 'savid-dt-btn',
                    exportOptions: { columns: ':visible:not(.no-export)' }
                }
            ];
            if (hasColVisButton()) {
                opts.buttons.push({
                    extend: 'colvis',
                    text: '👁 Columnas',
                    className: 'savid-dt-btn',
                    columns: ':not(.no-export):not(.crud-table-actions):not(.auditoria-th-actions)'
                });
            }
        }

        return opts;
    }

    function wrapTable(tableEl) {
        if (tableEl.closest('.savid-dt-shell')) {
            return;
        }
        var shell = document.createElement('div');
        shell.className = 'savid-dt-shell';
        tableEl.parentNode.insertBefore(shell, tableEl);
        shell.appendChild(tableEl);
    }

    function markReady(tableEl) {
        tableEl.dataset[INIT_FLAG] = '1';
        tableEl.classList.add('savid-dt-active');
        var shell = tableEl.closest('.savid-dt-shell');
        if (shell) {
            shell.classList.add('savid-dt-ready');
        }
    }

    function createInstance(tableEl) {
        var opts = buildOptions(tableEl);
        return new DataTable(tableEl, opts);
    }

    var TABLE_SELECTORS = [
        '.crud-table > table',
        'table.savid-datatable',
        'table.auditoria-table',
        'table.sesiones-table',
        'table.sgd-doc-table',
        'table.role-table',
        'table.tercero-ident-table',
        'table.item-acciones-table',
        'table.empresa-sedes-table',
        'table.empresa-usuarios-table',
        'table.sgd-ref-table'
    ];

    function isWordEditorTable(tableEl) {
        if (!tableEl) return true;
        if (tableEl.classList.contains('sgd-word-table')) return true;
        return !!tableEl.closest('.sgd-word-editor, #sgd-word-import-preview, #sgd-word-import-modal');
    }

    function matchesDataTableSelector(tableEl) {
        if (!tableEl || tableEl.tagName !== 'TABLE') return false;
        for (var i = 0; i < TABLE_SELECTORS.length; i++) {
            var sel = TABLE_SELECTORS[i];
            var sep = sel.indexOf(' > ');
            if (sep >= 0) {
                var parentSel = sel.slice(0, sep).trim();
                var childSel = sel.slice(sep + 3).trim();
                if (tableEl.matches(childSel) && tableEl.parentElement && tableEl.parentElement.matches(parentSel)) {
                    return true;
                }
            } else if (tableEl.matches(sel)) {
                return true;
            }
        }
        return false;
    }

    function isManagedDataTable(tableEl) {
        if (!tableEl || tableEl.classList.contains('no-datatable')) return false;
        if (isWordEditorTable(tableEl)) return false;
        return matchesDataTableSelector(tableEl);
    }

    function initTable(tableEl) {
        if (!tableEl || tableEl.tagName !== 'TABLE') return null;
        if (!isManagedDataTable(tableEl)) return null;

        if (tableEl.dataset[INIT_FLAG] === '1' || isDataTableInstance(tableEl)) {
            tableEl.dataset[INIT_FLAG] = '1';
            return isDataTableInstance(tableEl) ? getApi(tableEl) : null;
        }

        var tbody = tableEl.querySelector('tbody');
        if (!tbody) return null;

        var rows = tbody.querySelectorAll('tr');
        var onlyEmpty = rows.length === 1 && rows[0].querySelector('td[colspan]');
        if (rows.length === 0 || (onlyEmpty && tableEl.classList.contains('skip-dt-empty'))) {
            return null;
        }

        tableEl.dataset[INIT_FLAG] = '1';
        wrapTable(tableEl);
        hideLegacySearch(tableEl);

        var api;
        try {
            api = createInstance(tableEl);
        } catch (err) {
            console.warn('[SAVID DataTables] Error al inicializar:', err);
            delete tableEl.dataset[INIT_FLAG];
            return null;
        }

        markReady(tableEl);
        addColumnFilters(api, tableEl);
        updateShellScroll(tableEl, api);

        api.on('length.dt', function () {
            updateShellScroll(tableEl, api);
        });

        api.on('draw', function () {
            cleanupDtButtonOverlays();
            document.dispatchEvent(new CustomEvent('savid-datatable-draw', {
                detail: { table: tableEl, api: api }
            }));
        });

        return api;
    }

    function collectTables(root) {
        root = root || document;
        var seen = new Set();
        var out = [];

        TABLE_SELECTORS.forEach(function (sel) {
            root.querySelectorAll(sel).forEach(function (t) {
                if (!isManagedDataTable(t)) return;
                if (seen.has(t)) return;
                seen.add(t);
                out.push(t);
            });
        });

        return out;
    }

    function cleanupDtButtonOverlays() {
        if (!document.querySelector('div.dt-button-collection')) {
            document.querySelectorAll('div.dt-button-background').forEach(function (bg) {
                bg.remove();
            });
        }
    }

    function initAll(root) {
        collectTables(root).forEach(initTable);
        cleanupDtButtonOverlays();
    }

    function collectTablesFromMutations(mutations) {
        var seen = new Set();
        var out = [];

        function addTable(t) {
            if (!isManagedDataTable(t)) return;
            if (seen.has(t)) return;
            if (t.dataset[INIT_FLAG] === '1' || isDataTableInstance(t)) return;
            seen.add(t);
            out.push(t);
        }

        mutations.forEach(function (mutation) {
            mutation.addedNodes.forEach(function (node) {
                if (node.nodeType !== 1) return;
                if (node.tagName === 'TABLE') {
                    addTable(node);
                    return;
                }
                if (node.querySelectorAll) {
                    collectTables(node).forEach(addTable);
                }
            });
        });

        return out;
    }

    document.addEventListener('DOMContentLoaded', function () {
        initAll(document);

        document.addEventListener('click', function () {
            window.setTimeout(cleanupDtButtonOverlays, 0);
        });

        var observer = new MutationObserver(function (mutations) {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(function () {
                collectTablesFromMutations(mutations).forEach(initTable);
            }, DEBOUNCE_MS);
        });
        observer.observe(document.body, { childList: true, subtree: true });
    });

    global.SavidDataTables = {
        init: initTable,
        initAll: initAll,
        language: SPANISH
    };
})(window);
