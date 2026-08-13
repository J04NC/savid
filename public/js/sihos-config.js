(function () {
    'use strict';

    const btn = document.getElementById('btnProbarSihos');
    const status = document.getElementById('sihosConexionStatus');
    if (!btn || !status) return;

    btn.addEventListener('click', function () {
        const empresaId = btn.getAttribute('data-empresa-id') || '';

        btn.disabled = true;
        status.textContent = 'Probando…';

        fetch('?url=sihos/configProbar&empresa_id=' + encodeURIComponent(empresaId), {
            method: 'POST',
            credentials: 'same-origin',
        })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                status.textContent = j.message || (j.ok ? 'Listo' : 'Error');
                btn.disabled = false;
            })
            .catch(function () {
                status.textContent = 'Error de conexión.';
                btn.disabled = false;
            });
    });
})();
