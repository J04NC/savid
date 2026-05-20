<?php
/** @var int $eid */
/** @var array{id:int, razon_social:string} $empresa */
/** @var array<int, array<string, mixed>> $sedes */
?>
<script>
document.addEventListener("DOMContentLoaded", function () {
    const modal = document.querySelector(".modal-content");
    if (modal) modal.classList.add("modal-lg");
});
</script>

<div id="empresaSedesModal" class="modal-inner" style="padding:16px; max-height:80vh; overflow:auto;"
     data-empresa-id="<?= (int)$eid ?>">
    <h3 style="margin:0 0 4px;">Sedes de la empresa</h3>
    <p style="margin:0 0 16px; color:#666; font-size:14px;">
        Empresa: <strong><?= htmlspecialchars((string)$empresa['razon_social'], ENT_QUOTES, 'UTF-8') ?></strong>
        (ID <?= (int)$eid ?>)
    </p>

    <form id="formSedeEmpresa" autocomplete="off"
          style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:10px;
                 padding:12px; border:1px solid #e5e5e5; border-radius:8px; background:#fafafa; margin-bottom:16px;">
        <input type="hidden" name="_action" value="save">
        <input type="hidden" name="sede_id" id="sedeFormId" value="">

        <label style="display:flex; flex-direction:column; gap:4px; font-size:12px;">
            <span>Nombre <span style="color:#c00;">*</span></span>
            <input type="text" name="nombre" id="sedeFormNombre" maxlength="100" required
                   style="padding:6px 8px; border:1px solid #ccc; border-radius:4px;">
            <span class="sede-err" data-field="nombre" style="color:#c00; font-size:11px; min-height:14px;"></span>
        </label>

        <label style="display:flex; flex-direction:column; gap:4px; font-size:12px;">
            <span>Dirección</span>
            <input type="text" name="direccion" id="sedeFormDireccion" maxlength="150"
                   style="padding:6px 8px; border:1px solid #ccc; border-radius:4px;">
            <span class="sede-err" data-field="direccion" style="color:#c00; font-size:11px; min-height:14px;"></span>
        </label>

        <label style="display:flex; flex-direction:column; gap:4px; font-size:12px;">
            <span>Teléfono</span>
            <input type="text" name="telefono" id="sedeFormTelefono" maxlength="150"
                   style="padding:6px 8px; border:1px solid #ccc; border-radius:4px;">
            <span class="sede-err" data-field="telefono" style="color:#c00; font-size:11px; min-height:14px;"></span>
        </label>

        <label style="display:flex; flex-direction:column; gap:4px; font-size:12px;">
            <span>Código interno</span>
            <input type="text" name="codigo_interno" id="sedeFormCodigo" maxlength="150"
                   style="padding:6px 8px; border:1px solid #ccc; border-radius:4px;">
            <span class="sede-err" data-field="codigo_interno" style="color:#c00; font-size:11px; min-height:14px;"></span>
        </label>

        <label style="display:flex; flex-direction:column; gap:4px; font-size:12px;">
            <span>Estado</span>
            <select name="estado_id" id="sedeFormEstado"
                    style="padding:6px 8px; border:1px solid #ccc; border-radius:4px;">
                <option value="1">Activo</option>
                <option value="2">Inactivo</option>
            </select>
        </label>

        <div style="grid-column:1 / -1; display:flex; gap:8px; justify-content:flex-end; align-items:flex-end;">
            <button type="button" id="sedeFormReset" class="btn-cancel">Limpiar</button>
            <button type="submit" id="sedeFormSubmit" class="btn-save">💾 Guardar sede</button>
        </div>
    </form>

    <div style="overflow-x:auto;">
        <table id="sedeTable" style="width:100%; border-collapse:collapse; font-size:13px;">
            <thead>
                <tr style="background:#f5f5f5;">
                    <th style="text-align:left; padding:8px;">Nombre</th>
                    <th style="text-align:left; padding:8px;">Dirección</th>
                    <th style="text-align:left; padding:8px;">Teléfono</th>
                    <th style="text-align:left; padding:8px;">Código</th>
                    <th style="text-align:center; padding:8px;">Estado</th>
                    <th style="text-align:right; padding:8px;">Acciones</th>
                </tr>
            </thead>
            <tbody id="sedeTableBody">
                <?php if (empty($sedes)): ?>
                    <tr><td colspan="6" style="padding:12px; text-align:center; color:#888;">No hay sedes registradas para esta empresa.</td></tr>
                <?php else: ?>
                    <?php foreach ($sedes as $s): ?>
                        <?php $sActivo = (int)($s['estado_id'] ?? 0) === 1; ?>
                        <tr data-sede-id="<?= (int)$s['id'] ?>"
                            data-nombre="<?= htmlspecialchars((string)($s['nombre'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                            data-direccion="<?= htmlspecialchars((string)($s['direccion'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                            data-telefono="<?= htmlspecialchars((string)($s['telefono'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                            data-codigo-interno="<?= htmlspecialchars((string)($s['codigo_interno'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                            data-estado-id="<?= (int)($s['estado_id'] ?? 0) ?>"
                            style="border-top:1px solid #eee;<?= $sActivo ? '' : ' opacity:0.55;' ?>">
                            <td style="padding:8px;"><?= htmlspecialchars((string)($s['nombre'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td style="padding:8px;"><?= htmlspecialchars((string)($s['direccion'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td style="padding:8px;"><?= htmlspecialchars((string)($s['telefono'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td style="padding:8px;"><?= htmlspecialchars((string)($s['codigo_interno'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td style="padding:8px; text-align:center;">
                                <span style="font-size:11px; padding:2px 8px; border-radius:10px;
                                       background:<?= $sActivo ? '#2e7d32' : '#9e9e9e' ?>; color:#fff;">
                                    <?= $sActivo ? 'Activo' : 'Inactivo' ?>
                                </span>
                            </td>
                            <td style="padding:8px; text-align:right; white-space:nowrap;">
                                <button type="button" class="sede-btn-edit"
                                        title="Editar"
                                        style="margin-right:4px;">✏️</button>
                                <button type="button" class="sede-btn-toggle"
                                        title="<?= $sActivo ? 'Inactivar' : 'Activar' ?>">
                                    <?= $sActivo ? '🚫' : '✅' ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div style="margin-top:18px; text-align:right;">
        <button type="button" class="btn-cancel" onclick="closeModalGod()">Cerrar</button>
    </div>
</div>

<script>
(function(){
const root = document.getElementById("empresaSedesModal");
if (!root) return;

const empresaId = parseInt(root.dataset.empresaId || "0", 10) || 0;
const form = document.getElementById("formSedeEmpresa");
const tableBody = document.getElementById("sedeTableBody");

const fields = {
    sede_id: document.getElementById("sedeFormId"),
    nombre: document.getElementById("sedeFormNombre"),
    direccion: document.getElementById("sedeFormDireccion"),
    telefono: document.getElementById("sedeFormTelefono"),
    codigo_interno: document.getElementById("sedeFormCodigo"),
    estado_id: document.getElementById("sedeFormEstado"),
};

const submitBtn = document.getElementById("sedeFormSubmit");
const resetBtn = document.getElementById("sedeFormReset");

function clearErrors() {
    root.querySelectorAll(".sede-err").forEach(el => el.textContent = "");
}

function showErrors(errs) {
    clearErrors();
    if (!errs) return;
    Object.keys(errs).forEach(k => {
        const el = root.querySelector('.sede-err[data-field="' + k + '"]');
        if (el) el.textContent = errs[k];
    });
}

function resetForm() {
    fields.sede_id.value = "";
    fields.nombre.value = "";
    fields.direccion.value = "";
    fields.telefono.value = "";
    fields.codigo_interno.value = "";
    fields.estado_id.value = "1";
    submitBtn.innerHTML = "💾 Guardar sede";
    clearErrors();
}

function loadRowIntoForm(row) {
    fields.sede_id.value = row.dataset.sedeId || "";
    fields.nombre.value = row.dataset.nombre || "";
    fields.direccion.value = row.dataset.direccion || "";
    fields.telefono.value = row.dataset.telefono || "";
    fields.codigo_interno.value = row.dataset.codigoInterno || "";
    fields.estado_id.value = row.dataset.estadoId === "1" ? "1" : "2";
    submitBtn.innerHTML = "💾 Actualizar sede";
    clearErrors();
    fields.nombre.focus();
}

function renderRows(sedes) {
    if (!sedes || sedes.length === 0) {
        tableBody.innerHTML = '<tr><td colspan="6" style="padding:12px; text-align:center; color:#888;">No hay sedes registradas para esta empresa.</td></tr>';
        return;
    }
    const esc = s => String(s == null ? "" : s)
        .replaceAll("&", "&amp;").replaceAll("<", "&lt;").replaceAll(">", "&gt;")
        .replaceAll('"', "&quot;");
    const html = sedes.map(s => {
        const estadoId = parseInt(s.estado_id, 10);
        const activo = estadoId === 1;
        return (
            '<tr data-sede-id="' + parseInt(s.id, 10) + '"' +
            ' data-nombre="' + esc(s.nombre) + '"' +
            ' data-direccion="' + esc(s.direccion) + '"' +
            ' data-telefono="' + esc(s.telefono) + '"' +
            ' data-codigo-interno="' + esc(s.codigo_interno) + '"' +
            ' data-estado-id="' + estadoId + '"' +
            ' style="border-top:1px solid #eee;' + (activo ? '' : ' opacity:0.55;') + '">' +
                '<td style="padding:8px;">' + esc(s.nombre) + '</td>' +
                '<td style="padding:8px;">' + esc(s.direccion) + '</td>' +
                '<td style="padding:8px;">' + esc(s.telefono) + '</td>' +
                '<td style="padding:8px;">' + esc(s.codigo_interno) + '</td>' +
                '<td style="padding:8px; text-align:center;">' +
                    '<span style="font-size:11px; padding:2px 8px; border-radius:10px; ' +
                    'background:' + (activo ? '#2e7d32' : '#9e9e9e') + '; color:#fff;">' +
                    (activo ? 'Activo' : 'Inactivo') + '</span>' +
                '</td>' +
                '<td style="padding:8px; text-align:right; white-space:nowrap;">' +
                    '<button type="button" class="sede-btn-edit" title="Editar" style="margin-right:4px;">✏️</button>' +
                    '<button type="button" class="sede-btn-toggle" title="' + (activo ? 'Inactivar' : 'Activar') + '">' +
                    (activo ? '🚫' : '✅') + '</button>' +
                '</td>' +
            '</tr>'
        );
    }).join("");
    tableBody.innerHTML = html;
}

resetBtn.addEventListener("click", resetForm);

tableBody.addEventListener("click", function(e) {
    const btnEdit = e.target.closest(".sede-btn-edit");
    const btnToggle = e.target.closest(".sede-btn-toggle");
    if (!btnEdit && !btnToggle) return;
    const row = e.target.closest("tr[data-sede-id]");
    if (!row) return;

    if (btnEdit) {
        loadRowIntoForm(row);
        return;
    }

    if (btnToggle) {
        const isActive = row.dataset.estadoId === "1";
        const ok = confirm(isActive
            ? "¿Inactivar esta sede? Los usuarios vinculados podrían perder acceso."
            : "¿Activar esta sede?"
        );
        if (!ok) return;

        const fd = new FormData();
        fd.append("_action", "toggle");
        fd.append("sede_id", row.dataset.sedeId);

        btnToggle.disabled = true;
        fetch("?url=empresa/sedes/" + empresaId, { method: "POST", body: fd })
            .then(r => r.json())
            .then(data => {
                btnToggle.disabled = false;
                if (data.success) {
                    renderRows(data.sedes || []);
                    if (parseInt(fields.sede_id.value, 10) === parseInt(row.dataset.sedeId, 10)) {
                        resetForm();
                    }
                } else {
                    alert("❌ " + (data.message || "No se pudo cambiar el estado"));
                }
            })
            .catch(() => {
                btnToggle.disabled = false;
                alert("❌ Error de conexión");
            });
    }
});

form.addEventListener("submit", function(e) {
    e.preventDefault();
    clearErrors();

    const txt = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = "⏳ Guardando…";

    fetch("?url=empresa/sedes/" + empresaId, {
        method: "POST",
        body: new FormData(form),
    })
    .then(r => r.json())
    .then(data => {
        submitBtn.disabled = false;
        submitBtn.innerHTML = txt;
        if (data.success) {
            renderRows(data.sedes || []);
            resetForm();
        } else {
            if (data.errors) showErrors(data.errors);
            alert("❌ " + (data.message || "No se pudo guardar"));
        }
    })
    .catch(() => {
        submitBtn.disabled = false;
        submitBtn.innerHTML = txt;
        alert("❌ Error de conexión");
    });
});
})();
</script>
