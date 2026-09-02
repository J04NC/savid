(function () {
    'use strict';

    const form = document.getElementById('sihosCorreccionForm');
    if (!form) return;

    const btnAplicar = document.getElementById('sihosCorreccionAplicarBtn');
    const status = document.getElementById('sihosCorreccionStatus');
    const marcarTodas = document.getElementById('sihosCorreccionMarcarTodas');

    if (marcarTodas) {
        marcarTodas.addEventListener('change', function () {
            form.querySelectorAll('.sihos-correccion-check').forEach(function (chk) {
                chk.checked = marcarTodas.checked;
            });
        });
    }

    if (!btnAplicar) return;

    btnAplicar.addEventListener('click', function () {
        const seleccionados = Array.prototype.filter.call(
            form.querySelectorAll('.sihos-correccion-check'),
            function (chk) { return chk.checked; }
        );

        if (seleccionados.length === 0) {
            alert('Marque al menos una corrección para aplicar.');
            return;
        }

        if (!confirm('¿Aplicar ' + seleccionados.length + ' corrección(es) sobre la nómina real en SIHOS? Esta acción no se puede deshacer desde aquí.')) {
            return;
        }

        btnAplicar.disabled = true;
        status.textContent = 'Aplicando…';

        const fd = new FormData();
        fd.append('empresa_id', form.dataset.empresaId);
        fd.append('codi_ano', form.dataset.codiAno);
        fd.append('codi_mes', form.dataset.codiMes);

        seleccionados.forEach(function (chk, i) {
            fd.append('seleccion[' + i + '][tipo_docu]', chk.dataset.tipoDocu);
            fd.append('seleccion[' + i + '][no_id]', chk.dataset.noId);
            fd.append('seleccion[' + i + '][concepto]', chk.dataset.concepto);
            fd.append('seleccion[' + i + '][suma_esperada]', chk.dataset.sumaEsperada);
        });

        fetch('?url=sihos/nominaPilaCorreccionAplicar', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
        })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j.ok) {
                    status.textContent = j.error || 'No se pudo aplicar.';
                    btnAplicar.disabled = false;
                    return;
                }

                let exitos = 0;
                let rechazos = 0;

                j.resultados.forEach(function (r, i) {
                    const chk = seleccionados[i];
                    const fila = chk ? chk.closest('tr') : null;
                    const estadoTd = fila ? fila.querySelector('.sihos-correccion-estado') : null;
                    if (!estadoTd) return;

                    if (r.ok) {
                        exitos++;
                        estadoTd.innerHTML = '<span style="color:#2ecc71;">✅ Corregido</span>';
                        if (chk) chk.disabled = true;
                    } else {
                        rechazos++;
                        estadoTd.innerHTML = '<span style="color:#e74c3c;">❌ ' + (r.motivo || 'Rechazado') + '</span>';
                    }
                });

                status.textContent = exitos + ' aplicada(s), ' + rechazos + ' rechazada(s).';
                btnAplicar.disabled = false;
            })
            .catch(function () {
                status.textContent = 'Error de conexión.';
                btnAplicar.disabled = false;
            });
    });
})();
