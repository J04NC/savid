<?php
/** @var array<int, array<string, mixed>> $assignments */
/** @var array<int, array<string, mixed>> $roles */
/** @var array<int, array<string, mixed>> $empresasDisponibles */
/** @var array<int|array-key, array<int, array<string, mixed>>> $sedesPorEmpresa */

$renderSedeOptions = static function (?int $empresaId, array $sedesPorEmpresa, $selectedSedeId): void {
    $sedeVacío = ($selectedSedeId === null || $selectedSedeId === '');
    echo '<option value=""' . ($sedeVacío ? ' selected' : '') . '>Toda la empresa (sin sede)</option>';
    if ($empresaId === null || $empresaId <= 0) {
        return;
    }
    $list = $sedesPorEmpresa[$empresaId] ?? [];
    foreach ($list as $sede) {
        $sid = (int)$sede['id'];
        $sel = (!$sedeVacío && (int)$selectedSedeId === $sid) ? ' selected' : '';
        echo '<option value="' . $sid . '"' . $sel . '>' . htmlspecialchars($sede['nombre'] ?? '') . '</option>';
    }
};
?>

<form id="formUsuarioRoles" class="modal-user-roles-form">
    <div class="modal-form-head">
        <h3 class="modal-form-title">Roles por alcance</h3>
        <p class="modal-form-meta">
            Usuario: <strong><?= htmlspecialchars($usuario['nombre'] ?? $usuario['username'] ?? 'Usuario') ?></strong>
        </p>
        <p class="modal-form-help">
            Cada fila asigna un <strong>rol</strong> al contexto <strong>empresa</strong> y opcionalmente <strong>sede</strong>.
            <?php if (!empty($puedeRolGlobal)): ?>
                Alcance <em>Global</em> (empresa y sede vacíos) aplica en cualquier contexto de sesión.
            <?php else: ?>
                Debe elegir empresa en cada fila (solo administradores globales pueden dejar alcance global vacío).
                El rol <strong>Super Admin</strong> solo lo puede asignar un superadministrador.
            <?php endif; ?>
        </p>
    </div>

    <?php if (empty($empresasDisponibles) && empty($puedeRolGlobal)): ?>
        <p class="modal-form-alert">No hay empresas asociadas a este usuario. Asigne empresas en <strong>Empresa / sede</strong> o use un administrador global para alcance global.</p>
    <?php else: ?>

    <div class="crud-table">
        <table>
            <thead>
                <tr>
                    <th>Rol</th>
                    <th>Empresa</th>
                    <th>Sede</th>
                    <th class="crud-table-actions" aria-label="Acciones"></th>
                </tr>
            </thead>
            <tbody id="roleAssignBody">
                <?php foreach (array_values($assignments) as $i => $a): ?>
                <tr class="crud-row role-assign-row">
                    <td>
                        <select name="assignments[<?= (int)$i ?>][rol_id]" class="form-input ra-rol">
                            <option value="">— Rol —</option>
                            <?php foreach ($roles as $rol): ?>
                                <?php $rid = (int)$rol['id']; ?>
                                <option value="<?= $rid ?>" <?= isset($a['rol_id']) && (int)$a['rol_id'] === $rid ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($rol['nombre'] ?? '') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <select name="assignments[<?= (int)$i ?>][empresa_id]" class="form-input ra-empresa" data-ra-empresa>
                            <?php
                            $eSel = $a['empresa_id'] ?? null;
                            $eSelNull = ($eSel === null || $eSel === '');
                            ?>
                            <?php if (!empty($puedeRolGlobal)): ?>
                                <option value="" <?= $eSelNull ? 'selected' : '' ?>>Global (cualquier empresa/sede)</option>
                            <?php else: ?>
                                <option value="" disabled <?= $eSelNull ? 'selected' : '' ?>>— Elija empresa —</option>
                            <?php endif; ?>
                            <?php foreach ($empresasDisponibles as $emp):
                                $eid = (int)$emp['id'];
                                ?>
                                <option value="<?= $eid ?>" <?= (!$eSelNull && (int)$eSel === $eid) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($emp['razon_social'] ?? $emp['nombre'] ?? ('Empresa #' . $eid)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <select name="assignments[<?= (int)$i ?>][sede_id]" class="form-input ra-sede" data-ra-sede>
                            <?php $renderSedeOptions($eSelNull ? null : (int)$eSel, $sedesPorEmpresa, $a['sede_id'] ?? null); ?>
                        </select>
                    </td>
                    <td class="crud-table-actions">
                        <button type="button" class="btn-row-remove" title="Quitar fila" aria-label="Quitar fila">✕</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="modal-form-toolbar">
        <button type="button" id="btnAddRoleRow" class="btn-add-crud-row">+ Agregar fila</button>
    </div>

    <?php endif; ?>

    <div class="role-footer">
        <button type="button" class="btn-cancel" onclick="closeModalGod()">Cancelar</button>
        <button type="submit" class="btn-save">💾 Guardar</button>
    </div>
</form>

<script>
(function(){
const SEDES_POR_EMPRESA = <?= json_encode($sedesPorEmpresa ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>;
const PUEDE_GLOBAL = <?= !empty($puedeRolGlobal) ? 'true' : 'false' ?>;

function reindexRoleRows() {
    const rows = document.querySelectorAll('#roleAssignBody .role-assign-row');
    rows.forEach(function (tr, i) {
        const rol = tr.querySelector('.ra-rol');
        const emp = tr.querySelector('.ra-empresa');
        const sed = tr.querySelector('.ra-sede');
        if (rol) rol.name = 'assignments[' + i + '][rol_id]';
        if (emp) emp.name = 'assignments[' + i + '][empresa_id]';
        if (sed) sed.name = 'assignments[' + i + '][sede_id]';
    });
}

function fillSedeSelect(empresaSelect, sedeSelect) {
    const e = empresaSelect.value.trim();
    sedeSelect.innerHTML = '';
    const optAll = document.createElement('option');
    optAll.value = '';
    optAll.textContent = 'Toda la empresa (sin sede)';
    sedeSelect.appendChild(optAll);
    if (!e) return;
    const list = SEDES_POR_EMPRESA[e] || [];
    list.forEach(function (s) {
        const o = document.createElement('option');
        o.value = String(s.id);
        o.textContent = s.nombre || ('Sede #' + s.id);
        sedeSelect.appendChild(o);
    });
}

function wireRow(tr) {
    const emp = tr.querySelector('.ra-empresa');
    const sed = tr.querySelector('.ra-sede');
    if (emp && sed) {
        emp.addEventListener('change', function () {
            const prev = sed.value;
            fillSedeSelect(emp, sed);
            if ([...sed.options].some(function (o) { return o.value === prev; })) {
                sed.value = prev;
            }
        });
    }
    const rm = tr.querySelector('.btn-row-remove');
    if (rm) {
        rm.addEventListener('click', function () {
            const body = document.getElementById('roleAssignBody');
            if (!body) return;
            if (body.querySelectorAll('.role-assign-row').length <= 1) {
                tr.querySelectorAll('select').forEach(function (s) { s.selectedIndex = 0; });
                const s2 = tr.querySelector('.ra-sede');
                const e2 = tr.querySelector('.ra-empresa');
                if (e2 && s2) fillSedeSelect(e2, s2);
                reindexRoleRows();
                return;
            }
            tr.remove();
            reindexRoleRows();
        });
    }
}

document.querySelectorAll('#roleAssignBody .role-assign-row').forEach(wireRow);

const btnAdd = document.getElementById('btnAddRoleRow');
const tbody = document.getElementById('roleAssignBody');
if (btnAdd && tbody) {
    btnAdd.addEventListener('click', function () {
        const first = tbody.querySelector('.role-assign-row');
        if (!first) return;
        const tr = first.cloneNode(true);
        tr.querySelectorAll('select').forEach(function (s) {
            if (s.classList.contains('ra-rol')) s.selectedIndex = 0;
            else if (s.classList.contains('ra-empresa')) {
                s.selectedIndex = PUEDE_GLOBAL ? 0 : 0;
                if (!PUEDE_GLOBAL && s.options.length) s.selectedIndex = 0;
            }
        });
        const emp = tr.querySelector('.ra-empresa');
        const sed = tr.querySelector('.ra-sede');
        if (emp && sed) fillSedeSelect(emp, sed);
        tbody.appendChild(tr);
        wireRow(tr);
        reindexRoleRows();
    });
}

reindexRoleRows();

const form = document.getElementById('formUsuarioRoles');
if (!form) return;

form.addEventListener('submit', function (e) {
    e.preventDefault();
    reindexRoleRows();

    const btn = this.querySelector('.btn-save');
    const txt = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '💾 Guardando...';

    fetch('?url=usuario/saveRoles/<?= (int)$usuario['id'] ?>', {
        method: 'POST',
        body: new FormData(this)
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        btn.disabled = false;
        btn.innerHTML = txt;
        if (data.success) {
            alert('✅ Roles guardados correctamente');
            closeModalGod();
        } else {
            alert('❌ ' + (data.message || 'No se pudo guardar'));
        }
    })
    .catch(function () {
        btn.disabled = false;
        btn.innerHTML = txt;
        alert('❌ Error de conexión');
    });
});
})();
</script>
