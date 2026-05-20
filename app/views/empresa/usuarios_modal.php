<?php
/** @var array{id:int, razon_social:string} $empresa */
/** @var array<int, array<string, mixed>> $usuarios */

$empresaId = (int)$empresa['id'];
$razonSocial = (string)$empresa['razon_social'];

$endpoint = '?url=empresa/usuarios/' . $empresaId;
$sessionEmpresaId = isset($_SESSION['empresa_id']) ? (int)$_SESSION['empresa_id'] : 0;
$currentUid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$esSuperAdmin = !empty($_SESSION['es_super_admin']) || (int)($_SESSION['rol_id'] ?? 0) === 1;
?>

<div class="empresa-usuarios-modal" data-endpoint="<?= htmlspecialchars($endpoint, ENT_QUOTES, 'UTF-8') ?>"
     data-empresa-id="<?= $empresaId ?>"
     data-session-empresa-id="<?= $sessionEmpresaId ?>"
     data-current-uid="<?= $currentUid ?>"
     data-es-super-admin="<?= $esSuperAdmin ? '1' : '0' ?>">

    <header style="display:flex; align-items:baseline; gap:.5rem; margin-bottom:.75rem;">
        <h3 style="margin:0; font-size:1.05rem;">Usuarios de la empresa</h3>
        <span style="opacity:.7;">·</span>
        <strong><?= htmlspecialchars($razonSocial, ENT_QUOTES, 'UTF-8') ?></strong>
        <?php if ($empresaId === $sessionEmpresaId): ?>
            <span style="background:#cfe8ff; color:#0b4f8a; border-radius:8px; padding:2px 8px; font-size:11px;">SESIÓN</span>
        <?php endif; ?>
    </header>

    <section style="margin-bottom:1rem;">
        <label for="empresaUsuariosSearchTerm" style="font-weight:600;">Vincular usuario</label>
        <div style="display:flex; gap:.5rem; margin-top:.25rem;">
            <input type="text" id="empresaUsuariosSearchTerm"
                   placeholder="Buscar por username, NIT, nombre o razón social…"
                   autocomplete="off"
                   style="flex:1; padding:.45rem .6rem; border:1px solid #ccc; border-radius:6px;">
            <button type="button" id="empresaUsuariosSearchBtn"
                    style="padding:.45rem .9rem; border:1px solid #1976d2; background:#1976d2; color:#fff; border-radius:6px; cursor:pointer;">
                Buscar
            </button>
        </div>
        <div id="empresaUsuariosSearchResults" style="margin-top:.5rem;"></div>
        <p id="empresaUsuariosFeedback" style="margin:.4rem 0 0; min-height:1.1em; font-size:13px; color:#1b5e20;"></p>
    </section>

    <section>
        <h4 style="margin:0 0 .4rem; font-size:.95rem;">Usuarios vinculados</h4>
        <div id="empresaUsuariosTableWrap">
            <?php require __DIR__ . '/_usuarios_table.php'; ?>
        </div>
    </section>
</div>

<script>
(function () {
    const root = document.querySelector('.empresa-usuarios-modal');
    if (!root) return;

    const endpoint = root.dataset.endpoint;
    const empresaId = parseInt(root.dataset.empresaId || '0', 10);
    const sessionEmpresaId = parseInt(root.dataset.sessionEmpresaId || '0', 10);
    const currentUid = parseInt(root.dataset.currentUid || '0', 10);
    const esSuperAdmin = root.dataset.esSuperAdmin === '1';

    const termEl = root.querySelector('#empresaUsuariosSearchTerm');
    const searchBtn = root.querySelector('#empresaUsuariosSearchBtn');
    const resultsEl = root.querySelector('#empresaUsuariosSearchResults');
    const feedbackEl = root.querySelector('#empresaUsuariosFeedback');
    const tableWrap = root.querySelector('#empresaUsuariosTableWrap');

    function setFeedback(msg, isError) {
        feedbackEl.textContent = msg || '';
        feedbackEl.style.color = isError ? '#b71c1c' : '#1b5e20';
    }

    function postAction(action, payload) {
        const fd = new FormData();
        fd.append('_action', action);
        Object.keys(payload || {}).forEach(k => fd.append(k, payload[k]));
        return fetch(endpoint, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(r => r.json());
    }

    function renderSearchResults(options) {
        if (!options || options.length === 0) {
            resultsEl.innerHTML = '<p style="margin:.25rem 0; color:#666; font-size:13px;">Sin coincidencias.</p>';
            return;
        }
        const rows = options.map(opt => {
            const label = [
                opt.username,
                (opt.razon_social || ((opt.nombres || '') + ' ' + (opt.apellidos || '')).trim()),
                opt.doc ? ('Doc. ' + opt.doc) : ''
            ].filter(Boolean).join(' · ');
            const linked = parseInt(opt.ya_vinculado, 10) === 1;
            return '<div style="display:flex; align-items:center; gap:.5rem; padding:.35rem 0; border-bottom:1px dashed #ddd;">'
                + '<span style="flex:1;">' + escHtml(label) + '</span>'
                + (linked
                    ? '<span style="font-size:12px; color:#666;">ya vinculado</span>'
                    : '<button type="button" class="empresa-usuarios-link-btn" data-uid="' + opt.id + '" '
                      + 'style="padding:.25rem .6rem; border:1px solid #2e7d32; background:#2e7d32; color:#fff; border-radius:6px; cursor:pointer;">'
                      + 'Vincular</button>')
                + '</div>';
        }).join('');
        resultsEl.innerHTML = rows;
    }

    function renderUsuariosTable(rows) {
        if (!rows || rows.length === 0) {
            tableWrap.innerHTML = '<p style="color:#666;">Sin usuarios vinculados.</p>';
            return;
        }
        const trs = rows.map(r => {
            const nombre = (r.razon_social || ((r.nombres || '') + ' ' + (r.apellidos || '')).trim()) || '—';
            const doc = r.nit_or_doc || '—';
            const estado = parseInt(r.link_estado_id, 10) === 1 ? 'Activo' : 'Inactivo';
            const estadoColor = parseInt(r.link_estado_id, 10) === 1 ? '#2e7d32' : '#b71c1c';
            const toggleLabel = parseInt(r.link_estado_id, 10) === 1 ? 'Inactivar' : 'Activar';
            return '<tr>'
                + '<td>' + escHtml(r.username || '') + '</td>'
                + '<td>' + escHtml(nombre) + '</td>'
                + '<td>' + escHtml(doc) + '</td>'
                + '<td><span style="color:' + estadoColor + '; font-weight:600;">' + estado + '</span></td>'
                + '<td><button type="button" class="empresa-usuarios-toggle-btn" data-uid="' + r.id + '" '
                    + 'style="padding:.2rem .55rem; border:1px solid #455a64; background:#fff; color:#37474f; border-radius:6px; cursor:pointer; font-size:12px;">'
                    + toggleLabel + '</button></td>'
                + '</tr>';
        }).join('');
        tableWrap.innerHTML =
            '<table style="width:100%; border-collapse:collapse; font-size:13px;">'
            + '<thead><tr style="background:#eceff1;">'
            + '<th style="text-align:left; padding:.4rem;">Username</th>'
            + '<th style="text-align:left; padding:.4rem;">Nombre / Razón</th>'
            + '<th style="text-align:left; padding:.4rem;">Doc/NIT</th>'
            + '<th style="text-align:left; padding:.4rem;">Vínculo</th>'
            + '<th style="text-align:left; padding:.4rem;">Acción</th>'
            + '</tr></thead>'
            + '<tbody>' + trs + '</tbody></table>';
    }

    function escHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function doSearch() {
        const term = (termEl.value || '').trim();
        if (term.length < 2) {
            resultsEl.innerHTML = '<p style="color:#666; font-size:13px;">Escriba al menos 2 caracteres.</p>';
            return;
        }
        setFeedback('');
        postAction('search', { term })
            .then(data => {
                if (!data || !data.success) {
                    setFeedback(data && data.message ? data.message : 'Búsqueda falló.', true);
                    return;
                }
                renderSearchResults(data.options || []);
            })
            .catch(err => setFeedback('Error de red: ' + err.message, true));
    }

    searchBtn.addEventListener('click', doSearch);
    termEl.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); doSearch(); } });

    resultsEl.addEventListener('click', e => {
        const btn = e.target.closest('.empresa-usuarios-link-btn');
        if (!btn) return;
        const uid = parseInt(btn.dataset.uid || '0', 10);
        if (!uid) return;
        btn.disabled = true;
        postAction('link', { usuario_id: uid })
            .then(data => {
                if (!data || !data.success) {
                    setFeedback(data && data.message ? data.message : 'No se pudo vincular.', true);
                    btn.disabled = false;
                    return;
                }
                setFeedback(data.message || 'Usuario vinculado.');
                renderUsuariosTable(data.usuarios || []);
                doSearch();
            })
            .catch(err => { setFeedback('Error de red: ' + err.message, true); btn.disabled = false; });
    });

    tableWrap.addEventListener('click', e => {
        const btn = e.target.closest('.empresa-usuarios-toggle-btn');
        if (!btn) return;
        const uid = parseInt(btn.dataset.uid || '0', 10);
        if (!uid) return;

        if (uid === currentUid && empresaId === sessionEmpresaId && !esSuperAdmin) {
            setFeedback('No puede inactivarse a sí mismo en la empresa de su sesión.', true);
            return;
        }

        if (!confirm('¿Confirma cambiar el estado del vínculo? Si lo inactiva, también se inactivarán las sedes asociadas para este usuario.')) {
            return;
        }

        btn.disabled = true;
        postAction('toggle', { usuario_id: uid })
            .then(data => {
                if (!data || !data.success) {
                    setFeedback(data && data.message ? data.message : 'No se pudo cambiar.', true);
                    btn.disabled = false;
                    return;
                }
                setFeedback(data.message || 'Estado actualizado.');
                renderUsuariosTable(data.usuarios || []);
            })
            .catch(err => { setFeedback('Error de red: ' + err.message, true); btn.disabled = false; });
    });
})();
</script>
