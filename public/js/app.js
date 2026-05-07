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
        const arrow = document.querySelector(".arrow");

        if (!body) return;

        body.classList.toggle("hidden");

        if (arrow) {
            arrow.innerHTML =
                body.classList.contains("hidden")
                ? "▶"
                : "▼";
        }

    };

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

        closeModalGod(true);

        modal.classList.remove("hidden");
        document.body.classList.add("modal-open");

        box.className = "modal-content modal-" + size;

        container.innerHTML =
            `<div class="modal-loader">${loading}</div>`;

        fetch("?url=" + url)
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

        modal.classList.add("hidden");
        document.body.classList.remove("modal-open");

        container.innerHTML = "";
        box.className = "modal-content";

        if (!silent) {
            document.activeElement?.blur();
        }

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

        const empresa =
            document.getElementById("empresaSelect")?.value;

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
                location.reload();
            } else {
                alert("No se pudo guardar");
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

    const empresa =
        document.getElementById("empresaSelect");

    const sede =
        document.getElementById("sedeSelect");

    if (!empresa || !sede) return;

    empresa.onchange = function () {

        const id = this.value;

        sede.innerHTML =
            `<option value="">Cargando...</option>`;

        if (!id) {
            sede.innerHTML =
                `<option value="">Seleccione sede</option>`;
            return;
        }

        fetch(`?url=context/cambiarSede&empresa_id=${id}`)
        .then(r => r.json())
        .then(rows => {

            sede.innerHTML =
                `<option value="">Seleccione sede</option>`;

            rows.forEach(x => {

                sede.innerHTML += `
                    <option value="${x.id}">
                        ${x.nombre}
                    </option>
                `;

            });

        })
        .catch(() => {

            sede.innerHTML =
                `<option value="">Error cargando sedes</option>`;

        });

    };

}