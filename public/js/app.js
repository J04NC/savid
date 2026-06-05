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

});

/* =====================================================
SIDEBAR
===================================================== */

function initSidebar() {

    window.toggleUserPanel = function () {
        const body = document.getElementById("userBody");
        const arrow = document.querySelector(".sidebar-floating .arrow");

        if (!body) return;

        body.classList.toggle("hidden");

        if (arrow) {
            arrow.innerHTML = body.classList.contains("hidden") ? "▶" : "▼";
        }
    };

    function collapseUserPanel() {
        const body = document.getElementById("userBody");
        const arrow = document.querySelector(".sidebar-floating .arrow");

        if (body && !body.classList.contains("hidden")) {
            body.classList.add("hidden");
            if (arrow) arrow.innerHTML = "▶";
        }
    }

    document.addEventListener("click", function (e) {
        const sidebar = document.querySelector(".sidebar-floating");
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

        container.innerHTML =
            `<div class="modal-loader">${loading}</div>`;

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