/**
 * Actualiza last_activity_at en usuario_sesion mientras el usuario navega.
 */
(function () {
    'use strict';

    function ping() {
        fetch('?url=sesiones/ping', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j && j.forced_logout) {
                    window.location.href = '?url=login/logout&motivo=admin';
                }
            })
            .catch(function () {});
    }

    ping();
    setInterval(ping, 120000);

    ['click', 'keydown'].forEach(function (ev) {
        document.addEventListener(ev, function () {
            if (!window.__sesionPingDebounced) {
                window.__sesionPingDebounced = true;
                setTimeout(function () {
                    window.__sesionPingDebounced = false;
                    ping();
                }, 60000);
            }
        }, { passive: true });
    });
})();
