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

<form id="formUsuarioRoles" style="padding:16px;">
    <h3 style="margin:0 0 8px;">Roles por alcance</h3>
    <p style="margin:0 0 6px; color:#555; font-size:14px;">
        Usuario: <strong><?= htmlspecialchars($usuario['nombre'] ?? $usuario['username'] ?? 'Usuario') ?></strong>
    </p>
    <p style="margin:0 0 16px; color:#666; font-size:13px; line-height:1.45;">
        Cada fila asigna un <strong>rol</strong> al contexto <strong>empresa</strong> y opcionalmente <strong>sede</strong>.
        <?php if (!empty($puedeRolGlobal)): ?>
            Alcance <em>Global</em> (empresa y sede vacíos) aplica en cualquier contexto de sesión.
        <?php else: ?>
            Debe elegir empresa en cada fila (solo administradores globales pueden dejar alcance global vacío).
        <?php endif; ?>
    </p>

    <?php if (empty($empresasDisponibles)): ?>
        <p style="color:#b00;">No hay empresas disponibles para asignar roles con alcance. Revise permisos o use un administrador global.</p>
    <?php else: ?>

    <div style="overflow-x:auto; border:1px solid #e8e8e8; border-radius:8px;">
        <table class="role-assign-table" style="width:100%; border-collapse:collapse; font-size:14px;">
            <thead>
                <tr style="background:#f5f5f5; text-align:left;">
                    <th style="padding:10px 12px; min-width:200px;">Rol</th>
                    <th style="padding:10px 12px; min-width:200px;">Empresa</th>
                    <th style="padding:10px 12px; min-width:200px;">Sede</th>
                    <th style="padding:10px 12px; width:56px;"></th>
                </tr>
            </thead>
            <tbody id="roleAssignBody">
                <?php foreach ($assignments as $a): ?>
                <tr class="role-assign-row">
                    <td style="padding:8px 12px; vertical-align:middle;">
                        <select name="assignments[][rol_id]" class="form-input ra-rol" style="width:100%;">
                            <option value="">— Rol —</option>
                            <?php foreach ($roles as $rol): ?>
                                <?php $rid = (int)$rol['id']; ?>
                                <option value="<?= $rid ?>" <?= isset($a['rol_id']) && (int)$a['rol_id'] === $rid ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($rol['nombre'] ?? '') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td style="padding:8px 12px; vertical-align:middle;">
                        <select name="assignments[][empresa_id]" class="form-input ra-empresa" style="width:100%;" data-ra-empresa>
                            <?php
                            $eSel = $a['empresa_id'] ?? null;
                            $eSelNull = ($eSel === null || $eSel === '');
                            ?>
                            <?php if (!empty($puedeRolGlobal)): ?>
                                <option value="" <?= $eSelNull ? 'selected' : '' ?>>Global</option>
                            <?php else: ?>
                                <option value="" <?= $eSelNull ? 'selected' : '' ?>>— Empresa —</option>
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
                    <td style="padding:8px 12px; vertical-align:middle;">
                        <select name="assignments[][sede_id]" class="form-input ra-sede" style="width:100%;" data-ra-sede>
                            <?php $renderSedeOptions($eSelNull ? null : (int)$eSel, $sedesPorEmpresa, $a['sede_id'] ?? null); ?>
                        </select>
                    </td>
                    <td style="padding:8px 12px; vertical-align:middle; text-align:center;">
                        <button type="button" class="btn-remove-row" title="Quitar fila" style="border:none;background:transparent;cursor:pointer;font-size:18px;line-height:1;">✕</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div style="margin-top:12px; display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
        <button type="button" id="btnAddRoleRow" class="form-input" style="width:auto; cursor:pointer; padding:8px 14px;">
            + Agregar fila
        </button>
    </div>

    <?php endif; ?>

    <div class="role-footer" style="margin-top:20px;">
        <button type="button" class="btn-cancel" onclick="closeModalGod()">Cancelar</button>
        <button type="submit" class="btn-save">💾 Guardar</button>
    </div>
</form>

<script>
(function(){
const SEDES_POR_EMPRESA = <?= json_encode($sedesPorEmpresa ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>;
const PUEDE_GLOBAL = <?= !empty($puedeRolGlobal) ? 'true' : 'false' ?>;

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
    const rm = tr.querySelector('.btn-remove-row');
    if (rm) {
        rm.addEventListener('click', function () {
            const body = document.getElementById('roleAssignBody');
            if (!body) return;
            if (body.querySelectorAll('.role-assign-row').length <= 1) {
                tr.querySelectorAll('select').forEach(function (s) { s.selectedIndex = 0; });
                const s2 = tr.querySelector('.ra-sede');
                const e2 = tr.querySelector('.ra-empresa');
                if (e2 && s2) fillSedeSelect(e2, s2);
                return;
            }
            tr.remove();
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
    });
}

const form = document.getElementById('formUsuarioRoles');
if (!form) return;

form.addEventListener('submit', function (e) {
    e.preventDefault();

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
