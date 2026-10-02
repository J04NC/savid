(function () {
    'use strict';

    const modal = document.getElementById('sihosReclasificarCuentaVigenciaActualModal');
    if (!modal) return;

    const docSpan = document.getElementById('sihosReclasificarCuentaVigenciaActualDocumento');
    const docLabelSpan = document.getElementById('sihosReclasificarCuentaVigenciaActualDocumentoLabel');
    const input = document.getElementById('sihosReclasificarCuentaVigenciaActualConfirmacion');
    const btnConfirmar = document.getElementById('sihosReclasificarCuentaVigenciaActualConfirmar');
    const btnCerrar = document.getElementById('sihosReclasificarCuentaVigenciaActualCerrar');
    const status = document.getElementById('sihosReclasificarCuentaVigenciaActualStatus');

    let pending = null;

    function abrirModal(empresaId, codiDocu, numeDocu) {
        pending = { empresaId: empresaId, codiDocu: codiDocu, numeDocu: numeDocu };
        const documento = codiDocu + '-' + numeDocu;
        docSpan.textContent = documento;
        docLabelSpan.textContent = documento;
        input.value = '';
        status.textContent = '';
        btnConfirmar.disabled = true;
        modal.classList.remove('hidden');
        input.focus();
    }

    function cerrarModal() {
        modal.classList.add('hidden');
        pending = null;
    }

    document.addEventListener('click', function (ev) {
        const btn = ev.target.closest ? ev.target.closest('.btnSihosReclasificarCuentaVigenciaActual') : null;
        if (!btn) return;
        abrirModal(
            btn.getAttribute('data-empresa-id'),
            btn.getAttribute('data-codi-docu'),
            btn.getAttribute('data-nume-docu')
        );
    });

    btnCerrar.addEventListener('click', cerrarModal);

    input.addEventListener('input', function () {
        if (!pending) return;
        const esperado = pending.codiDocu + '-' + pending.numeDocu;
        btnConfirmar.disabled = input.value.trim() !== esperado;
    });

    btnConfirmar.addEventListener('click', function () {
        if (!pending) return;

        btnConfirmar.disabled = true;
        status.textContent = 'Reclasificando…';

        const fd = new FormData();
        fd.append('empresa_id', pending.empresaId);
        fd.append('codi_docu', pending.codiDocu);
        fd.append('nume_docu', pending.numeDocu);

        // El backend da hasta 180s a esta operación (varias idas y vueltas a
        // SIHOS) — el overlay bloquea también "Cerrar" de este modal, para
        // que no se pierda de vista mientras dura.
        if (typeof savidMostrarCargando === 'function') {
            savidMostrarCargando('Reclasificando en SIHOS, no cierre esta ventana…', true);
        }

        fetch('?url=sihos/cruceReclasificarCuentaVigenciaActual', {
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
                    btnConfirmar.disabled = false;
                    if (typeof savidOcultarCargando === 'function') savidOcultarCargando();
                }
            })
            .catch(function () {
                status.textContent = 'Error de conexión.';
                btnConfirmar.disabled = false;
                if (typeof savidOcultarCargando === 'function') savidOcultarCargando();
            });
    });
})();
