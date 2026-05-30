(function () {
    'use strict';

    var catalogEl = document.getElementById('sgd-doc-catalogos');
    if (!catalogEl) return;

    var catalogos;
    try {
        catalogos = JSON.parse(catalogEl.textContent || '{}');
    } catch (e) {
        catalogos = {};
    }

    var padresById = {};
    (catalogos.padres || []).forEach(function (p) {
        padresById[String(p.id)] = p;
    });

    var elProceso = document.getElementById('sgd_doc_proceso_id');
    var elTipo = document.getElementById('sgd_doc_tipo_id');
    var elLinea = document.getElementById('sgd_doc_linea_id');
    var elPadre = document.getElementById('sgd_doc_padre_id');
    var elConsecutivo = document.getElementById('sgd_doc_consecutivo');
    var elCodeValue = document.getElementById('sgd-doc-code-value');
    var elCodeNote = document.getElementById('sgd-doc-code-note');
    var elLineaGroup = document.getElementById('sgd-doc-linea-group');

    function selectedCodigo(selectEl) {
        if (!selectEl || !selectEl.selectedOptions.length) return '';
        return (selectEl.selectedOptions[0].dataset.codigo || '').toUpperCase();
    }

    function buildChildSuffix(tipo, consecutivo) {
        consecutivo = String(consecutivo || '').trim();
        if (!consecutivo) return tipo;
        if (/^[A-Z]+\d+$/i.test(consecutivo)) return consecutivo.toUpperCase();
        if (/^\d+$/.test(consecutivo)) return tipo + consecutivo;
        return consecutivo.toUpperCase();
    }

    function buildTaRoot(proceso, linea, consecutivo) {
        if (!proceso || !consecutivo) return '';
        if (!linea) return proceso + '-TA' + consecutivo;
        return proceso + '-TA' + linea + '-' + consecutivo;
    }

    function buildCodigo() {
        var proceso = selectedCodigo(elProceso);
        var tipo = selectedCodigo(elTipo);
        var linea = selectedCodigo(elLinea);
        var consecutivo = elConsecutivo ? elConsecutivo.value.trim() : '';
        var padreId = elPadre ? elPadre.value : '';
        var padreCodigo = '';

        if (padreId && padresById[padreId]) {
            padreCodigo = padresById[padreId].codigo_display || '';
            if (!proceso) {
                proceso = (padresById[padreId].proceso_codigo || '').toUpperCase();
            }
        }

        if (padreCodigo) {
            return padreCodigo + '-' + buildChildSuffix(tipo, consecutivo);
        }
        if (tipo === 'TA') {
            return buildTaRoot(proceso, linea, consecutivo);
        }
        if (proceso && tipo && consecutivo) {
            return proceso + '-' + tipo + consecutivo;
        }
        if (proceso && tipo) {
            return proceso + '-' + tipo + (consecutivo || '?');
        }
        return '';
    }

    function updateLineaVisibility() {
        if (!elLineaGroup || !elTipo || !elPadre) return;
        var tipo = selectedCodigo(elTipo);
        var hasPadre = elPadre.value !== '';
        var show = tipo === 'TA' && !hasPadre;
        elLineaGroup.hidden = !show;
        if (!show && elLinea) {
            elLinea.value = '';
        }
    }

    function updatePreview() {
        updateLineaVisibility();
        var code = buildCodigo();
        if (elCodeValue) {
            elCodeValue.textContent = code || '—';
        }
        if (elCodeNote) {
            if (elPadre && elPadre.value) {
                elCodeNote.textContent = 'Hijo: código del padre + tipo + consecutivo.';
            } else if (selectedCodigo(elTipo) === 'TA') {
                elCodeNote.textContent = 'Raíz TA: proceso + TA + línea + consecutivo.';
            } else {
                elCodeNote.textContent = 'Raíz: proceso + tipo + consecutivo.';
            }
        }
    }

    [elProceso, elTipo, elLinea, elPadre, elConsecutivo].forEach(function (el) {
        if (!el) return;
        el.addEventListener('change', updatePreview);
        el.addEventListener('input', updatePreview);
    });

    updatePreview();
})();
