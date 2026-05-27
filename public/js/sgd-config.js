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

    document.addEventListener('DOMContentLoaded', function () {
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
