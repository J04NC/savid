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
            $arr = explode(',', $show);

            if(in_array('none',$arr)){
                $config['showForm'] = false;
                $config['showTable'] = false;
            }else{
                $config['showForm'] = in_array('form',$arr);
                $config['showTable'] = in_array('table',$arr);
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

$crudZonaFieldNames = array_column($columns, 'Field');
$crudUrbanoRuralFields = ['comuna_id', 'barrio_id', 'corregimiento_id', 'vereda_id'];
$crudHasUrbanoRural = count(array_intersect($crudUrbanoRuralFields, $crudZonaFieldNames)) > 0;
$crudZonaUbicacionToggle = ($crudContextTable === 'tercero')
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
<?php endif; ?>

<div class="crud-toolbar">

<div>

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
?>

<?php if($canNuevo): ?>
<button type="button" id="btnNuevo">➕</button>
<?php endif; ?>

<?php if($canGuardar): ?>
<button type="submit">💾</button>
<?php endif; ?>

<?php if($canEliminar): ?>
<button type="button" class="btn-delete">🗑</button>
<?php endif; ?>

</div>

<div style="margin-left:auto; display:flex; gap:10px;">

<?php foreach($acciones as $accion): ?>

<?php if(
    !in_array($accion['codigo'],['ver','limpiar','guardar','eliminar']) &&
    PermisoService::canByItemAccion($accion['item_accion_id'])
): ?>

<button type="button" 
class="btn-accion"
data-accion="<?= $accion['accion_codigo'] ?>"
title="<?= $accion['nombre'] ?>">
<?= $accion['icono'] ?: '⚙️' ?>
</button>

<?php endif; ?>

<?php endforeach; ?>

<input type="text" placeholder="Buscar..." class="crud-search">

</div>

</div>

<div class="crud-form<?= $crudContextTable === 'usuario' ? ' crud-form-usuario' : '' ?>">

<?php
$crudUsuarioLayoutOpen = false;
$crudUsuarioFieldsOpen = false;
$crudUsuarioAsideOpen = false;
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
    $formGroupExtra .= ' crud-usuario-dv-wrap';
    $formGroupStyle = ' style="display:none;"';
}
if ($crudContextTable === 'usuario' && in_array($campo, ['tipodocumento_id', 'numero_documento', 'documento_dv'], true)) {
    $formGroupExtra .= ' crud-usuario-doc-field';
}
if (($config['span'] ?? '') === 'full') {
    $formGroupExtra .= ' crud-form-field-full';
}
if ($crudContextTable === 'usuario' && in_array($campo, ['foto_ruta', 'firma_ruta'], true)) {
    $formGroupExtra .= ' crud-usuario-media-field';
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
if ($crudUsuarioMediaAccordion) {
    $accOpen = $campo === 'foto_ruta' ? ' open' : '';
    echo '<details class="crud-usuario-accordion"' . $accOpen . '>';
    echo '<summary class="crud-usuario-accordion-summary">' . htmlspecialchars($fieldLabel, ENT_QUOTES, 'UTF-8') . '</summary>';
    echo '<div class="crud-usuario-accordion-body">';
}

?>

<div class="form-group<?= htmlspecialchars($formGroupExtra, ENT_QUOTES, 'UTF-8') ?>"<?= $formGroupStyle ?>>

<?php
$labelTitleAttr = ($config['title'] ?? '') !== ''
    ? ' title="' . htmlspecialchars((string)$config['title'], ENT_QUOTES, 'UTF-8') . '"'
    : '';
?>
<?php if (!$crudUsuarioMediaAccordion): ?>
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
?>
<div class="crud-upload-wrap"
     data-upload-endpoint="?url=usuario/uploadAsset"
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
<p class="crud-upload-hint"><?= htmlspecialchars($uploadSubtype === 'signature' ? 'Firma: imagen PNG, JPG o WebP (máx. 3 MB).' : 'Foto: imagen PNG, JPG o WebP (máx. 3 MB).', ENT_QUOTES, 'UTF-8') ?></p>
<div class="crud-upload-btnrow">
<input type="file" class="crud-upload-input-hidden" tabindex="-1" aria-hidden="true" accept="image/jpeg,image/png,image/webp">
<button type="button" class="btn-crud-upload-file">Elegir archivo</button>
<button type="button" class="btn-crud-upload-camera">Tomar foto</button>
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

<?php if (!empty($crudUsuarioMediaAccordion)): ?>
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
?>

</div>

<?php if ($crudContextTable === 'usuario'): ?>
<div id="crud-usuario-toast" class="crud-usuario-toast" hidden aria-live="polite"></div>
<?php endif; ?>

</form>

<div class="crud-table">

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

<table>

<thead>
<tr>

<?php foreach($columns as $col): ?>

<?php
if($col['Field']=='id') continue;

$config = getConfigFromComment($col['COLUMN_COMMENT'] ?? '');
if(!$config['showTable']) continue;
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

<tr class="crud-row" data-id="<?= $row['id'] ?>">

<?php foreach($columns as $col): ?>

<?php
$campo = $col['Field'];
if($campo=='id') continue;

$config = getConfigFromComment($col['COLUMN_COMMENT'] ?? '');
if(!$config['showTable']) continue;

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
if (!empty($crudContextTable) && $crudContextTable === 'usuario') {
    $meta = $tipodocumentoMetaById ?? [];
    echo '<script>window.__TIPO_DOC_USUARIO_META = ' . json_encode($meta, JSON_UNESCAPED_UNICODE) . ';</script>';
    $ujsPath = defined('BASE_PATH') ? BASE_PATH . '/public/js/usuario_crud.js' : '';
    $ujsV = ($ujsPath !== '' && is_readable($ujsPath)) ? (int) filemtime($ujsPath) : time();
    echo '<script src="/js/usuario_crud.js?v=' . $ujsV . '"></script>';
}
?>

</div>