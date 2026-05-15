/**
 * Cierra la sesión tras N minutos sin actividad (valor usuario.sesion_idle_minutos en sesión).
 */
(function () {
    const raw = document.body && document.body.dataset.sessionIdleMinutes;
    const minutes = parseInt(raw || "0", 10);
    if (!minutes || minutes < 1) {
        return;
    }

    const limitMs = minutes * 60 * 1000;
    let last = Date.now();
    let warned = false;

    function bump() {
        last = Date.now();
        warned = false;
    }

    ["mousemove", "keydown", "click", "scroll", "touchstart"].forEach(function (ev) {
        document.addEventListener(ev, bump, { passive: true });
    });

    setInterval(function () {
        const idle = Date.now() - last;
        if (idle > limitMs - 60000 && !warned && limitMs > 120000) {
            warned = true;
            if (typeof window.alert === "function") {
                window.alert("Su sesión se cerrará en aproximadamente 1 minuto por inactividad.");
            }
        }
        if (idle >= limitMs) {
            window.location.href = "?url=login/logout";
        }
    }, 10000);
})();
