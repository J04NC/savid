(function () {
    'use strict';

    const modal = document.getElementById('sihosVerRubrosModal');
    if (!modal) return;

    const docSpan = document.getElementById('sihosVerRubrosDocumento');
    const body = document.getElementById('sihosVerRubrosBody');
    const totalCell = document.getElementById('sihosVerRubrosTotal');
    const btnCerrar = document.getElementById('sihosVerRubrosCerrar');

    function formatoMoneda(valor) {
        const numero = Math.round(Number(valor) || 0);
        return '$' + numero.toLocaleString('es-CO');
    }

    function abrirModal(documento, rubros) {
        docSpan.textContent = documento;
        body.innerHTML = '';

        let total = 0;
        rubros.forEach(function (r) {
            total += Number(r.Valor) || 0;
            const tr = document.createElement('tr');

            const tdCodigo = document.createElement('td');
            tdCodigo.textContent = r.CodiPlan;
            tr.appendChild(tdCodigo);

            const tdNombre = document.createElement('td');
            tdNombre.textContent = r.NombPlan || '—';
            tr.appendChild(tdNombre);

            const tdValor = document.createElement('td');
            tdValor.style.textAlign = 'right';
            tdValor.textContent = formatoMoneda(r.Valor);
            tr.appendChild(tdValor);

            body.appendChild(tr);
        });

        totalCell.textContent = formatoMoneda(total);
        modal.classList.remove('hidden');
    }

    function cerrarModal() {
        modal.classList.add('hidden');
    }

    document.querySelectorAll('.btnSihosVerRubros').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const rubros = JSON.parse(btn.getAttribute('data-rubros') || '[]');
            abrirModal(btn.getAttribute('data-documento'), rubros);
        });
    });

    btnCerrar.addEventListener('click', cerrarModal);
})();
