(function () {
    'use strict';

    var catalogEl = document.getElementById('sgd-ccd-catalogos');
    if (!catalogEl) return;

    var catalogos;
    try {
        catalogos = JSON.parse(catalogEl.textContent || '{}');
    } catch (e) {
        catalogos = {};
    }

    if (window.SgdLocalCatalog) {
        window.SgdLocalCatalog.init('sgd-ccd-catalogos', '.sgd-ccd-page');
    }

    var byId = {
        dependencias: {},
        series: {},
        subseries: {},
        documentos: {}
    };

    (catalogos.dependencias || []).forEach(function (d) {
        byId.dependencias[String(d.id)] = d;
    });
    (catalogos.series || []).forEach(function (s) {
        byId.series[String(s.id)] = s;
    });
    (catalogos.subseries || []).forEach(function (s) {
        byId.subseries[String(s.id)] = s;
    });
    (catalogos.documentos || []).forEach(function (d) {
        byId.documentos[String(d.id)] = d;
    });

    var elDep = document.getElementById('sgd_ccd_dependencia_id');
    var elSerie = document.getElementById('sgd_ccd_serie_id');
    var elSub = document.getElementById('sgd_ccd_subserie_id');
    var elCodeValue = document.getElementById('sgd-ccd-code-value');

    function codigoFromItem(map, id) {
        if (!id || !map[String(id)]) return '';
        return String(map[String(id)].codigo || '').trim();
    }

    function buildCarpetaCodigo() {
        var dep = codigoFromItem(byId.dependencias, elDep ? elDep.value : '');
        if (!dep) return '';
        var parts = [dep];
        var serie = codigoFromItem(byId.series, elSerie ? elSerie.value : '');
        if (serie) {
            parts.push(serie);
            var sub = codigoFromItem(byId.subseries, elSub ? elSub.value : '');
            if (sub) {
                parts.push(sub);
            }
        }
        return parts.join('.');
    }

    function updatePreview() {
        if (!elCodeValue) return;
        elCodeValue.textContent = buildCarpetaCodigo() || '—';
    }

    [elDep, elSerie, elSub].forEach(function (el) {
        if (!el) return;
        el.addEventListener('change', updatePreview);
    });

    updatePreview();
})();
