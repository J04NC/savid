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

        function parametros() {
            return { empresa_id: modal.getAttribute('data-empresa'), job: modal.getAttribute('data-job') };
        }

        function crearTabla() {
            tabla = $('#sihosLiquidacionDetalleTabla').DataTable({
                serverSide: true,
                processing: true,
                autoWidth: false,
                pageLength: 50,
                lengthMenu: [25, 50, 100, 200],
                order: [[0, 'asc']],
                columnDefs: [{ targets: [12, 13], className: 'dt-right' }],
                ajax: {
                    url: url,
                    type: 'GET',
                    data: function (d) {
                        return $.extend(parametros(), {
                            draw: d.draw, start: d.start, length: d.length,
                            search: { value: d.search.value }, order: d.order.slice(0, 1), f: filtros
                        });
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
            if (tabla) {
                tabla.search('').order([[0, 'asc']]).draw();
            } else {
                crearTabla();
            }
        }

        function cerrar() {
            modal.classList.add('hidden');
        }

        function filaDesde(el) {
            var tr = el.closest ? el.closest('tr[data-liq-filtro]') : null;
            if (!tr) return;
            try {
                abrir(JSON.parse(tr.getAttribute('data-liq-filtro')), tr.getAttribute('data-liq-titulo') || 'Detalle');
            } catch (e) { /* atributo mal formado: no se abre */ }
        }

        document.addEventListener('click', function (e) { filaDesde(e.target); });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && e.target.matches && e.target.matches('tr[data-liq-filtro]')) filaDesde(e.target);
            if (e.key === 'Escape') cerrar();
        });
        document.getElementById('sihosLiquidacionDetalleCerrar').addEventListener('click', cerrar);
        modal.addEventListener('click', function (e) { if (e.target === modal) cerrar(); });
    });
})();
