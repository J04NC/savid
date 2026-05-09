/* =====================================================
   public/js/crud.js
   CRUD ENGINE PRO - SAVID
===================================================== */

let selectedRow = null;
let selectedId = null;

document.addEventListener("DOMContentLoaded", function () {

    initCrudRows();
    initCrudNuevo();
    initCrudSearch();
    initCrudDelete();
    initCrudAcciones();
    initCrudValidation();

});

/* =====================================================
   CLICK FILAS
===================================================== */

function initCrudRows() {

    document.querySelectorAll(".crud-row").forEach(row => {

        row.addEventListener("click", function () {

            document.querySelectorAll(".crud-row").forEach(r => {
                r.classList.remove("selected");
            });

            this.classList.add("selected");

            selectedRow = this;
            selectedId = this.dataset.id || "";

            const hiddenId = document.getElementById("crud_id");
            if (hiddenId) hiddenId.value = selectedId;

            this.querySelectorAll("td[data-field]").forEach(cell => {

                let field = cell.dataset.field;
                let input = document.querySelector(`[name="${field}"]`);

                if (!input) return;

                if (input.type === "password" || input.dataset.password === "1") {
                    input.value = "";
                    return;
                }

                let value = cell.dataset.value ?? cell.innerText.trim();

                input.value = value;

            });

        });

    });

}

/* =====================================================
   BOTON NUEVO / LIMPIAR
===================================================== */

function initCrudNuevo() {

    const btn = document.getElementById("btnNuevo");

    if (!btn) return;

    btn.addEventListener("click", function () {

        document.querySelectorAll(".form-input").forEach(input => {

            if (input.tagName === "SELECT") {
                input.selectedIndex = 0;
            } else {
                input.value = "";
            }

            input.classList.remove("input-error");
            input.style.border = "";

        });

        document.querySelectorAll(".error-text").forEach(e => {
            e.innerHTML = "";
            e.style.display = "none";
        });

        document.querySelectorAll(".crud-row").forEach(r => {
            r.classList.remove("selected");
        });

        const hiddenId = document.getElementById("crud_id");
        if (hiddenId) hiddenId.value = "";

        selectedRow = null;
        selectedId = null;

    });

}

/* =====================================================
   BUSQUEDA CRUD
===================================================== */

function initCrudSearch() {

    const search = document.querySelector(".crud-search");

    if (!search) return;

    search.addEventListener("keyup", function () {

        const value = this.value.toLowerCase();

        document.querySelectorAll(".crud-table tbody tr").forEach(row => {

            const text = row.innerText.toLowerCase();

            row.style.display = text.includes(value)
                ? ""
                : "none";

        });

    });

}

/* =====================================================
   ELIMINAR
===================================================== */

function initCrudDelete() {

    const btn = document.querySelector(".btn-delete");

    if (!btn) return;

    btn.addEventListener("click", function () {

        if (!selectedId) {
            alert("Seleccione un registro");
            return;
        }

        if (!confirm("¿Eliminar registro?")) return;

        let url = new URL(window.location.href);
        url.searchParams.set("delete", selectedId);

        window.location = url.toString();

    });

}

/* =====================================================
   ACCIONES ESPECIALES
===================================================== */

function initCrudAcciones() {

    document.querySelectorAll(".btn-accion").forEach(btn => {

        btn.addEventListener("click", function () {

            if (!selectedId) {
                alert("Seleccione un registro");
                return;
            }

            let accion = this.dataset.accion;

            switch (accion) {

                case "rol_permisos":

                    if (typeof openModalGod === "function") {
                        openModalGod(
                            "rol/permisos&id=" + selectedId,
                            "xl",
                            "Cargando permisos..."
                        );
                    }

                break;

                case "usuario_permisos":
                case "user_permisos":
                    if (typeof openModalGod === "function") {
                        openModalGod(
                            "usuario/permisos/" + selectedId,
                            "xl",
                            "Cargando permisos..."
                        );
                    }
                break;

                case "usuario_roles":
                case "user_roles":
                    if (typeof openModalGod === "function") {
                        openModalGod(
                            "usuario/roles/" + selectedId,
                            "lg",
                            "Cargando roles..."
                        );
                    }
                break;

                default:
                    alert("Acción: " + accion);

            }

        });

    });

}

/* =====================================================
   VALIDACION
===================================================== */

function initCrudValidation() {

    const form = document.querySelector("form");

    if (!form) return;

    form.addEventListener("submit", function (e) {

        let errores = 0;

        document.querySelectorAll(".form-input[required]").forEach(input => {

            if (!input.value.trim()) {

                input.classList.add("input-error");
                errores++;

            } else {
                input.classList.remove("input-error");
            }

        });

        if (errores > 0) {
            e.preventDefault();
            alert("Complete los campos obligatorios.");
        }

    });

}