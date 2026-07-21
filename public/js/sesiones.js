(function () {
    'use strict';

    /* Búsqueda en página: filtro vanilla en crud.js (initCrudSearch), tabla sin DataTables */

    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.btn-cerrar-sesion');
        if (!btn) return;

        const sesionId = btn.getAttribute('data-sesion-id');
        if (!sesionId) return;

        if (!confirm('¿Cerrar esta sesión? El usuario deberá iniciar sesión de nuevo.')) {
            return;
        }

        btn.disabled = true;

        fetch('?url=sesiones/cerrar/' + encodeURIComponent(sesionId), {
            method: 'POST',
            credentials: 'same-origin',
        })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j.ok) {
                    alert(j.error || 'No se pudo cerrar la sesión.');
                    btn.disabled = false;
                    return;
                }
                location.reload();
            })
            .catch(function () {
                alert('Error de conexión.');
                btn.disabled = false;
            });
    });
})();
