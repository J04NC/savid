# SAVID

Documentacion unica del proyecto: guia rapida para el dia a dia y detalle tecnico para mantenimiento.

**Antes de ejecutar la aplicacion:** configura la conexion a MySQL. Lee la seccion [Configuracion de base de datos](#configuracion-de-base-de-datos) (archivo `config/.env` a partir de `config.example/env.example`). Sin ellos la app no podra conectar a la base de datos.

---

## Indice

1. [Configuracion de base de datos](#configuracion-de-base-de-datos)
2. [Guia rapida (equipo)](#guia-rapida-equipo)
3. [Arquitectura y flujo](#arquitectura-y-flujo)
4. [Modulos refactorizados](#modulos-refactorizados)
5. [CRUD automatico y acciones especiales](#crud-automatico-y-acciones-especiales) (tercero / `zona.tipo`, [comentarios MySQL](#opciones-reconocidas-en-column_comment-mysql))
6. [Como agregar funcionalidad](#como-agregar-funcionalidad)
7. [Detalle tecnico (capas y contratos)](#detalle-tecnico-capas-y-contratos)
8. [Checklist y verificacion](#checklist-y-verificacion)
9. [Auditoría y archivo histórico](#auditoría-y-archivo-histórico)
10. [Riesgos y mantenimiento](#riesgos-y-mantenimiento)

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
- `app/services/CrudService.php` (relaciones, catalogo y metadatos como `tipo` en `zona`)

La vista CRUD necesita estas variables (si falta `relations`, se rompen los selects `*_id`):

- `$data`, `$columns`, `$acciones`, `$relations`, `$relationData`

En el CRUD de **`tercero`**, el modo urbano/rural se basa en **`zona.tipo`** (no en columna `zona_ubicacion`); el detalle esta en la subseccion **Tercero: modo urbano o rural** dentro de [CRUD automatico y acciones especiales](#crud-automatico-y-acciones-especiales). Las directivas del comentario de columna (`type:`, `relmode:`, etc.) estan descritas en [Opciones reconocidas en COLUMN_COMMENT](#opciones-reconocidas-en-column_comment-mysql).

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
| SGD (fase 1) | `SgdController` | `SgdConfigService`, `SgdImportService`, `SgdScopeService` | `SgdRepository` |

### SGD — fase 1 (catálogos e importación)

- Migración: `database/migrations/20260524_sgd_fase1.sql` (tablas `sgd_*`, módulo menú, permisos superadmin).
- Rutas: `?url=sgd`, `sgd/config`, `sgd/importar`; catálogos CRUD: `sgd_proceso`, `sgd_tipo_documental`, `sgd_dependencia`, `sgd_documento`, `sgd_ccd_entrada`, etc.
- Todo filtrado por `empresa_id` (sesión o filtro superadmin). Tipos documentales desde plantilla JSON (`config/sgd_tipos_plantilla.json`), no hardcodeados en PHP.
- Importación Excel: lector `scripts/sgd_read_sheet.py` (requiere `python3` + `xlrd` para `.xls`).
- Diseño funcional: `docs/sgd/FICHA_MODULO_SGD.md`.

---

## CRUD automatico y acciones especiales

- Datos: `ModuleService` -> `CrudService`.
- Acciones estandar: `ver`, `limpiar`, `guardar`, `eliminar`.
- Especiales: botones si existen en `item_accion` y el usuario tiene permiso sobre esa `item_accion`.

### Campos tipo password (generico)

Forma parte de la directiva **`type:password`** descrita en [Opciones reconocidas en COLUMN_COMMENT](#opciones-reconocidas-en-column_comment-mysql).

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

### Tercero: modo urbano o rural (`zona_id` + `zona.tipo`)

El formulario CRUD de la tabla **`tercero`** alterna bloques de ubicacion segun el **tipo de la zona** elegida (`tercero.zona_id` → `zona.tipo`). **No** se usa ninguna columna `zona_ubicacion` en base de datos.

| `zona.tipo` | Campos visibles (el otro bloque se oculta y se limpia) |
|-------------|--------------------------------------------------------|
| `rural` (comparacion en minusculas) | **Corregimiento** y **vereda** |
| Cualquier otro valor (p. ej. `urbana`) | **Comuna** y **barrio** |

**Condiciones para activar el toggle en la vista** (`data-crud-zona-ubicacion-toggle` en `app/views/crud/table.php`):

- Contexto de tabla `tercero`.
- Columna `zona_id`.
- Al menos una columna entre `comuna_id`, `barrio_id`, `corregimiento_id`, `vereda_id`.

**Datos y API:** en `app/services/CrudService.php`, cuando la tabla referenciada es **`zona`** y existe la columna **`tipo`**, tanto `getRelationData` como `searchCatalogOptions` (autocomplete del catalogo) devuelven `tipo` para que el front pueda etiquetar opciones y celdas con `data-zona-tipo`.

**Front:** `public/js/crud.js` lee el tipo desde el `<select name="zona_id">` o desde el input oculto del catalogo (`dataset.zonaTipo`), aplica clases `crud-zona-urban` / `crud-zona-rural` y ajusta `required` y limpieza de grupos.

**Migracion:** el archivo `database/migrations/add_tercero_zona_ubicacion_comuna_corregimiento.sql` quedo como **obsoleto** (solo comentario + `SELECT 1`). No ejecutar un ALTER que anada `zona_ubicacion` ni duplique `comuna_id` / `corregimiento_id` si el esquema maestro de territorio ya esta aplicado.

### Autocomplete del catalogo (teclado)

En el desplegable de busqueda del catalogo CRUD, **Tab** (sin Shift) confirma la opcion resaltada igual que **Enter**; **Shift+Tab** sigue moviendo el foco hacia atras sin seleccionar.

### Opciones reconocidas en COLUMN_COMMENT (MySQL)

En MySQL, el comentario de cada columna (`COLUMN_COMMENT`) puede llevar **varias directivas en una sola cadena**, separadas por **`|`** (pipe). Cada trozo se recorta con espacios (`trim`). La vista las interpreta en **`getConfigFromComment`** (`app/views/crud/table.php`); **`CrudService`** añade lectura de **`relmode`**, **`relfilter`**, **`type:password`** y la marca **`uppercase`**. **Mayusculas:** `relmode` y `relfilter` aceptan prefijo en cualquier mezcla (se normaliza a minusculas). **`type:`**, **`show:`**, **`order:`** y **`placeholder:`** deben ir en **minusculas** para que la vista los reconozca. La palabra clave **`uppercase`** es un trozo exacto en minusculas (igual que **`required`**).

| Directiva | Ejemplo | Efecto |
|-----------|---------|--------|
| **`type:`** *valor* | `type:email` | Define el control en el formulario. Valores especiales: **`password`** (input dedicado, hash al guardar en servidor, ver mas abajo); **`textarea`**. Cualquier otro valor se usa como **`type` del `<input>` HTML** (p. ej. `text`, `email`, `number`, `date`…); el navegador puede aplicar validacion nativa (`email`, etc.). |
| **`relmode:`** *modo* | `relmode:autocomplete` | Solo en columnas **clave foranea** (`*_id` con relacion declarada). **`select`**: lista completa (comportamiento por defecto si no pones `relmode`). **`autocomplete`**: siempre widget de busqueda con API `catalogSearch`. **`auto`**: catalogo solo si la tabla referenciada supera **~250 filas** (`ModuleService::CRUD_CATALOG_AUTO_THRESHOLD` y `CrudService::getApproxTableRows`); si no, lista tipo `select`. En `getConfigFromComment` los tres valores se guardan tal cual en configuracion de la vista; la decision catalogo vs select para `auto` ocurre al armar datos en `ModuleService`. |
| **`relfilter:`** *lista* | `relfilter:1,2,3` o `relfilter:!5,Bogota` | Restringe las filas que alimentan el **combo relacion** en `getRelationData` (opciones del `<select>`). Lista separada por comas: numeros se interpretan como **`id`**; texto como coincidencia por **etiqueta mostrada** (`nombre`, etc.). Prefijo **`!`** en un elemento → **excluir** ese id o ese nombre (se generan condiciones `NOT IN`). Si no usas `relfilter`, no se añade filtro SQL extra. **Nota:** la busqueda del **catalogo** (`searchCatalogOptions`) **no** reaplica hoy esta lista; el filtro aplica de forma fiable al listado del modo `select` y a datos auxiliares cargados con la misma funcion. |
| **`show:`** *vistas* | `show:none`, `show:form`, `show:table`, `show:form,table` | **`none`**: oculta la columna en **formulario y tabla**. Sin `none`: puedes combinar **`form`** y **`table`** separados por coma para mostrar solo en formulario, solo en grilla, o en ambos. |
| **`order:`** *n* | `order:10` | Entero para **ordenar** columnas en formulario y cabecera de grilla (menor numero = mas arriba). Por defecto interno `999` si no se indica. |
| **`placeholder:`** *texto* | `placeholder:Buscar…` | Texto de **marcador de posicion** en inputs/selects del formulario. Evita el caracter `|` dentro del texto (partiria la directiva). |
| **`required`** | `required` | Se concatena en la cadena **`data-rules`** del input (convencion reservada). El atributo HTML **`required`** que bloquea el envio lo marca sobre todo **`IS_NULLABLE = NO`** en la columna; revisa ambos si quieres obligatoriedad estricta en cliente. |
| **`uppercase`** | `uppercase` | El campo se edita y persiste **solo en mayusculas** (UTF-8: `mb_strtoupper` en servidor si existe la extension **mbstring**; si no, `strtoupper`). En el formulario: **`data-crud-uppercase="1"`** en `<input>` de texto, `<textarea>` y caja de busqueda del **catalogo** (`relmode` autocomplete/auto); **no** aplica a **`type:password`** ni al `<select>` de FK en modo lista. El JS (`initCrudUppercaseFields` en `public/js/crud.js`) fuerza mayusculas al escribir y al cargar fila. Valores vacios no se transforman. |
| **`min:`** *n* / **`max:`** *n* | Dos trozos: `min:1` y `max:255` | Se añaden a **`data-rules`** del control (p. ej. `min:1|max:255`). La validacion al enviar en `public/js/crud.js` hoy solo comprueba campos con atributo **`required`**; `min`/`max` quedan para convencion o extensiones futuras salvo que se implemente otro chequeo. |

**Ejemplo con solo mayusculas:**

```sql
COMMENT 'type:text|uppercase|order:15|placeholder:Numero de documento'
```

**Ejemplo combinado** (como en maestros / `tercero`):

```sql
COMMENT 'type:email|relmode:autocomplete|order:40|placeholder:Correo de contacto'
```

**Referencia de codigo:** `getConfigFromComment` en `app/views/crud/table.php`; `extractRelModeFromComment`, `extractRelFilterListFromColumnComment`, `columnCommentIsPasswordType`, `columnCommentIsUppercaseOnly` y uso de `relfilter` en `getRelationData` en `app/services/CrudService.php` (`save` aplica mayusculas antes de persistir); umbral `relmode:auto` en `ModuleService::CRUD_CATALOG_AUTO_THRESHOLD`; `initCrudUppercaseFields` / `crudNormalizeUppercaseField` en `public/js/crud.js`.

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

**Humo rapido CRUD:** nuevo, editar fila, select `*_id`, eliminar, boton especial si aplica. Si el item es **tercero** con `zona_id` y `zona.tipo`, comprobar que al cambiar de zona urbana a rural (y viceversa) se muestran u ocultan comuna/barrio frente a corregimiento/vereda.

---

## Auditoría y archivo histórico

Sistema de trazabilidad **global** (todas las escrituras SQL vía PDO) y pantalla de consulta.

### Qué se registra

| Campo | Contenido |
|-------|-----------|
| `accion` | `INSERT`, `UPDATE`, `DELETE` |
| `tabla` / `registro_id` | Entidad afectada |
| `datos_anteriores` / `datos_nuevos` | Snapshot JSON (contraseñas enmascaradas) |
| `campos_cambiados` | Diff campo a campo en UPDATE |
| `usuario_id`, `empresa_id`, `sede_id` | Contexto de sesión |
| `ip`, `user_agent`, `request_url` | Origen HTTP |

### Soft delete (recomendado)

- Si la tabla tiene `deleted_at`, el botón **Eliminar** del CRUD hace baja lógica (`deleted_at`, `deleted_by`) en lugar de `DELETE` físico.
- La migración `database/migrations/20260522_auditoria_sistema.sql` añade esas columnas a las tablas con `id` (excepto `auditoria` / `auditoria_archivo`).
- Tablas puente sin `id` o sin `deleted_at` siguen con borrado físico, pero quedan en el log de auditoría.

### Cómo archivar (retención en dos niveles)

1. **Tabla caliente** `auditoria`: consultas rápidas (últimos meses en uso).
2. **Tabla archivo** `auditoria_archivo`: copia de registros antiguos; misma estructura + `archived_at`.

**Proceso de archivo** (no borra historial, solo lo mueve):

```sql
INSERT INTO auditoria_archivo (...) SELECT ..., NOW(3) FROM auditoria WHERE occurred_at < 'fecha_corte';
DELETE FROM auditoria WHERE occurred_at < 'fecha_corte';
```

**Desde la UI:** superadmin → Auditoría → botón *Archivar antiguos (24 meses)*.

**Por cron (recomendado, mensual):**

```bash
0 3 1 * * cd /var/www/savid && php database/scripts/archive_auditoria.php 24
```

El argumento `24` son los meses que permanecen en `auditoria` antes de moverse al archivo. Ajustar según política (ej. `36`).

En la pantalla de auditoría, marcar **Consultar archivo histórico** para buscar solo en `auditoria_archivo`.

### Menú y permisos

- Ruta: `?url=auditoria` (ítem **Auditoría** bajo **Reportes** en módulo **Administración**).
- **Superadmin:** acceso total + archivar.
- **Otros usuarios:** permiso **ver** en el ítem `auditoria` (`permiso` / `rol_permiso` como el resto del sistema).

### Trazabilidad created_at/by, updated_at/by

La migración `database/migrations/20260523_trackable_columns.sql` añade las cuatro columnas en tablas con `id`.

En cada **INSERT** y **UPDATE** vía PDO, `TrackableColumnsService` rellena automáticamente:

| Columna | Cuándo |
|---------|--------|
| `created_at`, `created_by` | Solo en INSERT (si no vienen en el SQL) |
| `updated_at`, `updated_by` | En todo UPDATE (incluye soft delete) |

El usuario de sesión (`$_SESSION['user_id']`) se usa para `*_by`. Scripts CLI sin sesión dejan `*_by` en NULL pero sí marcan `*_at`.

El CRUD no permite editar estos campos desde el formulario (se excluyen en `CrudService::save`).

---

## Riesgos y mantenimiento

| Riesgo | Mitigacion |
|--------|------------|
| Regresion de selects en CRUD | Verificar que `relations` llegue a `crud/table.php`. |
| Toggle tercero incoherente | Comprobar que `zona` tenga `tipo` y valores acordes (`rural` vs resto); revisar `data-zona-tipo` en grilla y catalogo. |
| Migraciones duplicadas en territorio | No reejecutar `add_tercero_zona_ubicacion_comuna_corregimiento.sql` como ALTER legacy. |
| Accion en BD sin JS | Checklist accion especial (punto 4 arriba). |
| Logica repartida en controllers | Regla controller delgado; mover a Service. |

- SQL nuevo: empezar por Repository.
- Regla duplicada en dos controllers: unificar en Service.
