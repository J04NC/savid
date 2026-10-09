(function () {
    'use strict';

    const form = document.getElementById('sihosPlanillaIntegradaCorreccionForm');
    if (!form) return;

    // El botón/estado/"marcar todas" se repiten arriba y abajo del detalle
    // (a pedido del usuario, 2026-10-09, para no tener que hacer scroll) —
    // se manejan como listas y se mantienen sincronizados entre sí.
    const btnsAplicar = form.querySelectorAll('.sihos-pi-aplicar-btn');
    const statuses = form.querySelectorAll('.sihos-pi-status');
    const marcarTodasChecks = form.querySelectorAll('.sihos-pi-marcar-todas');

    if (btnsAplicar.length === 0) return;

    function setStatus(texto) {
        statuses.forEach(function (s) { s.textContent = texto; });
    }

    function setBtnsDisabled(disabled) {
        btnsAplicar.forEach(function (b) { b.disabled = disabled; });
    }

    marcarTodasChecks.forEach(function (marcarTodas) {
        marcarTodas.addEventListener('change', function () {
            form.querySelectorAll('.sihos-pi-check').forEach(function (chk) {
                chk.checked = marcarTodas.checked;
            });
            marcarTodasChecks.forEach(function (otro) { otro.checked = marcarTodas.checked; });
        });
    });

    function aplicar() {
        const seleccionados = Array.prototype.filter.call(
            form.querySelectorAll('.sihos-pi-check'),
            function (chk) { return chk.checked; }
        );

        if (seleccionados.length === 0) {
            alert('Marque al menos una corrección para aplicar.');
            return;
        }

        if (!confirm('¿Aplicar ' + seleccionados.length + ' corrección(es) sobre la nómina real en SIHOS? Esta acción no se puede deshacer desde aquí.')) {
            return;
        }

        setBtnsDisabled(true);
        setStatus('Aplicando…');

        // Escritura real en SIHOS: bloquea el resto de la pantalla mientras
        // dura (evita navegar a mitad de la escritura) — se muestra antes
        // del fetch y se apaga siempre al terminar, éxito o error (ver
        // savidMostrarCargando en app.js).
        if (typeof savidMostrarCargando === 'function') {
            savidMostrarCargando('Aplicando correcciones en SIHOS, no cierre esta ventana…');
        }

        const fd = new FormData();
        fd.append('empresa_id', form.dataset.empresaId);
        fd.append('codi_ano', form.dataset.codiAno);
        fd.append('codi_mes', form.dataset.codiMes);

        seleccionados.forEach(function (chk, i) {
            fd.append('seleccion[' + i + '][tipo_docu]', chk.dataset.tipoDocu);
            fd.append('seleccion[' + i + '][no_id]', chk.dataset.noId);
            fd.append('seleccion[' + i + '][concepto]', chk.dataset.concepto);
            fd.append('seleccion[' + i + '][tipo_correccion]', chk.dataset.tipoCorreccion);
            if (chk.dataset.tipoCorreccion === 'valor') {
                fd.append('seleccion[' + i + '][suma_esperada]', chk.dataset.sumaEsperada);
            } else {
                fd.append('seleccion[' + i + '][tercero_nit]', chk.dataset.terceroNit);
            }
        });

        fetch('?url=sihos/nominaPlanillaIntegradaAplicar', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
        })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j.ok) {
                    setStatus(j.error || 'No se pudo aplicar.');
                    setBtnsDisabled(false);
                    return;
                }

                let exitos = 0;
                let rechazos = 0;

                j.resultados.forEach(function (r, i) {
                    const chk = seleccionados[i];
                    const etiqueta = chk ? chk.closest('label') : null;

                    if (r.ok) {
                        exitos++;
                        if (chk) {
                            chk.disabled = true;
                        }
                        if (etiqueta) {
                            etiqueta.insertAdjacentHTML('afterend', ' <span style="color:#2ecc71;">✅ Corregido</span>');
                        }
                    } else {
                        rechazos++;
                        if (etiqueta) {
                            etiqueta.insertAdjacentHTML('afterend', ' <span style="color:#e74c3c;">❌ ' + (r.motivo || 'Rechazado') + '</span>');
                        }
                    }
                });

                setStatus(exitos + ' aplicada(s), ' + rechazos + ' rechazada(s).');
                setBtnsDisabled(false);
            })
            .catch(function () {
                setStatus('Error de conexión.');
                setBtnsDisabled(false);
            })
            .finally(function () {
                if (typeof savidOcultarCargando === 'function') {
                    savidOcultarCargando();
                }
            });
    }

    btnsAplicar.forEach(function (btn) {
        btn.addEventListener('click', aplicar);
    });
})();
