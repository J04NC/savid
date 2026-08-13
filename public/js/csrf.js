(function () {
    "use strict";

    if (typeof window.CSRF_TOKEN === "undefined" || !window.CSRF_TOKEN) return;

    function isSameOrigin(url) {
        try {
            var u = new URL(url, window.location.href);
            return u.origin === window.location.origin;
        } catch (e) {
            return true;
        }
    }

    var originalFetch = window.fetch;
    if (typeof originalFetch === "function") {
        window.fetch = function (input, init) {
            init = init || {};
            var method = (init.method || (input && input.method) || "GET").toUpperCase();
            var url = typeof input === "string" ? input : ((input && input.url) || "");

            if (method !== "GET" && method !== "HEAD" && isSameOrigin(url)) {
                var headers = new Headers(init.headers || (input && input.headers) || {});
                if (!headers.has("X-CSRF-Token")) {
                    headers.set("X-CSRF-Token", window.CSRF_TOKEN);
                }
                init = Object.assign({}, init, { headers: headers });
            }

            return originalFetch.call(this, input, init);
        };
    }

    var originalOpen = XMLHttpRequest.prototype.open;
    var originalSend = XMLHttpRequest.prototype.send;

    XMLHttpRequest.prototype.open = function (method, url) {
        this.__csrfMethod = (method || "GET").toUpperCase();
        this.__csrfUrl = url;
        return originalOpen.apply(this, arguments);
    };

    XMLHttpRequest.prototype.send = function (body) {
        if (this.__csrfMethod && this.__csrfMethod !== "GET" && this.__csrfMethod !== "HEAD" && isSameOrigin(this.__csrfUrl)) {
            try {
                this.setRequestHeader("X-CSRF-Token", window.CSRF_TOKEN);
            } catch (e) {
                /* noop */
            }
        }
        return originalSend.apply(this, arguments);
    };

    // Red de seguridad para formularios nativos (submit clásico, sin fetch/XHR) que no
    // llevan el hidden csrf_token embebido server-side: se agrega automáticamente a
    // cualquier <form method="post"> que no lo tenga ya, incluidos los inyectados
    // dinámicamente después de cargar la página (modales vía innerHTML).
    function injectHiddenTokenIntoForm(form) {
        var method = (form.getAttribute("method") || "GET").toUpperCase();
        if (method !== "POST") return;
        if (form.querySelector('input[name="csrf_token"]')) return;
        var input = document.createElement("input");
        input.type = "hidden";
        input.name = "csrf_token";
        input.value = window.CSRF_TOKEN;
        form.appendChild(input);
    }

    function injectHiddenTokenIntoForms(root) {
        (root || document).querySelectorAll("form").forEach(injectHiddenTokenIntoForm);
    }

    function startObserving() {
        injectHiddenTokenIntoForms(document);

        var observer = new MutationObserver(function (mutations) {
            mutations.forEach(function (m) {
                m.addedNodes.forEach(function (node) {
                    if (node.nodeType !== 1) return;
                    if (node.tagName === "FORM") {
                        injectHiddenTokenIntoForm(node);
                    } else if (node.querySelectorAll) {
                        injectHiddenTokenIntoForms(node);
                    }
                });
            });
        });
        observer.observe(document.body, { childList: true, subtree: true });
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", startObserving);
    } else {
        startObserving();
    }
})();
