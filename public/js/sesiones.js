(function () {
    'use strict';

    const searchInput = document.getElementById('sesionesTableSearch');
    const tableBody = document.getElementById('sesionesTableBody');

    if (searchInput && tableBody) {
        searchInput.addEventListener('input', function () {
            const q = searchInput.value.trim().toLowerCase();
            tableBody.querySelectorAll('.sesiones-row').forEach(function (row) {
                const hay = (row.getAttribute('data-search') || '').toLowerCase();
                row.style.display = !q || hay.indexOf(q) !== -1 ? '' : 'none';
            });
        });
    }
})();
