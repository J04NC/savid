document.addEventListener("DOMContentLoaded", function() {
    // Theme toggle
    const toggle = document.getElementById("toggleTheme");
    if (toggle) {
        const saved = localStorage.getItem("theme");
        if (saved) {
            document.body.classList.remove("dark-mode", "light-mode");
            document.body.classList.add(saved);
            toggle.textContent = saved === "light-mode" ? "🌙" : "☀️";
        }
        toggle.onclick = function() {
            document.body.classList.toggle("light-mode");
            document.body.classList.toggle("dark-mode");
            const theme = document.body.classList.contains("light-mode") ? "light-mode" : "dark-mode";
            localStorage.setItem("theme", theme);
            this.textContent = theme === "light-mode" ? "🌙" : "☀️";
        };
    }

    // Focus username
    document.querySelector('input[name="username"]')?.focus();

    // Turnstile: el botón arranca deshabilitado y solo se habilita cuando el
    // widget confirma el chequeo (data-callback). Si el widget no existe en
    // la página (Turnstile desactivado por config), el botón se deja como
    // está en el HTML y no se toca nada de esto.
    const submitBtn = document.getElementById("loginSubmitBtn");
    const turnstileBox = document.querySelector(".cf-turnstile");
    if (submitBtn && turnstileBox) {
        submitBtn.disabled = true;

        // Falla abierto si Turnstile no llega a responder (script bloqueado,
        // caída de Cloudflare, etc.) — mismo criterio que TurnstileService::verify()
        // en el servidor: un antibot no puede ser el único motivo de que nadie
        // pueda entrar a SAVID.
        const failOpenTimer = setTimeout(function() {
            submitBtn.disabled = false;
        }, 8000);

        window.savidTurnstileOk = function() {
            clearTimeout(failOpenTimer);
            submitBtn.disabled = false;
        };

        window.savidTurnstileReset = function() {
            submitBtn.disabled = true;
        };
    }
});