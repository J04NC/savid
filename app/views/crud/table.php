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
        'placeholder' => ''
    ];

    if(empty($comment)) return $config;

    $parts = explode('|', $comment);

    foreach($parts as $part){

        $part = trim($part);

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
?>

<div class="module-container">

<form method="POST">

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
?>

<div class="form-group">

<label><?= formatLabel($campo) ?></label>

<?php if(isset($relations[$campo])): ?>

<select name="<?= $campo ?>"
<?= $requiredAttr ?>
data-label="<?= formatLabel($campo) ?>"
data-rules="<?= $config['rules'] ?>"
class="form-input <?= $error ? 'input-error' : '' ?>">

<option value=""><?= $config['placeholder'] ?: 'Seleccione' ?></option>

<?php foreach($relationData[$campo] as $opt): ?>
<option value="<?= $opt['id'] ?>" <?= ($value == $opt['id']) ? 'selected' : '' ?>>
<?= $opt['nombre'] ?>
</option>
<?php endforeach; ?>

</select>

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
class="form-input <?= $error ? 'input-error' : '' ?>"><?= htmlspecialchars($value) ?></textarea>

<?php else: ?>

<input
type="<?= $config['type'] ?>"
name="<?= $campo ?>"
value="<?= htmlspecialchars($value) ?>"
<?= $requiredAttr ?>
placeholder="<?= $config['placeholder'] ?>"
data-label="<?= formatLabel($campo) ?>"
data-rules="<?= $config['rules'] ?>"
class="form-input <?= $error ? 'input-error' : '' ?>">

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
?>

<td data-field="<?= $campo ?>" data-value="<?= ($config['type'] === 'password') ? '' : htmlspecialchars((string)$row[$campo]) ?>">
<?= htmlspecialchars((string)$valor) ?>
</td>

<?php endforeach; ?>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

</div>