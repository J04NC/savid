<script>
document.addEventListener("DOMContentLoaded", function () {
    const modal = document.querySelector(".modal-content");
    if (modal) modal.classList.add("modal-lg");
});
</script>

<?php
$sessionEmpresaId = isset($sessionEmpresaId) ? (int)$sessionEmpresaId : 0;
$outOfScopeEmpresaCount = isset($outOfScopeEmpresaCount) ? (int)$outOfScopeEmpresaCount : 0;
$outOfScopeSedeCount = isset($outOfScopeSedeCount) ? (int)$outOfScopeSedeCount : 0;
$targetHasSessionEmpresa = !empty($targetHasSessionEmpresa);
$esSuperAdmin = !empty($esSuperAdmin);
?>

<form id="formUsuarioEmpresaSede"
      style="padding:16px; max-height:75vh; overflow:auto;"
      data-session-empresa-id="<?= $sessionEmpresaId ?>"
      data-target-has-session-empresa="<?= $targetHasSessionEmpresa ? '1' : '0' ?>"
      data-es-super-admin="<?= $esSuperAdmin ? '1' : '0' ?>"
      data-usuario-id="<?= (int)$usuario['id'] ?>"
>
    <h3 style="margin:0 0 8px;">Empresas y sedes del usuario</h3>
    <p style="margin:0 0 16px; color:#666; font-size:14px;">
        Usuario: <strong><?= htmlspecialchars($usuario['nombre'] ?? $usuario['username'] ?? '') ?></strong>
    </p>

    <?php if (!$esSuperAdmin && ($outOfScopeEmpresaCount > 0 || $outOfScopeSedeCount > 0)): ?>
        <div style="margin:0 0 16px; padding:10px 12px; background:#fff8e1; border:1px solid #ffd54f; border-radius:8px; font-size:13px; color:#5d4037;">
            Este usuario también tiene
            <?php if ($outOfScopeEmpresaCount > 0): ?>
                <strong><?= $outOfScopeEmpresaCount ?></strong>
                empresa<?= $outOfScopeEmpresaCount === 1 ? '' : 's' ?>
            <?php endif; ?>
            <?php if ($outOfScopeEmpresaCount > 0 && $outOfScopeSedeCount > 0): ?> y <?php endif; ?>
            <?php if ($outOfScopeSedeCount > 0): ?>
                <strong><?= $outOfScopeSedeCount ?></strong>
                sede<?= $outOfScopeSedeCount === 1 ? '' : 's' ?>
            <?php endif; ?>
            fuera de su alcance que no se mostrarán ni se modificarán al guardar.
        </div>
    <?php endif; ?>

    <h4 style="margin:16px 0 8px; font-size:14px;">Empresas permitidas</h4>
    <?php if (empty($empresasDisponibles)): ?>
        <p style="color:#c00;">No tienes empresas asignadas para poder configurar a otros usuarios. Contacta al administrador.</p>
    <?php else: ?>
        <div style="display:grid; grid-template-columns:repeat(auto-fill,minmax(240px,1fr)); gap:8px; margin-bottom:8px;">
            <?php foreach ($empresasDisponibles as $emp): ?>
                <?php $eid = (int)$emp['id']; $isSession = ($eid === $sessionEmpresaId); ?>
                <label style="display:flex; align-items:center; gap:8px;<?= $isSession ? ' padding:4px 6px; background:#e8f5e9; border:1px solid #a5d6a7; border-radius:6px;' : '' ?>">
                    <input
                        type="checkbox"
                        name="empresas[]"
                        value="<?= $eid ?>"
                        class="chk-empresa"
                        data-empresa="<?= $eid ?>"
                        data-is-session="<?= $isSession ? '1' : '0' ?>"
                        <?= in_array($eid, $selectedEmpresaIds, true) ? 'checked' : '' ?>
                    >
                    <span><?= htmlspecialchars($emp['razon_social']) ?></span>
                    <?php if ($isSession): ?>
                        <span style="font-size:11px; padding:2px 6px; border-radius:10px; background:#2e7d32; color:#fff; margin-left:auto;">SESIÓN</span>
                    <?php endif; ?>
                </label>
            <?php endforeach; ?>
        </div>

        <p id="empresaSedeWarnings" style="margin:0 0 12px; font-size:12px; min-height:18px; color:#c00;"></p>

        <h4 style="margin:16px 0 8px; font-size:14px;">Sedes permitidas</h4>
        <p style="margin:0 0 12px; font-size:12px; color:#666;">
            Solo puedes marcar sedes de empresas seleccionadas arriba.
        </p>

        <?php foreach ($empresasDisponibles as $emp): ?>
            <?php
            $eid = (int)$emp['id'];
            $isSession = ($eid === $sessionEmpresaId);
            $sedesList = $sedesPorEmpresa[$eid] ?? [];
            ?>
            <div class="bloque-sedes" data-empresa="<?= $eid ?>"
                 style="margin-bottom:14px; padding:10px; border:1px solid <?= $isSession ? '#a5d6a7' : '#e5e5e5' ?>; border-radius:8px;<?= $isSession ? ' background:#f1f8e9;' : '' ?>">
                <strong style="font-size:13px;"><?= htmlspecialchars($emp['razon_social']) ?></strong>
                <?php if ($isSession): ?>
                    <span style="font-size:11px; padding:2px 6px; border-radius:10px; background:#2e7d32; color:#fff; margin-left:8px;">SESIÓN</span>
                <?php endif; ?>
                <?php if (empty($sedesList)): ?>
                    <p style="margin:8px 0 0; font-size:12px; color:#888;">Sin sedes activas.</p>
                <?php else: ?>
                    <div style="display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:6px; margin-top:8px;">
                        <?php foreach ($sedesList as $sede): ?>
                            <label style="display:flex; align-items:center; gap:8px; font-size:13px;">
                                <input
                                    type="checkbox"
                                    name="sedes[]"
                                    value="<?= (int)$sede['id'] ?>"
                                    data-empresa-sede="<?= $eid ?>"
                                    class="chk-sede"
                                    <?= in_array((int)$sede['id'], $selectedSedeIds, true) ? 'checked' : '' ?>
                                >
                                <?= htmlspecialchars($sede['nombre']) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <div class="role-footer" style="margin-top:20px;">
        <button type="button" class="btn-cancel" onclick="closeModalGod()">Cancelar</button>
        <button type="submit" class="btn-save">💾 Guardar</button>
    </div>
</form>

<script>
(function(){
const form = document.getElementById("formUsuarioEmpresaSede");
if (!form) return;

const sessionEmpresaId = parseInt(form.dataset.sessionEmpresaId || "0", 10) || 0;
const targetHasSessionEmpresa = form.dataset.targetHasSessionEmpresa === "1";
const esSuperAdmin = form.dataset.esSuperAdmin === "1";
const warnings = document.getElementById("empresaSedeWarnings");

function syncSedesHabilitadas() {
    const empresasOn = new Set();
    document.querySelectorAll(".chk-empresa:checked").forEach(ch => {
        empresasOn.add(ch.dataset.empresa);
    });
    document.querySelectorAll(".chk-sede").forEach(ch => {
        const emp = ch.dataset.empresaSede;
        const ok = empresasOn.has(emp);
        ch.disabled = !ok;
        if (!ok) ch.checked = false;
    });
    updateWarnings();
}

function isSessionEmpresaChecked() {
    if (sessionEmpresaId <= 0) return true;
    const el = form.querySelector('.chk-empresa[data-empresa="' + sessionEmpresaId + '"]');
    return !!(el && el.checked);
}

function isAnyEmpresaChecked() {
    return form.querySelectorAll(".chk-empresa:checked").length > 0;
}

function willLoseVisibility() {
    if (esSuperAdmin) return false;
    if (!targetHasSessionEmpresa) return false;
    return !isSessionEmpresaChecked();
}

function updateWarnings() {
    if (!warnings) return;
    const msgs = [];
    if (!isAnyEmpresaChecked()) {
        msgs.push("⚠ Sin empresas seleccionadas el usuario quedará sin acceso operativo.");
    }
    if (willLoseVisibility()) {
        msgs.push("⚠ Al guardar perderá visibilidad de este usuario en su sesión.");
    }
    warnings.innerHTML = msgs.join("<br>");
}

document.querySelectorAll(".chk-empresa").forEach(ch => {
    ch.addEventListener("change", syncSedesHabilitadas);
});
syncSedesHabilitadas();

form.addEventListener("submit", function(e) {
    e.preventDefault();

    if (willLoseVisibility()) {
        const ok = confirm(
            "Está a punto de quitar la empresa de sesión de este usuario.\n" +
            "Después de guardar ya no podrá verlo desde su sesión actual.\n\n" +
            "¿Desea continuar?"
        );
        if (!ok) return;
    } else if (!isAnyEmpresaChecked() && !esSuperAdmin) {
        const ok = confirm(
            "El usuario quedará sin ninguna empresa asignada (en el alcance que usted puede gestionar).\n" +
            "¿Desea continuar?"
        );
        if (!ok) return;
    }

    const btn = this.querySelector(".btn-save");
    const txt = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = "💾 Guardando...";

    fetch("?url=usuario/empresa_sede/<?= (int)$usuario['id'] ?>", {
        method: "POST",
        body: new FormData(this)
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = txt;
        if (data.success) {
            const lost = !!data.lost_visibility;
            alert((lost ? "ℹ " : "✅ ") + (data.message || "Empresas y sedes guardadas"));
            closeModalGod();
            if (lost) {
                if (typeof window.usuarioEmpresaSedeOnLostVisibility === "function") {
                    window.usuarioEmpresaSedeOnLostVisibility(parseInt(form.dataset.usuarioId, 10) || 0);
                } else {
                    window.location.reload();
                }
            } else {
                if (typeof window.usuarioEmpresaSedeOnSaved === "function") {
                    window.usuarioEmpresaSedeOnSaved(parseInt(form.dataset.usuarioId, 10) || 0);
                }
            }
        } else {
            alert("❌ " + (data.message || "No se pudo guardar"));
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.innerHTML = txt;
        alert("❌ Error de conexión");
    });
});
})();
</script>
