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
        'rules' => '',
        'showForm' => true,
        'showTable' => true,
        'order' => 999,
        'placeholder' => '',
        'relmode' => 'select',
        'uppercase' => false,
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

<div class="crud-form">

<?php foreach($columns as $col): ?>

<?php
$campo = $col['Field'];
if($campo == 'id') continue;

$config = getConfigFromComment($col['COLUMN_COMMENT'] ?? '');

if(!$config['showForm']) continue;

$value = $old[$campo] ?? '';
$error = $errors[$campo] ?? null;
$required = ($col['IS_NULLABLE'] == 'NO') ? 'required' : '';
$requiredAttr = ($config['type'] === 'password') ? '' : $required;
$uppercaseDataAttr = (!empty($config['uppercase']) && $config['type'] !== 'password')
    ? ' data-crud-uppercase="1"'
    : '';

$formGroupExtra = '';
if (!empty($crudZonaUbicacionToggle)) {
    if (in_array($campo, ['comuna_id', 'barrio_id'], true)) {
        $formGroupExtra = ' crud-zona-urban';
    } elseif (in_array($campo, ['corregimiento_id', 'vereda_id'], true)) {
        $formGroupExtra = ' crud-zona-rural';
    }
}
?>

<div class="form-group<?= htmlspecialchars($formGroupExtra, ENT_QUOTES, 'UTF-8') ?>">

<label><?= formatLabel($campo) ?></label>

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
data-label="<?= formatLabel($campo) ?>"
data-rules="<?= $config['rules'] ?>"
class="form-input crud-catalog-id <?= $error ? 'input-error' : '' ?>"
<?= $hidZonaTipoAttr ?>>
<input type="search"
value="<?= htmlspecialchars($selNombre) ?>"
placeholder="<?= htmlspecialchars($config['placeholder'] ?: 'Buscar…') ?>"
autocomplete="off"
data-label="<?= formatLabel($campo) ?>"
data-rules=""
class="form-input crud-catalog-search <?= $error ? 'input-error' : '' ?>"
<?= $uppercaseDataAttr ?>>
<ul class="crud-catalog-dropdown" hidden></ul>
</div>

<?php else: ?>

<select name="<?= $campo ?>"
<?= $requiredAttr ?>
data-label="<?= formatLabel($campo) ?>"
data-rules="<?= $config['rules'] ?>"
class="form-input <?= $error ? 'input-error' : '' ?>">

<option value=""><?= $config['placeholder'] ?: 'Seleccione' ?></option>

<?php foreach($relationData[$campo] as $opt): ?>
<option value="<?= $opt['id'] ?>" <?= ($value == $opt['id']) ? 'selected' : '' ?>
<?php if ($campo === 'zona_id' && isset($opt['tipo']) && $opt['tipo'] !== '' && $opt['tipo'] !== null): ?>
data-zona-tipo="<?= htmlspecialchars(strtolower((string) $opt['tipo']), ENT_QUOTES, 'UTF-8') ?>"
<?php endif; ?>>
<?= $opt['nombre'] ?>
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
data-label="<?= formatLabel($campo) ?>"
data-rules="<?= $config['rules'] ?>"
class="form-input <?= $error ? 'input-error' : '' ?>">

<?php elseif($config['type'] == 'textarea'): ?>

<textarea
name="<?= $campo ?>"
<?= $requiredAttr ?>
placeholder="<?= $config['placeholder'] ?>"
data-label="<?= formatLabel($campo) ?>"
data-rules="<?= $config['rules'] ?>"
class="form-input <?= $error ? 'input-error' : '' ?>"
<?= $uppercaseDataAttr ?>><?= htmlspecialchars($value) ?></textarea>

<?php else: ?>

<input
type="<?= $config['type'] ?>"
name="<?= $campo ?>"
value="<?= htmlspecialchars($value) ?>"
<?= $requiredAttr ?>
placeholder="<?= $config['placeholder'] ?>"
data-label="<?= formatLabel($campo) ?>"
data-rules="<?= $config['rules'] ?>"
class="form-input <?= $error ? 'input-error' : '' ?>"
<?= $uppercaseDataAttr ?>>

<?php endif; ?>

<?php endif; ?>

<?php if($error): ?>
<span class="error-text"><?= $error ?></span>
<?php endif; ?>

</div>

<?php endforeach; ?>

</div>

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

<th><?= formatLabel($col['Field']) ?></th>

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

$tdZonaTipo = '';
if ($campo === 'zona_id' && !empty($crudZonaUbicacionToggle) && $row[$campo] !== null && $row[$campo] !== '') {
    $ztRow = $zonaTipoById[(string) $row[$campo]] ?? null;
    if ($ztRow !== null && $ztRow !== '') {
        $tdZonaTipo = ' data-zona-tipo="' . htmlspecialchars($ztRow, ENT_QUOTES, 'UTF-8') . '"';
    }
}
?>

<td data-field="<?= $campo ?>" data-value="<?= ($config['type'] === 'password') ? '' : htmlspecialchars((string)$row[$campo]) ?>"<?= $tdZonaTipo ?>>
<?= htmlspecialchars((string)$valor) ?>
</td>

<?php endforeach; ?>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

</div>