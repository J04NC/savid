<?php
/** @var int $eid */
/** @var array{id:int, razon_social:string} $empresa */
/** @var array<int, array<string, mixed>> $sedes */

$esSuperAdmin = !empty($_SESSION['es_super_admin'])
    || (int)($_SESSION['rol_id'] ?? 0) === 1;
?>

<div id="empresaSedesModal" class="empresa-sedes-modal modal-inner"
     data-empresa-id="<?= (int)$eid ?>"
     data-es-super-admin="<?= $esSuperAdmin ? '1' : '0' ?>">

    <header class="modal-form-head">
        <h3 class="modal-form-title">Sedes de la empresa</h3>
        <p class="modal-form-meta">
            Empresa: <strong><?= htmlspecialchars((string)$empresa['razon_social'], ENT_QUOTES, 'UTF-8') ?></strong>
            <span class="empresa-sedes-meta-id">(ID <?= (int)$eid ?>)</span>
        </p>
        <p class="modal-form-help">Cree o edite sedes. Use activar/inactivar para retirar acceso sin borrar el registro.</p>
    </header>

    <form id="formSedeEmpresa" class="empresa-sedes-form" autocomplete="off">
        <input type="hidden" name="_action" value="save">
        <input type="hidden" name="sede_id" id="sedeFormId" value="">

        <label class="empresa-sedes-field">
            <span class="empresa-sedes-label">Nombre <span class="empresa-sedes-required">*</span></span>
            <input type="text" name="nombre" id="sedeFormNombre" class="empresa-sedes-input" maxlength="100" required>
            <span class="sede-err" data-field="nombre"></span>
        </label>

        <label class="empresa-sedes-field">
            <span class="empresa-sedes-label">Dirección</span>
            <input type="text" name="direccion" id="sedeFormDireccion" class="empresa-sedes-input" maxlength="150">
            <span class="sede-err" data-field="direccion"></span>
        </label>

        <label class="empresa-sedes-field">
            <span class="empresa-sedes-label">Teléfono</span>
            <input type="text" name="telefono" id="sedeFormTelefono" class="empresa-sedes-input" maxlength="150">
            <span class="sede-err" data-field="telefono"></span>
        </label>

        <label class="empresa-sedes-field">
            <span class="empresa-sedes-label">Código interno</span>
            <input type="text" name="codigo_interno" id="sedeFormCodigo" class="empresa-sedes-input" maxlength="150">
            <span class="sede-err" data-field="codigo_interno"></span>
        </label>

        <label class="empresa-sedes-field">
            <span class="empresa-sedes-label">Estado</span>
            <select name="estado_id" id="sedeFormEstado" class="empresa-sedes-input">
                <option value="1">Activo</option>
                <option value="2">Inactivo</option>
            </select>
        </label>

        <div class="empresa-sedes-form-actions">
            <button type="button" id="sedeFormReset" class="btn-cancel">Limpiar</button>
            <button type="submit" id="sedeFormSubmit" class="btn-save">Guardar sede</button>
        </div>
    </form>

    <div class="empresa-sedes-table-wrap">
        <table id="sedeTable" class="empresa-sedes-table">
            <thead>
                <tr>
                    <th>Nombre</th>
                    <th>Dirección</th>
                    <th>Teléfono</th>
                    <th>Código</th>
                    <th class="empresa-sedes-th-center">Estado</th>
                    <th class="empresa-sedes-th-actions">Acciones</th>
                </tr>
            </thead>
            <tbody id="sedeTableBody">
                <?php if (empty($sedes)): ?>
                    <tr>
                        <td colspan="6" class="empresa-sedes-empty">No hay sedes registradas para esta empresa.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($sedes as $s): ?>
                        <?php $sActivo = (int)($s['estado_id'] ?? 0) === 1; ?>
                        <tr data-sede-id="<?= (int)$s['id'] ?>"
                            data-nombre="<?= htmlspecialchars((string)($s['nombre'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                            data-direccion="<?= htmlspecialchars((string)($s['direccion'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                            data-telefono="<?= htmlspecialchars((string)($s['telefono'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                            data-codigo-interno="<?= htmlspecialchars((string)($s['codigo_interno'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                            data-estado-id="<?= (int)($s['estado_id'] ?? 0) ?>"
                            class="<?= $sActivo ? '' : 'empresa-sedes-row-inactive' ?>">
                            <td><?= htmlspecialchars((string)($s['nombre'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($s['direccion'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($s['telefono'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($s['codigo_interno'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="empresa-sedes-td-center">
                                <span class="empresa-sedes-badge <?= $sActivo ? 'empresa-sedes-badge-active' : 'empresa-sedes-badge-inactive' ?>">
                                    <?= $sActivo ? 'Activo' : 'Inactivo' ?>
                                </span>
                            </td>
                            <td class="empresa-sedes-td-actions">
                                <button type="button" class="empresa-sedes-icon-btn sede-btn-edit" title="Editar">✏️</button>
                                <button type="button" class="empresa-sedes-icon-btn sede-btn-toggle"
                                        title="<?= $sActivo ? 'Inactivar' : 'Activar' ?>">
                                    <?= $sActivo ? '🚫' : '✅' ?>
                                </button>
                                <?php if ($esSuperAdmin): ?>
                                <button type="button" class="empresa-sedes-icon-btn sede-btn-delete empresa-sedes-btn-delete"
                                        title="Eliminar registro">🗑</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <footer class="empresa-sedes-footer">
        <button type="button" class="btn-cancel" onclick="closeModalGod()">Cerrar</button>
    </footer>
</div>

<script>
(function () {
    const root = document.getElementById("empresaSedesModal");
    if (!root) return;

    const empresaId = parseInt(root.dataset.empresaId || "0", 10) || 0;
    const esSuperAdmin = root.dataset.esSuperAdmin === "1";
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
        root.querySelectorAll(".sede-err").forEach(el => { el.textContent = ""; });
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
        submitBtn.textContent = "Guardar sede";
        clearErrors();
    }

    function loadRowIntoForm(row) {
        fields.sede_id.value = row.dataset.sedeId || "";
        fields.nombre.value = row.dataset.nombre || "";
        fields.direccion.value = row.dataset.direccion || "";
        fields.telefono.value = row.dataset.telefono || "";
        fields.codigo_interno.value = row.dataset.codigoInterno || "";
        fields.estado_id.value = row.dataset.estadoId === "1" ? "1" : "2";
        submitBtn.textContent = "Actualizar sede";
        clearErrors();
        fields.nombre.focus();
    }

    function esc(s) {
        return String(s == null ? "" : s)
            .replaceAll("&", "&amp;").replaceAll("<", "&lt;").replaceAll(">", "&gt;")
            .replaceAll('"', "&quot;");
    }

    function renderRows(sedes) {
        if (!sedes || sedes.length === 0) {
            tableBody.innerHTML = '<tr><td colspan="6" class="empresa-sedes-empty">No hay sedes registradas para esta empresa.</td></tr>';
            return;
        }

        const deleteBtnHtml = esSuperAdmin
            ? '<button type="button" class="empresa-sedes-icon-btn sede-btn-delete empresa-sedes-btn-delete" title="Eliminar registro">🗑</button>'
            : "";

        tableBody.innerHTML = sedes.map(s => {
            const estadoId = parseInt(s.estado_id, 10);
            const activo = estadoId === 1;
            return (
                '<tr data-sede-id="' + parseInt(s.id, 10) + '"' +
                ' data-nombre="' + esc(s.nombre) + '"' +
                ' data-direccion="' + esc(s.direccion) + '"' +
                ' data-telefono="' + esc(s.telefono) + '"' +
                ' data-codigo-interno="' + esc(s.codigo_interno) + '"' +
                ' data-estado-id="' + estadoId + '"' +
                ' class="' + (activo ? "" : "empresa-sedes-row-inactive") + '">' +
                    '<td>' + esc(s.nombre) + '</td>' +
                    '<td>' + esc(s.direccion) + '</td>' +
                    '<td>' + esc(s.telefono) + '</td>' +
                    '<td>' + esc(s.codigo_interno) + '</td>' +
                    '<td class="empresa-sedes-td-center">' +
                        '<span class="empresa-sedes-badge ' + (activo ? "empresa-sedes-badge-active" : "empresa-sedes-badge-inactive") + '">' +
                        (activo ? "Activo" : "Inactivo") + '</span>' +
                    '</td>' +
                    '<td class="empresa-sedes-td-actions">' +
                        '<button type="button" class="empresa-sedes-icon-btn sede-btn-edit" title="Editar">✏️</button>' +
                        '<button type="button" class="empresa-sedes-icon-btn sede-btn-toggle" title="' +
                        (activo ? "Inactivar" : "Activar") + '">' + (activo ? "🚫" : "✅") + '</button>' +
                        deleteBtnHtml +
                    '</td>' +
                '</tr>'
            );
        }).join("");
    }

    resetBtn.addEventListener("click", resetForm);

    tableBody.addEventListener("click", function (e) {
        const btnEdit = e.target.closest(".sede-btn-edit");
        const btnToggle = e.target.closest(".sede-btn-toggle");
        const btnDelete = e.target.closest(".sede-btn-delete");
        if (!btnEdit && !btnToggle && !btnDelete) return;

        const row = e.target.closest("tr[data-sede-id]");
        if (!row) return;

        if (btnEdit) {
            loadRowIntoForm(row);
            return;
        }

        if (btnDelete) {
            const nombre = row.dataset.nombre || "";
            const ok = confirm(
                "¿Eliminar permanentemente la sede \"" + nombre + "\"?\n\n" +
                "Se quitarán las asignaciones de usuarios en esa sede. No se puede deshacer."
            );
            if (!ok) return;

            const fd = new FormData();
            fd.append("_action", "delete");
            fd.append("sede_id", row.dataset.sedeId);

            btnDelete.disabled = true;
            fetch("?url=empresa/sedes/" + empresaId, { method: "POST", body: fd })
                .then(r => r.json())
                .then(data => {
                    btnDelete.disabled = false;
                    if (data.success) {
                        renderRows(data.sedes || []);
                        if (parseInt(fields.sede_id.value, 10) === parseInt(row.dataset.sedeId, 10)) {
                            resetForm();
                        }
                    } else {
                        alert("❌ " + (data.message || "No se pudo eliminar"));
                    }
                })
                .catch(() => {
                    btnDelete.disabled = false;
                    alert("❌ Error de conexión");
                });
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

    form.addEventListener("submit", function (e) {
        e.preventDefault();
        clearErrors();

        const txt = submitBtn.textContent;
        submitBtn.disabled = true;
        submitBtn.textContent = "Guardando…";

        fetch("?url=empresa/sedes/" + empresaId, {
            method: "POST",
            body: new FormData(form),
        })
            .then(r => r.json())
            .then(data => {
                submitBtn.disabled = false;
                submitBtn.textContent = txt;
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
                submitBtn.textContent = txt;
                alert("❌ Error de conexión");
            });
    });
})();
</script>
