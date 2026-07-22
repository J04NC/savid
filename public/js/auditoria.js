(function () {
    'use strict';

    const modal = document.getElementById('auditoriaModal');
    const body = document.getElementById('auditoriaModalBody');

    function esc(s) {
        const d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function renderJsonBlock(label, data) {
        if (data == null || data === '') {
            return '';
        }
        const txt = typeof data === 'string' ? data : JSON.stringify(data, null, 2);
        return (
            '<section class="auditoria-detail-block">' +
            '<h4 class="auditoria-detail-label">' + esc(label) + '</h4>' +
            '<pre class="auditoria-json">' + esc(txt) + '</pre>' +
            '</section>'
        );
    }

    function renderDetail(d) {
        const badgeClass = 'auditoria-badge auditoria-badge-' + String(d.accion || '').toLowerCase();
        return (
            '<div class="auditoria-detail-grid">' +
            '<div class="auditoria-detail-item"><span class="auditoria-detail-k">Fecha</span><span>' + esc(d.occurred_at) + '</span></div>' +
            '<div class="auditoria-detail-item"><span class="auditoria-detail-k">Acción</span><span class="' + esc(badgeClass) + '">' + esc(d.accion) + '</span></div>' +
            '<div class="auditoria-detail-item"><span class="auditoria-detail-k">Tabla</span><code class="auditoria-code">' + esc(d.tabla) + '</code></div>' +
            '<div class="auditoria-detail-item"><span class="auditoria-detail-k">Registro</span><span>' + esc(d.registro_id) + '</span></div>' +
            '<div class="auditoria-detail-item"><span class="auditoria-detail-k">Usuario</span><span>' + esc(d.usuario_username || d.usuario_id) + '</span></div>' +
            '<div class="auditoria-detail-item"><span class="auditoria-detail-k">Empresa</span><span>' + esc(d.empresa_id || '—') + '</span></div>' +
            '<div class="auditoria-detail-item"><span class="auditoria-detail-k">Sede</span><span>' + esc(d.sede_id || '—') + '</span></div>' +
            '<div class="auditoria-detail-item auditoria-detail-item-wide"><span class="auditoria-detail-k">URL</span><span class="auditoria-detail-url">' + esc(d.request_url || '—') + '</span></div>' +
            '</div>' +
            renderJsonBlock('Campos cambiados', d.campos_cambiados) +
            renderJsonBlock('Datos anteriores', d.datos_anteriores) +
            renderJsonBlock('Datos nuevos', d.datos_nuevos) +
            '<section class="auditoria-detail-block">' +
            '<h4 class="auditoria-detail-label">SQL</h4>' +
            '<pre class="auditoria-json">' + esc(d.sql_resumen) + '</pre>' +
            '</section>'
        );
    }

    function openModal() {
        if (!modal) return;
        modal.classList.remove('hidden');
        document.body.classList.add('modal-open');
    }

    function closeModal() {
        if (!modal) return;
        modal.classList.add('hidden');
        document.body.classList.remove('modal-open');
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.btn-auditoria-detalle');
        if (!btn) return;

        var id = btn.getAttribute('data-id');
        var archivo = btn.getAttribute('data-archivo') === '1' ? '&archivo=1' : '';
        if (!body) return;

        body.innerHTML = '<p class="auditoria-loading">Cargando…</p>';
        openModal();

        fetch('?url=auditoria/detalle&id=' + encodeURIComponent(id) + archivo, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j.ok) {
                    body.innerHTML = '<p class="auditoria-error">' + esc(j.error || 'Error') + '</p>';
                    return;
                }
                body.innerHTML = renderDetail(j.data);
            })
            .catch(function () {
                body.innerHTML = '<p class="auditoria-error">No se pudo cargar el detalle.</p>';
            });
    });

    ['auditoriaModalClose', 'auditoriaModalCloseBtn'].forEach(function (id) {
        const el = document.getElementById(id);
        if (el) {
            el.addEventListener('click', closeModal);
        }
    });

    if (modal) {
        modal.addEventListener('click', function (e) {
            if (e.target === modal) {
                closeModal();
            }
        });
    }

    const btnArch = document.getElementById('btnArchivarAuditoria');
    if (btnArch) {
        btnArch.addEventListener('click', function () {
            if (!confirm('¿Mover a archivo los registros de auditoría con más de 24 meses?')) {
                return;
            }
            const fd = new FormData();
            fd.append('meses', '24');
            fetch('?url=auditoria/archivar', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    alert(j.message || j.error || (j.ok ? 'Listo' : 'Error'));
                    if (j.ok) {
                        location.reload();
                    }
                });
        });
    }
})();
