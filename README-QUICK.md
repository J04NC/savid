# SAVID - Guia Rapida (Equipo)

Guia corta para desarrollar sin romper la arquitectura.

## Regla de oro

- No SQL en `app/controllers`.

## Estructura

- `app/controllers/`: HTTP (request/response, redirecciones, vistas/json).
- `app/services/`: reglas de negocio.
- `app/models/`: repositorios SQL.
- `app/views/`: UI.

## Flujo recomendado

1. Controller recibe request.
2. Controller llama Service.
3. Service usa Repository.
4. Controller devuelve vista o JSON.

## CRUD automatico

Archivos clave:

- `app/views/crud/table.php`
- `public/js/crud.js`
- `app/controllers/ModuleController.php`
- `app/services/ModuleService.php`

Variables necesarias para la vista CRUD:

- `$data`
- `$columns`
- `$acciones`
- `$relations`
- `$relationData`

## Acciones especiales (botones)

1. Crear accion en BD (`accion.accion_codigo`).
2. Vincular a item (`item_accion`).
3. Agregar caso en `public/js/crud.js`.
4. Implementar endpoint en controller (delegando a service/repository).

Ejemplos usados:

- `rol_permisos`
- `usuario_permisos`
- `usuario_roles`

## Checklist rapido antes de commit

- [ ] No hay `prepare/query` en controllers.
- [ ] Permisos por ruta y por accion siguen funcionando.
- [ ] CRUD guarda/edita/elimina.
- [ ] Campos select (`*_id`) cargan bien.
- [ ] Acciones especiales abren y guardan.
- [ ] Linter sin errores.

