# SAVID

Documentacion unica del proyecto: guia rapida para el dia a dia y detalle tecnico para mantenimiento.

---

## Indice

1. [Configuracion de base de datos](#configuracion-de-base-de-datos)
2. [Guia rapida (equipo)](#guia-rapida-equipo)
3. [Arquitectura y flujo](#arquitectura-y-flujo)
4. [Modulos refactorizados](#modulos-refactorizados)
5. [CRUD automatico y acciones especiales](#crud-automatico-y-acciones-especiales)
6. [Como agregar funcionalidad](#como-agregar-funcionalidad)
7. [Detalle tecnico (capas y contratos)](#detalle-tecnico-capas-y-contratos)
8. [Checklist y verificacion](#checklist-y-verificacion)
9. [Riesgos y mantenimiento](#riesgos-y-mantenimiento)

---

## Configuracion de base de datos

La clase `Database` ya no esta en `core/`. El punto de entrada (`public/index.php`) carga en este orden:

1. `config/Database.php` si existe (configuracion local).
2. Si no, `config.example/Database.php` (plantilla segura, sin credenciales en el codigo).

La carpeta `/config/` esta en `.gitignore`: no subas credenciales al repositorio.

### Opcion recomendada: archivo `.env`

1. Crea la carpeta `config/` en la raiz del proyecto (no se versiona).
2. Copia la plantilla: `cp config.example/env.example config/.env`
3. Edita `config/.env` con tu host, usuario y contraseña MySQL.

Variables: `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_CHARSET`.

Tambien se lee `.env` en la raiz del proyecto si prefieres ese archivo.

### Opcion alternativa: clase propia

Copia la plantilla y personalizala:

`cp config.example/Database.php config/Database.php`

---

## Guia rapida (equipo)

### Regla de oro

- No SQL en `app/controllers` (ni `prepare` ni `query`).

### Estructura

| Carpeta | Rol |
|---------|-----|
| `app/controllers/` | HTTP: request, redirecciones, vistas, JSON |
| `app/services/` | Reglas de negocio y orquestacion |
| `app/models/` | Repositorios (SQL) |
| `app/views/` | Presentacion |

### Flujo en 4 pasos

1. Controller recibe el request.
2. Controller llama a un Service.
3. Service usa Repository(s).
4. Controller devuelve vista o JSON.

### CRUD: archivos y variables

Archivos clave:

- `app/views/crud/table.php`
- `public/js/crud.js`
- `app/controllers/ModuleController.php`
- `app/services/ModuleService.php`

La vista CRUD necesita estas variables (si falta `relations`, se rompen los selects `*_id`):

- `$data`, `$columns`, `$acciones`, `$relations`, `$relationData`

### Acciones especiales (botones)

1. Alta en `accion` con `accion_codigo`.
2. Vinculo en `item_accion`.
3. Permiso en `permiso` / `rol_permiso`.
4. Caso en `public/js/crud.js` (`switch` de `data-accion`).
5. Endpoint en controller (delegando a service/repository).

Ejemplos: `rol_permisos`, `usuario_permisos`, `usuario_roles`.

---

## Arquitectura y flujo

MVC ligero:

- `public/index.php`: bootstrap, sesion, autoload, router.
- `core/`: `Router`, `Database`, `SessionManager`.

Flujo de request:

1. Router resuelve `?url=controlador/metodo/param`.
2. Controller valida contexto HTTP y delega a Service.
3. Service aplica reglas y usa Repository.
4. Repository ejecuta SQL.
5. Controller responde con vista o JSON.

---

## Modulos refactorizados

| Area | Controller | Service | Repositories |
|------|------------|---------|--------------|
| Contexto empresa/sede | `ContextController` | `ContextService` | `CompanyRepository`, `BranchRepository` |
| Permisos de rol | `RolController` | `RolePermissionService` | `RolePermissionRepository` |
| CRUD dinamico | `ModuleController` | `ModuleService` | `ModuleRepository` |
| Autenticacion | `LoginController` | `AuthService` | `UserRepository`, `SubscriptionRepository`, etc. |
| Usuario (roles / permisos directos) | `UsuarioController` | `UserAccessService` | `UserAccessRepository` |
| Dashboard | `DashboardController` | `DashboardService` | `MenuService`, `ModuleRepository` |

---

## CRUD automatico y acciones especiales

- Datos: `ModuleService` -> `CrudService`.
- Acciones estandar: `ver`, `limpiar`, `guardar`, `eliminar`.
- Especiales: botones si existen en `item_accion` y el usuario tiene permiso sobre esa `item_accion`.

---

## Como agregar funcionalidad

### Solo CRUD estandar

1. Crear `item` y `item_accion` en BD.
2. Permisos (`permiso` / `rol_permiso`).
3. Sin vista personalizada en `app/views/<ruta>/index.php`, entra el CRUD automatico.

### Accion especial por boton

1. `accion` con `accion_codigo`.
2. `item_accion`.
3. Handler en `public/js/crud.js`.
4. Controller + Service + Repository segun corresponda.

---

## Detalle tecnico (capas y contratos)

### Principios

- Controllers sin SQL y sin reglas de negocio pesadas.
- Services: casos de uso, permisos/alcance, armado de matrices y breadcrumbs.
- Repositories: unica capa de acceso a datos (consultas y mapeo).
- Vistas y JS sin reglas de negocio duplicadas.

### Responsabilidades por capa

**Controllers:** autenticacion basica del request, leer `$_GET`/`$_POST`, invocar services, `require` / `header` / JSON.

**Services:** orquestacion, validaciones de negocio, coordinar repositorios.

**Repositories:** `prepare` / `query` / `execute`, resultados como arrays.

### Contrato `ModuleService::getCrudData`

Debe exponer:

- `data`, `columns`, `acciones`, `relations`, `relationData`

### Acciones especiales

La UI usa `data-accion` = `accion.accion_codigo`. Checklist: accion en BD, `item_accion`, permiso, handler JS.

### Convenciones

- Metodos de service con nombres de caso de uso (`buildMatrixResponse`, `changeContext`, etc.).
- JSON uniforme: `['success' => bool, 'message' => ...]` cuando aplique.
- Evitar duplicar breadcrumb/jerarquia: centralizar en services existentes.

### Estrategia para evolutivos

1. Caso de uso en Service.
2. Repository nuevo o extendido.
3. Controller delgado que solo llama al Service.
4. Cambios de UI en views/JS.
5. Revisar permisos y contexto `empresa_id` / `sede_id`.

### Comandos utiles

```bash
rg "->prepare\\(|->query\\(" app/controllers
rg "data-accion|btn-accion|accion_codigo" public/js app/views
```

---

## Checklist y verificacion

**Antes de commit:**

- [ ] No hay SQL en `app/controllers`.
- [ ] Linter sin errores en archivos tocados.
- [ ] CRUD: selects, guardar, eliminar, acciones especiales.
- [ ] Permisos por ruta y por accion coherentes.
- [ ] Contexto `empresa_id` / `sede_id` intacto.

**Humo rapido CRUD:** nuevo, editar fila, select `*_id`, eliminar, boton especial si aplica.

---

## Riesgos y mantenimiento

| Riesgo | Mitigacion |
|--------|------------|
| Regresion de selects en CRUD | Verificar que `relations` llegue a `crud/table.php`. |
| Accion en BD sin JS | Checklist accion especial (punto 4 arriba). |
| Logica repartida en controllers | Regla controller delgado; mover a Service. |

- SQL nuevo: empezar por Repository.
- Regla duplicada en dos controllers: unificar en Service.
