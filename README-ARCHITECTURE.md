# SAVID - Guia Tecnica de Arquitectura

Documento tecnico para mantenimiento y evolutivos.

## 1. Principios

- Separacion de capas estricta.
- Controllers sin SQL.
- Services como capa de negocio/orquestacion.
- Repositories como unica capa de acceso a datos.
- Vistas y JS desacoplados de reglas de negocio.

## 2. Mapa de capas

### 2.1 Controllers (`app/controllers`)

Responsables de:

- validar autenticacion basica del request,
- leer parametros (`$_GET`, `$_POST`),
- invocar services,
- responder (`require`, `header`, `echo json`).

No deben:

- ejecutar SQL,
- contener reglas complejas de negocio.

### 2.2 Services (`app/services`)

Responsables de:

- casos de uso,
- reglas de permisos/alcance,
- armado de estructuras para vistas (matrices, breadcrumb, payloads),
- coordinacion entre repositorios.

### 2.3 Repositories (`app/models`)

Responsables de:

- consultas SQL,
- mapeo de resultados,
- operaciones CRUD a nivel de entidad/aggregate.

## 3. Modulos refactorizados

- Contexto empresa/sede:
  - `ContextController`, `ContextService`, `CompanyRepository`, `BranchRepository`
- Permisos por rol:
  - `RolController`, `RolePermissionService`, `RolePermissionRepository`
- Modulo CRUD dinamico:
  - `ModuleController`, `ModuleService`, `ModuleRepository`
- Autenticacion:
  - `LoginController`, `AuthService`, `UserRepository`, `SubscriptionRepository`
- Usuario (roles/permisos directos):
  - `UsuarioController`, `UserAccessService`, `UserAccessRepository`
- Dashboard:
  - `DashboardController`, `DashboardService`, `MenuService`, `ModuleRepository`

## 4. Contratos utiles

### 4.1 CRUD dinamico (`ModuleService::getCrudData`)

Debe retornar:

- `data`
- `columns`
- `acciones`
- `relations`
- `relationData`

Si falta `relations`, se rompen los campos select en `crud/table.php`.

### 4.2 Acciones especiales

La UI toma `data-accion` desde `accion.accion_codigo`.  
Para activar una accion especial se requiere:

1. alta de accion (`accion`),
2. vinculo al item (`item_accion`),
3. permiso vigente (`permiso`/`rol_permiso`),
4. handler JS en `public/js/crud.js`.

## 5. Convenciones de implementacion

- Nombres de metodos service orientados a caso de uso:
  - `buildMatrixResponse`, `saveDirectPermissions`, `changeContext`, etc.
- Repositories pequenos y cohesivos (por tema).
- Preferir respuestas JSON uniformes:
  - `['success' => true|false, 'message' => ...]`.
- Evitar logica duplicada de breadcrumb/jerarquias.

## 6. Estrategia para cambios futuros

1. Modelar caso de uso en Service.
2. Crear o extender Repository.
3. Conectar Controller al Service.
4. Actualizar vista/JS solo en capa UI.
5. Validar permisos y contexto (`empresa_id`, `sede_id`).

## 7. Verificacion tecnica

Comandos utiles:

- Buscar SQL en controllers:
  - `rg "->prepare\\(|->query\\(" app/controllers`
- Buscar handlers de acciones especiales:
  - `rg "data-accion|btn-accion|accion_codigo" public/js app/views app/services`

## 8. Riesgos conocidos y mitigacion

- Riesgo: regresion de campos select en CRUD.
  - Mitigacion: validar payload completo (`relations` y `relationData`).
- Riesgo: accion especial creada en BD sin handler JS.
  - Mitigacion: checklist de accion especial.
- Riesgo: logica de negocio movida parcialmente.
  - Mitigacion: mantener regla "controller delgado".

