(function () {
    'use strict';

    // Detalle (modal) del reporte SIHOS Liquidación: al hacer clic en una fila con data-liq-filtro se
    // muestran, paginadas del lado del servidor, las líneas del CSV generado que cumplen ese filtro.
    document.addEventListener('DOMContentLoaded', function () {
        var modal = document.getElementById('sihosLiquidacionDetalleModal');
        var $ = window.jQuery;
        if (!modal || !$ || !$.fn.DataTable) return;

        var titulo = document.getElementById('sihosLiquidacionDetalleTitulo');
        var estado = document.getElementById('sihosLiquidacionDetalleEstado');
        var descargar = document.getElementById('sihosLiquidacionDetalleDescargar');
        var url = '?url=sihos/liquidacionDetalle';
        var filtros = {};
        var tabla = null;
        // Con permiso de corrección, la columna 0 es el check (llave de la línea); las demás se corren una posición.
        var corregir = modal.getAttribute('data-corregir') === '1';
        var desfase = corregir ? 1 : 0;
        var marcadas = {}; // llave de la línea => confianza ('Alta'/'Media'); se conserva entre páginas
        var btnCorregir = document.getElementById('sihosLiquidacionCorregirBtn');
        var resultado = document.getElementById('sihosLiquidacionCorregirResultado');

        function parametros() {
            return { empresa_id: modal.getAttribute('data-empresa'), job: modal.getAttribute('data-job') };
        }

        var filtrosColumna = {};

        // Segunda fila de la cabecera con un cuadro de texto por columna (filtra del lado del servidor).
        function agregarFiltrosColumna() {
            var $cab = $('#sihosLiquidacionDetalleTabla thead');
            var primera = $cab.find('tr:first th');
            var $fila = $('<tr class="liq-filtros-columna"></tr>');
            primera.each(function (i) {
                if (i < desfase) { $fila.append('<th></th>'); return; }
                var $in = $('<input type="text" class="form-control" style="width:100%;min-width:60px;font-weight:normal;" autocomplete="off">')
                    .attr('aria-label', 'Filtrar ' + $(this).text()).attr('placeholder', 'Filtrar')
                    .attr('data-col', i - desfase);
                $fila.append($('<th></th>').append($in));
            });
            $cab.append($fila);
            var temporizador = null;
            $fila.on('input', 'input', function () {
                var i = $(this).attr('data-col'), v = $.trim($(this).val());
                if (v === '') { delete filtrosColumna[i]; } else { filtrosColumna[i] = v; }
                clearTimeout(temporizador);
                temporizador = setTimeout(function () { tabla.draw(); }, 350);
            });
            $fila.on('click', 'input', function (e) { e.stopPropagation(); });
        }

        function crearTabla() {
            agregarFiltrosColumna();
            tabla = $('#sihosLiquidacionDetalleTabla').DataTable({
                serverSide: true,
                orderCellsTop: true,
                processing: true,
                autoWidth: false,
                pageLength: 50,
                lengthMenu: [25, 50, 100, 200],
                order: [[desfase, 'asc']],
                columnDefs: [{ targets: [12 + desfase, 13 + desfase], className: 'dt-right' }].concat(corregir ? [{
                    targets: 0, orderable: false, searchable: false, className: 'dt-center',
                    render: function (clave, tipo, fila) {
                        if (tipo !== 'display' || !clave) { return ''; }
                        var esc = String(clave).replace(/&/g, '&amp;').replace(/"/g, '&quot;');
                        return '<input type="checkbox" class="liq-corregir" data-clave="' + esc + '" data-confianza="' + fila[12] + '"'
                            + (marcadas[clave] ? ' checked' : '') + ' aria-label="Marcar para corregir">';
                    }
                }] : []),
                ajax: {
                    url: url,
                    type: 'GET',
                    data: function (d) {
                        var orden = d.order.slice(0, 1).map(function (o) { return { column: o.column - desfase, dir: o.dir }; });
                        return $.extend(parametros(), {
                            draw: d.draw, start: d.start, length: d.length,
                            search: { value: d.search.value }, order: orden, f: filtros, fc: filtrosColumna
                        });
                    },
                    // El servidor manda la llave de la línea al final de la fila; con checks pasa a la columna 0.
                    dataSrc: function (json) {
                        return corregir ? json.data.map(function (r) { return [r[14]].concat(r.slice(0, 14)); }) : json.data;
                    },
                    error: function (xhr) {
                        estado.textContent = (xhr.responseJSON && xhr.responseJSON.error) || 'No se pudo cargar el detalle.';
                    }
                },
                language: {
                    processing: 'Cargando…', search: 'Buscar:', lengthMenu: 'Mostrar _MENU_',
                    info: 'Mostrando _START_ a _END_ de _TOTAL_ líneas', infoEmpty: 'Sin líneas',
                    infoFiltered: '(de _MAX_ de la selección)', emptyTable: 'Sin líneas', zeroRecords: 'Sin coincidencias',
                    paginate: { first: '«', previous: '‹', next: '›', last: '»' }
                }
            });
        }

        function abrir(f, texto) {
            filtros = f;
            titulo.textContent = texto;
            estado.textContent = '';
            descargar.setAttribute('href', url + '&' + $.param($.extend(parametros(), { descargar: 1, f: f })));
            modal.classList.remove('hidden');
            filtrosColumna = {};
            marcadas = {};
            actualizarBoton();
            if (resultado) { resultado.textContent = ''; }
            $('#sihosLiquidacionDetalleTabla .liq-filtros-columna input').val('');
            if (tabla) {
                tabla.search('').order([[desfase, 'asc']]).draw();
            } else {
                crearTabla();
            }
        }

        function actualizarBoton() {
            if (!btnCorregir) { return; }
            var n = Object.keys(marcadas).length;
            document.getElementById('sihosLiquidacionCorregirN').textContent = n;
            btnCorregir.disabled = n === 0;
        }

        function corregirMarcadas() {
            var claves = Object.keys(marcadas);
            var media = claves.filter(function (c) { return marcadas[c] === 'Media'; }).length;
            if (!claves.length || !window.confirm('Se va a cambiar el profesional de ' + claves.length + ' línea(s) en SIHOS'
                + (media ? ' (' + media + ' con confianza Media, menos segura)' : '') + '. El valor anterior queda en la auditoría. ¿Continuar?')) { return; }
            btnCorregir.disabled = true;
            resultado.textContent = 'Actualizando en SIHOS…';
            $.ajax({
                url: '?url=sihos/liquidacionCorregir', type: 'POST', dataType: 'json',
                data: $.extend(parametros(), { f: filtros, claves: claves })
            }).done(function (r) {
                var omitidas = (r.resultados || []).filter(function (x) { return !x.ok; });
                (r.resultados || []).forEach(function (x, i) { if (x.ok) { delete marcadas[claves[i]]; } });
                resultado.textContent = r.actualizadas + ' línea(s) actualizada(s), ' + r.omitidas + ' omitida(s).'
                    + (omitidas.length ? ' ' + omitidas.slice(0, 10).map(function (x) { return x.linea + ': ' + x.motivo; }).join(' · ') : '')
                    + ' El reporte conserva los datos del momento de la consulta; vuelva a generarlo para ver el cambio.';
            }).fail(function (xhr) {
                resultado.textContent = (xhr.responseJSON && xhr.responseJSON.error) || 'No se pudo actualizar.';
            }).always(function () {
                actualizarBoton();
                if (tabla) { tabla.draw(false); }
            });
        }

        function cerrar() {
            modal.classList.add('hidden');
        }

        // Clic en una celda con data-liq-extra: filtro de la fila (si la tiene) + el de la celda (columna).
        // Clic en otra parte de una fila con data-liq-filtro: solo el de la fila.
        function filaDesde(el) {
            if (!el.closest) return;
            var td = el.closest('td[data-liq-extra]');
            var tr = el.closest('tr[data-liq-filtro]');
            if (!tr && !td) return;
            try {
                var f = tr ? JSON.parse(tr.getAttribute('data-liq-filtro')) : {};
                var titulo = tr ? tr.getAttribute('data-liq-titulo') : '';
                if (td) {
                    f = $.extend(f, JSON.parse(td.getAttribute('data-liq-extra')));
                    titulo = (titulo ? titulo + ' · ' : '') + td.getAttribute('data-liq-titulo');
                }
                abrir(f, titulo || 'Detalle');
            } catch (e) { /* atributo mal formado: no se abre */ }
        }

        document.addEventListener('click', function (e) { filaDesde(e.target); });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && e.target.matches && e.target.matches('tr[data-liq-filtro], td[data-liq-extra]')) filaDesde(e.target);
            if (e.key === 'Escape') cerrar();
        });
        if (corregir) {
            $(document).on('change', '#sihosLiquidacionDetalleTabla input.liq-corregir', function () {
                var clave = this.getAttribute('data-clave');
                if (this.checked) { marcadas[clave] = this.getAttribute('data-confianza'); } else { delete marcadas[clave]; }
                actualizarBoton();
            });
            btnCorregir.addEventListener('click', corregirMarcadas);
        }
        document.getElementById('sihosLiquidacionDetalleCerrar').addEventListener('click', cerrar);
        modal.addEventListener('click', function (e) { if (e.target === modal) cerrar(); });
    });
})();
