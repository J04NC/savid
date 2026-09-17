(function () {
    'use strict';

    var tabButtons = document.querySelectorAll('.sihos-interlab-tab-btn');
    if (!tabButtons.length) return;

    var panels = {
        homologacion: document.getElementById('sihosInterlabTabHomologacion'),
        solicitudes: document.getElementById('sihosInterlabTabSolicitudes'),
        resultados: document.getElementById('sihosInterlabTabResultados'),
    };

    function activarTab(nombre) {
        Object.keys(panels).forEach(function (key) {
            if (!panels[key]) return;
            panels[key].hidden = key !== nombre;
        });
        tabButtons.forEach(function (btn) {
            var activo = btn.getAttribute('data-tab') === nombre;
            btn.setAttribute('aria-selected', activo ? 'true' : 'false');
            btn.classList.toggle('sihos-interlab-tab-activa', activo);
        });
        try {
            sessionStorage.setItem('sihosInterlabTabActiva', nombre);
        } catch (e) {
            // almacenamiento no disponible (navegación privada, etc.) — no es crítico
        }
    }

    tabButtons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            activarTab(btn.getAttribute('data-tab'));
        });
    });

    var tabInicial = 'homologacion';
    try {
        var guardada = sessionStorage.getItem('sihosInterlabTabActiva');
        if (guardada && panels[guardada]) tabInicial = guardada;
    } catch (e) {
        // ignorar
    }
    // Si se acaba de consultar solicitudes o resultados (recarga con ?buscar_s=1
    // o ?buscar_r=1), muestra esa pestaña en vez de la guardada — el usuario
    // acaba de pedir ver justamente eso.
    var params = new URLSearchParams(window.location.search);
    if (params.has('buscar_r')) tabInicial = 'resultados';
    else if (params.has('buscar_s')) tabInicial = 'solicitudes';

    activarTab(tabInicial);

    /* ---- Contadores de selección (checkbox "seleccionar todo" + botón masivo) ---- */

    function wireSelectAll(selectAllId, checkboxClass, btnId, etiquetaBase) {
        var selectAll = document.getElementById(selectAllId);
        var btn = document.getElementById(btnId);
        if (!selectAll || !btn) return;

        function checkboxes() {
            return Array.prototype.slice.call(document.querySelectorAll('.' + checkboxClass));
        }

        function actualizar() {
            var n = checkboxes().filter(function (cb) { return cb.checked; }).length;
            btn.textContent = etiquetaBase + ' (' + n + ')';
            btn.disabled = n === 0;
        }

        selectAll.addEventListener('click', function () {
            checkboxes().forEach(function (cb) {
                var fila = cb.closest('tr');
                if (fila && fila.offsetParent !== null) cb.checked = selectAll.checked;
            });
            actualizar();
        });

        document.addEventListener('change', function (ev) {
            if (ev.target && ev.target.classList && ev.target.classList.contains(checkboxClass)) {
                actualizar();
            }
        });

        actualizar();
    }

    wireSelectAll('sihosResuSelectAll', 'sihosResuCheckbox', 'btnSihosResuMasivo', '▶️ Procesar seleccionados');
    wireSelectAll('sihosSoliSelectAll', 'sihosSoliCandidataCheckbox', 'btnSihosSoliMasivo', '▶️ Procesar seleccionadas');

    /* ---- Datos del scope actual (empresa) ---- */

    function empresaIdActual() {
        var params = new URLSearchParams(window.location.search);
        return params.get('empresa_id') || '';
    }

    /* ---- Procesamiento masivo: fetch secuencial, progreso, detener, errores ---- */

    function procesarMasivo(opciones) {
        var btn = document.getElementById(opciones.btnId);
        var contenedorProgreso = document.getElementById(opciones.progresoId);
        if (!btn || !contenedorProgreso) return;

        var detener = false;
        var btnDetener = null;

        btn.addEventListener('click', async function () {
            var checkboxes = Array.prototype.slice.call(document.querySelectorAll('.' + opciones.checkboxClass))
                .filter(function (cb) { return cb.checked; });

            if (checkboxes.length === 0) return;

            if (!window.confirm('¿Procesar ' + checkboxes.length + ' fila(s) en SIHOS? Esta acción escribe datos reales y no se puede deshacer automáticamente.')) {
                return;
            }

            btn.disabled = true;
            detener = false;
            contenedorProgreso.hidden = false;
            contenedorProgreso.innerHTML =
                '<div>Procesando <span id="' + opciones.progresoId + 'Contador">0</span>/' + checkboxes.length + '… ' +
                '(✅ <span id="' + opciones.progresoId + 'Ok">0</span> ok · ⚠️ <span id="' + opciones.progresoId + 'Err">0</span> con error)</div>' +
                '<button type="button" id="' + opciones.progresoId + 'Detener" class="auditoria-btn-secondary" style="margin-top:6px;">⏹ Detener</button>' +
                '<ul id="' + opciones.progresoId + 'Lista"></ul>';

            btnDetener = document.getElementById(opciones.progresoId + 'Detener');
            btnDetener.addEventListener('click', function () {
                detener = true;
                btnDetener.disabled = true;
                btnDetener.textContent = 'Deteniendo…';
            });

            var spanContador = document.getElementById(opciones.progresoId + 'Contador');
            var spanOk = document.getElementById(opciones.progresoId + 'Ok');
            var spanErr = document.getElementById(opciones.progresoId + 'Err');
            var lista = document.getElementById(opciones.progresoId + 'Lista');

            var procesados = 0, ok = 0, err = 0;

            for (var i = 0; i < checkboxes.length; i++) {
                if (detener) break;

                var resultado = await opciones.procesarUno(checkboxes[i]);
                procesados++;
                if (resultado.ok) ok++; else err++;

                spanContador.textContent = String(procesados);
                spanOk.textContent = String(ok);
                spanErr.textContent = String(err);

                if (!resultado.ok || opciones.mostrarTodosLosMensajes) {
                    var li = document.createElement('li');
                    li.textContent = (resultado.etiqueta || '') + ': ' + (resultado.message || (resultado.ok ? 'Listo' : 'Error'));
                    lista.appendChild(li);
                }
            }

            btnDetener.hidden = true;
            var resumen = document.createElement('div');
            resumen.style.marginTop = '8px';
            resumen.style.fontWeight = 'bold';
            resumen.textContent = detener
                ? 'Detenido: ' + procesados + '/' + checkboxes.length + ' procesados.'
                : 'Terminado: ' + ok + ' ok, ' + err + ' con error.';
            contenedorProgreso.appendChild(resumen);

            btn.disabled = false;
            window.location.reload();
        });
    }

    procesarMasivo({
        btnId: 'btnSihosResuMasivo',
        progresoId: 'sihosResuMasivoProgreso',
        checkboxClass: 'sihosResuCheckbox',
        procesarUno: async function (cb) {
            var fd = new FormData();
            fd.append('empresa_id', empresaIdActual());
            fd.append('id', cb.getAttribute('data-id'));
            try {
                var r = await fetch('?url=sihos/interfazLaboratorioProcesarResultado', { method: 'POST', body: fd, credentials: 'same-origin' });
                var j = await r.json();
                return { ok: !!j.ok, message: j.message, etiqueta: 'id ' + cb.getAttribute('data-id') };
            } catch (e) {
                return { ok: false, message: 'Error de conexión.', etiqueta: 'id ' + cb.getAttribute('data-id') };
            }
        },
    });

    procesarMasivo({
        btnId: 'btnSihosSoliMasivo',
        progresoId: 'sihosSoliMasivoProgreso',
        checkboxClass: 'sihosSoliCandidataCheckbox',
        procesarUno: async function (cb) {
            var fd = new FormData();
            fd.append('empresa_id', empresaIdActual());
            var campos = ['codi-inst', 'cons-admi', 'cons-orde', 'item', 'codi-modu', 'codi-proc', 'obse-proc',
                'cons-de-fa', 'fech-digi', 'hora-digi', 'usua-digi', 'tipo-docu', 'nume-usua', 'nume-liqu', 'tipo-orde', 'tipo-interfaz'];
            var nombresPost = ['codi_inst', 'cons_admi', 'cons_orde', 'item', 'codi_modu', 'codi_proc', 'obse_proc',
                'cons_de_fa', 'fech_digi', 'hora_digi', 'usua_digi', 'tipo_docu', 'nume_usua', 'nume_liqu', 'tipo_orde', 'tipo_interfaz'];
            campos.forEach(function (campo, idx) {
                fd.append(nombresPost[idx], cb.getAttribute('data-' + campo) || '');
            });
            var etiqueta = cb.getAttribute('data-cons-admi') + '-' + cb.getAttribute('data-cons-orde') + '-' + cb.getAttribute('data-item');
            try {
                var r = await fetch('?url=sihos/interfazLaboratorioProcesarSolicitud', { method: 'POST', body: fd, credentials: 'same-origin' });
                var j = await r.json();
                return { ok: !!j.ok, message: j.message, etiqueta: etiqueta };
            } catch (e) {
                return { ok: false, message: 'Error de conexión.', etiqueta: etiqueta };
            }
        },
    });

    /* ---- Homologación: crear/editar/eliminar ---- */

    var homoModal = document.getElementById('sihosHomoModal');
    if (homoModal) {
        var homoFormCups = document.getElementById('sihosHomoFormCups');
        var homoFormAnalito = document.getElementById('sihosHomoFormAnalito');
        var homoFormPrue = document.getElementById('sihosHomoFormPrue');
        var homoCupsAnterior = document.getElementById('sihosHomoCodiCupsAnterior');
        var homoPrueAnterior = document.getElementById('sihosHomoCodiPrueAnterior');
        var homoAnalitoAnterior = document.getElementById('sihosHomoAnalitoAnterior');
        var homoError = document.getElementById('sihosHomoFormError');
        var btnNueva = document.getElementById('btnSihosHomoNueva');
        var btnCancelar = document.getElementById('btnSihosHomoCancelar');
        var btnGuardar = document.getElementById('btnSihosHomoGuardar');

        function abrirModal(esEdicion, datos) {
            homoError.hidden = true;
            homoFormCups.value = datos ? datos.cups : '';
            homoFormAnalito.value = datos ? datos.analito : '';
            homoFormPrue.value = datos ? datos.prue : '';
            homoCupsAnterior.value = esEdicion ? datos.cups : '';
            homoPrueAnterior.value = esEdicion ? datos.prue : '';
            homoAnalitoAnterior.value = esEdicion ? datos.analito : '';
            homoModal.hidden = false;
            homoFormCups.focus();
        }

        function cerrarModal() {
            homoModal.hidden = true;
        }

        if (btnNueva) btnNueva.addEventListener('click', function () { abrirModal(false, null); });
        if (btnCancelar) btnCancelar.addEventListener('click', cerrarModal);
        homoModal.addEventListener('click', function (ev) {
            if (ev.target === homoModal) cerrarModal();
        });

        document.addEventListener('click', function (ev) {
            var btn = ev.target.closest('.sihosHomoEditar');
            if (!btn) return;
            abrirModal(true, { cups: btn.getAttribute('data-codi-cups'), prue: btn.getAttribute('data-codi-prue'), analito: btn.getAttribute('data-analito') });
        });

        document.addEventListener('click', async function (ev) {
            var btn = ev.target.closest('.sihosHomoEliminar');
            if (!btn) return;

            var cups = btn.getAttribute('data-codi-cups');
            var prue = btn.getAttribute('data-codi-prue');
            var analito = btn.getAttribute('data-analito');

            if (!window.confirm('¿Eliminar la homologación ' + cups + ' / analito ' + analito + ' / prueba ' + prue + '?')) return;

            var fd = new FormData();
            fd.append('empresa_id', empresaIdActual());
            fd.append('codi_cups', cups);
            fd.append('codi_prue', prue);
            fd.append('analito', analito);

            try {
                var r = await fetch('?url=sihos/interfazLaboratorioHomologacionEliminar', { method: 'POST', body: fd, credentials: 'same-origin' });
                var j = await r.json();
                if (!j.ok) { window.alert(j.message || 'No se pudo eliminar.'); return; }
                window.location.reload();
            } catch (e) {
                window.alert('Error de conexión.');
            }
        });

        if (btnGuardar) {
            btnGuardar.addEventListener('click', async function () {
                var cups = homoFormCups.value.trim();
                var analito = homoFormAnalito.value.trim();
                var prue = homoFormPrue.value.trim();

                if (!cups || !analito || !prue) {
                    homoError.textContent = 'CUPS, Analito y CodiPrue son obligatorios.';
                    homoError.hidden = false;
                    return;
                }

                var fd = new FormData();
                fd.append('empresa_id', empresaIdActual());
                fd.append('codi_cups', cups);
                fd.append('codi_prue', prue);
                fd.append('analito', analito);
                fd.append('codi_cups_anterior', homoCupsAnterior.value);
                fd.append('codi_prue_anterior', homoPrueAnterior.value);
                fd.append('analito_anterior', homoAnalitoAnterior.value);

                btnGuardar.disabled = true;
                try {
                    var r = await fetch('?url=sihos/interfazLaboratorioHomologacionGuardar', { method: 'POST', body: fd, credentials: 'same-origin' });
                    var j = await r.json();
                    if (!j.ok) {
                        homoError.textContent = j.message || 'No se pudo guardar.';
                        homoError.hidden = false;
                        btnGuardar.disabled = false;
                        return;
                    }
                    window.location.reload();
                } catch (e) {
                    homoError.textContent = 'Error de conexión.';
                    homoError.hidden = false;
                    btnGuardar.disabled = false;
                }
            });
        }
    }
})();
