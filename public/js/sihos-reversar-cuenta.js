(function () {
    'use strict';

    const modal = document.getElementById('sihosReversarCuentaModal');
    if (!modal) return;

    const cuentaSpan = document.getElementById('sihosReversarCuentaCuenta');
    const docSpan = document.getElementById('sihosReversarCuentaDocumento');
    const docLabelSpan = document.getElementById('sihosReversarCuentaDocumentoLabel');
    const input = document.getElementById('sihosReversarCuentaConfirmacion');
    const btnConfirmar = document.getElementById('sihosReversarCuentaConfirmar');
    const btnCerrar = document.getElementById('sihosReversarCuentaCerrar');
    const status = document.getElementById('sihosReversarCuentaStatus');

    let pending = null;

    function abrirModal(empresaId, codiDocu, numeDocu, consDeta, cuenta) {
        pending = { empresaId: empresaId, codiDocu: codiDocu, numeDocu: numeDocu, consDeta: consDeta };
        const documento = codiDocu + '-' + numeDocu;
        cuentaSpan.textContent = cuenta;
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

    document.querySelectorAll('.btnSihosReversarCuenta').forEach(function (btn) {
        btn.addEventListener('click', function () {
            abrirModal(
                btn.getAttribute('data-empresa-id'),
                btn.getAttribute('data-codi-docu'),
                btn.getAttribute('data-nume-docu'),
                btn.getAttribute('data-cons-deta'),
                btn.getAttribute('data-cuenta')
            );
        });
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
        status.textContent = 'Creando nota de ajuste…';

        const fd = new FormData();
        fd.append('empresa_id', pending.empresaId);
        fd.append('codi_docu', pending.codiDocu);
        fd.append('nume_docu', pending.numeDocu);
        fd.append('cons_deta', pending.consDeta);

        // El overlay global (z-index por encima del modal) bloquea también
        // "Cerrar" mientras la escritura está en curso: cerrar este modal no
        // cancela nada en el servidor, solo dejaría al usuario sin saber si
        // terminó bien.
        if (typeof savidMostrarCargando === 'function') {
            savidMostrarCargando('Escribiendo en SIHOS, no cierre esta ventana…', true);
        }

        fetch('?url=sihos/cruceReversarCuentaInesperada', {
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
