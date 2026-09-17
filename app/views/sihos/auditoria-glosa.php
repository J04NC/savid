<?php
/** @var array $scope */
/** @var bool $configurado */
/** @var ?array $reporte */
/** @var string $fechaInicio */
/** @var string $fechaFin */
/** @var string $fechaCorte */
/** @var string $codigoAdministradora */
/** @var string $nombreAdministradora */
/** @var string $tipoUsuario */
/** @var array $tiposUsuario */
/** @var bool $soloEnCurso */
/** @var ?string $tokenDatos */
/** @var array{exportables: list<array<string, mixed>>, excluidas: list<array<string, mixed>>} $conclusionSaldoCero */
/** @var bool $puedeConcluirDirecto */

$assetSihosConcluirGlosa = BASE_PATH . '/public/js/sihos-concluir-glosa-directo.js';
$sihosConcluirGlosaJsV = is_readable($assetSihosConcluirGlosa) ? (int)filemtime($assetSihosConcluirGlosa) : time();
?>

<div class="module-container auditoria-page">

    <div class="crud-toolbar auditoria-toolbar">
        <div class="auditoria-toolbar-title">
            <h2 class="auditoria-title">SIHOS — Auditoría Glosa</h2>
            <p class="auditoria-subtitle">Cruza cada glosa (<code class="auditoria-code">AnotGlos</code>) contra la factura que referencia: cartera real (cuenta <code class="auditoria-code">14%</code>), cuenta de orden de glosa en trámite (<code class="auditoria-code">8333%</code>) y el valor que SIHOS ya trae nativo (<code class="auditoria-code">DetaFaCr.GlosCurs</code>). Reporte de solo lectura.</p>
        </div>
    </div>

    <?php $filterUrl = 'sihos/auditoriaGlosa'; require BASE_PATH . '/app/views/sgd/_empresa_filter.php'; ?>

    <?php
    /* _empresa_filter.php también usa $empresaId internamente; se recalcula
       aquí después del require para no quedarse con el valor pisado. */
    $empresaId = $scope['empresaId'] ?? null;
    ?>

    <?php if ($empresaId === null): ?>
        <p class="field-note sgd-page-empty">Seleccione la empresa en el filtro superior para ver este reporte.</p>
    <?php elseif (!$configurado): ?>
        <p class="modal-form-alert">⚠️ Esta empresa no tiene conexión a SIHOS configurada. Ve a <a href="?url=sihos&empresa_id=<?= (int)$empresaId ?>">Conexión SIHOS</a>.</p>
    <?php else: ?>

        <form method="get" id="sihosAuditoriaGlosaForm" class="crud-form auditoria-filters-grid" style="margin-bottom:20px;">
            <input type="hidden" name="url" value="sihos/auditoriaGlosa">
            <input type="hidden" name="empresa_id" value="<?= (int)$empresaId ?>">
            <div class="form-group">
                <label for="sihosGlosaFechaInicio">Desde</label>
                <input type="date" id="sihosGlosaFechaInicio" name="fecha_inicio" class="form-input" value="<?= htmlspecialchars($fechaInicio) ?>">
                <span class="field-note">Opcional. Vacío = todo el historial hasta "Hasta" — puede tardar varios minutos en instalaciones con muchas glosas.</span>
            </div>
            <div class="form-group">
                <label for="sihosGlosaFechaFin">Hasta</label>
                <input type="date" id="sihosGlosaFechaFin" name="fecha_fin" class="form-input" value="<?= htmlspecialchars($fechaFin) ?>" required>
            </div>
            <div class="form-group">
                <label for="sihosGlosaFechaCorte">Fecha de corte</label>
                <input type="date" id="sihosGlosaFechaCorte" name="fecha_corte" class="form-input" value="<?= htmlspecialchars($fechaCorte) ?>" min="<?= htmlspecialchars($fechaFin) ?>">
                <span class="field-note">Para calcular antigüedad. No puede ser anterior a "Hasta".</span>
            </div>
            <div class="form-group">
                <label for="sihosGlosaAdministradora_search">Administradora (EPS)</label>
                <div class="crud-catalog-wrap" id="sihosGlosaAdministradoraWrap">
                    <input type="hidden" name="administradora" id="sihosGlosaAdministradora" class="form-input crud-catalog-id" value="<?= htmlspecialchars($codigoAdministradora) ?>">
                    <input type="hidden" name="administradora_nombre" id="sihosGlosaAdministradoraNombre" value="<?= htmlspecialchars($nombreAdministradora) ?>">
                    <input type="search" id="sihosGlosaAdministradora_search" class="form-input crud-catalog-search" placeholder="Número de documento, código o nombre" value="<?= htmlspecialchars($nombreAdministradora) ?>" autocomplete="off">
                    <ul class="crud-catalog-dropdown" hidden></ul>
                </div>
                <span class="field-note">Busca en el catálogo de administradoras (CodiAdmi) de SIHOS.</span>
            </div>
            <div class="form-group">
                <label for="sihosGlosaTipoUsuario">Tipo de usuario</label>
                <select id="sihosGlosaTipoUsuario" name="tipo_usuario" class="form-input">
                    <option value="">— Todos —</option>
                    <?php foreach ($tiposUsuario as $codigo => $nombre): ?>
                        <option value="<?= htmlspecialchars((string)$codigo) ?>" <?= (string)$tipoUsuario === (string)$codigo ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string)$nombre) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="sihosGlosaEnCurso">
                    <input type="hidden" name="en_curso" value="0">
                    <input type="checkbox" id="sihosGlosaEnCurso" name="en_curso" value="1" <?= $soloEnCurso ? 'checked' : '' ?>>
                    Glosas en curso
                </label>
                <span class="field-note">Oculta las que ya están resueltas (En curso = 0).</span>
            </div>
            <div class="form-group" style="align-self:flex-end;">
                <button type="submit" class="auditoria-btn-primary">🔍 Ejecutar auditoría</button>
            </div>
        </form>

        <?php if ($reporte === null): ?>
            <p class="auditoria-subtitle">Elija al menos la fecha "Hasta" para ejecutar la auditoría.</p>
        <?php elseif (!$reporte['ok']): ?>
            <p class="modal-form-alert">⚠️ <?= htmlspecialchars($reporte['error']) ?></p>
        <?php else: ?>

            <?php $filas = $reporte['filas']; $resumen = $reporte['resumenAlertas']; ?>

            <div class="crud-toolbar auditoria-toolbar" style="margin-top:8px; gap:16px; flex-wrap:wrap;">
                <div class="auditoria-toolbar-title">
                    <h3 class="auditoria-title" style="font-size:16px; <?= $resumen['saldoCero'] > 0 ? 'color:#c0392b;' : '' ?>">
                        ⚠️ Glosas en curso con factura saldada (<?= (int)$resumen['saldoCero'] ?>)
                    </h3>
                    <p class="auditoria-subtitle">La cartera de la factura (cuenta 14%) ya está en $0, pero la glosa sigue "en curso" (por cálculo, por cuenta de orden 8333 o por el valor nativo de SIHOS). Este reporte sigue sin escribir en SIHOS — pero podés generar aquí el CSV para concluirlas por "Importar Glosas → Detalle" en SIHOS.</p>
                </div>
            </div>

            <?php if ($tokenDatos !== null && ($conclusionSaldoCero['exportables'] !== [] || $conclusionSaldoCero['excluidas'] !== [])): ?>
            <?php $urlConcluir = '?url=sihos/auditoriaGlosaExportarConcluirCsv'; ?>
            <form method="post" action="<?= htmlspecialchars($urlConcluir) ?>" id="sihosConcluirGlosaForm" style="margin-bottom:16px;">
                <input type="hidden" name="empresa_id" value="<?= (int)$empresaId ?>">
                <input type="hidden" name="token" value="<?= htmlspecialchars($tokenDatos) ?>">
                <input type="hidden" name="fecha_corte" value="<?= htmlspecialchars($fechaCorte !== '' ? $fechaCorte : $fechaFin) ?>">

                <?php if ($conclusionSaldoCero['exportables'] !== []): ?>
                <div style="overflow-x:auto;">
                    <table class="seguridad-table">
                        <thead>
                            <tr>
                                <th class="no-dt-order"><input type="checkbox" id="sihosConcluirGlosaSelectAll" aria-label="Seleccionar todo lo visible"></th>
                                <th>Factura</th>
                                <th>Glosa</th>
                                <th>Administradora</th>
                                <th style="text-align:right;">En curso (a asumir por EAPB)</th>
                                <?php if ($puedeConcluirDirecto): ?>
                                <th>Concluir directo en SIHOS</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($conclusionSaldoCero['exportables'] as $fila): ?>
                                <tr>
                                    <td><input type="checkbox" class="sihosConcluirGlosaCheckbox" name="claves[]" value="<?= htmlspecialchars($fila['clave']) ?>"></td>
                                    <td><?= htmlspecialchars($fila['Refe']) ?></td>
                                    <td><?= htmlspecialchars($fila['CodiDocu'] . '-' . $fila['NumeDocu']) ?></td>
                                    <td><?= htmlspecialchars((string)($fila['NombAdmi'] ?? $fila['CodiAdmi'] ?? '')) ?></td>
                                    <td style="text-align:right;"><?= SihosAuditoriaGlosaService::formatoMoneda($fila['EnCurso']) ?></td>
                                    <?php if ($puedeConcluirDirecto): ?>
                                    <td>
                                        <button type="button" class="sihosConcluirGlosaDirectoBtn"
                                                data-empresa-id="<?= (int)$empresaId ?>"
                                                data-codi-docu="<?= htmlspecialchars($fila['CodiDocu']) ?>"
                                                data-nume-docu="<?= htmlspecialchars((string)$fila['NumeDocu']) ?>"
                                                data-fecha-corte="<?= htmlspecialchars($fechaCorte !== '' ? $fechaCorte : $fechaFin) ?>"
                                                data-refe="<?= htmlspecialchars($fila['Refe']) ?>"
                                                data-en-curso="<?= htmlspecialchars(SihosAuditoriaGlosaService::formatoMoneda($fila['EnCurso'])) ?>">
                                            ✅ Concluir en SIHOS
                                        </button>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="auditoria-filters-footer" style="margin-top:10px; gap:10px; display:flex; flex-wrap:wrap;">
                    <button type="submit" id="btnSihosConcluirGlosaExportar" class="auditoria-btn-primary" disabled>📥 Generar CSV para concluir seleccionadas (Aceptado EAPB)</button>
                    <button type="submit" id="btnSihosConcluirGlosaExportarTodas" name="todas" value="1" class="auditoria-btn-primary" style="background:#555;">📥 Generar CSV con las <?= count($conclusionSaldoCero['exportables']) ?> exportables (todas, sin seleccionar)</button>
                </div>
                <p class="field-note">Genera un archivo de 7 columnas para SIHOS → Procesos → Glosas → Importar Glosas. <strong>En esa pantalla de SIHOS, cambiá el combo "Tipo" a "Detalle"</strong> (por defecto trae seleccionado "Encabezado + Detalle", que exige 15 columnas y rechaza este archivo con "el numero de columnas es incorrecto") y dejá "Delimitador" en "Punto y Coma (;)" (ya viene así por defecto, y es el que usa este archivo). Cargalo pronto después de generarlo: si el saldo de la glosa cambia en SIHOS entre que se generó el reporte y se carga el archivo, SIHOS puede rechazar la fila. La tabla puede paginar — "seleccionar todo" solo marca lo visible en pantalla; usá el segundo botón para incluir las <?= count($conclusionSaldoCero['exportables']) ?> sin tener que recorrer cada página.</p>
                <?php if ($puedeConcluirDirecto): ?>
                <p class="field-note">La columna "Concluir directo en SIHOS" escribe la anotación de aceptación EPS y revierte la cuenta de orden en trámite (8333/8915) directamente en SIHOS, sin generar ni cargar ningún CSV — una glosa a la vez, con confirmación explícita. No toca cartera ni la cuenta de ingreso real (esas solo se tocan con aceptación IPS). Solo alcanza glosas con un único concepto y contabilización simple; si la glosa no cumple esas condiciones, SIHOS/SAVID lo rechaza al confirmar sin escribir nada.</p>
                <?php endif; ?>
                <?php endif; ?>

                <?php if ($conclusionSaldoCero['excluidas'] !== []): ?>
                <p class="modal-form-alert" style="margin-top:12px;">⚠️ <?= count($conclusionSaldoCero['excluidas']) ?> glosa(s) no se pueden incluir en el CSV: la factura tiene más de una glosa en tránsito (SIHOS rechazaría la carga). Requieren revisión manual en SIHOS.</p>
                <div style="overflow-x:auto;">
                    <table class="seguridad-table">
                        <thead>
                            <tr>
                                <th>Factura</th>
                                <th>Glosa</th>
                                <th>Administradora</th>
                                <th style="text-align:right;">En curso</th>
                                <th>Motivo</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($conclusionSaldoCero['excluidas'] as $fila): ?>
                                <tr>
                                    <td><?= htmlspecialchars($fila['Refe']) ?></td>
                                    <td><?= htmlspecialchars($fila['CodiDocu'] . '-' . $fila['NumeDocu']) ?></td>
                                    <td><?= htmlspecialchars((string)($fila['NombAdmi'] ?? $fila['CodiAdmi'] ?? '')) ?></td>
                                    <td style="text-align:right;"><?= SihosAuditoriaGlosaService::formatoMoneda($fila['EnCurso']) ?></td>
                                    <td><?= htmlspecialchars($fila['motivoExclusion']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </form>
            <?php endif; ?>

            <div class="crud-toolbar auditoria-toolbar" style="margin-top:8px; margin-bottom:16px; gap:16px; flex-wrap:wrap;">
                <div class="auditoria-toolbar-title">
                    <h3 class="auditoria-title" style="font-size:16px; <?= $resumen['divergencia'] > 0 ? 'color:#c0392b;' : '' ?>">
                        ⚠️ Divergencia motor vs. SIHOS (<?= (int)$resumen['divergencia'] ?>)
                    </h3>
                    <p class="auditoria-subtitle">El "en curso" recalculado a partir de las anotaciones (<code class="auditoria-code">AnotGlos</code>) no coincide con el valor que SIHOS ya trae nativo (<code class="auditoria-code">DetaFaCr.GlosCurs</code>).</p>
                </div>
            </div>

            <p class="field-note">
                Total de glosas<?= $soloEnCurso ? ' en curso' : '' ?> en el rango: <strong><?= count($filas) ?></strong>
                · Corte de antigüedad: <strong><?= htmlspecialchars($fechaCorte !== '' ? $fechaCorte : $fechaFin) ?></strong>
            </p>

            <?php if ($filas === [] || $tokenDatos === null): ?>
                <p class="field-note">No hay glosas para los filtros seleccionados.</p>
            <?php else: ?>
            <?php
            $urlExportar = '?url=sihos/auditoriaGlosaExportar&empresa_id=' . (int)$empresaId . '&token=' . rawurlencode($tokenDatos);
            $urlDatos = '?url=sihos/auditoriaGlosaDatos&empresa_id=' . (int)$empresaId . '&token=' . rawurlencode($tokenDatos);
            ?>
            <p class="field-note">
                <a href="<?= htmlspecialchars($urlExportar) ?>" class="auditoria-btn-primary" style="text-decoration:none; display:inline-block;">📥 Exportar CSV (las <?= count($filas) ?> filas)</a>
                — los botones Excel/CSV de la tabla de abajo solo exportan la página visible.
            </p>
            <table class="seguridad-table"
                       id="sihosAuditoriaGlosaTabla"
                       data-dt-server="1"
                       data-dt-server-url="<?= htmlspecialchars($urlDatos) ?>"
                       data-dt-column-search="1"
                       data-dt-scroll-wrap-class="sihos-auditoria-glosa-scroll"
                       data-dt-order="[[3,&quot;desc&quot;]]">
                    <thead>
                        <tr>
                            <th>Tipo usuario</th>
                            <th>Tercero</th>
                            <th>Administradora</th>
                            <th>Fecha glosa</th>
                            <th>Glosa</th>
                            <th>Factura</th>
                            <th style="text-align:right;">Saldo cartera</th>
                            <th style="text-align:right;">Valor glosa</th>
                            <th style="text-align:right;">En curso (calc.)</th>
                            <th style="text-align:right;">Días</th>
                            <th style="text-align:right;">Cuenta 8333</th>
                            <th style="text-align:right;">Cuenta 8333 NIIF</th>
                            <th style="text-align:right;">En curso (SIHOS)</th>
                            <th style="text-align:right;">Acep. IPS</th>
                            <th style="text-align:right;">Acep. EPS</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
            </table>
            <p class="field-note">⚠️ = glosa en curso con factura saldada o divergencia motor vs. SIHOS (pasa el cursor sobre el ícono para ver cuál).</p>
            <?php endif; ?>

        <?php endif; ?>

    <?php endif; ?>

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var fechaFin = document.getElementById('sihosGlosaFechaFin');
    var fechaCorte = document.getElementById('sihosGlosaFechaCorte');
    if (!fechaFin || !fechaCorte) {
        return;
    }

    fechaFin.addEventListener('change', function () {
        fechaCorte.min = fechaFin.value;
        if (fechaFin.value !== '' && (fechaCorte.value === '' || fechaCorte.value < fechaFin.value)) {
            fechaCorte.value = fechaFin.value;
        }
    });

    // Sin "Desde" el universo puede ser de decenas de miles de glosas y
    // tardar varios minutos (ver SihosAuditoriaGlosaService) — se avisa
    // antes de dejar navegar, igual que sihos/cruce.
    var form = document.getElementById('sihosAuditoriaGlosaForm');
    var fechaInicio = document.getElementById('sihosGlosaFechaInicio');
    if (form && typeof savidMostrarCargando === 'function') {
        form.addEventListener('submit', function () {
            var mensaje = (fechaInicio && fechaInicio.value === '')
                ? 'Generando auditoría (sin "Desde" puede tardar varios minutos)…'
                : 'Generando auditoría…';
            savidMostrarCargando(mensaje, true);
        });
    }

    // Autocompletado de administradora (EPS) contra CodiAdmi — propio de
    // esta vista, no el genérico de crud.js (ese exige
    // form[data-crud-context], que aquí no aplica: no es el motor CRUD).
    var admiWrap = document.getElementById('sihosGlosaAdministradoraWrap');
    if (admiWrap) {
        var admiHidden = document.getElementById('sihosGlosaAdministradora');
        var admiNombre = document.getElementById('sihosGlosaAdministradoraNombre');
        var admiSearch = document.getElementById('sihosGlosaAdministradora_search');
        var admiDropdown = admiWrap.querySelector('.crud-catalog-dropdown');
        var admiDebounce = null;

        function admiCerrarDropdown() {
            admiDropdown.hidden = true;
            admiDropdown.innerHTML = '';
        }

        function admiBuscar(termino) {
            var u = new URL(window.location.href);
            u.search = '';
            u.searchParams.set('url', 'sihos/auditoriaGlosaBuscarAdministradora');
            u.searchParams.set('empresa_id', '<?= (int)$empresaId ?>');
            u.searchParams.set('q', termino);

            fetch(u.toString(), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    admiDropdown.innerHTML = '';
                    var resultados = (data && data.ok && Array.isArray(data.resultados)) ? data.resultados : [];
                    if (!resultados.length) {
                        var vacio = document.createElement('li');
                        vacio.className = 'crud-catalog-hint';
                        vacio.textContent = 'Sin resultados';
                        admiDropdown.appendChild(vacio);
                        admiDropdown.hidden = false;
                        return;
                    }
                    resultados.forEach(function (r) {
                        var li = document.createElement('li');
                        var nombre = r.nombre || '(sin nombre)';
                        li.textContent = nombre + ' — ' + r.codigo + (r.nit ? ' — NIT ' + r.nit : '');
                        li.setAttribute('data-codigo', r.codigo);
                        li.setAttribute('data-nombre', nombre);
                        li.addEventListener('click', function () {
                            admiHidden.value = r.codigo;
                            admiNombre.value = nombre;
                            admiSearch.value = nombre;
                            admiCerrarDropdown();
                        });
                        admiDropdown.appendChild(li);
                    });
                    admiDropdown.hidden = false;
                })
                .catch(function () {
                    admiCerrarDropdown();
                });
        }

        admiSearch.addEventListener('input', function () {
            // Texto editado a mano: invalida la selección previa hasta que
            // el usuario elija de nuevo en la lista.
            admiHidden.value = '';
            admiNombre.value = '';

            var termino = admiSearch.value.trim();
            clearTimeout(admiDebounce);
            if (termino.length < 2) {
                admiCerrarDropdown();
                return;
            }
            admiDebounce = setTimeout(function () { admiBuscar(termino); }, 250);
        });

        admiSearch.addEventListener('focus', function () {
            if (admiSearch.value.trim().length >= 2 && admiHidden.value === '') {
                admiBuscar(admiSearch.value.trim());
            }
        });

        document.addEventListener('click', function (ev) {
            if (!admiWrap.contains(ev.target)) {
                admiCerrarDropdown();
            }
        });
    }

    // Selección para "Generar CSV para concluir seleccionadas" — mismo
    // patrón de "seleccionar todo lo visible" + botón deshabilitado hasta
    // que haya al menos una fila marcada que ya usa sihos/cruce.
    var concluirCheckboxes = Array.prototype.slice.call(document.querySelectorAll('.sihosConcluirGlosaCheckbox'));
    var concluirSelectAll = document.getElementById('sihosConcluirGlosaSelectAll');
    var concluirBoton = document.getElementById('btnSihosConcluirGlosaExportar');
    if (concluirCheckboxes.length && concluirBoton) {
        function actualizarBotonConcluir() {
            var marcados = concluirCheckboxes.some(function (cb) { return cb.checked; });
            concluirBoton.disabled = !marcados;
        }
        concluirCheckboxes.forEach(function (cb) {
            cb.addEventListener('change', function () {
                if (!cb.checked && concluirSelectAll) {
                    concluirSelectAll.checked = false;
                }
                actualizarBotonConcluir();
            });
        });
        if (concluirSelectAll) {
            concluirSelectAll.addEventListener('change', function () {
                concluirCheckboxes.forEach(function (cb) { cb.checked = concluirSelectAll.checked; });
                actualizarBotonConcluir();
            });
        }
    }

    // "Generar CSV con todas": desmarca cualquier selección antes de
    // enviar para que el formulario no mande ningún claves[] — el backend
    // interpreta la ausencia como "todas las exportables" (evita tener que
    // paginar y marcar página por página en la tabla de arriba).
    var concluirBotonTodas = document.getElementById('btnSihosConcluirGlosaExportarTodas');
    if (concluirBotonTodas) {
        concluirBotonTodas.addEventListener('click', function () {
            concluirCheckboxes.forEach(function (cb) { cb.checked = false; });
        });
    }
});
</script>

<?php if ($puedeConcluirDirecto): ?>
<div id="sihosConcluirGlosaDirectoModal" class="modal hidden">
    <div class="modal-content">
        <div class="modal-header-bar">
            <span>⚠️ Concluir glosa en SIHOS</span>
            <span class="close-modal" id="sihosConcluirGlosaDirectoCerrar" role="button" tabindex="0" aria-label="Cerrar">&times;</span>
        </div>
        <div style="padding:16px;">
            <p class="modal-form-alert">
                Esta acción escribe en SIHOS una <strong>anotación de aceptación EPS</strong> y crea un documento
                <strong>GLC permanente</strong> que revierte la cuenta de orden en trámite de la glosa
                <strong id="sihosConcluirGlosaDirectoDocumento"></strong> (factura <strong id="sihosConcluirGlosaDirectoRefe"></strong>,
                <strong id="sihosConcluirGlosaDirectoValor"></strong> en curso). No toca cartera ni cuenta de ingreso real.
                Es <strong>irreversible</strong> desde SAVID.
            </p>
            <div class="form-group">
                <label for="sihosConcluirGlosaDirectoConfirmacion">Escriba <strong id="sihosConcluirGlosaDirectoDocumentoLabel"></strong> para confirmar</label>
                <input type="text" id="sihosConcluirGlosaDirectoConfirmacion" class="form-input" autocomplete="off">
            </div>
            <div class="auditoria-filters-footer">
                <button type="button" id="sihosConcluirGlosaDirectoConfirmar" class="auditoria-btn-primary" style="background:#c0392b;" disabled>✅ Concluir en SIHOS</button>
            </div>
            <p id="sihosConcluirGlosaDirectoStatus" class="usuario-perm-save-status" aria-live="polite"></p>
        </div>
    </div>
</div>
<script src="/js/sihos-concluir-glosa-directo.js?v=<?= (int)$sihosConcluirGlosaJsV ?>"></script>
<?php endif; ?>
