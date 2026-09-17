(function () {
    'use strict';

    var modal = document.getElementById('sihosReclasificarCuentaMasivoModal');
    var tabla = document.getElementById('sihosReclasificarCuentaTable');
    var btnMasivo = document.getElementById('btnSihosReclasificarCuentaMasivo');
    var selectAll = document.getElementById('sihosReclasificarCuentaSelectAll');
    if (!modal || !tabla || !btnMasivo || !selectAll) return;

    var preInicio = document.getElementById('sihosReclasificarCuentaMasivoPreInicio');
    var progreso = document.getElementById('sihosReclasificarCuentaMasivoProgreso');
    var resumen = document.getElementById('sihosReclasificarCuentaMasivoResumen');
    var conteoSpan = document.getElementById('sihosReclasificarCuentaMasivoConteo');
    var selectDestino = document.getElementById('sihosReclasificarCuentaMasivoDestino');
    var input = document.getElementById('sihosReclasificarCuentaMasivoConfirmacion');
    var btnIniciar = document.getElementById('sihosReclasificarCuentaMasivoIniciar');
    var btnCerrar = document.getElementById('sihosReclasificarCuentaMasivoCerrar');
    var btnDetener = document.getElementById('sihosReclasificarCuentaMasivoDetener');
    var btnActualizar = document.getElementById('sihosReclasificarCuentaMasivoActualizar');
    var contador = document.getElementById('sihosReclasificarCuentaMasivoContador');
    var listaFallidos = document.getElementById('sihosReclasificarCuentaMasivoFallidos');
    var resumenTexto = document.getElementById('sihosReclasificarCuentaMasivoResumenTexto');

    var empresaId = null;
    var btnEjemplo = tabla.querySelector('.btnSihosReclasificarCuenta');
    if (btnEjemplo) empresaId = btnEjemplo.getAttribute('data-empresa-id');

    var detener = false;

    function checkboxesMarcados() {
        return Array.prototype.slice.call(tabla.querySelectorAll('.sihosReclasificarCuentaCheckbox:checked'));
    }

    function documentosUnicos(checkboxes) {
        var vistos = {};
        var items = [];
        checkboxes.forEach(function (cb) {
            var codiDocu = cb.getAttribute('data-codi-docu');
            var numeDocu = cb.getAttribute('data-nume-docu');
            var clave = codiDocu + '-' + numeDocu;
            if (!vistos[clave]) {
                vistos[clave] = true;
                items.push({ codiDocu: codiDocu, numeDocu: numeDocu });
            }
        });
        return items;
    }

    function actualizarBotonMasivo() {
        var n = checkboxesMarcados().length;
        btnMasivo.textContent = '▶️ Reclasificar seleccionadas (' + n + ')';
        btnMasivo.disabled = n === 0;
    }

    document.addEventListener('change', function (ev) {
        if (ev.target && ev.target.classList && ev.target.classList.contains('sihosReclasificarCuentaCheckbox')) {
            actualizarBotonMasivo();
        }
    });

    function filaEsVisible(fila) {
        return fila.offsetParent !== null;
    }

    selectAll.addEventListener('click', function () {
        var checkboxes = tabla.querySelectorAll('.sihosReclasificarCuentaCheckbox');
        checkboxes.forEach(function (cb) {
            var fila = cb.closest('tr');
            if (fila && filaEsVisible(fila)) {
                cb.checked = selectAll.checked;
            }
        });
        actualizarBotonMasivo();
    });

    function actualizarBotonIniciar() {
        btnIniciar.disabled = input.value.trim() !== 'RECLASIFICAR' || selectDestino.value === '';
    }

    function resetModal() {
        preInicio.hidden = false;
        progreso.hidden = true;
        resumen.hidden = true;
        btnDetener.hidden = false;
        input.value = '';
        selectDestino.value = '';
        btnIniciar.disabled = true;
        listaFallidos.innerHTML = '';
        detener = false;
    }

    function cerrarModal() {
        modal.classList.add('hidden');
    }

    btnMasivo.addEventListener('click', function () {
        resetModal();
        conteoSpan.textContent = String(documentosUnicos(checkboxesMarcados()).length);
        modal.classList.remove('hidden');
        input.focus();
    });

    btnCerrar.addEventListener('click', cerrarModal);

    input.addEventListener('input', actualizarBotonIniciar);
    selectDestino.addEventListener('change', actualizarBotonIniciar);

    btnDetener.addEventListener('click', function () {
        detener = true;
        btnDetener.disabled = true;
        btnDetener.textContent = 'Deteniendo…';
    });

    btnActualizar.addEventListener('click', function () {
        window.location.reload();
    });

    function agregarFallido(codiDocu, numeDocu, mensaje) {
        var li = document.createElement('li');
        li.style.padding = '4px 0';
        li.style.borderBottom = '1px solid rgba(255,255,255,0.1)';
        li.textContent = codiDocu + '-' + numeDocu + ': ' + mensaje;
        listaFallidos.appendChild(li);
    }

    function actualizarContador(procesados, total, ok, fallidos) {
        contador.textContent = 'Procesando ' + procesados + '/' + total + '… (✅ ' + ok + ' ok · ⚠️ ' + fallidos + ' con error)';
    }

    async function procesarUno(item, cuentaDestino) {
        var fd = new FormData();
        fd.append('empresa_id', empresaId);
        fd.append('codi_docu', item.codiDocu);
        fd.append('nume_docu', item.numeDocu);
        fd.append('cuenta_destino', cuentaDestino);

        try {
            var r = await fetch('?url=sihos/cruceReclasificarCuentaVigenciaAnterior', {
                method: 'POST',
                body: fd,
                credentials: 'same-origin',
            });
            var j = await r.json();
            return { ok: !!j.ok, message: j.message || (j.ok ? 'Listo' : 'Error') };
        } catch (e) {
            return { ok: false, message: 'Error de conexión.' };
        }
    }

    btnIniciar.addEventListener('click', async function () {
        var cuentaDestino = selectDestino.value;
        var items = documentosUnicos(checkboxesMarcados());

        preInicio.hidden = true;
        progreso.hidden = false;
        btnDetener.disabled = false;
        btnDetener.textContent = '⏹ Detener';

        var total = items.length;
        var ok = 0;
        var fallidos = 0;
        var procesados = 0;

        actualizarContador(0, total, 0, 0);

        for (var i = 0; i < items.length; i++) {
            if (detener) break;

            var resultado = await procesarUno(items[i], cuentaDestino);
            procesados++;
            if (resultado.ok) {
                ok++;
            } else {
                fallidos++;
                agregarFallido(items[i].codiDocu, items[i].numeDocu, resultado.message);
            }
            actualizarContador(procesados, total, ok, fallidos);
        }

        btnDetener.hidden = true;
        resumen.hidden = false;
        resumenTexto.textContent = detener
            ? 'Detenido: ' + procesados + '/' + total + ' procesados (' + ok + ' ok, ' + fallidos + ' con error).'
            : 'Terminado: ' + ok + ' de ' + total + ' reclasificados correctamente' + (fallidos > 0 ? ', ' + fallidos + ' con error (ver lista arriba).' : '.');
    });
})();
