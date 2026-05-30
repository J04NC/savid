<?php
// =========================
// HELPERS UI PRO
// =========================

function formatLabel($field){
    $field = preg_replace('/_id$/', '', $field);
    $field = str_replace('_',' ', $field);
    return ucfirst($field);
}

function getConfigFromComment($comment){

    $config = [
        'type' => 'text',
        'subtype' => '',
        'rules' => '',
        'showForm' => true,
        'showTable' => true,
        'order' => 999,
        'placeholder' => '',
        'relmode' => 'select',
        'uppercase' => false,
        'title' => '',
        'label' => '',
        'span' => '',
    ];

    if(empty($comment)) return $config;

    $parts = explode('|', $comment);

    foreach($parts as $part){

        $part = trim($part);

        if(str_starts_with(strtolower($part), 'relmode:')){
            $rm = strtolower(trim(substr($part, strlen('relmode:'))));
            if(in_array($rm, ['select', 'autocomplete', 'auto'], true)){
                $config['relmode'] = $rm;
            }
        }

        if(str_starts_with($part,'type:')){
            $config['type'] = str_replace('type:','',$part);
        }

        if(str_starts_with(strtolower($part), 'subtype:')){
            $config['subtype'] = trim(substr($part, strlen('subtype:')));
        }

        if(str_starts_with(strtolower($part), 'title:')){
            $config['title'] = trim(substr($part, strlen('title:')));
        }

        if(str_starts_with(strtolower($part), 'label:')){
            $config['label'] = trim(substr($part, strlen('label:')));
        }

        if(str_starts_with(strtolower($part), 'span:')){
            $config['span'] = trim(substr($part, strlen('span:')));
        }

        if(
            $part == 'required' ||
            str_starts_with($part,'min:') ||
            str_starts_with($part,'max:')
        ){
            $config['rules'] .= ($config['rules'] ? '|' : '') . $part;
        }

        if(str_starts_with($part,'show:')){
            $show = str_replace('show:','',$part);
            $arr = array_map('trim', explode(',', $show));

            if(in_array('none',$arr)){
                $config['showForm'] = false;
                $config['showTable'] = false;
            }else{
                if(in_array('form',$arr)){
                    $config['showForm'] = true;
                }
                if(in_array('table',$arr)){
                    $config['showTable'] = true;
                }
            }
        }

        if(str_starts_with($part,'order:')){
            $config['order'] = (int) str_replace('order:','',$part);
        }

        if(str_starts_with($part,'placeholder:')){
            $config['placeholder'] = str_replace('placeholder:','',$part);
        }

        if ($part === 'uppercase') {
            $config['uppercase'] = true;
        }
    }

    return $config;
}

// =========================
// ORDENAR COLUMNAS
// =========================

usort($columns, function($a,$b){
    $ca = getConfigFromComment($a['COLUMN_COMMENT'] ?? '');
    $cb = getConfigFromComment($b['COLUMN_COMMENT'] ?? '');
    return $ca['order'] <=> $cb['order'];
});

$catalogRegistry = $catalogRegistry ?? [];
$crudContextTable = $crudContextTable ?? '';
$tipodocumentoMetaById = $tipodocumentoMetaById ?? [];
$usuarioTableColumnFields = $usuarioTableColumnFields ?? [];

$crudZonaFieldNames = array_column($columns, 'Field');
$crudUrbanoRuralFields = ['comuna_id', 'barrio_id', 'corregimiento_id', 'vereda_id'];
$crudHasUrbanoRural = count(array_intersect($crudUrbanoRuralFields, $crudZonaFieldNames)) > 0;
$crudZonaUbicacionToggle = in_array($crudContextTable, ['tercero', 'empresa'], true)
    && in_array('zona_id', $crudZonaFieldNames, true)
    && $crudHasUrbanoRural;

$zonaTipoById = [];
foreach ($relationData['zona_id'] ?? [] as $zopt) {
    if (!isset($zopt['id'], $zopt['tipo']) || $zopt['tipo'] === '' || $zopt['tipo'] === null) {
        continue;
    }
    $zonaTipoById[(string) $zopt['id']] = strtolower((string) $zopt['tipo']);
}

$relationMapsPreview = [];

foreach ($relationData as $campoRel => $options) {
    foreach ($options as $opt) {
        $relationMapsPreview[$campoRel][$opt['id']] = $opt['nombre'];
    }
}

?>

<div class="module-container">

<form method="POST" data-crud-context="<?= htmlspecialchars($crudContextTable, ENT_QUOTES, 'UTF-8') ?>"<?= !empty($crudZonaUbicacionToggle) ? ' data-crud-zona-ubicacion-toggle="1"' : '' ?>>

<input type="hidden" name="id" id="crud_id" value="<?= $old['id'] ?? '' ?>">
<?php if ($crudContextTable === 'usuario'): ?>
<input type="hidden" name="usuario_email_overwrite_ok" id="usuario_email_overwrite_ok" value="0">
<input type="hidden" name="usuario_identificacion_accion" id="usuario_identificacion_accion" value="update_principal">
<input type="hidden" name="tercero_id" id="usuario_tercero_id" value="<?= htmlspecialchars((string)($old['tercero_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
<input type="hidden" name="terceroidentificacion_id" id="usuario_terceroidentificacion_id" value="<?= htmlspecialchars((string)($old['terceroidentificacion_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
<?php endif; ?>
<?php if ($crudContextTable === 'empresa'): ?>
<input type="hidden" name="tercero_id" id="empresa_tercero_id" value="<?= htmlspecialchars((string)($old['tercero_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
<input type="hidden" name="terceroidentificacion_id" id="empresa_terceroidentificacion_id" value="<?= htmlspecialchars((string)($old['terceroidentificacion_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
<input type="hidden" name="representante_terceroidentificacion_id" id="empresa_rep_terceroidentificacion_id" value="<?= htmlspecialchars((string)($old['representante_terceroidentificacion_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
<?php endif; ?>

<div class="crud-toolbar crud-auto-toolbar">

<div class="crud-toolbar-actions sgd-doc-toolbar-actions">

<?php
$canNuevo = false;
$canGuardar = false;
$canEliminar = false;

foreach($acciones as $acc){
    if(PermisoService::canByItemAccion($acc['item_accion_id'])){
        if($acc['codigo']=='limpiar') $canNuevo = true;
        if($acc['codigo']=='guardar') $canGuardar = true;
        if($acc['codigo']=='eliminar') $canEliminar = true;
    }
}

if ($crudContextTable === 'empresa') {
    $crudEsSuperAdmin = !empty($_SESSION['es_super_admin'])
        || (int)($_SESSION['rol_id'] ?? 0) === 1;
    if (!$crudEsSuperAdmin) {
        $canNuevo = false;
        $canEliminar = false;
    }
}

if ($crudContextTable === 'item') {
    $crudEsSuperAdmin = class_exists('PermisoService')
        ? PermisoService::isSuperAdminSession()
        : (!empty($_SESSION['es_super_admin']) || (int)($_SESSION['rol_id'] ?? 0) === 1);
    if (!$crudEsSuperAdmin) {
        $canNuevo = false;
        $canGuardar = false;
        $canEliminar = false;
    }
}
?>

<?php if($canNuevo): ?>
<button type="button" id="btnNuevo" class="sgd-doc-btn" title="Limpiar formulario">➕ <span class="crud-toolbar-btn-text">Limpiar</span></button>
<?php endif; ?>

<?php if($canGuardar): ?>
<button type="submit" class="sgd-doc-btn sgd-doc-btn-primary" title="Guardar">💾 <span class="crud-toolbar-btn-text">Guardar</span></button>
<?php endif; ?>

<?php if($canEliminar): ?>
<button type="button" class="btn-delete sgd-doc-btn sgd-doc-btn-danger" title="Eliminar">🗑 <span class="crud-toolbar-btn-text">Eliminar</span></button>
<?php endif; ?>

<?php foreach($acciones as $accion): ?>

<?php if(
    !in_array($accion['codigo'],['ver','limpiar','guardar','eliminar']) &&
    PermisoService::canByItemAccion($accion['item_accion_id'])
): ?>
<?php
$accionNombre = trim((string)($accion['nombre'] ?? ''));
if ($accionNombre === '') {
    $accionNombre = ucfirst((string)($accion['codigo'] ?? 'Acción'));
}
$accionIcono = trim((string)($accion['icono'] ?? ''));
if ($accionIcono === '') {
    $accionIcono = '⚙️';
}
?>

<button type="button"
class="btn-accion sgd-doc-btn"
data-accion="<?= htmlspecialchars((string)$accion['accion_codigo'], ENT_QUOTES, 'UTF-8') ?>"
title="<?= htmlspecialchars($accionNombre, ENT_QUOTES, 'UTF-8') ?>">
<?= $accionIcono ?> <span class="crud-toolbar-btn-text"><?= htmlspecialchars($accionNombre, ENT_QUOTES, 'UTF-8') ?></span>
</button>

<?php endif; ?>

<?php endforeach; ?>

</div>

</div>

<div class="crud-form<?= $crudContextTable === 'usuario' ? ' crud-form-usuario' : '' ?><?= $crudContextTable === 'empresa' ? ' crud-form-empresa' : '' ?>">

<?php
$crudUsuarioLayoutOpen = false;
$crudUsuarioFieldsOpen = false;
$crudUsuarioAsideOpen = false;
$crudEmpresaLayoutOpen = false;
$crudEmpresaFieldsOpen = false;
$crudEmpresaAsideOpen = false;
$crudEmpresaUbicacionOpen = false;
$crudEmpresaRepOpen = false;
$crudEmpresaContactoPairOpen = false;
?>

<?php foreach($columns as $col): ?>

<?php
$campo = $col['Field'];
if($campo == 'id') continue;

$config = getConfigFromComment($col['COLUMN_COMMENT'] ?? '');

$fieldLabel = ($config['label'] ?? '') !== '' ? (string)$config['label'] : formatLabel($campo);

if(!$config['showForm']) continue;

$value = $old[$campo] ?? '';
$error = $errors[$campo] ?? null;
$required = ($col['IS_NULLABLE'] == 'NO') ? 'required' : '';
$requiredAttr = ($config['type'] === 'password') ? '' : $required;
$uppercaseDataAttr = (!empty($config['uppercase']) && $config['type'] !== 'password')
    ? ' data-crud-uppercase="1"'
    : '';

$formGroupExtra = '';
$formGroupStyle = '';
if (!empty($crudZonaUbicacionToggle)) {
    if (in_array($campo, ['comuna_id', 'barrio_id'], true)) {
        $formGroupExtra = ' crud-zona-urban';
    } elseif (in_array($campo, ['corregimiento_id', 'vereda_id'], true)) {
        $formGroupExtra = ' crud-zona-rural';
    }
}
if ($campo === 'documento_dv') {
    if ($crudContextTable === 'usuario') {
        $formGroupExtra .= ' crud-usuario-dv-wrap';
        $formGroupStyle = ' style="display:none;"';
    } elseif ($crudContextTable === 'empresa') {
        $formGroupExtra .= ' crud-empresa-dv-wrap';
    }
}
if ($crudContextTable === 'usuario' && in_array($campo, ['tipodocumento_id', 'numero_documento', 'documento_dv'], true)) {
    $formGroupExtra .= ' crud-usuario-doc-field';
}
if ($crudContextTable === 'empresa' && in_array($campo, ['nit', 'documento_dv', 'razon_social'], true)) {
    $formGroupExtra .= ' crud-empresa-doc-field';
}
if ($crudContextTable === 'empresa' && in_array($campo, ['rep_tipodocumento_id', 'rep_numero_documento', 'rep_nombres', 'rep_apellidos'], true)) {
    $formGroupExtra .= ' crud-empresa-rep-field';
}
if ($crudContextTable === 'empresa' && in_array($campo, ['email', 'sitio_web'], true)) {
    $formGroupExtra .= ' crud-empresa-contacto-field';
}
if ($crudContextTable === 'empresa' && in_array($campo, ['pais_id', 'departamento_id', 'municipio_id', 'zona_id', 'comuna_id', 'corregimiento_id', 'barrio_id', 'vereda_id'], true)) {
    $formGroupExtra .= ' crud-empresa-ubicacion-field';
}
if (($config['span'] ?? '') === 'full') {
    $formGroupExtra .= ' crud-form-field-full';
}
if ($crudContextTable === 'usuario' && in_array($campo, ['foto_ruta', 'firma_ruta'], true)) {
    $formGroupExtra .= ' crud-usuario-media-field';
}
if ($crudContextTable === 'empresa' && in_array($campo, ['logo', 'logo2'], true)) {
    $formGroupExtra .= ' crud-empresa-media-field';
}

if ($crudContextTable === 'empresa') {
    $isEmpresaMedia = in_array($campo, ['logo', 'logo2'], true);
    if (!$crudEmpresaLayoutOpen) {
        echo '<div class="crud-empresa-layout">';
        $crudEmpresaLayoutOpen = true;
    }
    if ($isEmpresaMedia) {
        if ($crudEmpresaFieldsOpen) {
            echo '</div>';
            $crudEmpresaFieldsOpen = false;
        }
        if (!$crudEmpresaAsideOpen) {
            echo '<aside class="crud-empresa-media-aside" aria-label="Logos de la empresa">';
            $crudEmpresaAsideOpen = true;
        }
    } else {
        if ($crudEmpresaAsideOpen) {
            echo '</aside>';
            $crudEmpresaAsideOpen = false;
        }
        if (!$crudEmpresaFieldsOpen) {
            echo '<div class="crud-empresa-fields">';
            $crudEmpresaFieldsOpen = true;
        }
    }
}

if ($crudContextTable === 'empresa' && $campo === 'nit') {
    echo '<div class="crud-empresa-section-head crud-form-field-full"><h3 class="crud-empresa-section-title">Identificación tributaria</h3></div>';
    echo '<div class="crud-empresa-nit-fields">';
}
if ($crudContextTable === 'empresa' && $campo === 'razon_social') {
    echo '</div>';
}
if ($crudContextTable === 'empresa' && $campo === 'pais_id') {
    echo '<div class="crud-empresa-section-head crud-form-field-full"><h3 class="crud-empresa-section-title">Ubicación</h3></div>';
    echo '<div class="crud-empresa-ubicacion-fields">';
    $crudEmpresaUbicacionOpen = true;
}
if ($crudContextTable === 'empresa' && $campo === 'telefono') {
    if ($crudEmpresaUbicacionOpen) {
        echo '</div>';
        $crudEmpresaUbicacionOpen = false;
    }
    echo '<div class="crud-empresa-section-head crud-form-field-full"><h3 class="crud-empresa-section-title">Contacto</h3></div>';
}
if ($crudContextTable === 'empresa' && $campo === 'email') {
    echo '<div class="crud-empresa-contacto-pair">';
    $crudEmpresaContactoPairOpen = true;
}
if ($crudContextTable === 'empresa' && $campo === 'rep_tipodocumento_id') {
    if ($crudEmpresaContactoPairOpen) {
        echo '</div>';
        $crudEmpresaContactoPairOpen = false;
    }
    echo '<div class="crud-empresa-section-head crud-form-field-full"><h3 class="crud-empresa-section-title">Representante legal</h3></div>';
    echo '<div class="crud-empresa-rep-fields">';
    echo '<div class="form-group crud-empresa-rep-field crud-form-field-full">';
    echo '<label>Buscar representante</label>';
    echo '<div class="crud-rep-lookup-wrap">';
    echo '<input type="search" class="form-input crud-rep-lookup-search" placeholder="Documento, nombre o apellido…" autocomplete="off" data-label="Buscar representante">';
    echo '<ul class="crud-catalog-dropdown" hidden></ul>';
    echo '</div>';
    echo '</div>';
    $crudEmpresaRepOpen = true;
}
if ($crudContextTable === 'empresa' && $campo === 'rep_apellidos') {
    if ($crudEmpresaRepOpen) {
        echo '</div>';
        $crudEmpresaRepOpen = false;
    }
}
if ($crudContextTable === 'empresa' && $campo === 'fecha_registro') {
    echo '<div class="crud-empresa-section-head crud-form-field-full"><h3 class="crud-empresa-section-title">Datos operativos</h3></div>';
}

if ($crudContextTable === 'usuario') {
    $isUsuarioMedia = in_array($campo, ['foto_ruta', 'firma_ruta'], true);
    if (!$crudUsuarioLayoutOpen) {
        echo '<div class="crud-usuario-layout">';
        $crudUsuarioLayoutOpen = true;
    }
    if ($isUsuarioMedia) {
        if ($crudUsuarioFieldsOpen) {
            echo '</div>';
            $crudUsuarioFieldsOpen = false;
        }
        if (!$crudUsuarioAsideOpen) {
            echo '<aside class="crud-usuario-media-aside" aria-label="Foto y firma">';
            $crudUsuarioAsideOpen = true;
        }
    } else {
        if ($crudUsuarioAsideOpen) {
            echo '</aside>';
            $crudUsuarioAsideOpen = false;
        }
        if (!$crudUsuarioFieldsOpen) {
            echo '<div class="crud-usuario-fields">';
            $crudUsuarioFieldsOpen = true;
        }
    }
}

if ($crudContextTable === 'usuario' && $campo === 'tipodocumento_id') {
    echo '<div class="crud-usuario-section-head crud-form-field-full"><h3 class="crud-usuario-section-title">Identificación y datos personales</h3></div>';
    echo '<div class="crud-usuario-doc-fields">';
}
if ($crudContextTable === 'usuario' && $campo === 'username') {
    echo '<div class="crud-usuario-section-head crud-form-field-full"><h3 class="crud-usuario-section-title">Cuenta de acceso</h3></div>';
}

$crudUsuarioMediaAccordion = $crudContextTable === 'usuario' && in_array($campo, ['foto_ruta', 'firma_ruta'], true);
$crudEmpresaMediaAccordion = $crudContextTable === 'empresa' && in_array($campo, ['logo', 'logo2'], true);
if ($crudUsuarioMediaAccordion || $crudEmpresaMediaAccordion) {
    $accOpen = ($campo === 'foto_ruta' || $campo === 'logo') ? ' open' : '';
    $accClass = $crudContextTable === 'empresa' ? 'crud-empresa-accordion' : 'crud-usuario-accordion';
    $accSummaryClass = $crudContextTable === 'empresa' ? 'crud-empresa-accordion-summary' : 'crud-usuario-accordion-summary';
    $accBodyClass = $crudContextTable === 'empresa' ? 'crud-empresa-accordion-body' : 'crud-usuario-accordion-body';
    echo '<details class="' . $accClass . '"' . $accOpen . '>';
    echo '<summary class="' . $accSummaryClass . '">' . htmlspecialchars($fieldLabel, ENT_QUOTES, 'UTF-8') . '</summary>';
    echo '<div class="' . $accBodyClass . '">';
}

?>

<div class="form-group<?= htmlspecialchars($formGroupExtra, ENT_QUOTES, 'UTF-8') ?>"<?= $formGroupStyle ?>>

<?php
$labelTitleAttr = ($config['title'] ?? '') !== ''
    ? ' title="' . htmlspecialchars((string)$config['title'], ENT_QUOTES, 'UTF-8') . '"'
    : '';
?>
<?php if (!$crudUsuarioMediaAccordion && !$crudEmpresaMediaAccordion): ?>
<label<?= $labelTitleAttr ?>><?= htmlspecialchars($fieldLabel, ENT_QUOTES, 'UTF-8') ?></label>
<?php endif; ?>

<?php if(isset($relations[$campo])): ?>

<?php if (!empty($catalogRegistry[$campo])): ?>
<?php
$selNombre = '';
if ($value !== '' && $value !== null && isset($relationMapsPreview[$campo][$value])) {
    $selNombre = $relationMapsPreview[$campo][$value];
}
$hidZonaTipoAttr = '';
if ($campo === 'zona_id' && !empty($crudZonaUbicacionToggle) && $value !== '' && $value !== null) {
    $hz = $zonaTipoById[(string) $value] ?? null;
    if ($hz !== null && $hz !== '') {
        $hidZonaTipoAttr = ' data-zona-tipo="' . htmlspecialchars($hz, ENT_QUOTES, 'UTF-8') . '"';
    }
}
?>
<div class="crud-catalog-wrap"
     data-catalog-field="<?= htmlspecialchars($campo, ENT_QUOTES, 'UTF-8') ?>"
     data-parent-fields="<?= htmlspecialchars(json_encode($catalogRegistry[$campo]['parent_fields'], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>"
     <?= ($campo === 'zona_id' && !empty($crudZonaUbicacionToggle)) ? 'data-crud-zona-master="1"' : '' ?>>
<input type="hidden" name="<?= $campo ?>"
value="<?= htmlspecialchars((string)$value) ?>"
<?= $requiredAttr ?>
data-label="<?= htmlspecialchars($fieldLabel, ENT_QUOTES, 'UTF-8') ?>"
data-rules="<?= $config['rules'] ?>"
class="form-input crud-catalog-id <?= $error ? 'input-error' : '' ?>"
<?= $hidZonaTipoAttr ?>>
<input type="search"
value="<?= htmlspecialchars($selNombre) ?>"
placeholder="<?= htmlspecialchars($config['placeholder'] ?: 'Buscar…') ?>"
autocomplete="off"
data-label="<?= htmlspecialchars($fieldLabel, ENT_QUOTES, 'UTF-8') ?>"
data-rules=""
class="form-input crud-catalog-search <?= $error ? 'input-error' : '' ?>"
<?= $uppercaseDataAttr ?>>
<ul class="crud-catalog-dropdown" hidden></ul>
</div>

<?php else: ?>

<select name="<?= $campo ?>"
<?= $requiredAttr ?>
data-label="<?= htmlspecialchars($fieldLabel, ENT_QUOTES, 'UTF-8') ?>"
data-rules="<?= $config['rules'] ?>"
class="form-input <?= $error ? 'input-error' : '' ?>">

<option value=""><?= $config['placeholder'] ?: 'Seleccione' ?></option>

<?php foreach($relationData[$campo] as $opt): ?>
<?php
$optCodigo = isset($opt['codigo']) ? strtoupper(trim((string)$opt['codigo'])) : '';
$optNombre = isset($opt['nombre']) ? (string)$opt['nombre'] : '';
?>
<option value="<?= $opt['id'] ?>" <?= ($value == $opt['id']) ? 'selected' : '' ?>
data-doc-codigo="<?= htmlspecialchars($optCodigo, ENT_QUOTES, 'UTF-8') ?>"
data-doc-nombre="<?= htmlspecialchars($optNombre, ENT_QUOTES, 'UTF-8') ?>"
<?php if ($campo === 'zona_id' && isset($opt['tipo']) && $opt['tipo'] !== '' && $opt['tipo'] !== null): ?>
data-zona-tipo="<?= htmlspecialchars(strtolower((string) $opt['tipo']), ENT_QUOTES, 'UTF-8') ?>"
<?php endif; ?>>
<?= htmlspecialchars($optNombre) ?>
</option>
<?php endforeach; ?>

</select>

<?php endif; ?>

<?php else: ?>

<?php if($config['type'] == 'password'): ?>

<input
type="password"
name="<?= $campo ?>"
value=""
autocomplete="new-password"
data-password="1"
placeholder="<?= htmlspecialchars($config['placeholder'] ?: 'Contraseña; vacío al editar = sin cambios') ?>"
data-label="<?= htmlspecialchars($fieldLabel, ENT_QUOTES, 'UTF-8') ?>"
data-rules="<?= $config['rules'] ?>"
class="form-input <?= $error ? 'input-error' : '' ?>"
<?= ($config['title'] ?? '') !== '' ? 'title="' . htmlspecialchars((string)$config['title'], ENT_QUOTES, 'UTF-8') . '"' : '' ?>>

<?php elseif($config['type'] === 'upload'): ?>
<?php
$uploadSubtype = strtolower((string)($config['subtype'] ?? ''));
$pathVal = htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$uploadEndpoint = $crudContextTable === 'empresa'
    ? '?url=empresa/uploadLogo'
    : '?url=usuario/uploadAsset';
?>
<div class="crud-upload-wrap"
     data-upload-endpoint="<?= htmlspecialchars($uploadEndpoint, ENT_QUOTES, 'UTF-8') ?>"
     data-field-name="<?= htmlspecialchars($campo, ENT_QUOTES, 'UTF-8') ?>"
     data-subtype="<?= htmlspecialchars($uploadSubtype, ENT_QUOTES, 'UTF-8') ?>">
<input type="hidden" name="<?= $campo ?>" value="<?= $pathVal ?>" class="form-input crud-upload-path <?= $error ? 'input-error' : '' ?>"
       data-label="<?= htmlspecialchars($fieldLabel, ENT_QUOTES, 'UTF-8') ?>" data-rules="<?= $config['rules'] ?>">
<div class="crud-upload-card">
<div class="crud-upload-preview" aria-live="polite">
<?php if ($value !== '' && $value !== null): ?>
<?php if ($uploadSubtype === 'signature' || $uploadSubtype === 'image'): ?>
<img src="<?= $pathVal ?>" alt="" class="crud-upload-thumb" loading="lazy">
<?php else: ?>
<span class="crud-upload-filename"><?= $pathVal ?></span>
<?php endif; ?>
<?php else: ?>
<span class="crud-upload-placeholder">Sin archivo</span>
<?php endif; ?>
</div>
<div class="crud-upload-side">
<p class="crud-upload-hint"><?= htmlspecialchars(
    $uploadSubtype === 'signature'
        ? 'Firma: imagen PNG, JPG o WebP (máx. 3 MB).'
        : ($crudContextTable === 'empresa' ? 'Logo: imagen PNG, JPG o WebP (máx. 3 MB).' : 'Foto: imagen PNG, JPG o WebP (máx. 3 MB).'),
    ENT_QUOTES,
    'UTF-8'
) ?></p>
<div class="crud-upload-btnrow">
<input type="file" class="crud-upload-input-hidden" tabindex="-1" aria-hidden="true" accept="image/jpeg,image/png,image/webp">
<button type="button" class="btn-crud-upload-file">Elegir archivo</button>
<?php if ($crudContextTable === 'usuario' && $uploadSubtype === 'image'): ?>
<button type="button" class="btn-crud-upload-camera">Tomar foto</button>
<?php endif; ?>
<button type="button" class="btn-crud-upload-clear">Quitar</button>
</div>
</div>
</div>
</div>

<?php elseif($config['type'] == 'textarea'): ?>

<textarea
name="<?= $campo ?>"
<?= $requiredAttr ?>
placeholder="<?= $config['placeholder'] ?>"
data-label="<?= htmlspecialchars($fieldLabel, ENT_QUOTES, 'UTF-8') ?>"
data-rules="<?= $config['rules'] ?>"
class="form-input <?= $error ? 'input-error' : '' ?>"
<?= $uppercaseDataAttr ?>
<?= ($config['title'] ?? '') !== '' ? 'title="' . htmlspecialchars((string)$config['title'], ENT_QUOTES, 'UTF-8') . '"' : '' ?>><?= htmlspecialchars($value) ?></textarea>

<?php else: ?>

<input
type="<?= $config['type'] ?>"
name="<?= $campo ?>"
value="<?= htmlspecialchars($value) ?>"
<?= $requiredAttr ?>
placeholder="<?= $config['placeholder'] ?>"
data-label="<?= htmlspecialchars($fieldLabel, ENT_QUOTES, 'UTF-8') ?>"
data-rules="<?= $config['rules'] ?>"
class="form-input <?= $error ? 'input-error' : '' ?>"
<?= $uppercaseDataAttr ?>
<?= ($config['title'] ?? '') !== '' ? 'title="' . htmlspecialchars((string)$config['title'], ENT_QUOTES, 'UTF-8') . '"' : '' ?>>

<?php endif; ?>

<?php endif; ?>

<?php if($error): ?>
<span class="error-text"><?= $error ?></span>
<?php endif; ?>

</div>

<?php if (!empty($crudUsuarioMediaAccordion) || !empty($crudEmpresaMediaAccordion)): ?>
</div>
</details>
<?php endif; ?>
<?php if ($crudContextTable === 'usuario' && $campo === 'documento_dv'): ?>
</div>
<?php endif; ?>

<?php endforeach; ?>

<?php
if ($crudContextTable === 'usuario') {
    if ($crudUsuarioFieldsOpen) {
        echo '</div>';
    }
    if ($crudUsuarioAsideOpen) {
        echo '</aside>';
    }
    if ($crudUsuarioLayoutOpen) {
        echo '</div>';
    }
}
if ($crudContextTable === 'empresa') {
    if ($crudEmpresaContactoPairOpen) {
        echo '</div>';
    }
    if ($crudEmpresaUbicacionOpen) {
        echo '</div>';
    }
    if ($crudEmpresaRepOpen) {
        echo '</div>';
    }
    if ($crudEmpresaFieldsOpen) {
        echo '</div>';
    }
    if ($crudEmpresaAsideOpen) {
        echo '</aside>';
    }
    if ($crudEmpresaLayoutOpen) {
        echo '</div>';
    }
}
?>

</div>

<?php if ($crudContextTable === 'usuario'): ?>
<div id="crud-usuario-toast" class="crud-usuario-toast" hidden aria-live="polite"></div>
<?php endif; ?>

</form>

<div class="crud-table savid-dt-host">

<?php
// =========================
// MAPA RELACIONES
// =========================
$relationMaps = [];

foreach($relationData as $campoRel => $options){
    foreach($options as $opt){
        $relationMaps[$campoRel][$opt['id']] = $opt['nombre'];
    }
}
?>

<table class="savid-datatable savid-datatable-crud">

<thead>
<tr>

<?php foreach($columns as $col): ?>

<?php
if($col['Field']=='id') continue;

$config = getConfigFromComment($col['COLUMN_COMMENT'] ?? '');
if(!$config['showTable']) continue;
if ($crudContextTable === 'usuario' && $usuarioTableColumnFields !== []
    && !in_array($col['Field'], $usuarioTableColumnFields, true)) {
    continue;
}
?>

<?php
$thCfg = getConfigFromComment($col['COLUMN_COMMENT'] ?? '');
$thLabel = ($thCfg['label'] ?? '') !== '' ? (string)$thCfg['label'] : formatLabel($col['Field']);
?>
<th><?= htmlspecialchars($thLabel, ENT_QUOTES, 'UTF-8') ?></th>

<?php endforeach; ?>

</tr>
</thead>

<tbody>

<?php foreach($data as $row): ?>

<tr class="crud-row" data-id="<?= $row['id'] ?>"<?php if (in_array($crudContextTable, ['usuario', 'empresa'], true)): ?>
    <?php if (array_key_exists('tercero_id', $row)): ?> data-tercero-id="<?= htmlspecialchars((string)($row['tercero_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"<?php endif; ?>
    <?php if (array_key_exists('terceroidentificacion_id', $row)): ?> data-terceroidentificacion-id="<?= htmlspecialchars((string)($row['terceroidentificacion_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"<?php endif; ?>
    <?php if (in_array($crudContextTable, ['empresa', 'usuario'], true)): ?> data-row-json="<?= htmlspecialchars(json_encode($row, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>"<?php endif; ?>
<?php endif; ?>>

<?php foreach($columns as $col): ?>

<?php
$campo = $col['Field'];
if($campo=='id') continue;

$config = getConfigFromComment($col['COLUMN_COMMENT'] ?? '');
if(!$config['showTable']) continue;
if ($crudContextTable === 'usuario' && $usuarioTableColumnFields !== []
    && !in_array($campo, $usuarioTableColumnFields, true)) {
    continue;
}

$valor = $row[$campo];

if(isset($relationMaps[$campo])){
    $valor = $relationMaps[$campo][$valor] ?? $valor;
}

if ($config['type'] === 'password') {
    $valor = $row[$campo] !== null && $row[$campo] !== '' ? '••••••••' : '';
}

$dataValueRaw = $row[$campo];
if ($config['type'] === 'password') {
    $dataValueRaw = '';
}

$tdZonaTipo = '';
if ($campo === 'zona_id' && !empty($crudZonaUbicacionToggle) && $row[$campo] !== null && $row[$campo] !== '') {
    $ztRow = $zonaTipoById[(string) $row[$campo]] ?? null;
    if ($ztRow !== null && $ztRow !== '') {
        $tdZonaTipo = ' data-zona-tipo="' . htmlspecialchars($ztRow, ENT_QUOTES, 'UTF-8') . '"';
    }
}
?>

<td data-field="<?= $campo ?>" data-value="<?= htmlspecialchars((string)$dataValueRaw, ENT_QUOTES, 'UTF-8') ?>"<?= $tdZonaTipo ?>>
<?php if ($config['type'] === 'upload'): ?>
<?php
$rawPath = $row[$campo];
$upSub = strtolower((string)($config['subtype'] ?? ''));
if ($rawPath !== null && $rawPath !== '') {
    $escPath = htmlspecialchars((string)$rawPath, ENT_QUOTES, 'UTF-8');
    if ($upSub === 'signature' || $upSub === 'image') {
        echo '<span class="crud-upload-cell-thumb-wrap"><img src="' . $escPath . '" alt="" class="crud-upload-cell-thumb" loading="lazy"></span>';
    } else {
        echo '<span class="crud-upload-cell-text">' . $escPath . '</span>';
    }
} else {
    echo '<span class="crud-upload-cell-empty">—</span>';
}
?>
<?php elseif ($crudContextTable === 'usuario' && $campo === 'documento_dv' && ($valor === null || $valor === '')): ?>
<span class="crud-dv-cell-empty">—</span>
<?php else: ?>
<?= htmlspecialchars((string)$valor) ?>
<?php endif; ?>
</td>

<?php endforeach; ?>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

<?php
if (!empty($crudContextTable) && $crudContextTable === 'empresa') {
    echo '<script>window.__CRUD_EMPRESA_FK_LABELS = ' . json_encode($relationMaps, JSON_UNESCAPED_UNICODE) . ';</script>';
}
if (!empty($crudContextTable) && $crudContextTable === 'usuario') {
    $meta = $tipodocumentoMetaById ?? [];
    echo '<script>window.__TIPO_DOC_USUARIO_META = ' . json_encode($meta, JSON_UNESCAPED_UNICODE) . ';</script>';
    $ujsPath = defined('BASE_PATH') ? BASE_PATH . '/public/js/usuario_crud.js' : '';
    $ujsV = ($ujsPath !== '' && is_readable($ujsPath)) ? (int) filemtime($ujsPath) : time();
    echo '<script src="/js/usuario_crud.js?v=' . $ujsV . '"></script>';
}
?>

</div>