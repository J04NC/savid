(function () {
    'use strict';

    var modal = document.getElementById('sihosAuditarReferenciasModal');
    if (!modal) return;

    var docSpan = document.getElementById('sihosAuditarReferenciasDocumento');
    var btnCerrar = document.getElementById('sihosAuditarReferenciasCerrar');
    var status = document.getElementById('sihosAuditarReferenciasStatus');
    var contenido = document.getElementById('sihosAuditarReferenciasContenido');

    var ESTADO_ETIQUETA = { confirmado: 'Confirmado', anulado: 'Anulado', preliminar: 'Preliminar' };

    function money(valor) {
        var n = Number(valor) || 0;
        return '$' + n.toLocaleString('es-CO', { maximumFractionDigits: 0 });
    }

    function moneyOrDash(valor) {
        return (valor === null || valor === undefined) ? '—' : money(valor);
    }

    function escapeHtml(texto) {
        var div = document.createElement('div');
        div.textContent = texto === null || texto === undefined ? '' : String(texto);
        return div.innerHTML;
    }

    function fecha(f) {
        if (!f) return '—';
        var partes = String(f).split('-');
        return partes.length === 3 ? partes[2] + '/' + partes[1] + '/' + partes[0] : escapeHtml(f);
    }

    function badgeEstado(estado) {
        var clave = estado || 'preliminar';
        var etiqueta = ESTADO_ETIQUETA[clave] || 'Preliminar';
        return '<span class="sihos-auditoria-badge sihos-auditoria-badge-' + escapeHtml(clave) + '">' + etiqueta + '</span>';
    }

    function nombreCuenta(codiCont, nombCuen) {
        var texto = escapeHtml(codiCont);
        if (nombCuen) texto += ' - ' + escapeHtml(nombCuen);
        return texto;
    }

    function nombreTercero(tipo, numero, nombre) {
        if (!numero) return '—';
        var texto = escapeHtml(tipo) + ' - ' + escapeHtml(numero);
        if (nombre) texto += ' - ' + escapeHtml(nombre.trim());
        return texto;
    }

    function centroCosto(codiCent, nombCent) {
        if (!codiCent) return '—';
        var texto = escapeHtml(codiCent);
        if (nombCent) texto += ' - ' + escapeHtml(nombCent);
        return texto;
    }

    function resumen(j) {
        var e = j.encabezado;
        var r = j.resumenPropio;
        var items = [
            ['Tercero', nombreTercero(e.TiDoTerc, e.NuDoTerc, e.NombTerc)],
            ['Valor total', money(r.valorTotal)],
            ['Saldo', moneyOrDash(r.saldo)],
            ['Fecha', fecha(e.FechDocu)],
            ['Estado', badgeEstado(e.Estado)]
        ];
        var html = '<div class="sihos-auditoria-resumen">';
        items.forEach(function (it) {
            html += '<div class="sihos-auditoria-resumen-item"><p class="field-note">' + it[0] + '</p><strong>' + it[1] + '</strong></div>';
        });
        html += '</div>';
        if (e.TiDoRefe) {
            html += '<p class="field-note">Este documento referencia a: <strong>' + escapeHtml(e.TiDoRefe) + '-' + escapeHtml(e.NuDoRefe) + '</strong></p>';
        }
        return html;
    }

    /** Una fila por línea, agrupada por documento (propio primero, luego cada vinculado). */
    /**
     * Una clase CSS por grupo: el propio conserva su resaltado de siempre;
     * los vinculados alternan entre "con banda" y "sin banda" (no fila por
     * fila, sino grupo por grupo) para que se note dónde termina un
     * documento y empieza el siguiente, aun repitiendo su nombre en cada
     * línea. Se calcula una sola vez y la reutilizan ambas tablas
     * (contable y presupuestal) en vez de duplicar el contador.
     */
    function clasesPorGrupo(grupos) {
        var clases = [];
        var contadorVinculado = 0;
        grupos.forEach(function (g) {
            if (g.esPropio) {
                clases.push(' class="sihos-auditoria-fila-autorreferencia"');
                return;
            }
            clases.push(contadorVinculado % 2 === 0 ? ' class="sihos-auditoria-fila-grupo-alterno"' : '');
            contadorVinculado++;
        });
        return clases;
    }

    /**
     * mostrarReferencia: agrega una columna "Documento referencia" con el
     * TiDoRefe/NuDoRefe de cada línea — solo tiene sentido en el detalle
     * contable completo, donde un mismo documento puede tener líneas que
     * referencian documentos distintos; las demás tablas ya muestran un
     * documento a la vez agrupado por la referencia puntual que se pidió.
     */
    function tablaReferenciasContables(grupos, mostrarReferencia) {
        if (!grupos.some(function (g) { return g.lineas.length > 0; })) {
            return '<p class="field-note">Sin líneas contables.</p>';
        }
        var clases = clasesPorGrupo(grupos);
        var filas = '';
        var totalDebito = 0;
        var totalCredito = 0;
        grupos.forEach(function (g, i) {
            var documento = escapeHtml(g.CodiDocu) + '-' + escapeHtml(g.NumeDocu);
            var estado = badgeEstado(g.Estado);
            g.lineas.forEach(function (l, idx) {
                var debito = '';
                var credito = '';
                if (l.Valor > 0) {
                    debito = money(l.Valor);
                    totalDebito += l.Valor;
                } else {
                    credito = '<span style="color:#ef5350">' + money(Math.abs(l.Valor)) + '</span>';
                    totalCredito += Math.abs(l.Valor);
                }
                var referencia = l.TiDoRefe ? (escapeHtml(l.TiDoRefe) + '-' + escapeHtml(l.NuDoRefe)) : '—';
                filas += '<tr' + clases[i] + '>' +
                    '<td>' + documento + '</td>' +
                    '<td>' + fecha(g.FechDocu) + '</td>' +
                    '<td>' + nombreTercero(l.TiDoTerc, l.NuDoTerc, l.NombTerc) + '</td>' +
                    '<td>' + nombreCuenta(l.CodiCont, l.NombCuen) + '</td>' +
                    '<td>' + centroCosto(l.CentCost, l.NombCent) + '</td>' +
                    '<td style="text-align:right">' + debito + '</td>' +
                    '<td style="text-align:right">' + credito + '</td>' +
                    (mostrarReferencia ? '<td>' + referencia + '</td>' : '') +
                    '<td>' + (idx === 0 ? estado : '') + '</td>' +
                    '</tr>';
            });
        });
        filas += '<tr><td colspan="5" style="text-align:right"><strong>Total</strong></td><td style="text-align:right"><strong>' +
            money(totalDebito) + '</strong></td><td style="text-align:right"><strong>' + money(totalCredito) + '</strong></td>' +
            (mostrarReferencia ? '<td></td>' : '') + '<td></td></tr>';
        var thReferencia = mostrarReferencia ? '<th>Documento referencia</th>' : '';
        return '<table class="seguridad-table no-datatable"><thead><tr><th>Documento</th><th>Fecha</th><th>Tercero</th><th>Cuenta</th><th>Centro de costo</th><th>Débito</th><th>Crédito</th>' +
            thReferencia + '<th>Estado</th></tr></thead><tbody>' + filas + '</tbody></table>';
    }

    /** Misma idea que tablaReferenciasContables(), para las líneas de presupuesto (DetaPlan). */
    function tablaReferenciasPresupuestales(grupos) {
        if (!grupos.some(function (g) { return g.lineas.length > 0; })) {
            return '<p class="field-note">Sin líneas de presupuesto.</p>';
        }
        var clases = clasesPorGrupo(grupos);
        var filas = '';
        var total = 0;
        grupos.forEach(function (g, i) {
            var documento = escapeHtml(g.CodiDocu) + '-' + escapeHtml(g.NumeDocu);
            var estado = badgeEstado(g.Estado);
            g.lineas.forEach(function (l, idx) {
                var rubro = escapeHtml(l.CodiPlan) + (l.NombPlan ? ' - ' + escapeHtml(l.NombPlan) : '');
                total += l.Valor;
                filas += '<tr' + clases[i] + '>' +
                    '<td>' + documento + '</td>' +
                    '<td>' + fecha(g.FechDocu) + '</td>' +
                    '<td>' + rubro + '</td>' +
                    '<td style="text-align:right">' + money(l.Valor) + '</td>' +
                    '<td>' + (idx === 0 ? estado : '') + '</td>' +
                    '</tr>';
            });
        });
        filas += '<tr><td colspan="3" style="text-align:right"><strong>Total</strong></td><td style="text-align:right"><strong>' +
            money(total) + '</strong></td><td></td></tr>';
        return '<table class="seguridad-table no-datatable"><thead><tr><th>Documento</th><th>Fecha</th><th>Rubro</th><th>Valor</th><th>Estado</th></tr></thead><tbody>' + filas + '</tbody></table>';
    }

    function render(j) {
        var html = '';
        html += resumen(j);

        html += '<h4 class="auditoria-title" style="font-size:14px; margin-top:16px;">Referencias contables</h4>';
        html += tablaReferenciasContables(j.referenciasContables);

        html += '<h4 class="auditoria-title" style="font-size:14px; margin-top:16px;">Referencias presupuestales</h4>';
        html += tablaReferenciasPresupuestales(j.referenciasPresupuestales);

        html += '<h4 class="auditoria-title" style="font-size:14px; margin-top:16px;">Totales (cuenta 4312 + presupuesto, propio + referenciado)</h4>';
        html += '<p class="field-note">Presupuesto: <strong>' + money(j.totales.presupuesto) + '</strong> · Contabilidad: <strong>' +
            money(j.totales.contabilidad) + '</strong> · Diferencia: <strong>' + money(j.totales.diferencia) + '</strong></p>';

        html += '<h4 class="auditoria-title" style="font-size:14px; margin-top:16px;">Detalle contable completo de cada documento referenciante</h4>';
        html += '<p class="field-note">Todas las líneas de cada documento que referencia esta factura (no solo la que apunta de vuelta a ella) — solo trazabilidad, no suma a los totales de arriba.</p>';
        if (j.detalleContableCompleto.length === 0) {
            html += '<p class="field-note">Ningún documento referencia a este.</p>';
        } else {
            html += tablaReferenciasContables(j.detalleContableCompleto, true);
        }

        contenido.innerHTML = html;
    }

    function abrirModal(empresaId, codiDocu, numeDocu) {
        var documento = codiDocu + '-' + numeDocu;
        docSpan.textContent = documento;
        status.textContent = 'Consultando…';
        contenido.hidden = true;
        contenido.innerHTML = '';
        modal.classList.remove('hidden');

        var params = new URLSearchParams({ empresa_id: empresaId, codi_docu: codiDocu, nume_docu: numeDocu });
        fetch('?url=sihos/cruceAuditarReferencias&' + params.toString(), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j.ok) {
                    status.textContent = j.message || 'No se pudo consultar.';
                    return;
                }
                status.textContent = '';
                render(j);
                contenido.hidden = false;
            })
            .catch(function () {
                status.textContent = 'Error de conexión.';
            });
    }

    function cerrarModal() {
        modal.classList.add('hidden');
    }

    document.addEventListener('click', function (ev) {
        var btn = ev.target.closest ? ev.target.closest('.btnSihosAuditarReferencias') : null;
        if (!btn) return;
        abrirModal(
            btn.getAttribute('data-empresa-id'),
            btn.getAttribute('data-codi-docu'),
            btn.getAttribute('data-nume-docu')
        );
    });

    btnCerrar.addEventListener('click', cerrarModal);
})();
