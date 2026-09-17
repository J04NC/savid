(function () {
    'use strict';

    const contenedor = document.getElementById('sihosTarifaProcConfirmar');
    const btnConfirmar = document.getElementById('sihosTarifaProcConfirmarBtn');
    const status = document.getElementById('sihosTarifaProcStatus');

    if (!contenedor || !btnConfirmar) return;

    btnConfirmar.addEventListener('click', function () {
        const token = contenedor.dataset.token;
        const empresaId = contenedor.dataset.empresaId;
        const codiManu = contenedor.dataset.codiManu;
        const nombreManu = contenedor.dataset.nombreManu;
        const codiPlan = contenedor.dataset.codiPlan;
        const nuevos = contenedor.dataset.nuevos;
        const actualizar = contenedor.dataset.actualizar;

        const mensaje = '¿Confirmar la carga sobre la tarifa "' + codiManu + ' - ' + nombreManu + '" (plan ' + codiPlan + ')?\n\n'
            + nuevos + ' tarifa(s) nueva(s) y ' + actualizar + ' tarifa(s) existente(s) se van a escribir en SIHOS.\n\n'
            + 'Esta acción no se puede deshacer desde aquí.';

        if (!confirm(mensaje)) {
            return;
        }

        btnConfirmar.disabled = true;
        status.textContent = 'Aplicando…';

        // Escritura real en SIHOS: bloquea el resto de la pantalla mientras
        // dura, se apaga siempre al terminar (ver savidMostrarCargando en
        // app.js, mismo patrón que sihos-nomina-correccion.js).
        if (typeof savidMostrarCargando === 'function') {
            savidMostrarCargando('Aplicando tarifas en SIHOS, no cierre esta ventana…');
        }

        const fd = new FormData();
        fd.append('empresa_id', empresaId);
        fd.append('token', token);

        fetch('?url=sihos/tarifaProcedimientoConfirmar', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
        })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j.ok) {
                    status.textContent = j.error || 'No se pudo confirmar la carga.';
                    btnConfirmar.disabled = false;
                    return;
                }

                status.textContent = j.insertados + ' insertada(s), ' + j.actualizados + ' actualizada(s), '
                    + j.rechazados.length + ' rechazada(s).';
                btnConfirmar.disabled = true;
                btnConfirmar.textContent = '✅ Carga aplicada';
            })
            .catch(function () {
                status.textContent = 'Error de conexión.';
                btnConfirmar.disabled = false;
            })
            .finally(function () {
                if (typeof savidOcultarCargando === 'function') {
                    savidOcultarCargando();
                }
            });
    });
})();
