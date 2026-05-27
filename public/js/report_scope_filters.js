(function () {
    'use strict';

    const empresaSelect = document.getElementById('report_filter_empresa');
    const sedeSelect = document.getElementById('report_filter_sede');

    if (!empresaSelect || !sedeSelect) {
        return;
    }

    function resetSedes() {
        sedeSelect.innerHTML = '<option value="">— Todas —</option>';
    }

    function loadSedes(empresaId) {
        if (!empresaId) {
            resetSedes();
            sedeSelect.disabled = true;
            sedeSelect.value = '';
            return;
        }

        sedeSelect.disabled = true;
        fetch('?url=context/cambiarSede&empresa_id=' + encodeURIComponent(empresaId), {
            credentials: 'same-origin',
        })
            .then(function (r) { return r.json(); })
            .then(function (sedes) {
                resetSedes();
                if (!Array.isArray(sedes)) {
                    return;
                }
                sedes.forEach(function (s) {
                    const opt = document.createElement('option');
                    opt.value = String(s.id);
                    opt.textContent = s.nombre || ('#' + s.id);
                    sedeSelect.appendChild(opt);
                });
                sedeSelect.disabled = sedes.length === 0;
            })
            .catch(function () {
                resetSedes();
                sedeSelect.disabled = true;
            });
    }

    empresaSelect.addEventListener('change', function () {
        loadSedes(empresaSelect.value);
    });
})();
