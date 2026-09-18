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

    function esAutorreferencia(linea, codiDocu, numeDocu) {
        return linea.TiDoRefe === codiDocu && String(linea.NuDoRefe) === String(numeDocu);
    }

    function resumen(j) {
        var e = j.encabezado;
        var r = j.resumenPropio;
        var items = [
            ['Tercero', nombreTercero(e.TiDoTerc, e.NuDoTerc, e.NombTerc)],
            ['Valor total', money(r.valorTotal)],
            ['Valor débito', money(r.valorDebito)],
            ['Valor crédito', money(r.valorCredito)],
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

    function tablaContabilidad(lineas, codiDocu, numeDocu) {
        if (lineas.length === 0) return '<p class="field-note">Sin líneas contables.</p>';
        var totalDebito = 0;
        var totalCredito = 0;
        var filas = lineas.map(function (l) {
            var clase = esAutorreferencia(l, codiDocu, numeDocu) ? ' class="sihos-auditoria-fila-autorreferencia"' : '';
            var debito = '';
            var credito = '';
            if (l.Valor > 0) {
                debito = money(l.Valor);
                totalDebito += l.Valor;
            } else {
                credito = money(Math.abs(l.Valor));
                totalCredito += Math.abs(l.Valor);
            }
            var referencia = l.NuDoRefe ? escapeHtml(l.TiDoRefe) + '-' + escapeHtml(l.NuDoRefe) : '—';
            return '<tr' + clase + '><td>' + nombreCuenta(l.CodiCont, l.NombCuen) + '</td><td>' + nombreTercero(l.TiDoTerc, l.NuDoTerc, l.NombTerc) +
                '</td><td>' + referencia + '</td><td style="text-align:right">' + debito + '</td><td style="text-align:right">' + credito + '</td></tr>';
        }).join('');
        filas += '<tr><td colspan="3" style="text-align:right"><strong>Total</strong></td><td style="text-align:right"><strong>' +
            money(totalDebito) + '</strong></td><td style="text-align:right"><strong>' + money(totalCredito) + '</strong></td></tr>';
        return '<table class="seguridad-table"><thead><tr><th>Cuenta</th><th>Tercero</th><th>Referencia</th><th>Débito</th><th>Crédito</th></tr></thead><tbody>' + filas + '</tbody></table>';
    }

    function tablaPresupuesto(lineas) {
        if (lineas.length === 0) return '<p class="field-note">Sin líneas de presupuesto.</p>';
        var filas = lineas.map(function (l) {
            var rubro = escapeHtml(l.CodiPlan) + (l.NombPlan ? ' - ' + escapeHtml(l.NombPlan) : '');
            return '<tr><td>' + rubro + '</td><td style="text-align:right">' + money(l.Valor) + '</td></tr>';
        }).join('');
        return '<table class="seguridad-table"><thead><tr><th>Rubro</th><th>Valor</th></tr></thead><tbody>' + filas + '</tbody></table>';
    }

    function tablaResumenReferencias(referenciadoPor) {
        if (referenciadoPor.length === 0) return '<p class="field-note">Ningún documento referencia a este.</p>';
        var filas = '';
        referenciadoPor.forEach(function (v) {
            var documento = escapeHtml(v.CodiDocu) + '-' + escapeHtml(v.NumeDocu);
            var tercero = nombreTercero(v.TiDoTerc, v.NuDoTerc, v.NombTerc);
            var estado = badgeEstado(v.Estado);
            v.lineas.forEach(function (l, idx) {
                var esDC = l.Valor > 0;
                var marca = esDC ? 'D' : '<span style="color:#ef5350">C</span>';
                var valor = money(Math.abs(l.Valor));
                filas += '<tr>' +
                    '<td>' + documento + '</td>' +
                    '<td>' + fecha(v.FechDocu) + '</td>' +
                    '<td>' + tercero + '</td>' +
                    '<td>' + nombreCuenta(l.CodiCont, l.NombCuen) + '</td>' +
                    '<td style="text-align:center">' + marca + '</td>' +
                    '<td style="text-align:right">' + valor + '</td>' +
                    '<td>' + (idx === 0 ? estado : '') + '</td>' +
                    '</tr>';
            });
            filas += '<tr><td colspan="6" style="text-align:right">Presupuesto total del documento</td><td style="text-align:right"><strong>' +
                money(v.presupuestoTotal) + '</strong></td></tr>';
        });
        return '<table class="seguridad-table"><thead><tr><th>Documento</th><th>Fecha</th><th>Tercero</th><th>Cuenta</th><th>D/C</th><th>Valor</th><th>Estado</th></tr></thead><tbody>' + filas + '</tbody></table>';
    }

    function render(j) {
        var html = '';
        html += resumen(j);

        html += '<h4 class="auditoria-title" style="font-size:14px; margin-top:16px;">Contabilidad propia</h4>';
        html += tablaContabilidad(j.contabilidadPropia, j.codiDocu, j.numeDocu);

        html += '<h4 class="auditoria-title" style="font-size:14px; margin-top:16px;">Presupuesto propio</h4>';
        html += tablaPresupuesto(j.presupuestoPropio);

        html += '<h4 class="auditoria-title" style="font-size:14px; margin-top:16px;">Resumen de Referencias — documentos que referencian a este (' + j.referenciadoPor.length + ')</h4>';
        html += tablaResumenReferencias(j.referenciadoPor);

        html += '<h4 class="auditoria-title" style="font-size:14px; margin-top:16px;">Totales (cuenta 4312 + presupuesto, propio + referenciado)</h4>';
        html += '<p class="field-note">Presupuesto: <strong>' + money(j.totales.presupuesto) + '</strong> · Contabilidad: <strong>' +
            money(j.totales.contabilidad) + '</strong> · Diferencia: <strong>' + money(j.totales.diferencia) + '</strong></p>';

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
