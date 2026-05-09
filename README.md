# SAVID

Documentacion unica del proyecto: guia rapida para el dia a dia y detalle tecnico para mantenimiento.

**Antes de ejecutar la aplicacion:** configura la conexion a MySQL. Lee la seccion [Configuracion de base de datos](#configuracion-de-base-de-datos) (archivo `config/.env` a partir de `config.example/env.example`). Sin ellos la app no podra conectar a la base de datos.

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

La clase `Database` vive fuera de `core/` y usa variables de entorno para no versionar secretos.

### Como se carga la clase

`public/index.php` resuelve la clase en este orden:

1. **`config/Database.php`** si existe (override total, opcional).
2. Si no existe, **`config.example/Database.php`** (plantilla incluida en el repo).

La carpeta **`/config/`** esta listada en `.gitignore`: todo lo que pongas ahi (`.env`, `Database.php` propio) no se sube al repositorio.

### Opcion recomendada: `config/.env`

Es el metodo habitual para desarrollo y servidores propios.

| Paso | Accion |
|------|--------|
| 1 | Crear carpeta local (no versionada): `mkdir -p config` |
| 2 | Copiar plantilla: `cp config.example/env.example config/.env` |
| 3 | Editar `config/.env` con tus valores reales (sobre todo `DB_PASSWORD`) |

Plantilla de referencia en el repo: **`config.example/env.example`**.

#### Variables de entorno (`DB_*`)

| Variable | Descripcion | Valor por defecto si no esta definida |
|----------|-------------|--------------------------------------|
| `DB_HOST` | Servidor MySQL/MariaDB | `localhost` |
| `DB_DATABASE` | Nombre de la base de datos | `savid` |
| `DB_USERNAME` | Usuario de la base de datos | `root` |
| `DB_PASSWORD` | Contrasena (dejar vacio si no usas clave) | cadena vacia |
| `DB_CHARSET` | Charset PDO | `utf8mb4` |

Formato del archivo `.env` (una variable por linea, sin comillas salvo que las necesites):

```env
DB_HOST=localhost
DB_DATABASE=savid
DB_USERNAME=root
DB_PASSWORD=tu_clave_aqui
DB_CHARSET=utf8mb4
```

- Lineas que empiezan por `#` se ignoran (comentarios).
- No subas `config/.env` al git: la carpeta `config/` ya esta ignorada.

#### Orden de lectura del archivo `.env`

La clase `Database` intenta cargar variables en este orden y usa el **primer archivo que exista y se pueda leer**:

1. `config/.env` (recomendado, junto con credenciales ignoradas por git)
2. `.env` en la **raiz del proyecto** (alternativa si prefieres un solo archivo en la raiz)

Si ninguno existe, se usan solo los valores por defecto de la tabla anterior (usuario `root`, contrasena vacia, etc.).

### Opcion alternativa: clase `Database` propia

Si necesitas logica especial (SSL, socket, etc.), copia la plantilla y editala solo en local:

```bash
cp config.example/Database.php config/Database.php
```

Ese archivo en `config/` tampoco se versiona. El bootstrap cargara `config/Database.php` antes que la plantilla de `config.example/`.

### Resumen de seguridad

- Las credenciales reales deben estar solo en **`config/.env`** o en **`config/Database.php`** local, nunca commiteadas.
- El repositorio solo incluye **`config.example/`** como referencia segura.

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

Ejemplos: `rol_permisos`, `usuario_permisos`, `usuario_roles`, `usuario_sedes`.

### Boton empresa/sede por usuario (`usuario_sedes`)

- Ruta del modal: `usuario/empresa_sede/{id}` (compatibilidad: `action/usuario_sedes/{id}` delega en lo mismo).
- Persistencia en tablas `usuario_empresa` y `usuario_sede` (reemplazo completo al guardar).
- Superadmin (`rol_id = 1`): puede asignar cualquier empresa activa.
- Resto de usuarios: solo empresas que ya tengan en `usuario_empresa` para si mismos (misma idea que la matriz de permisos por rol).
- Las sedes marcadas deben pertenecer a empresas tambien marcadas; si desmarcas una empresa, las sedes de esa empresa se deshabilitan y no se guardan.

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

### Campos tipo password (generico)

Cualquier columna cuyo **comentario en MySQL** incluya `type:password` (junto al resto de opciones que ya usas, separadas por `|`) se comporta asi:

- Al **guardar**, el valor se guarda con `password_hash()` (algoritmo por defecto de PHP).
- En **edicion** (registro con `id`), si el campo llega **vacío**, **no** se actualiza la contraseña (se mantiene el hash anterior).
- En **alta**, si la columna es `NOT NULL` y envias vacío, el servidor devuelve error de obligatoriedad.
- En la **tabla** y al **seleccionar una fila**, no se muestra ni copia el hash; el input siempre va vacío para no filtrar el secreto.

Ejemplo de comentario en la columna `password` de la tabla `usuario`:

```sql
ALTER TABLE usuario MODIFY COLUMN password VARCHAR(255) NOT NULL
  COMMENT 'type:password|order:5|placeholder:Contraseña del usuario';
```

Para **ocultar** la columna en la grilla pero dejarla solo en el formulario, añade por ejemplo `show:form` (segun tu convencion actual en `getConfigFromComment`).

Recomendacion: columna `password` de longitud **al menos 255** para hashes bcrypt/argon.

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
