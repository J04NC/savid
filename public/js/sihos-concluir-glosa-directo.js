(function () {
    'use strict';

    const modal = document.getElementById('sihosConcluirGlosaDirectoModal');
    if (!modal) return;

    const refeSpan = document.getElementById('sihosConcluirGlosaDirectoRefe');
    const valorSpan = document.getElementById('sihosConcluirGlosaDirectoValor');
    const docSpan = document.getElementById('sihosConcluirGlosaDirectoDocumento');
    const docLabelSpan = document.getElementById('sihosConcluirGlosaDirectoDocumentoLabel');
    const input = document.getElementById('sihosConcluirGlosaDirectoConfirmacion');
    const btnConfirmar = document.getElementById('sihosConcluirGlosaDirectoConfirmar');
    const btnCerrar = document.getElementById('sihosConcluirGlosaDirectoCerrar');
    const status = document.getElementById('sihosConcluirGlosaDirectoStatus');

    let pending = null;

    function abrirModal(empresaId, codiDocu, numeDocu, fechaCorte, refe, enCurso) {
        pending = { empresaId: empresaId, codiDocu: codiDocu, numeDocu: numeDocu, fechaCorte: fechaCorte };
        const documento = codiDocu + '-' + numeDocu;
        refeSpan.textContent = refe;
        valorSpan.textContent = enCurso;
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

    document.querySelectorAll('.sihosConcluirGlosaDirectoBtn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            abrirModal(
                btn.getAttribute('data-empresa-id'),
                btn.getAttribute('data-codi-docu'),
                btn.getAttribute('data-nume-docu'),
                btn.getAttribute('data-fecha-corte'),
                btn.getAttribute('data-refe'),
                btn.getAttribute('data-en-curso')
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
        status.textContent = 'Escribiendo en SIHOS…';

        const fd = new FormData();
        fd.append('empresa_id', pending.empresaId);
        fd.append('codi_docu', pending.codiDocu);
        fd.append('nume_docu', pending.numeDocu);
        fd.append('fecha_corte', pending.fechaCorte);

        // El overlay global bloquea también "Cerrar" mientras la escritura
        // está en curso — mismo motivo que sihos-reversar-cuenta.js.
        if (typeof savidMostrarCargando === 'function') {
            savidMostrarCargando('Escribiendo en SIHOS, no cierre esta ventana…', true);
        }

        fetch('?url=sihos/auditoriaGlosaConcluirDirecto', {
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
