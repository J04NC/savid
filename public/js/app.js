/* =====================================================
public/js/app.js
VERSION LIMPIA PROFESIONAL FINAL
SIN DUPLICADOS / SIN PERDER X / SIN ROMPER SCROLL
===================================================== */

document.addEventListener("DOMContentLoaded", function () {

    initTheme?.();
    initSearch?.();

    initSidebar();
    initModalSystem();
    initContextButton();
    cleanupStaleOverlays();

});

window.addEventListener("pageshow", cleanupStaleOverlays);

/*
 * =====================================================
 * OVERLAY DE CARGA GLOBAL
 * =====================================================
 * Bloquea la interacción con el resto de la pantalla mientras dura una
 * operación lenta (guardar, generar un reporte, escribir en SIHOS…). Antes
 * cada sitio manejaba su propia espera a su manera — o no la manejaba: los
 * botones de escritura de SIHOS solo se deshabilitaban a sí mismos y el
 * resto de la pantalla quedaba libre, así que se podía cerrar el modal o
 * navegar mientras la escritura seguía en curso en el servidor.
 *
 * Uso para peticiones fetch (mostrar antes, ocultar siempre en el finally):
 *   savidMostrarCargando("Guardando…");
 *   try { await fetch(...); } finally { savidOcultarCargando(); }
 *
 * Uso para un <form> de navegación completa (submit normal, sin fetch): se
 * muestra inmediato (sin el retraso) porque la navegación reemplaza el
 * documento entero al terminar — no hace falta ni es posible ocultarlo a
 * mano:
 *   savidMostrarCargando("Generando reporte…", true);
 *   // se deja continuar el submit nativo
 *
 * El retraso de 200ms (para fetch) evita el parpadeo de mostrar y ocultar
 * de inmediato en operaciones que en la práctica son instantáneas.
 */
let savidCargandoTimer = null;
let savidCargandoProfundidad = 0;

function savidMostrarCargando(texto, inmediato) {
    savidCargandoProfundidad++;
    clearTimeout(savidCargandoTimer);

    const pintar = function () {
        let overlay = document.getElementById("savidCargandoOverlay");
        if (!overlay) {
            overlay = document.createElement("div");
            overlay.id = "savidCargandoOverlay";
            overlay.className = "savid-cargando-overlay";
            overlay.innerHTML =
                '<div class="savid-cargando-caja">' +
                '<div class="savid-cargando-spinner" aria-hidden="true"></div>' +
                '<div class="savid-cargando-texto"></div>' +
                "</div>";
            document.body.appendChild(overlay);
        }
        overlay.querySelector(".savid-cargando-texto").textContent = texto || "Procesando…";
        overlay.classList.add("activo");
    };

    if (inmediato) {
        pintar();
    } else {
        savidCargandoTimer = setTimeout(pintar, 200);
    }
}

function savidOcultarCargando() {
    savidCargandoProfundidad = Math.max(0, savidCargandoProfundidad - 1);
    if (savidCargandoProfundidad > 0) {
        return;
    }
    clearTimeout(savidCargandoTimer);
    const overlay = document.getElementById("savidCargandoOverlay");
    if (overlay) {
        overlay.classList.remove("activo");
    }
}

/** Fuerza el overlay a cerrado, ignorando la profundidad — usar solo al recuperar la página (ver cleanupStaleOverlays). */
function savidOcultarCargandoForzado() {
    savidCargandoProfundidad = 0;
    clearTimeout(savidCargandoTimer);
    const overlay = document.getElementById("savidCargandoOverlay");
    if (overlay) {
        overlay.classList.remove("activo");
    }
}

/**
 * Quita capas invisibles que a veces quedan abiertas y bloquean clics (p. ej. menú Columnas de DataTables).
 */
function cleanupStaleOverlays() {

    var openCollection = document.querySelector("div.dt-button-collection");
    if (!openCollection || openCollection.offsetParent === null) {
        document.querySelectorAll("div.dt-button-background").forEach(function (el) {
            el.remove();
        });
    }

    const searchOverlay = document.getElementById("searchOverlay");
    if (searchOverlay && !searchOverlay.classList.contains("active")) {
        searchOverlay.classList.remove("active");
        searchOverlay.setAttribute("aria-hidden", "true");
    }

    const contextModal = document.getElementById("contextModal");
    if (contextModal && contextModal.classList.contains("hidden")) {
        document.body.classList.remove("modal-open");
    }
}

document.addEventListener("click", function (e) {
    if (e.target && e.target.closest && e.target.closest("#contextModal, .search-overlay.active, .sgd-revert-modal:not([hidden])")) {
        return;
    }
    window.setTimeout(cleanupStaleOverlays, 0);
}, true);

// Restaurar desde el historial (botón atrás) puede traer de vuelta el
// overlay de carga visible y bloqueando la pantalla para siempre — la
// operación que lo mostró ya no está en curso en esta vista restaurada.
// A propósito NO va dentro de cleanupStaleOverlays(): esa función también
// se dispara en CUALQUIER clic del documento (ver arriba, con setTimeout 0),
// y ese mismo clic puede ser justo el que llama a savidMostrarCargando()
// (p. ej. el submit de un <form> con overlay inmediato) — apagarlo ahí lo
// cerraba a los pocos milisegundos de haberlo abierto.
window.addEventListener("pageshow", function () {
    if (typeof savidOcultarCargandoForzado === "function") {
        savidOcultarCargandoForzado();
    }
});

/* =====================================================
SIDEBAR
===================================================== */

function initSidebar() {

    const sidebar = document.querySelector(".sidebar-floating");
    const header = sidebar ? sidebar.querySelector(".user-header") : null;
    const usernameEl = sidebar ? sidebar.querySelector(".username") : null;

    if (header && usernameEl) {
        const name = usernameEl.textContent.trim();
        const initial = (name.charAt(0) || "?").toUpperCase();
        header.setAttribute("data-initial", initial);
        header.setAttribute("title", name);
    }

    window.toggleUserPanel = function () {
        const body = document.getElementById("userBody");
        const arrow = document.querySelector(".sidebar-floating .arrow");

        if (!body) return;

        if (sidebar && sidebar.classList.contains("is-compact") && !sidebar.classList.contains("is-compact-expanded")) {
            sidebar.classList.add("is-compact-expanded");
            body.classList.remove("hidden");
            if (arrow) arrow.innerHTML = "▼";
            return;
        }

        body.classList.toggle("hidden");

        if (arrow) {
            arrow.innerHTML = body.classList.contains("hidden") ? "▶" : "▼";
        }

        if (sidebar && sidebar.classList.contains("is-compact") && body.classList.contains("hidden")) {
            sidebar.classList.remove("is-compact-expanded");
        }
    };

    function collapseUserPanel() {
        const body = document.getElementById("userBody");
        const arrow = document.querySelector(".sidebar-floating .arrow");

        if (body && !body.classList.contains("hidden")) {
            body.classList.add("hidden");
            if (arrow) arrow.innerHTML = "▶";
        }

        if (sidebar) {
            sidebar.classList.remove("is-compact-expanded");
        }
    }

    function setSidebarCompact(compact) {
        if (!sidebar || window.matchMedia("(max-width: 768px)").matches) return;

        sidebar.classList.toggle("is-compact", compact);
        if (compact) {
            collapseUserPanel();
        } else {
            sidebar.classList.remove("is-compact-expanded");
        }
    }

    let scrollTick = false;
    window.addEventListener("scroll", function () {
        if (!sidebar || scrollTick) return;
        scrollTick = true;
        requestAnimationFrame(function () {
            scrollTick = false;
            const y = window.scrollY || document.documentElement.scrollTop || 0;
            if (y < 48) {
                setSidebarCompact(false);
            } else if (y > 120) {
                setSidebarCompact(true);
            }
        });
    }, { passive: true });

    document.addEventListener("click", function (e) {
        if (!sidebar) return;

        if (sidebar.contains(e.target)) return;

        const modal = document.getElementById("contextModal");
        if (modal && !modal.classList.contains("hidden") && modal.contains(e.target)) {
            return;
        }

        collapseUserPanel();
    });

    document.addEventListener("keydown", function (e) {
        if (e.key === "Escape") collapseUserPanel();
    });

}

/* =====================================================
MODAL SYSTEM
===================================================== */

function initModalSystem() {

    const modal = document.getElementById("contextModal");
    const box = document.querySelector(".modal-content");
    const container = document.getElementById("contextContainer");
    const closeBtn = document.getElementById("closeContextModal");

    if (!modal || !box || !container) return;

    window.openModalGod = function (
        url,
        size = "md",
        loading = "Cargando..."
    ) {

        window.__modalBeforeClose = null;

        modal.classList.remove("hidden");
        document.body.classList.add("modal-open");

        const isPermisosModal = /(^|\/)(rol\/permisos|usuario\/permisos)/.test(url);
        const modalSize = isPermisosModal ? "xl" : size;

        box.className = "modal-content modal-" + modalSize;
        if (isPermisosModal) {
            box.classList.add("modal-permisos");
        } else {
            box.classList.remove("modal-permisos");
        }

        // El modal en sí ya cubre la pantalla y bloquea la interacción con
        // el fondo (ver body.modal-open); aquí solo se usa el mismo spinner
        // visual del overlay global (sin su caja con fondo/sombra, pensada
        // para flotar sobre la página, no para ir dentro de otro modal) para
        // que se vea consistente en todo el sistema.
        container.innerHTML =
            '<div class="modal-loader">'
            + '<div class="savid-cargando-spinner" style="margin:0 auto 14px;" aria-hidden="true"></div>'
            + loading
            + '</div>';

        let fetchUrl = "?url=" + url;
        if (url.includes("context/cambiarSede") && !url.includes("partial=")) {
            fetchUrl += "&partial=1";
        }

        fetch(fetchUrl)
        .then(r => r.text())
        .then(html => {

            container.innerHTML = html;
            container.scrollTop = 0;

            if (url.includes("context/cambiarSede")) {
                bindContextForm();
            } else {
                ejecutarScripts(container);
            }

        })
        .catch(() => {

            container.innerHTML =
                `<div class="modal-loader">
                    Error cargando contenido
                </div>`;

        });

    };

    window.closeModalGod = function (silent = false) {

        const finishClose = function () {
            window.__modalBeforeClose = null;
            modal.classList.add("hidden");
            document.body.classList.remove("modal-open");

            container.innerHTML = "";
            box.className = "modal-content";
            box.classList.remove("modal-permisos");

            if (!silent) {
                document.activeElement?.blur();
            }
        };

        if (typeof window.__modalBeforeClose === "function") {
            try {
                const ret = window.__modalBeforeClose(silent);

                if (ret != null && typeof ret.then === "function") {
                    ret.then(function (ok) {
                        if (ok !== false) {
                            finishClose();
                        }
                    }).catch(function () {
                        /* error ya mostrada; no cerrar */
                    });

                    return;
                }
            } catch (e) {
                finishClose();

                return;
            }
        }

        finishClose();

    };

    if (closeBtn) {
        closeBtn.onclick = closeModalGod;
    }

    modal.onclick = function (e) {
        if (e.target === modal) {
            closeModalGod();
        }
    };

    document.addEventListener("keydown", function (e) {
        if (e.key === "Escape") {
            closeModalGod();
        }
    });

}

/* =====================================================
EJECUTAR SCRIPTS DINAMICOS
===================================================== */

function ejecutarScripts(container) {

    const scripts = container.querySelectorAll("script");

    scripts.forEach(oldScript => {

        const s = document.createElement("script");

        if (oldScript.src) {
            s.src = oldScript.src;
        } else {
            s.textContent = oldScript.textContent;
        }

        document.body.appendChild(s);
        document.body.removeChild(s);

    });

}

/* =====================================================
BOTON CONTEXTO
===================================================== */

function initContextButton() {

    const btn = document.getElementById("btnCambiarSede");
    if (!btn) return;

    btn.onclick = function (e) {

        e.preventDefault();

        openModalGod(
            "context/cambiarSede",
            "sm",
            "Cargando..."
        );

    };

}

/* =====================================================
FORM CONTEXTO
===================================================== */

function bindContextForm() {

    const form =
        document.querySelector("#contextContainer form");

    if (!form) return;

    activarAjaxSedes();

    form.onsubmit = function (e) {

        e.preventDefault();

        const empresaHidden = document.getElementById("empresaHidden");
        const empresaSelect = document.getElementById("empresaSelect");
        const empresa =
            (empresaHidden && empresaHidden.value) ||
            (empresaSelect && empresaSelect.value) ||
            "";

        const sede =
            document.getElementById("sedeSelect")?.value;

        if (!empresa || !sede) {
            alert("Debe seleccionar empresa y sede.");
            return;
        }

        fetch("?url=context/cambiarSede", {
            method: "POST",
            body: new FormData(form)
        })
        .then(r => r.json())
        .then(data => {

            if (data.success) {
                closeModalGod();
                window.location.href = "?url=dashboard";
            } else {
                alert(data.error || "No se pudo guardar");
            }

        })
        .catch(() => {
            alert("Error de conexión");
        });

    };

}

/* =====================================================
SEDES AJAX
===================================================== */

function activarAjaxSedes() {

    const form =
        document.querySelector("#contextContainer #contextForm") ||
        document.getElementById("contextForm");

    const empresaSelect = document.getElementById("empresaSelect");
    const empresaHidden = document.getElementById("empresaHidden");
    const sede = document.getElementById("sedeSelect");

    if (!sede) return;

    const preSede =
        form && form.dataset && form.dataset.preselectSede
            ? String(form.dataset.preselectSede).trim()
            : "";

    let initialLoad = true;

    function cargar(empresaId) {

        if (!empresaId) {
            sede.innerHTML = `<option value="">Seleccione sede</option>`;
            return;
        }

        sede.innerHTML = `<option value="">Cargando...</option>`;

        fetch(
            `?url=context/cambiarSede&empresa_id=${encodeURIComponent(empresaId)}`
        )
            .then((r) => r.json())
            .then((rows) => {
                sede.innerHTML = `<option value="">Seleccione sede</option>`;

                if (!rows || !rows.length) {
                    sede.innerHTML += `<option value="" disabled>No tiene sedes autorizadas</option>`;
                    initialLoad = false;
                    return;
                }

                rows.forEach((x) => {
                    sede.innerHTML += `
                    <option value="${x.id}">
                        ${x.nombre}
                    </option>
                `;
                });

                if (initialLoad && preSede) {
                    const sid = String(preSede);
                    if ([...sede.options].some((o) => o.value === sid)) {
                        sede.value = sid;
                    }
                }
                initialLoad = false;
            })
            .catch(() => {
                sede.innerHTML = `<option value="">Error cargando sedes</option>`;
                initialLoad = false;
            });
    }

    if (empresaSelect) {
        empresaSelect.addEventListener("change", function () {
            initialLoad = false;
            cargar(this.value);
        });
        if (empresaSelect.value) {
            cargar(empresaSelect.value);
        }
    }

    if (empresaHidden && empresaHidden.value) {
        cargar(empresaHidden.value);
    }
}