(function () {
    'use strict';

    var modal = document.getElementById('sihosConstruirDetaPlanMasivoModal');
    var tabla = document.getElementById('sihosConstruirDetaPlanTable');
    var btnMasivo = document.getElementById('btnSihosConstruirDetaPlanMasivo');
    var selectAll = document.getElementById('sihosConstruirDetaPlanSelectAll');
    if (!modal || !tabla || !btnMasivo || !selectAll) return;

    var preInicio = document.getElementById('sihosConstruirDetaPlanMasivoPreInicio');
    var progreso = document.getElementById('sihosConstruirDetaPlanMasivoProgreso');
    var resumen = document.getElementById('sihosConstruirDetaPlanMasivoResumen');
    var conteoSpan = document.getElementById('sihosConstruirDetaPlanMasivoConteo');
    var input = document.getElementById('sihosConstruirDetaPlanMasivoConfirmacion');
    var btnIniciar = document.getElementById('sihosConstruirDetaPlanMasivoIniciar');
    var btnCerrar = document.getElementById('sihosConstruirDetaPlanMasivoCerrar');
    var btnDetener = document.getElementById('sihosConstruirDetaPlanMasivoDetener');
    var btnActualizar = document.getElementById('sihosConstruirDetaPlanMasivoActualizar');
    var contador = document.getElementById('sihosConstruirDetaPlanMasivoContador');
    var listaFallidos = document.getElementById('sihosConstruirDetaPlanMasivoFallidos');
    var resumenTexto = document.getElementById('sihosConstruirDetaPlanMasivoResumenTexto');

    var empresaId = null;
    var btnEjemplo = tabla.querySelector('.btnSihosConstruirDetaPlan');
    if (btnEjemplo) empresaId = btnEjemplo.getAttribute('data-empresa-id');

    var detener = false;

    function checkboxesMarcados() {
        return Array.prototype.slice.call(tabla.querySelectorAll('.sihosConstruirDetaPlanCheckbox:checked'));
    }

    function actualizarBotonMasivo() {
        var n = checkboxesMarcados().length;
        btnMasivo.textContent = '▶️ Construir seleccionados (' + n + ')';
        btnMasivo.disabled = n === 0;
    }

    document.addEventListener('change', function (ev) {
        if (ev.target && ev.target.classList && ev.target.classList.contains('sihosConstruirDetaPlanCheckbox')) {
            actualizarBotonMasivo();
        }
    });

    function filaEsVisible(fila) {
        // No depende de la API de DataTables (evita sorpresas con la extensión
        // Responsive, que en pantallas angostas colapsa columnas): pregunta
        // directamente al navegador si la fila está renderizada en pantalla.
        // DataTables oculta con display:none tanto las filas filtradas como
        // las que quedan fuera de la página actual, así que esto respeta
        // ambas cosas a la vez.
        return fila.offsetParent !== null;
    }

    selectAll.addEventListener('click', function () {
        var checkboxes = tabla.querySelectorAll('.sihosConstruirDetaPlanCheckbox');
        checkboxes.forEach(function (cb) {
            var fila = cb.closest('tr');
            if (fila && filaEsVisible(fila)) {
                cb.checked = selectAll.checked;
            }
        });
        actualizarBotonMasivo();
    });

    function resetModal() {
        preInicio.hidden = false;
        progreso.hidden = true;
        resumen.hidden = true;
        btnDetener.hidden = false;
        input.value = '';
        btnIniciar.disabled = true;
        listaFallidos.innerHTML = '';
        detener = false;
    }

    function cerrarModal() {
        modal.classList.add('hidden');
    }

    btnMasivo.addEventListener('click', function () {
        resetModal();
        conteoSpan.textContent = String(checkboxesMarcados().length);
        modal.classList.remove('hidden');
        input.focus();
    });

    btnCerrar.addEventListener('click', cerrarModal);

    input.addEventListener('input', function () {
        btnIniciar.disabled = input.value.trim() !== 'CONSTRUIR';
    });

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

    async function procesarUno(item) {
        var fd = new FormData();
        fd.append('empresa_id', empresaId);
        fd.append('codi_docu', item.codiDocu);
        fd.append('nume_docu', item.numeDocu);

        try {
            var r = await fetch('?url=sihos/cruceConstruirDetaPlan', {
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
        var items = checkboxesMarcados().map(function (cb) {
            return { codiDocu: cb.getAttribute('data-codi-docu'), numeDocu: cb.getAttribute('data-nume-docu') };
        });

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

            var resultado = await procesarUno(items[i]);
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
            : 'Terminado: ' + ok + ' de ' + total + ' construidos correctamente' + (fallidos > 0 ? ', ' + fallidos + ' con error (ver lista arriba).' : '.');
    });
})();
