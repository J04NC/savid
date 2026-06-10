/**
 * Tooltips de ayuda en Configuración SGD (hover, foco y toque).
 */
(function () {
    'use strict';

    const WRAP_SELECTOR = '[data-sgd-help]';

    function closeAll(except) {
        document.querySelectorAll(WRAP_SELECTOR).forEach((wrap) => {
            if (except && wrap === except) {
                return;
            }
            wrap.classList.remove('is-open');
            const btn = wrap.querySelector('.sgd-info');
            if (btn) {
                btn.setAttribute('aria-expanded', 'false');
            }
        });
    }

    function setOpen(wrap, open) {
        const btn = wrap.querySelector('.sgd-info');
        wrap.classList.toggle('is-open', open);
        if (btn) {
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
    }

    function toggle(wrap) {
        const willOpen = !wrap.classList.contains('is-open');
        closeAll(willOpen ? wrap : null);
        setOpen(wrap, willOpen);
    }

    function syncTitulosEmptyState() {
        const tbody = document.getElementById('sgd-titulos-tbody');
        const wrap = document.querySelector('.sgd-titulos-table-wrap');
        const emptyNote = document.getElementById('sgd-titulos-empty');
        if (!tbody) {
            return;
        }
        const hasRows = tbody.querySelectorAll('.sgd-titulo-row').length > 0;
        if (wrap) {
            wrap.classList.toggle('is-empty', !hasRows);
        }
        if (emptyNote) {
            emptyNote.hidden = hasRows;
        }
    }

    function reindexTituloRows() {
        const tbody = document.getElementById('sgd-titulos-tbody');
        const countInput = document.getElementById('sgd-titulo-count');
        if (!tbody || !countInput) {
            return;
        }

        const rows = tbody.querySelectorAll('.sgd-titulo-row');
        countInput.value = String(rows.length);

        rows.forEach((row, index) => {
            row.dataset.index = String(index);
            const ordenCell = row.querySelector('.sgd-titulos-orden-col');
            if (ordenCell) {
                ordenCell.textContent = String(index + 1);
            }

            row.querySelectorAll('input[name^="titulo_"]').forEach((input) => {
                const match = input.name.match(/^(titulo_[^\[]+)\[\d+\]$/);
                if (match) {
                    input.name = match[1] + '[' + index + ']';
                }
            });
        });
        syncTitulosEmptyState();
    }

    function bindTituloRow(row) {
        const removeBtn = row.querySelector('.sgd-titulo-remove');
        if (!removeBtn) {
            return;
        }
        removeBtn.addEventListener('click', function () {
            const tbody = document.getElementById('sgd-titulos-tbody');
            if (!tbody) {
                return;
            }
            row.remove();
            reindexTituloRows();
        });
    }

    function initTitulosConfig() {
        const addBtn = document.getElementById('sgd-titulo-add');
        const tbody = document.getElementById('sgd-titulos-tbody');
        const tpl = document.getElementById('sgd-titulo-row-tpl');
        if (!addBtn || !tbody || !tpl) {
            return;
        }

        tbody.querySelectorAll('.sgd-titulo-row').forEach(bindTituloRow);

        addBtn.addEventListener('click', function () {
            const index = tbody.querySelectorAll('.sgd-titulo-row').length;
            const html = tpl.innerHTML
                .replace(/__INDEX__/g, String(index))
                .replace(/__ORDEN__/g, String(index + 1));
            const temp = document.createElement('tbody');
            temp.innerHTML = html.trim();
            const row = temp.firstElementChild;
            if (!row) {
                return;
            }
            tbody.appendChild(row);
            bindTituloRow(row);
            reindexTituloRows();
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initTitulosConfig();

        const wraps = document.querySelectorAll(WRAP_SELECTOR);
        if (!wraps.length) {
            return;
        }

        wraps.forEach((wrap) => {
            const btn = wrap.querySelector('.sgd-info');
            if (!btn) {
                return;
            }

            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                toggle(wrap);
            });

            btn.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') {
                    setOpen(wrap, false);
                }
            });
        });

        document.addEventListener('click', function (e) {
            if (!e.target.closest(WRAP_SELECTOR)) {
                closeAll();
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closeAll();
            }
        });
    });
})();
