# SAVID - Arquitectura y Guia de Desarrollo

Este documento describe la arquitectura actual del proyecto y las reglas para mantener el codigo limpio por capas.

## 1) Arquitectura actual

El proyecto usa un MVC ligero con separacion por responsabilidades:

- `public/index.php`: bootstrap, sesion y router.
- `core/`: infraestructura base (`Router`, `Database`, `SessionManager`).
- `app/controllers/`: capa HTTP (request/response, redirecciones, carga de vistas/json).
- `app/services/`: logica de negocio y orquestacion de casos de uso.
- `app/models/`: repositorios (acceso a datos con SQL).
- `app/views/`: presentacion.

Flujo general:

1. Router recibe `?url=controlador/metodo/param`.
2. Controller valida contexto HTTP y delega a Service.
3. Service aplica reglas y usa Repository.
4. Repository ejecuta SQL.
5. Controller responde con vista o JSON.

## 2) Regla principal de capas

Regla obligatoria:

- No escribir SQL en `app/controllers`.

Distribucion correcta:

- Controller: `$_GET`, `$_POST`, `SessionManager`, `header()`, `require view`, `echo json`.
- Service: validaciones de negocio, permisos, armado de estructuras.
- Repository: `prepare/query/execute` y mapeo de resultados.

## 3) Modulos ya separados

Refactorizados a patron limpio:

- Contexto empresa/sede:
  - `ContextController`
  - `ContextService`
  - `CompanyRepository`, `BranchRepository`

- Permisos de rol:
  - `RolController`
  - `RolePermissionService`
  - `RolePermissionRepository`

- CRUD dinamico por modulo:
  - `ModuleController`
  - `ModuleService`
  - `ModuleRepository`

- Autenticacion:
  - `LoginController`
  - `AuthService`
  - `UserRepository`, `SubscriptionRepository`, `CompanyRepository`, `BranchRepository`

- Accesos de usuario (roles/permisos directos):
  - `UsuarioController`
  - `UserAccessService`
  - `UserAccessRepository`

- Dashboard/navegacion:
  - `DashboardController`
  - `DashboardService`
  - `MenuService`, `ModuleRepository`

## 4) CRUD automatico y acciones especiales

El CRUD dinamico usa:

- Vista: `app/views/crud/table.php`
- JS: `public/js/crud.js`
- Datos: `ModuleService -> CrudService`

Acciones:

- Estandar: `ver`, `limpiar`, `guardar`, `eliminar`.
- Especiales: se renderizan como botones si existen en `item_accion` y el usuario tiene permiso.

Para acciones especiales se usa `accion_codigo` (ejemplos):

- `rol_permisos`
- `usuario_permisos`
- `usuario_roles`

## 5) Como agregar una nueva funcionalidad

### Caso A: solo CRUD estandar de un item

1. Crear `item` y `item_accion` en BD.
2. Asegurar permisos (`permiso`/`rol_permiso`).
3. Si no hay vista personalizada, `ModuleController` usa CRUD automatico.

### Caso B: accion especial por boton

1. Crear accion en tabla `accion` con `accion_codigo`.
2. Vincular accion al item en `item_accion`.
3. Agregar manejo en `public/js/crud.js` (switch de `data-accion`).
4. Implementar endpoint en controller (idealmente delegado a service/repository).

## 6) Convenciones recomendadas

- Controllers pequenos y legibles (orquestacion, no SQL).
- Services con metodos de caso de uso (nombres claros).
- Repositories por agregado funcional (usuario, permisos, modulo, etc).
- Validar autenticacion al inicio de actions protegidas.
- Mantener respuestas JSON consistentes (`success`, `message` cuando aplique).

## 7) Checklist antes de commit

- [ ] No hay SQL en `app/controllers`.
- [ ] Linter sin errores en archivos tocados.
- [ ] CRUD: selects, guardar, eliminar y acciones especiales funcionando.
- [ ] Permisos validan acceso por ruta y por accion.
- [ ] No se rompio contexto `empresa_id` / `sede_id`.

## 8) Notas de mantenimiento

- Si un cambio requiere SQL nuevo, crear/ajustar Repository primero.
- Si una regla se repite en dos controllers, moverla a Service.
- Si aparece regresion de UI CRUD, revisar primero:
  - variables enviadas a `crud/table.php` (`data`, `columns`, `acciones`, `relations`, `relationData`)
  - acciones especiales en `public/js/crud.js`

