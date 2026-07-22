(function () {
    'use strict';

    const btn = document.getElementById('btnProbarCorreo');
    const destino = document.getElementById('sistemaCorreoDestino');
    const status = document.getElementById('sistemaCorreoStatus');
    if (!btn || !destino || !status) return;

    btn.addEventListener('click', function () {
        const email = destino.value.trim();
        if (!email) {
            status.textContent = 'Escriba un correo de destino.';
            return;
        }

        btn.disabled = true;
        status.textContent = 'Enviando…';

        const fd = new FormData();
        fd.append('destino', email);

        fetch('?url=sistema/correoProbar', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
        })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                status.textContent = j.message || j.error || (j.ok ? 'Listo' : 'Error');
                btn.disabled = false;
            })
            .catch(function () {
                status.textContent = 'Error de conexión.';
                btn.disabled = false;
            });
    });
})();
