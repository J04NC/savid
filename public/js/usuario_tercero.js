/**
 * Pantalla dedicada usuario: abre modal de tercero y sincroniza resumen al elegir fila.
 */
(function () {
    function terceroIdFromForm() {
        const hid = document.querySelector('input[name="tercero_id"].crud-catalog-id')
            || document.querySelector('input[name="tercero_id"]')
            || document.querySelector('select[name="tercero_id"]');
        if (hid) {
            const v = String(hid.value || "").trim();
            if (v !== "") return v;
        }
        const ident = document.querySelector('input[name="terceroidentificacion_id"]');
        if (ident && String(ident.value || "").trim() !== "") {
            return String(ident.value || "").trim();
        }
        return "0";
    }

    function syncResumenFromRow(row) {
        const sum = document.getElementById("usuario_tercero_resumen");
        if (!sum || !row || !row.dataset) return;
        const lab = row.dataset.terceroEtiqueta;
        if (lab) sum.textContent = lab;
    }

    document.addEventListener("DOMContentLoaded", function () {
        const btn = document.getElementById("btnUsuarioTerceroModal");
        if (!btn) return;

        btn.addEventListener("click", function () {
            if (typeof openModalGod !== "function") {
                alert("Modal no disponible");
                return;
            }
            const tid = terceroIdFromForm();
            openModalGod("usuario/terceroModal/" + tid, "lg", "Cargando tercero…");
        });

        document.querySelectorAll(".crud-row").forEach(function (row) {
            row.addEventListener("click", function () {
                window.setTimeout(function () {
                    syncResumenFromRow(row);
                }, 0);
            });
        });
    });
})();
