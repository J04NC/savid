/**
 * Formulario CRUD usuario: DV NIT, foto/firma, validaciones tercero/usuario.
 */
(function () {
    const FACTORS = [3, 7, 13, 17, 19, 23, 29, 37, 41, 43, 47, 53, 59, 67, 71];
    const LOOKUP_DEBOUNCE_MS = 450;

    let lookupLock = false;
    let skipTipoNumeroLookup = false;
    let skipNumeroOnlyLookup = false;
    let skipEmailLookup = false;
    let skipUsernameLookup = false;
    let skipEmailTerceroLookup = false;
    let skipIdentLookup = false;
    let debounceTimers = {};
    let formSaveBlocked = false;

    function nitDvFromDigits(digits) {
        const nit = String(digits || "").replace(/\D/g, "");
        if (!nit) return "";
        let sum = 0;
        const L = nit.length;
        for (let i = 0; i < L; i++) {
            sum += parseInt(nit.charAt(L - 1 - i), 10) * FACTORS[i % FACTORS.length];
        }
        const r = sum % 11;
        const dv = r > 1 ? 11 - r : r;
        return String(dv);
    }

    function tipoMetaById(id) {
        const meta = window.__TIPO_DOC_USUARIO_META || {};
        const k = String(id || "").trim();
        if (!k) return null;
        return meta[k] || meta[parseInt(k, 10)] || null;
    }

    function isNitTipo(id) {
        const m = tipoMetaById(id);
        if (!m) return false;
        const cod = String(m.codigo || "").toUpperCase();
        if (cod === "NIT") return true;
        const nom = String(m.nombre || "");
        return nom.toUpperCase().indexOf("NIT") !== -1;
    }

    function getUsuarioId(form) {
        const hid = form.querySelector("#crud_id") || form.querySelector('[name="id"]');
        const v = hid ? String(hid.value || "").trim() : "";
        return v === "" ? null : parseInt(v, 10);
    }

    function getTerceroId(form) {
        const el = form.querySelector('[name="tercero_id"]');
        const v = el ? String(el.value || "").trim() : "";
        return v === "" ? null : parseInt(v, 10);
    }

    function getTerceroIdentificacionId(form) {
        const el = form.querySelector('[name="terceroidentificacion_id"]');
        const v = el ? String(el.value || "").trim() : "";
        return v === "" ? null : parseInt(v, 10);
    }

    /** Id del vínculo persona para APIs (identificación o tercero legado). */
    function getPersonaLinkId(form) {
        const identId = getTerceroIdentificacionId(form);
        if (identId) return identId;
        return getTerceroId(form);
    }

    function personaLinkQueryParam(form) {
        const identId = getTerceroIdentificacionId(form);
        if (identId) {
            return ["terceroidentificacion_id", String(identId)];
        }
        const tid = getTerceroId(form);
        if (tid) {
            return ["tercero_id", String(tid)];
        }
        return null;
    }

    function setSaveBlocked(blocked) {
        formSaveBlocked = !!blocked;
    }

    function markFieldError(form, name, on) {
        const el = form.querySelector('[name="' + name + '"]');
        if (!el) return;
        el.classList.toggle("input-error", !!on);
    }

    function syncEmailBaseline(form) {
        const email = form.querySelector('[name="email"]');
        if (email) {
            form.dataset.terceroEmailBaseline = String(email.value || "").trim();
        }
    }

    function showToast(msg, warn) {
        const el = document.getElementById("crud-usuario-toast");
        if (!el) return;
        el.textContent = msg;
        el.classList.toggle("crud-usuario-toast-warn", !!warn);
        el.hidden = false;
        clearTimeout(el._hideTimer);
        el._hideTimer = setTimeout(function () {
            el.hidden = true;
        }, warn ? 7000 : 5000);
    }

    function syncDvVisibility(form) {
        const sel = form.querySelector('[name="tipodocumento_id"]');
        const wrap = form.querySelector(".crud-usuario-dv-wrap");
        const num = form.querySelector('[name="numero_documento"]');
        const dv = form.querySelector('[name="documento_dv"]');
        if (!wrap || !dv) return;

        const id = sel ? String(sel.value || "").trim() : "";
        const nit = isNitTipo(id);

        wrap.style.display = nit ? "" : "none";
        if (!nit) {
            if (!lookupLock) {
                dv.value = "";
            }
            dv.readOnly = false;
            return;
        }
        dv.readOnly = true;
        if (num) {
            dv.value = nitDvFromDigits(num.value);
        }
    }

    function paintUploadPreview(wrap, previewEl, url) {
        previewEl.innerHTML = "";
        if (!url) {
            const ph = document.createElement("span");
            ph.className = "crud-upload-placeholder";
            ph.textContent = "Sin archivo";
            previewEl.appendChild(ph);
            return;
        }
        const subtype = (wrap.getAttribute("data-subtype") || "").toLowerCase();
        if (subtype === "image" || subtype === "signature") {
            const img = document.createElement("img");
            img.src = url;
            img.alt = "";
            img.className = "crud-upload-thumb";
            img.loading = "lazy";
            previewEl.appendChild(img);
        } else {
            const sp = document.createElement("span");
            sp.className = "crud-upload-filename";
            sp.textContent = url;
            previewEl.appendChild(sp);
        }
    }

    function refreshUploadPreviews(form) {
        form.querySelectorAll(".crud-upload-wrap").forEach(function (wrap) {
            const pathInput = wrap.querySelector(".crud-upload-path");
            const preview = wrap.querySelector(".crud-upload-preview");
            if (pathInput && preview) {
                paintUploadPreview(wrap, preview, pathInput.value);
            }
        });
    }

    function setField(form, name, value) {
        const el = form.querySelector('[name="' + name + '"]');
        if (!el) return;
        if (el.type === "password") return;
        if (el.tagName === "SELECT") {
            el.value = value == null || value === "" ? "" : String(value);
        } else {
            el.value = value == null ? "" : String(value);
        }
        if (el.classList.contains("crud-upload-path")) {
            const wrap = el.closest(".crud-upload-wrap");
            const preview = wrap && wrap.querySelector(".crud-upload-preview");
            if (wrap && preview) {
                paintUploadPreview(wrap, preview, el.value || "");
            }
        }
        el.dispatchEvent(new Event("change", { bubbles: true }));
    }

    function applyTerceroPayload(form, payload, usuario) {
        if (!payload) return;

        lookupLock = true;
        try {
            if (payload.tercero_id) {
                setField(form, "tercero_id", payload.tercero_id);
            }
            if (payload.terceroidentificacion_id) {
                setField(form, "terceroidentificacion_id", payload.terceroidentificacion_id);
            }
            if (payload.tipodocumento_id != null && payload.tipodocumento_id !== "") {
                setField(form, "tipodocumento_id", payload.tipodocumento_id);
            }
            if (payload.numero_documento != null) {
                setField(form, "numero_documento", payload.numero_documento);
            }
            if (payload.documento_dv != null) {
                setField(form, "documento_dv", payload.documento_dv);
            }
            setField(form, "nombres", payload.nombres || "");
            setField(form, "apellidos", payload.apellidos || "");
            setField(form, "email", payload.email || "");
            setField(form, "foto_ruta", payload.foto_ruta || "");
            setField(form, "firma_ruta", payload.firma_ruta || "");

            if (usuario) {
                if (usuario.username) setField(form, "username", usuario.username);
                if (usuario.sesion_idle_minutos != null && usuario.sesion_idle_minutos !== "") {
                    setField(form, "sesion_idle_minutos", usuario.sesion_idle_minutos);
                }
                if (usuario.estado_id != null) {
                    setField(form, "estado_id", usuario.estado_id);
                }
                const hid = form.querySelector("#crud_id");
                if (hid && usuario.id) {
                    hid.value = String(usuario.id);
                }
            }

            syncDvVisibility(form);
            refreshUploadPreviews(form);
            syncEmailBaseline(form);
        } finally {
            lookupLock = false;
        }
    }

    function handleLookupBlocked(data) {
        if (!data || !data.blocked) {
            setSaveBlocked(false);
            return false;
        }
        setSaveBlocked(true);
        showToast(data.message || "Operación no permitida.", true);
        return true;
    }

    function clearTerceroLink(form) {
        setField(form, "tercero_id", "");
        setField(form, "terceroidentificacion_id", "");
    }

    function resolveAppUrl(relative) {
        const rel = String(relative || "").trim();
        if (rel.indexOf("http://") === 0 || rel.indexOf("https://") === 0) {
            return rel;
        }
        try {
            return new URL(rel, window.location.href).href;
        } catch (e) {
            return rel;
        }
    }

    function fetchJson(url) {
        return fetch(resolveAppUrl(url), { credentials: "same-origin" }).then(function (r) {
            return r.text().then(function (text) {
                try {
                    return JSON.parse(text);
                } catch (e) {
                    const err = new Error("invalid_json");
                    err.status = r.status;
                    err.body = text;
                    throw err;
                }
            });
        });
    }

    function debounce(key, fn) {
        clearTimeout(debounceTimers[key]);
        debounceTimers[key] = setTimeout(fn, LOOKUP_DEBOUNCE_MS);
    }

    function showPickModal(title, message, options, onPick, onNew) {
        const overlay = document.createElement("div");
        overlay.className = "crud-usuario-pick-overlay";
        overlay.setAttribute("role", "dialog");
        overlay.setAttribute("aria-modal", "true");

        const dialog = document.createElement("div");
        dialog.className = "crud-usuario-pick-dialog";
        dialog.innerHTML =
            "<h3></h3><p></p><ul class=\"crud-usuario-pick-list\"></ul><div class=\"crud-usuario-pick-actions\"></div>";

        dialog.querySelector("h3").textContent = title;
        dialog.querySelector("p").textContent = message;

        const list = dialog.querySelector(".crud-usuario-pick-list");
        options.forEach(function (opt, idx) {
            const li = document.createElement("li");
            const btn = document.createElement("button");
            btn.type = "button";
            btn.textContent = opt.label || ("Opción " + (idx + 1));
            btn.addEventListener("click", function () {
                document.body.removeChild(overlay);
                onPick(opt);
            });
            li.appendChild(btn);
            list.appendChild(li);
        });

        const actions = dialog.querySelector(".crud-usuario-pick-actions");
        const btnNew = document.createElement("button");
        btnNew.type = "button";
        btnNew.textContent = "Crear tercero nuevo";
        btnNew.addEventListener("click", function () {
            document.body.removeChild(overlay);
            onNew();
        });
        const btnCancel = document.createElement("button");
        btnCancel.type = "button";
        btnCancel.textContent = "Cancelar";
        btnCancel.addEventListener("click", function () {
            document.body.removeChild(overlay);
        });
        actions.appendChild(btnNew);
        actions.appendChild(btnCancel);

        overlay.appendChild(dialog);
        overlay.addEventListener("click", function (e) {
            if (e.target === overlay) {
                document.body.removeChild(overlay);
            }
        });
        document.body.appendChild(overlay);
    }

    function showConfirmModal(title, message, primaryLabel, onPrimary, onCancel) {
        const overlay = document.createElement("div");
        overlay.className = "crud-usuario-pick-overlay";
        const dialog = document.createElement("div");
        dialog.className = "crud-usuario-pick-dialog";
        dialog.innerHTML = "<h3></h3><p></p><div class=\"crud-usuario-pick-actions\"></div>";
        dialog.querySelector("h3").textContent = title;
        dialog.querySelector("p").textContent = message;
        const actions = dialog.querySelector(".crud-usuario-pick-actions");
        const btnOk = document.createElement("button");
        btnOk.type = "button";
        btnOk.className = "btn-primary-pick";
        btnOk.textContent = primaryLabel;
        btnOk.addEventListener("click", function () {
            document.body.removeChild(overlay);
            onPrimary();
        });
        const btnNo = document.createElement("button");
        btnNo.type = "button";
        btnNo.textContent = "Cancelar";
        btnNo.addEventListener("click", function () {
            document.body.removeChild(overlay);
            if (onCancel) onCancel();
        });
        actions.appendChild(btnOk);
        actions.appendChild(btnNo);
        overlay.appendChild(dialog);
        overlay.addEventListener("click", function (e) {
            if (e.target === overlay) {
                document.body.removeChild(overlay);
                if (onCancel) onCancel();
            }
        });
        document.body.appendChild(overlay);
    }

    function showIdentificacionChoiceModal(message, onUpdatePrincipal, onNewRow, onCancel) {
        const overlay = document.createElement("div");
        overlay.className = "crud-usuario-pick-overlay";
        const dialog = document.createElement("div");
        dialog.className = "crud-usuario-pick-dialog";
        dialog.innerHTML = "<h3>Cambio de identificación</h3><p></p><div class=\"crud-usuario-pick-actions\"></div>";
        dialog.querySelector("p").textContent = message;
        const actions = dialog.querySelector(".crud-usuario-pick-actions");
        const b1 = document.createElement("button");
        b1.type = "button";
        b1.className = "btn-primary-pick";
        b1.textContent = "Actualizar principal";
        b1.addEventListener("click", function () {
            document.body.removeChild(overlay);
            onUpdatePrincipal();
        });
        const b2 = document.createElement("button");
        b2.type = "button";
        b2.textContent = "Nueva fila de identificación";
        b2.addEventListener("click", function () {
            document.body.removeChild(overlay);
            onNewRow();
        });
        const b3 = document.createElement("button");
        b3.type = "button";
        b3.textContent = "Cancelar";
        b3.addEventListener("click", function () {
            document.body.removeChild(overlay);
            if (onCancel) onCancel();
        });
        actions.appendChild(b1);
        actions.appendChild(b2);
        actions.appendChild(b3);
        overlay.appendChild(dialog);
        document.body.appendChild(overlay);
    }

    function lookupTipoNumero(form) {
        if (skipTipoNumeroLookup || lookupLock) return;

        const tipo = form.querySelector('[name="tipodocumento_id"]');
        const num = form.querySelector('[name="numero_documento"]');
        if (!tipo || !num) return;

        const tipoId = String(tipo.value || "").trim();
        const numero = String(num.value || "").trim();
        if (!tipoId || !numero) return;

        const uid = getUsuarioId(form);
        const q = new URLSearchParams({
            tipodocumento_id: tipoId,
            numero_documento: numero,
        });
        if (uid) q.set("usuario_id", String(uid));

        fetchJson("?url=usuario/lookupDocumento&" + q.toString()).then(function (data) {
            if (!data || data.status === "none") {
                setSaveBlocked(false);
                return;
            }

            if (handleLookupBlocked(data)) {
                if (data.tercero) applyTerceroPayload(form, data.tercero, data.usuario || null);
                return;
            }

            if (data.status === "tercero_only") {
                applyTerceroPayload(form, data.tercero);
                setSaveBlocked(false);
                showToast(data.message || "Datos del tercero cargados.");
                return;
            }

            if (data.status === "both") {
                applyTerceroPayload(form, data.tercero, data.usuario);
                setSaveBlocked(!!data.blocked);
                const isWarn = !!data.blocked;
                showToast(data.message || "Usuario existente.", isWarn);
            }
        }).catch(function () { /* silencioso */ });
    }

    function lookupUsername(form) {
        if (skipUsernameLookup || lookupLock) return;

        const userEl = form.querySelector('[name="username"]');
        if (!userEl) return;

        const username = String(userEl.value || "").trim();
        if (!username) {
            markFieldError(form, "username", false);
            setSaveBlocked(false);
            return;
        }

        const uid = getUsuarioId(form);
        const q = new URLSearchParams({ username: username });
        const linkParam = personaLinkQueryParam(form);
        if (linkParam) q.set(linkParam[0], linkParam[1]);
        if (uid) q.set("usuario_id", String(uid));

        fetchJson("?url=usuario/lookupUsername&" + q.toString()).then(function (data) {
            if (!data || data.status === "none") {
                markFieldError(form, "username", false);
                setSaveBlocked(false);
                return;
            }

            markFieldError(form, "username", !!data.blocked);

            if (data.status === "same_tercero" && !data.blocked) {
                if (data.usuario) {
                    const linkPayload = {};
                    const tid = getTerceroId(form);
                    const identId = getTerceroIdentificacionId(form);
                    if (tid) linkPayload.tercero_id = tid;
                    if (identId) linkPayload.terceroidentificacion_id = identId;
                    applyTerceroPayload(form, linkPayload, data.usuario);
                }
                showToast(data.message || "Al guardar se vinculará a su empresa.", false);
                setSaveBlocked(false);
                return;
            }

            if (handleLookupBlocked(data)) {
                if (data.status === "same_tercero" && data.usuario) {
                    const linkPayload = {};
                    const tid = getTerceroId(form);
                    const identId = getTerceroIdentificacionId(form);
                    if (tid) linkPayload.tercero_id = tid;
                    if (identId) linkPayload.terceroidentificacion_id = identId;
                    applyTerceroPayload(form, linkPayload, data.usuario);
                }
            }
        }).catch(function () { /* silencioso */ });
    }

    function lookupEmailTercero(form) {
        if (skipEmailTerceroLookup || lookupLock) return;

        const tid = getTerceroId(form);
        const emailEl = form.querySelector('[name="email"]');
        const hid = form.querySelector("#usuario_email_overwrite_ok");
        if (!tid || !emailEl) return;

        const email = String(emailEl.value || "").trim();
        if (!email || email.indexOf("@") < 1) return;

        const q = new URLSearchParams({ email: email, tercero_id: String(tid) });

        fetchJson("?url=usuario/lookupEmailTercero&" + q.toString()).then(function (data) {
            if (!data || data.status !== "confirm_overwrite") {
                if (hid) hid.value = "0";
                markFieldError(form, "email", false);
                return;
            }

            showConfirmModal(
                "Correo distinto al del tercero",
                data.message || "¿Actualizar el correo del tercero?",
                "Sí, actualizar",
                function () {
                    if (hid) hid.value = "1";
                    markFieldError(form, "email", false);
                    showToast("Se actualizará el correo del tercero al guardar.");
                },
                function () {
                    if (hid) hid.value = "0";
                    const base = form.dataset.terceroEmailBaseline || "";
                    if (base) emailEl.value = base;
                    markFieldError(form, "email", true);
                    showToast("No se cambiará el correo del tercero.", true);
                }
            );
        }).catch(function () { /* silencioso */ });
    }

    function lookupIdentificacion(form) {
        if (skipIdentLookup || lookupLock) return;

        const tid = getTerceroId(form);
        const tipo = form.querySelector('[name="tipodocumento_id"]');
        const num = form.querySelector('[name="numero_documento"]');
        const hid = form.querySelector("#usuario_identificacion_accion");
        if (!tid || !tipo || !num) return;

        const tipoId = String(tipo.value || "").trim();
        const numero = String(num.value || "").trim();
        if (!tipoId || !numero) return;

        const q = new URLSearchParams({
            tercero_id: String(tid),
            tipodocumento_id: tipoId,
            numero_documento: numero,
        });

        fetchJson("?url=usuario/lookupIdentificacion&" + q.toString()).then(function (data) {
            if (!data || data.status !== "confirm_change") {
                if (hid) hid.value = "update_principal";
                return;
            }

            showIdentificacionChoiceModal(
                data.message || "¿Cómo desea registrar el cambio de documento?",
                function () {
                    if (hid) hid.value = "update_principal";
                    showToast("Se actualizará la identificación principal al guardar.");
                },
                function () {
                    if (hid) hid.value = "new_row";
                    showToast("Se creará una nueva fila de identificación al guardar.");
                },
                function () {
                    if (hid) hid.value = "update_principal";
                }
            );
        }).catch(function () { /* silencioso */ });
    }

    function validatePasswordClient(form) {
        const pwd = form.querySelector('[name="password"]');
        if (!pwd) return true;
        const v = String(pwd.value || "");
        const uid = getUsuarioId(form);
        if (uid && v === "") return true;
        if (!uid && v === "") {
            markFieldError(form, "password", true);
            return false;
        }
        const ok = v.length >= 8 && /[a-záéíóúñ]/i.test(v) && /[A-ZÁÉÍÓÚÑ]/.test(v) && /\d/.test(v);
        markFieldError(form, "password", !ok);
        if (!ok) {
            showToast("Contraseña: mínimo 8 caracteres, mayúscula, minúscula y número.", true);
        }
        return ok;
    }

    function lookupNumeroOnly(form) {
        if (skipNumeroOnlyLookup || lookupLock) return;

        const tipo = form.querySelector('[name="tipodocumento_id"]');
        const num = form.querySelector('[name="numero_documento"]');
        if (!num) return;

        const numero = String(num.value || "").trim();
        if (!numero) return;

        if (tipo && String(tipo.value || "").trim() !== "") {
            return;
        }

        const uid = getUsuarioId(form);
        const q = new URLSearchParams({ numero_documento: numero });
        if (uid) q.set("usuario_id", String(uid));

        fetchJson("?url=usuario/lookupDocumentoNumero&" + q.toString()).then(function (data) {
            if (!data || data.status === "none") return;

            if (data.status === "single") {
                applyTerceroPayload(form, data.tercero);
                if (data.tipodocumento_id) {
                    setField(form, "tipodocumento_id", data.tipodocumento_id);
                }
                showToast("Se encontró un tercero con ese número; datos cargados.");
                return;
            }

            if (data.status === "multiple" && Array.isArray(data.options)) {
                showPickModal(
                    "Varios terceros con el mismo número",
                    data.message || "¿Desea asociar el usuario a uno de estos terceros o crear uno nuevo?",
                    data.options,
                    function (opt) {
                        skipNumeroOnlyLookup = true;
                        applyTerceroPayload(form, opt.tercero);
                        if (opt.tipodocumento_id) {
                            setField(form, "tipodocumento_id", opt.tipodocumento_id);
                        }
                        window.setTimeout(function () {
                            skipNumeroOnlyLookup = false;
                        }, 800);
                    },
                    function () {
                        skipNumeroOnlyLookup = true;
                        clearTerceroLink(form);
                        showToast("Se creará un tercero nuevo al guardar.", true);
                        window.setTimeout(function () {
                            skipNumeroOnlyLookup = false;
                        }, 800);
                    }
                );
            }
        }).catch(function () { /* silencioso */ });
    }

    function lookupEmail(form) {
        if (skipEmailLookup || lookupLock) return;

        const emailEl = form.querySelector('[name="email"]');
        if (!emailEl) return;

        const email = String(emailEl.value || "").trim();
        if (!email || email.indexOf("@") < 1) return;

        const uid = getUsuarioId(form);
        const q = new URLSearchParams({ email: email });
        if (uid) q.set("usuario_id", String(uid));

        fetchJson("?url=usuario/lookupEmail&" + q.toString()).then(function (data) {
            if (!data || data.status === "none") return;

            if (data.status === "single") {
                applyTerceroPayload(form, data.tercero);
                if (data.tipodocumento_id) {
                    setField(form, "tipodocumento_id", data.tipodocumento_id);
                }
                showToast("Correo ya registrado en un tercero; datos cargados.");
                return;
            }

            if (data.status === "multiple" && Array.isArray(data.options)) {
                showPickModal(
                    "Correo en varios terceros",
                    data.message || "¿Asociar el usuario a uno de estos terceros o crear uno nuevo?",
                    data.options,
                    function (opt) {
                        skipEmailLookup = true;
                        applyTerceroPayload(form, opt.tercero);
                        if (opt.tipodocumento_id) {
                            setField(form, "tipodocumento_id", opt.tipodocumento_id);
                        }
                        window.setTimeout(function () {
                            skipEmailLookup = false;
                        }, 800);
                    },
                    function () {
                        skipEmailLookup = true;
                        clearTerceroLink(form);
                        showToast("Se creará un tercero nuevo al guardar.", true);
                        window.setTimeout(function () {
                            skipEmailLookup = false;
                        }, 800);
                    }
                );
            }
        }).catch(function () { /* silencioso */ });
    }

    function bindLookups(form) {
        const selTipo = form.querySelector('[name="tipodocumento_id"]');
        const num = form.querySelector('[name="numero_documento"]');
        const email = form.querySelector('[name="email"]');

        function scheduleTipoNumero() {
            debounce("tipoNumero", function () {
                lookupTipoNumero(form);
            });
        }

        if (selTipo) {
            selTipo.addEventListener("change", function () {
                syncDvVisibility(form);
                scheduleTipoNumero();
            });
        }

        if (num) {
            num.addEventListener("input", function () {
                if (isNitTipo(selTipo && selTipo.value)) {
                    const dv = form.querySelector('[name="documento_dv"]');
                    if (dv) dv.value = nitDvFromDigits(num.value);
                }
            });
            num.addEventListener("blur", function () {
                const tipoVal = selTipo ? String(selTipo.value || "").trim() : "";
                if (tipoVal) {
                    scheduleTipoNumero();
                } else {
                    debounce("numOnly", function () {
                        lookupNumeroOnly(form);
                    });
                }
            });
        }

        const username = form.querySelector('[name="username"]');
        if (username) {
            username.addEventListener("blur", function () {
                debounce("username", function () {
                    lookupUsername(form);
                });
            });
        }

        if (email) {
            email.addEventListener("blur", function () {
                debounce("email", function () {
                    const tid = getTerceroId(form);
                    if (tid) {
                        lookupEmailTercero(form);
                    } else {
                        lookupEmail(form);
                    }
                });
            });
        }

        if (num && selTipo) {
            const onIdentBlur = function () {
                if (getTerceroId(form)) {
                    debounce("ident", function () {
                        lookupIdentificacion(form);
                    });
                }
            };
            num.addEventListener("blur", onIdentBlur);
            selTipo.addEventListener("change", function () {
                if (getTerceroId(form)) {
                    debounce("ident", function () {
                        lookupIdentificacion(form);
                    });
                }
            });
        }
    }

    function bindTipoAndNumero(form) {
        const sel = form.querySelector('[name="tipodocumento_id"]');
        const num = form.querySelector('[name="numero_documento"]');
        if (sel) {
            sel.addEventListener("change", function () {
                syncDvVisibility(form);
            });
        }
        if (num) {
            num.addEventListener("input", function () {
                if (isNitTipo(sel && sel.value)) {
                    const dv = form.querySelector('[name="documento_dv"]');
                    if (dv) dv.value = nitDvFromDigits(num.value);
                }
            });
        }
    }

    document.addEventListener("crud-usuario-row-filled", function (ev) {
        const form = ev.detail && ev.detail.form;
        if (!form || form.getAttribute("data-crud-context") !== "usuario") return;
        syncDvVisibility(form);
        syncEmailBaseline(form);
    });

    function initForm(form) {
        bindTipoAndNumero(form);
        bindLookups(form);
        syncDvVisibility(form);
        syncEmailBaseline(form);

        form.addEventListener("submit", function (ev) {
            if (!validatePasswordClient(form)) {
                ev.preventDefault();
                return;
            }
            if (formSaveBlocked) {
                ev.preventDefault();
                showToast("Corrija las validaciones antes de guardar.", true);
            }
        }, true);
    }

    document.addEventListener("DOMContentLoaded", function () {
        const form = document.querySelector('form[data-crud-context="usuario"]');
        if (!form) return;
        initForm(form);
    });
})();
