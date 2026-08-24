(function () {
    'use strict';

    const modal = document.getElementById('sihosReclasificarCuentaModal');
    if (!modal) return;

    const origenSpan = document.getElementById('sihosReclasificarCuentaOrigen');
    const docSpan = document.getElementById('sihosReclasificarCuentaDocumento');
    const docLabelSpan = document.getElementById('sihosReclasificarCuentaDocumentoLabel');
    const selectDestino = document.getElementById('sihosReclasificarCuentaDestino');
    const input = document.getElementById('sihosReclasificarCuentaConfirmacion');
    const btnConfirmar = document.getElementById('sihosReclasificarCuentaConfirmar');
    const btnCerrar = document.getElementById('sihosReclasificarCuentaCerrar');
    const status = document.getElementById('sihosReclasificarCuentaStatus');

    let pending = null;

    function actualizarBotonConfirmar() {
        if (!pending) {
            btnConfirmar.disabled = true;
            return;
        }
        const esperado = pending.codiDocu + '-' + pending.numeDocu;
        btnConfirmar.disabled = input.value.trim() !== esperado || selectDestino.value === '';
    }

    function abrirModal(empresaId, codiDocu, numeDocu, cuenta) {
        pending = { empresaId: empresaId, codiDocu: codiDocu, numeDocu: numeDocu };
        const documento = codiDocu + '-' + numeDocu;
        origenSpan.textContent = cuenta;
        docSpan.textContent = documento;
        docLabelSpan.textContent = documento;
        input.value = '';
        selectDestino.value = '';
        status.textContent = '';
        actualizarBotonConfirmar();
        modal.classList.remove('hidden');
        input.focus();
    }

    function cerrarModal() {
        modal.classList.add('hidden');
        pending = null;
    }

    document.querySelectorAll('.btnSihosReclasificarCuenta').forEach(function (btn) {
        btn.addEventListener('click', function () {
            abrirModal(
                btn.getAttribute('data-empresa-id'),
                btn.getAttribute('data-codi-docu'),
                btn.getAttribute('data-nume-docu'),
                btn.getAttribute('data-cuenta')
            );
        });
    });

    btnCerrar.addEventListener('click', cerrarModal);
    input.addEventListener('input', actualizarBotonConfirmar);
    selectDestino.addEventListener('change', actualizarBotonConfirmar);

    btnConfirmar.addEventListener('click', function () {
        if (!pending) return;

        btnConfirmar.disabled = true;
        status.textContent = 'Reclasificando cuenta…';

        const fd = new FormData();
        fd.append('empresa_id', pending.empresaId);
        fd.append('codi_docu', pending.codiDocu);
        fd.append('nume_docu', pending.numeDocu);
        fd.append('cuenta_destino', selectDestino.value);

        // El backend da hasta 180s a esta operación (varias idas y vueltas a
        // SIHOS) — el overlay bloquea también "Cerrar" de este modal, para
        // que no se pierda de vista mientras dura.
        if (typeof savidMostrarCargando === 'function') {
            savidMostrarCargando('Reclasificando en SIHOS, no cierre esta ventana…', true);
        }

        fetch('?url=sihos/cruceReclasificarCuentaVigenciaAnterior', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
        })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                status.textContent = j.message || (j.ok ? 'Listo' : 'Error');
                if (j.ok) {
                    setTimeout(function () { window.location.reload(); }, 1500);
                } else {
                    actualizarBotonConfirmar();
                    if (typeof savidOcultarCargando === 'function') savidOcultarCargando();
                }
            })
            .catch(function () {
                status.textContent = 'Error de conexión.';
                actualizarBotonConfirmar();
                if (typeof savidOcultarCargando === 'function') savidOcultarCargando();
            });
    });
})();
