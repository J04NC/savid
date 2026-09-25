(function () {
    'use strict';

    const modal = document.getElementById('sihosEliminarPortionDetaPlanModal');
    if (!modal) return;

    const docSpan = document.getElementById('sihosEliminarPortionDetaPlanDocumento');
    const facturaSpan = document.getElementById('sihosEliminarPortionDetaPlanFactura');
    const docLabelSpan = document.getElementById('sihosEliminarPortionDetaPlanDocumentoLabel');
    const input = document.getElementById('sihosEliminarPortionDetaPlanConfirmacion');
    const btnConfirmar = document.getElementById('sihosEliminarPortionDetaPlanConfirmar');
    const btnCerrar = document.getElementById('sihosEliminarPortionDetaPlanCerrar');
    const status = document.getElementById('sihosEliminarPortionDetaPlanStatus');

    let pending = null;

    function abrirModal(empresaId, codiDocu, numeDocu, facturaCodiDocu, facturaNumeDocu) {
        pending = {
            empresaId: empresaId,
            codiDocu: codiDocu,
            numeDocu: numeDocu,
            facturaCodiDocu: facturaCodiDocu,
            facturaNumeDocu: facturaNumeDocu,
        };
        const documento = codiDocu + '-' + numeDocu;
        const factura = facturaCodiDocu + '-' + facturaNumeDocu;
        docSpan.textContent = documento;
        facturaSpan.textContent = factura;
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
        const btn = ev.target.closest ? ev.target.closest('.btnSihosEliminarPortionDetaPlan') : null;
        if (!btn) return;
        abrirModal(
            btn.getAttribute('data-empresa-id'),
            btn.getAttribute('data-codi-docu'),
            btn.getAttribute('data-nume-docu'),
            btn.getAttribute('data-factura-codi-docu'),
            btn.getAttribute('data-factura-nume-docu')
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
        status.textContent = 'Ajustando…';

        const fd = new FormData();
        fd.append('empresa_id', pending.empresaId);
        fd.append('codi_docu', pending.codiDocu);
        fd.append('nume_docu', pending.numeDocu);
        fd.append('factura_codi_docu', pending.facturaCodiDocu);
        fd.append('factura_nume_docu', pending.facturaNumeDocu);

        fetch('?url=sihos/cruceEliminarPortionDetaPlan', {
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
                }
            })
            .catch(function () {
                status.textContent = 'Error de conexión.';
                btnConfirmar.disabled = false;
            });
    });
})();
