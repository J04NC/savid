/* =====================================================
   public/js/crud.js
   CRUD ENGINE PRO - SAVID
===================================================== */

let selectedRow = null;
let selectedId = null;

function crudNormalizeUppercaseField(el) {
    if (!el || el.getAttribute("data-crud-uppercase") !== "1") {
        return;
    }
    const v = String(el.value || "").toUpperCase();
    if (el.value !== v) {
        el.value = v;
    }
}

function initCrudUppercaseFields() {
    document.querySelectorAll('[data-crud-uppercase="1"]').forEach(function (el) {
        el.addEventListener("input", function () {
            const start = el.selectionStart;
            const end = el.selectionEnd;
            const dir = el.selectionDirection;
            const v = String(el.value || "").toUpperCase();
            if (el.value === v) {
                return;
            }
            el.value = v;
            if (start != null && typeof el.setSelectionRange === "function") {
                try {
                    el.setSelectionRange(start, end, dir || "forward");
                } catch (e2) {
                    /* noop */
                }
            }
        });
        el.addEventListener("blur", function () {
            crudNormalizeUppercaseField(el);
        });
        crudNormalizeUppercaseField(el);
    });
}

document.addEventListener("DOMContentLoaded", function () {

    initCrudRows();
    initCrudNuevo();
    initCrudSearch();
    initCrudDelete();
    initCrudAcciones();
    initCrudValidation();
    initCrudCatalog();
    initCrudUppercaseFields();
    initCrudZonaUbicacionToggle();
    initCrudEmpresaNitLookup();

});

/* =====================================================
   EMPRESA: LOOKUP NIT
===================================================== */

function initCrudEmpresaNitLookup() {
    const form = document.querySelector('form[data-crud-context="empresa"]');
    if (!form) return;

    const nitInput = form.querySelector('[name="nit"]');
    if (!nitInput) return;

    nitInput.addEventListener("blur", function () {
        const nit = String(nitInput.value || "").trim();
        if (nit === "") return;

        const idEl = form.querySelector('[name="id"]');
        const currentEmpresaId = idEl ? String(idEl.value || "").trim() : "";

        const url = new URL(window.location.origin + window.location.pathname);
        url.searchParams.set("url", "empresa/lookupNit");
        url.searchParams.set("nit", nit);
        if (currentEmpresaId !== "") {
            url.searchParams.set("empresa_id", currentEmpresaId);
        }

        fetch(url.toString(), { credentials: "same-origin" })
            .then(r => r.json())
            .then(data => {
                if (!data || !data.status) return;

                if (data.status === "empresa_exists") {
                    alert("⚠ " + (data.message || "Ya existe una empresa con este NIT."));
                    nitInput.value = "";
                    nitInput.focus();
                    return;
                }

                if (data.status === "inactive_tercero") {
                    alert("⚠ " + data.message);
                    return;
                }

                if (data.status === "tercero_only" || data.status === "same_empresa") {
                    const t = data.tercero || {};
                    const tIdEl = form.querySelector('[name="tercero_id"]');
                    if (tIdEl && data.tercero_id) tIdEl.value = data.tercero_id;
                    const iIdEl = form.querySelector('[name="terceroidentificacion_id"]');
                    if (iIdEl && data.terceroidentificacion_id) iIdEl.value = data.terceroidentificacion_id;

                    const map = {
                        razon_social: t.razon_social || "",
                        email: t.email || "",
                        telefono: t.telefono || "",
                        direccion: t.direccion || "",
                    };
                    Object.keys(map).forEach(key => {
                        const el = form.querySelector('[name="' + key + '"]');
                        if (!el) return;
                        if (String(el.value || "").trim() === "") {
                            el.value = map[key];
                            crudNormalizeUppercaseField(el);
                        }
                    });

                    if (data.status === "tercero_only" && data.message) {
                        showCrudFlashMessage(data.message);
                    }
                }
            })
            .catch(() => { /* silencioso */ });
    });
}

function showCrudFlashMessage(msg) {
    let div = document.getElementById("crudFlashMessage");
    if (!div) {
        div = document.createElement("div");
        div.id = "crudFlashMessage";
        div.style.cssText =
            "position:fixed; top:16px; right:16px; z-index:9999; max-width:340px; " +
            "padding:10px 14px; border-radius:8px; background:#fff8e1; border:1px solid #ffd54f; " +
            "color:#5d4037; box-shadow:0 6px 24px rgba(0,0,0,0.15); font-size:13px;";
        document.body.appendChild(div);
    }
    div.textContent = msg;
    div.style.display = "block";
    clearTimeout(div.__hideTimer);
    div.__hideTimer = setTimeout(() => { div.style.display = "none"; }, 6500);
}

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

            const usuarioForm = document.querySelector('form[data-crud-context="usuario"]');
            if (usuarioForm) {
                const trTerceroId = this.getAttribute("data-tercero-id");
                if (trTerceroId !== null) {
                    const tEl = usuarioForm.querySelector('[name="tercero_id"]');
                    if (tEl) tEl.value = trTerceroId;
                }
                const trIdentId = this.getAttribute("data-terceroidentificacion-id");
                if (trIdentId !== null) {
                    const iEl = usuarioForm.querySelector('[name="terceroidentificacion_id"]');
                    if (iEl) iEl.value = trIdentId;
                }
            }

            const empresaForm = document.querySelector('form[data-crud-context="empresa"]');
            if (empresaForm) {
                const trTerceroId = this.getAttribute("data-tercero-id");
                if (trTerceroId !== null) {
                    const tEl = empresaForm.querySelector('[name="tercero_id"]');
                    if (tEl) tEl.value = trTerceroId;
                }
                const trIdentId = this.getAttribute("data-terceroidentificacion-id");
                if (trIdentId !== null) {
                    const iEl = empresaForm.querySelector('[name="terceroidentificacion_id"]');
                    if (iEl) iEl.value = trIdentId;
                }
            }

            this.querySelectorAll("td[data-field]").forEach(cell => {

                let field = cell.dataset.field;
                let input = document.querySelector(`[name="${field}"]`);

                if (!input) return;

                if (input.type === "password" || input.dataset.password === "1") {
                    input.value = "";
                    return;
                }

                let value = cell.dataset.value ?? cell.innerText.trim();

                if (input.classList.contains("crud-catalog-id")) {
                    input.value = value;
                    if (field === "zona_id") {
                        const zt = cell.getAttribute("data-zona-tipo");
                        if (zt) {
                            input.dataset.zonaTipo = zt;
                        } else {
                            delete input.dataset.zonaTipo;
                        }
                    }
                    const wrap = input.closest(".crud-catalog-wrap");
                    const searchEl = wrap && wrap.querySelector(".crud-catalog-search");
                    if (searchEl) {
                        searchEl.value = cell.innerText.trim();
                        crudNormalizeUppercaseField(searchEl);
                    }
                    input.dispatchEvent(new Event("change", { bubbles: true }));
                    return;
                }

                input.value = value;
                crudNormalizeUppercaseField(input);

            });

            const zf = document.querySelector("form[data-crud-zona-ubicacion-toggle]");
            if (zf) {
                crudZonaUbicacionApplyFromForm(zf);
            }

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

        document.querySelectorAll(".crud-catalog-wrap").forEach((wrap) => {
            const hid = wrap.querySelector(".crud-catalog-id");
            const searchEl = wrap.querySelector(".crud-catalog-search");
            const dd = wrap.querySelector(".crud-catalog-dropdown");
            if (hid) {
                hid.value = "";
                if (hid.name === "zona_id") {
                    delete hid.dataset.zonaTipo;
                }
            }
            if (searchEl) searchEl.value = "";
            if (dd) {
                dd.hidden = true;
                dd.innerHTML = "";
            }
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

        const usuarioForm = document.querySelector('form[data-crud-context="usuario"]');
        if (usuarioForm) {
            const tEl = usuarioForm.querySelector('[name="tercero_id"]');
            if (tEl) tEl.value = "";
            const iEl = usuarioForm.querySelector('[name="terceroidentificacion_id"]');
            if (iEl) iEl.value = "";
            const ovEl = usuarioForm.querySelector('#usuario_email_overwrite_ok');
            if (ovEl) ovEl.value = "0";
            const acEl = usuarioForm.querySelector('#usuario_identificacion_accion');
            if (acEl) acEl.value = "update_principal";
        }

        const empresaForm = document.querySelector('form[data-crud-context="empresa"]');
        if (empresaForm) {
            const tEl = empresaForm.querySelector('[name="tercero_id"]');
            if (tEl) tEl.value = "";
            const iEl = empresaForm.querySelector('[name="terceroidentificacion_id"]');
            if (iEl) iEl.value = "";
        }

        selectedRow = null;
        selectedId = null;

        crudZonaUbicacionApplyFromForm(document.querySelector("form[data-crud-zona-ubicacion-toggle]"));

    });

}

function crudToggleRequiredInGroup(groupEl, enable) {
    if (!groupEl) return;
    if (enable) {
        groupEl.querySelectorAll("input, select, textarea").forEach(function (el) {
            if (el.dataset.crudSavedRequired === "1") {
                el.setAttribute("required", "required");
                delete el.dataset.crudSavedRequired;
            }
        });
    } else {
        groupEl.querySelectorAll("input[required], select[required], textarea[required]").forEach(function (el) {
            el.dataset.crudSavedRequired = "1";
            el.removeAttribute("required");
        });
    }
}

function crudClearInputsInGroup(groupEl) {
    if (!groupEl) return;
    groupEl.querySelectorAll("select.form-input").forEach(function (sel) {
        sel.selectedIndex = 0;
        sel.classList.remove("input-error");
    });
    groupEl.querySelectorAll(".crud-catalog-wrap").forEach(function (wrap) {
        const hid = wrap.querySelector(".crud-catalog-id");
        const searchEl = wrap.querySelector(".crud-catalog-search");
        const dd = wrap.querySelector(".crud-catalog-dropdown");
        if (hid) {
            hid.value = "";
            hid.classList.remove("input-error");
        }
        if (searchEl) {
            searchEl.value = "";
            searchEl.classList.remove("input-error");
        }
        if (dd) {
            dd.hidden = true;
            dd.innerHTML = "";
        }
    });
    groupEl.querySelectorAll("input.form-input:not(.crud-catalog-search)").forEach(function (inp) {
        if (inp.type === "password" || inp.dataset.password === "1") return;
        inp.value = "";
        inp.classList.remove("input-error");
    });
}

function crudGetZonaTipoFromForm(form) {
    if (!form) return "";
    const sel = form.querySelector('select[name="zona_id"]');
    if (sel) {
        const o = sel.options[sel.selectedIndex];
        if (o && o.dataset.zonaTipo) {
            return String(o.dataset.zonaTipo).toLowerCase();
        }
        return "";
    }
    const hid = form.querySelector('.crud-catalog-wrap[data-crud-zona-master] .crud-catalog-id[name="zona_id"]');
    if (hid && hid.dataset.zonaTipo) {
        return String(hid.dataset.zonaTipo).toLowerCase();
    }
    return "";
}

function crudZonaUbicacionApplyFromForm(form) {
    if (!form) return;
    if (!form.querySelector(".crud-zona-urban") && !form.querySelector(".crud-zona-rural")) return;
    const tipo = crudGetZonaTipoFromForm(form);
    const isRural = tipo === "rural";
    form.querySelectorAll(".crud-zona-urban").forEach(function (g) {
        const hide = isRural;
        g.classList.toggle("crud-zona-hidden", hide);
        crudToggleRequiredInGroup(g, !hide);
        if (hide) crudClearInputsInGroup(g);
    });
    form.querySelectorAll(".crud-zona-rural").forEach(function (g) {
        const hide = !isRural;
        g.classList.toggle("crud-zona-hidden", hide);
        crudToggleRequiredInGroup(g, !hide);
        if (hide) crudClearInputsInGroup(g);
    });
}

function initCrudZonaUbicacionToggle() {
    const form = document.querySelector("form[data-crud-zona-ubicacion-toggle]");
    if (!form) return;
    if (!form.querySelector(".crud-zona-urban") && !form.querySelector(".crud-zona-rural")) return;
    form.addEventListener("change", function (ev) {
        const t = ev.target;
        if (t && t.getAttribute && t.getAttribute("name") === "zona_id") {
            crudZonaUbicacionApplyFromForm(form);
        }
    });
    crudZonaUbicacionApplyFromForm(form);
}

/* =====================================================
   BUSQUEDA CRUD
===================================================== */

function debounceCrud(fn, ms) {
    let t = null;
    return function () {
        const args = arguments;
        const self = this;
        clearTimeout(t);
        t = setTimeout(function () {
            fn.apply(self, args);
        }, ms);
    };
}

function initCrudCatalog() {
    const form = document.querySelector("form[data-crud-context]");
    if (!form) return;

    const ctx = (form.getAttribute("data-crud-context") || "").trim();
    if (!ctx) return;

    const wraps = Array.prototype.slice.call(document.querySelectorAll(".crud-catalog-wrap"));
    if (!wraps.length) return;

    const parentToWraps = new Map();

    wraps.forEach(function (wrap) {
        let parents = [];
        try {
            parents = JSON.parse(wrap.getAttribute("data-parent-fields") || "[]");
        } catch (e) {
            parents = [];
        }
        if (!Array.isArray(parents)) parents = [];
        parents.forEach(function (p) {
            if (!parentToWraps.has(p)) parentToWraps.set(p, []);
            parentToWraps.get(p).push(wrap);
        });
    });

    parentToWraps.forEach(function (wrapList, pName) {
        const el = form.querySelector('[name="' + pName.replace(/"/g, "") + '"]');
        if (!el) return;
        const resetDependents = function () {
            wrapList.forEach(function (wrap) {
                const hid = wrap.querySelector(".crud-catalog-id");
                const searchEl = wrap.querySelector(".crud-catalog-search");
                const dd = wrap.querySelector(".crud-catalog-dropdown");
                if (hid) {
                    const wasZona = hid.name === "zona_id";
                    hid.value = "";
                    if (wasZona) {
                        delete hid.dataset.zonaTipo;
                        hid.dispatchEvent(new Event("change", { bubbles: true }));
                    }
                }
                if (searchEl) searchEl.value = "";
                if (dd) {
                    dd.hidden = true;
                    dd.innerHTML = "";
                }
            });
        };
        el.addEventListener("change", resetDependents);
        el.addEventListener("input", resetDependents);
    });

    if (!window.__savidCrudCatalogOutside) {
        window.__savidCrudCatalogOutside = true;
        document.addEventListener("click", function (ev) {
            document.querySelectorAll(".crud-catalog-wrap").forEach(function (w) {
                if (!w.contains(ev.target)) {
                    const d = w.querySelector(".crud-catalog-dropdown");
                    if (d) d.hidden = true;
                }
            });
        });
    }

    wraps.forEach(function (wrap) {
        const field = wrap.getAttribute("data-catalog-field");
        const hid = wrap.querySelector(".crud-catalog-id");
        const searchEl = wrap.querySelector(".crud-catalog-search");
        const dd = wrap.querySelector(".crud-catalog-dropdown");
        if (!field || !hid || !searchEl || !dd) return;

        let parents = [];
        try {
            parents = JSON.parse(wrap.getAttribute("data-parent-fields") || "[]");
        } catch (e2) {
            parents = [];
        }
        if (!Array.isArray(parents)) parents = [];

        let activeIdx = -1;

        function getSelectableItems() {
            return Array.prototype.slice.call(dd.querySelectorAll("li:not(.crud-catalog-hint)"));
        }

        function clearActiveHighlight() {
            dd.querySelectorAll("li.crud-catalog-option-active").forEach(function (li) {
                li.classList.remove("crud-catalog-option-active");
            });
        }

        function renderActiveHighlight() {
            const items = getSelectableItems();
            clearActiveHighlight();
            if (activeIdx >= 0 && activeIdx < items.length) {
                items[activeIdx].classList.add("crud-catalog-option-active");
                items[activeIdx].scrollIntoView({ block: "nearest", behavior: "smooth" });
            }
        }

        function moveActiveHighlight(delta) {
            const items = getSelectableItems();
            if (!items.length) {
                return;
            }
            if (activeIdx < 0) {
                activeIdx = delta > 0 ? 0 : items.length - 1;
            } else {
                activeIdx = (activeIdx + delta + items.length) % items.length;
            }
            renderActiveHighlight();
        }

        function applyCatalogChoice(li) {
            if (!li || li.classList.contains("crud-catalog-hint")) {
                return;
            }
            const id = li.getAttribute("data-catalog-id");
            const nombre = li.getAttribute("data-catalog-nombre");
            hid.value = id != null && id !== "" ? String(id) : "";
            searchEl.value = nombre != null && nombre !== "" ? nombre : String(li.textContent || "").trim();
            crudNormalizeUppercaseField(searchEl);
            if (field === "zona_id") {
                const zt = li.getAttribute("data-zona-tipo");
                if (zt) {
                    hid.dataset.zonaTipo = zt;
                } else {
                    delete hid.dataset.zonaTipo;
                }
            }
            dd.hidden = true;
            dd.innerHTML = "";
            activeIdx = -1;
            hid.classList.remove("input-error");
            hid.dispatchEvent(new Event("change", { bubbles: true }));
        }

        const buildUrl = function () {
            const u = new URL(window.location.href);
            u.search = "";
            u.searchParams.set("url", "module/catalogSearch");
            u.searchParams.set("context", ctx);
            u.searchParams.set("field", field);
            u.searchParams.set("q", searchEl.value.trim());
            parents.forEach(function (p) {
                const pel = form.querySelector('[name="' + String(p).replace(/"/g, "") + '"]');
                if (pel) u.searchParams.set("parent_" + p, pel.value);
            });
            return u.toString();
        };

        const doFetch = function () {
            activeIdx = -1;
            clearActiveHighlight();
            fetch(buildUrl(), { credentials: "same-origin" })
                .then(function (r) {
                    const ct = r.headers.get("content-type") || "";
                    if (!ct.includes("application/json")) {
                        return r.text().then(function (t) {
                            throw new Error(t ? "non-json" : "empty");
                        });
                    }
                    return r.json();
                })
                .then(function (data) {
                    dd.innerHTML = "";
                    if (!data || data.ok === false) {
                        dd.hidden = false;
                        const li = document.createElement("li");
                        li.className = "crud-catalog-hint";
                        li.textContent = (data && data.error) ? String(data.error) : "No se pudo cargar el catálogo.";
                        dd.appendChild(li);
                        return;
                    }
                    if (!Array.isArray(data.items)) {
                        dd.hidden = true;
                        return;
                    }
                    const parentsMissing = parents.some(function (p) {
                        const pel = form.querySelector('[name="' + String(p).replace(/"/g, "") + '"]');
                        return !pel || String(pel.value).trim() === "";
                    });
                    if (data.items.length === 0) {
                        dd.hidden = false;
                        const li = document.createElement("li");
                        li.className = "crud-catalog-hint";
                        if (parents.length && parentsMissing && searchEl.value.trim() === "") {
                            li.textContent = "Seleccione primero los datos de ubicación o jerarquía indicados arriba, o escriba para buscar.";
                        } else {
                            li.textContent = "Sin resultados.";
                        }
                        dd.appendChild(li);
                        return;
                    }
                    data.items.forEach(function (it) {
                        const li = document.createElement("li");
                        li.setAttribute("role", "option");
                        li.setAttribute("data-catalog-id", String(it.id));
                        li.setAttribute("data-catalog-nombre", it.nombre != null ? String(it.nombre) : "");
                        li.textContent = it.nombre || ("#" + it.id);
                        if (it.tipo !== undefined && it.tipo !== null && String(it.tipo) !== "") {
                            li.setAttribute("data-zona-tipo", String(it.tipo));
                        }
                        li.addEventListener("mousedown", function (ev) {
                            ev.preventDefault();
                            applyCatalogChoice(li);
                        });
                        dd.appendChild(li);
                    });
                    dd.hidden = false;
                })
                .catch(function () {
                    dd.innerHTML = "";
                    dd.hidden = false;
                    const li = document.createElement("li");
                    li.className = "crud-catalog-hint";
                    li.textContent = "Error de red o respuesta no válida.";
                    dd.appendChild(li);
                });
        };

        const debounced = debounceCrud(doFetch, 280);

        searchEl.addEventListener("input", debounced);
        searchEl.addEventListener("focus", function () {
            activeIdx = -1;
            doFetch();
        });

        searchEl.addEventListener("keydown", function (ev) {
            const key = ev.key;
            const items = getSelectableItems();

            if (key === "Escape") {
                if (!dd.hidden) {
                    ev.preventDefault();
                    dd.hidden = true;
                    activeIdx = -1;
                    clearActiveHighlight();
                }
                return;
            }

            if (!items.length) {
                return;
            }

            if (key === "ArrowDown") {
                ev.preventDefault();
                if (dd.hidden) {
                    dd.hidden = false;
                }
                moveActiveHighlight(1);
                return;
            }

            if (key === "ArrowUp") {
                ev.preventDefault();
                if (dd.hidden) {
                    dd.hidden = false;
                }
                moveActiveHighlight(-1);
                return;
            }

            if (key === "Tab" && ev.shiftKey) {
                return;
            }

            if (key === "Enter" || key === "Tab") {
                if (!dd.hidden && items.length) {
                    ev.preventDefault();
                    const idx = activeIdx >= 0 ? activeIdx : 0;
                    applyCatalogChoice(items[idx]);
                }
                return;
            }

            if (key === "Home" && !dd.hidden) {
                ev.preventDefault();
                activeIdx = 0;
                renderActiveHighlight();
                return;
            }

            if (key === "End" && !dd.hidden) {
                ev.preventDefault();
                activeIdx = items.length - 1;
                renderActiveHighlight();
            }
        });
    });
}

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

function crudResetUsuarioForm() {
    const btn = document.getElementById("btnNuevo");
    if (btn) {
        btn.click();
        return;
    }
    document.querySelectorAll(".form-input").forEach(input => {
        if (input.tagName === "SELECT") {
            input.selectedIndex = 0;
        } else {
            input.value = "";
        }
        input.classList.remove("input-error");
    });
    const hiddenId = document.getElementById("crud_id");
    if (hiddenId) hiddenId.value = "";
    selectedRow = null;
    selectedId = null;
}

function crudRemoveUsuarioRow(usuarioId) {
    if (!usuarioId) return;
    const row = document.querySelector('.crud-row[data-id="' + usuarioId + '"]');
    if (row && row.parentNode) {
        row.parentNode.removeChild(row);
    }
}

window.usuarioEmpresaSedeOnSaved = function (usuarioId) {};

window.usuarioEmpresaSedeOnLostVisibility = function (usuarioId) {
    crudResetUsuarioForm();
    crudRemoveUsuarioRow(usuarioId);
};

function initCrudAcciones() {

    document.querySelectorAll(".btn-accion").forEach(btn => {

        btn.addEventListener("click", function () {

            if (!selectedId) {
                alert("Seleccione un registro");
                return;
            }

            const raw = this.getAttribute("data-accion") ?? this.dataset.accion ?? "";
            const accion = String(raw).trim().toLowerCase();

            if (typeof openModalGod !== "function") {
                alert("Modal no disponible (openModalGod)");
                return;
            }

            const modales = {
                rol_permisos: [
                    "rol/permisos&id=" + selectedId,
                    "xl",
                    "Cargando permisos..."
                ],
                usuario_permisos: [
                    "usuario/permisos/" + selectedId,
                    "xl",
                    "Cargando permisos..."
                ],
                user_permisos: [
                    "usuario/permisos/" + selectedId,
                    "xl",
                    "Cargando permisos..."
                ],
                usuario_roles: [
                    "usuario/roles/" + selectedId,
                    "xl",
                    "Cargando roles..."
                ],
                user_roles: [
                    "usuario/roles/" + selectedId,
                    "xl",
                    "Cargando roles..."
                ],
                usuario_sedes: [
                    "usuario/empresa_sede/" + selectedId,
                    "lg",
                    "Cargando empresas y sedes..."
                ],
                usuario_sede: [
                    "usuario/empresa_sede/" + selectedId,
                    "lg",
                    "Cargando empresas y sedes..."
                ],
                tercero_identificaciones: [
                    "tercero/identificaciones/" + selectedId,
                    "lg",
                    "Cargando identificaciones…"
                ],
                empresa_sedes: [
                    "empresa/sedes/" + selectedId,
                    "lg",
                    "Cargando sedes..."
                ],
                empresa_usuarios: [
                    "empresa/usuarios/" + selectedId,
                    "lg",
                    "Cargando usuarios..."
                ]
            };

            const cfg = modales[accion];

            if (cfg) {
                openModalGod(cfg[0], cfg[1], cfg[2]);
            } else {
                alert("Acción sin handler en crud.js: " + (raw || "(vacío)") +
                    "\nNormalizada: " + accion);
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