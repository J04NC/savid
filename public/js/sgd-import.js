/**
 * Importar SGD: nombre de archivo, metadatos CCD según tipo.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const form = document.getElementById('sgd-import-form');
        if (!form) {
            return;
        }

        const fileInput = document.getElementById('sgd_archivo');
        const fileNameEl = document.getElementById('sgd_archivo_nombre');
        const ccdPanel = document.getElementById('sgd-panel-ccd-meta');
        const tipoRadios = form.querySelectorAll('[data-sgd-tipo-import]');

        if (fileInput && fileNameEl) {
            fileInput.addEventListener('change', function () {
                const name = fileInput.files && fileInput.files[0]
                    ? fileInput.files[0].name
                    : 'Ningún archivo seleccionado';
                fileNameEl.textContent = name;
                if (fileInput.files && fileInput.files[0]) {
                    form.querySelectorAll('input[name="archivo_muestra"]').forEach(function (r) {
                        r.checked = false;
                    });
                }
            });
        }

        form.querySelectorAll('input[name="archivo_muestra"]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                if (radio.checked && fileInput) {
                    fileInput.value = '';
                    if (fileNameEl) {
                        fileNameEl.textContent = 'Usará: ' + radio.value;
                    }
                }
            });
        });

        function syncCcdPanel() {
            if (!ccdPanel) {
                return;
            }
            const selected = form.querySelector('[data-sgd-tipo-import]:checked');
            const isCcd = selected && selected.value === 'ccd';
            ccdPanel.hidden = !isCcd;
            ccdPanel.querySelectorAll('input').forEach(function (inp) {
                inp.disabled = !isCcd;
            });
        }

        tipoRadios.forEach(function (r) {
            r.addEventListener('change', syncCcdPanel);
        });
        syncCcdPanel();
    });
})();
