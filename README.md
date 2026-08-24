# SAVID

Documentacion unica del proyecto: guia rapida para el dia a dia y detalle tecnico para mantenimiento.

**Antes de ejecutar la aplicacion:** configura la conexion a MySQL. Lee la seccion [Configuracion de base de datos](#configuracion-de-base-de-datos) (archivo `config/.env` a partir de `config.example/env.example`). Sin ellos la app no podra conectar a la base de datos.

---

## Indice

1. [Configuracion de base de datos](#configuracion-de-base-de-datos)
2. [Guia rapida (equipo)](#guia-rapida-equipo)
3. [Arquitectura y flujo](#arquitectura-y-flujo)
4. [Modulos refactorizados](#modulos-refactorizados)
5. [CRUD automatico y acciones especiales](#crud-automatico-y-acciones-especiales) (tercero / `zona` catalogo, [comentarios MySQL](#opciones-reconocidas-en-column_comment-mysql))
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

### Recuperacion de contrasena (`login/forgot`)

Flujo: `?url=login/forgot` (pide usuario o correo) → `PasswordResetService::requestReset` genera un token de un solo uso (30 min, solo se persiste su hash SHA-256 en `usuario_password_reset`) y lo envia por correo via `MailerService` (PHPMailer/SMTP) → `?url=login/resetPassword&token=...` valida el token y muestra el formulario de nueva contrasena → `resetPasswordSave` reutiliza `UsuarioFormValidationService::validatePasswordPolicy` (misma politica que el CRUD de usuarios) y marca el token como usado.

La respuesta de `forgotSend` es siempre el mismo mensaje generico, exista o no la cuenta (anti-enumeracion). Requiere variables `SMTP_*` y opcionalmente `APP_URL` en `config/.env` (ver `config.example/env.example`); sin `SMTP_HOST` configurado, `MailerService` registra el intento en el log de PHP y no envia el correo (util en desarrollo, pero el token igual queda creado).

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

En el CRUD de **`tercero`**, `zona` es un catalogo de dos filas (urbana/rural) y la jerarquia es unica (`comuna` -> `barrio`), con etiqueta dinamica segun la zona; el detalle esta en la subseccion **Tercero: direccion urbana o rural** dentro de [CRUD automatico y acciones especiales](#crud-automatico-y-acciones-especiales). Las directivas del comentario de columna (`type:`, `relmode:`, etc.) estan descritas en [Opciones reconocidas en COLUMN_COMMENT](#opciones-reconocidas-en-column_comment-mysql).

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
| SGD | `SgdController` | `SgdConfigService`, `SgdImportService`, `SgdScopeService`, `SgdDocumentoService`, `SgdFormularioService`, `SgdRegistroService`, `SgdElaboracionService`, … | `SgdRepository` |

### SGD — Sistema de Gestión Documental

Diseño funcional completo: [`docs/sgd/FICHA_MODULO_SGD.md`](docs/sgd/FICHA_MODULO_SGD.md).

| Fase | Alcance | Estado |
|------|---------|--------|
| **F1** | Config empresa, catálogos, import CCD/maestro, multiempresa | ✅ |
| **F2** | Listado maestro, versiones documento, PDF/archivo oficial | ✅ |
| **F3a** | Diseñador plantillas operativas (esquema JSON, preview, publicar) | ✅ base |
| **F3b** | Arquetipos operativos, biblioteca de bloques, esquema **v3**, diligenciamiento piloto acta | 🔄 avanzado |
| **F3c** | Elaboración maestro M4 (secciones, redacción, import Word, preview PDF) | 🔄 base |
| **F4** | Expedientes + registros diligenciados (`sgd_registro`) | 🔄 base |
| **F5–F7** | Firmas colaborador, TRD, variantes sede, indexador `11.42.SGI` | ⏳ |

**Rutas principales** (`?url=…`):

| Ruta | Uso |
|------|-----|
| `sgd` | Hub del módulo |
| `sgd/config` | Configuración SGD por empresa |
| `sgd/importar` | Importación Excel CCD y listado maestro |
| `sgd/documentos` | Listado maestro (documentos F/R/PD…) |
| `sgd/ccd` | Cuadro de clasificación documental |
| `sgd/formularios` | Diseñador de plantillas **operativas** (modo dinámico F/R) |
| `sgd/elaboracion` | Elaboración de documentos **maestro** (M/PD/PL…) |
| `sgd/secciones` | Catálogo de secciones M4 y bloques operativos |
| `sgd/registros` | Registros diligenciados sobre plantilla publicada |

**Catálogos de referencia** (`config.example/`):

| Archivo | Contenido |
|---------|-----------|
| `sgd_tipos_plantilla.json` | Tipos documentales y `modo` (maestro / dinámico / híbrido) |
| `sgd_arquetipos_operativos.json` | 10 arquetipos, ~31 bloques operativos, biblioteca acta, **semilla por arquetipo** |
| `sgd_secciones_m4_plantilla.json` | Secciones de elaboración maestro (M4) |

**Migraciones recientes** (`database/migrations/`): `20260605_sgd_formulario.sql`, `20260606_sgd_seccion_elaboracion.sql`, `20260607_sgd_seccion_operativo.sql`, `20260608_sgd_documento_version_archivo.sql`, `20260612_sgd_registro_f4.sql`, permisos versiones (`20260609`–`20260611`).

**Frontend SGD** (`public/js/`): `sgd-formularios.js`, `sgd-bloque-config.js`, `sgd-checklist-config.js`, `sgd-registros.js`, `sgd-elaboracion.js`, `sgd-documentos.js`, …

**Operativa (F3b/F4):** plantillas con esquema **v3** (`elementos[]` unificados: bloques + campos intercalados). El diseñador compone formatos desde la biblioteca de bloques del arquetipo; **semilla** = subconjunto común vacío por arquetipo (sin presets corporativos). Cada registro queda ligado a `formulario_version_id` publicada; cambios en borrador no afectan instancias ya abiertas.

**Importación Excel:** lector `scripts/sgd_read_sheet.py` (requiere `python3` + `xlrd` para `.xls`).

Todo filtrado por `empresa_id` (sesión o filtro superadmin). Tipos documentales desde plantilla JSON, no hardcodeados en PHP.

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

### Tercero: direccion urbana o rural (`zona` como catalogo)

`zona` es un **catalogo de dos filas** (`U` Urbana / `R` Rural, con
`codigo_sispro` para reporte nacional), **no** una entidad territorial. La
jerarquia es **una sola** y la zona vive en la comuna:

```
zona (2 filas)          municipio (1122)
                           |
                           +-- comuna (municipio_id + zona_id) -- barrio
```

Un **corregimiento** es una comuna con zona rural; una **vereda**, un barrio con
zona rural. No existen tablas `corregimiento` ni `vereda`, ni las columnas
`tercero.corregimiento_id` / `tercero.vereda_id`.

Antes `zona` tenia una fila por municipio y por tipo (2244 filas), lo que obligaba
a que un `zona_id` mezclara *que municipio* con *urbano o rural*, duplicaba cuatro
tablas identicas y exigia ocultar y limpiar bloques del formulario con JS. El
modelo actual sigue el de SIHOS (`CodiZona` / `CodiComu` / `CodiBarr`), adaptado a
claves subrogadas `id` en vez de las claves compuestas de aquel esquema.

**Etiqueta dinamica.** Los campos son siempre `comuna_id` y `barrio_id`, pero se
**reetiquetan** segun la zona elegida, para conservar el vocabulario habitual:

| `zona.tipo` | `comuna_id` se muestra como | `barrio_id` se muestra como |
|-------------|-----------------------------|------------------------------|
| `urbana`    | Comuna                      | Barrio                       |
| `rural`     | Corregimiento               | Vereda                       |

**Como funciona:** la vista (`app/views/crud/table.php`) marca esos dos grupos con
la clase `crud-zona-label` y los atributos `data-zona-label-urbana` /
`data-zona-label-rural`; `crudZonaUbicacionApplyFromForm` en `public/js/crud.js`
lee el tipo de la zona seleccionada y cambia el texto de la etiqueta. Ya **no** se
ocultan ni se limpian campos.

**Datos y API:** en `app/services/CrudService.php`, cuando la tabla referenciada es
**`zona`** y existe la columna **`tipo`**, tanto `getRelationData` como
`searchCatalogOptions` devuelven `tipo`, que es lo que permite el reetiquetado.

**Migracion:** `database/migrations/20260822_zona_catalogo_y_jerarquia_unica.sql`
(respaldo previo en `storage/backups/territorio_pre_rediseno_*.sql`). El antiguo
`add_tercero_zona_ubicacion_comuna_corregimiento.sql` sigue obsoleto: no ejecutarlo.

### Ubicacion: cascada y autocompletado de la jerarquia

Los campos `pais_id`, `departamento_id`, `municipio_id`, `zona_id`, `comuna_id` y
`barrio_id` funcionan como una jerarquia en dos sentidos. **La jerarquia se
deduce sola de las claves foraneas**: no hay rutas fijas en el codigo, de modo
que cualquier tabla nueva con FK encadenadas hereda el comportamiento.

**Hacia abajo (filtrar).** `getCatalogParentFieldsForFk` calcula los padres de
cada campo:

| Campo | Padres que lo acotan |
|-------|----------------------|
| `departamento_id` | `pais_id` |
| `municipio_id` | `departamento_id` |
| `comuna_id` | `municipio_id`, `zona_id` |
| `barrio_id` | `comuna_id` |

Sin texto de busqueda se exigen los padres (evita volcar cientos de barrios);
**con texto se busca igual aunque falten**, asi que escribir "Cadiz" lo
encuentra sin haber elegido comuna. Al cambiar un padre se limpian los
dependientes, para no poder guardar un barrio de Ibague con municipio
Roldanillo.

**Hacia arriba (rellenar).** El caso real es que la persona conozca el barrio
pero no la comuna. Al elegir un valor, `resolveCatalogAncestors` recorre las FK
hacia arriba y el endpoint **`module/catalogAncestors`** devuelve el resto de la
cadena; `crudRellenarAncestros` en `public/js/crud.js` completa **solo los
campos vacios** (no pisa lo que el usuario ya eligio). Elegir un barrio rellena
comuna, zona, municipio, departamento y pais.

Durante ese relleno el formulario se marca con `__crudAutofill` para que el
listener de cascada no confunda esos cambios con una edicion manual y borre lo
que se acaba de escribir.

**Nombres repetidos.** En Ibague hay 16 barrios cuyo nombre existe en mas de una
comuna (La Esperanza esta en tres). `searchCatalogOptions` detecta las
coincidencias dentro de un mismo resultado y anade el padre entre parentesis
solo en esos casos: "La Esperanza (San Simon)" frente a "La Esperanza (Vergel)".
El resto de la lista no se altera.

Aplica igual en **`tercero`** (columnas propias) y en **`empresa`** (campos
sinteticos que escriben en tercero, via `resolveCatalogContextTable`).

### Autocomplete del catalogo (teclado)

En el desplegable de busqueda del catalogo CRUD, **Tab** (sin Shift) confirma la opcion resaltada igual que **Enter**; **Shift+Tab** sigue moviendo el foco hacia atras sin seleccionar.

### Opciones reconocidas en COLUMN_COMMENT (MySQL)

En MySQL, el comentario de cada columna (`COLUMN_COMMENT`) puede llevar **varias directivas en una sola cadena**, separadas por **`|`** (pipe). Cada trozo se recorta con espacios (`trim`). La vista las interpreta en **`getConfigFromComment`** (`app/views/crud/table.php`); **`CrudService`** añade lectura de **`relmode`**, **`relfilter`**, **`type:password`** y la marca **`uppercase`**. **Mayusculas:** `relmode` y `relfilter` aceptan prefijo en cualquier mezcla (se normaliza a minusculas). **`type:`**, **`show:`**, **`order:`** y **`placeholder:`** deben ir en **minusculas** para que la vista los reconozca. La palabra clave **`uppercase`** es un trozo exacto en minusculas (igual que **`required`**).

| Directiva | Ejemplo | Efecto |
|-----------|---------|--------|
| **`type:`** *valor* | `type:email` | Define el control en el formulario. Valores especiales: **`password`** (input dedicado, hash al guardar en servidor, ver mas abajo); **`textarea`**. Cualquier otro valor se usa como **`type` del `<input>` HTML** (p. ej. `text`, `email`, `number`, `date`…); el navegador puede aplicar validacion nativa (`email`, etc.). |
| **`relmode:`** *modo* | `relmode:autocomplete` | Solo en columnas **clave foranea** (`*_id` con relacion declarada). **`select`**: lista completa (comportamiento por defecto si no pones `relmode`). **`autocomplete`**: siempre widget de busqueda con API `catalogSearch`. **`auto`**: catalogo solo si la tabla referenciada supera **~250 filas** (`ModuleService::CRUD_CATALOG_AUTO_THRESHOLD` y `CrudService::getApproxTableRows`); si no, lista tipo `select`. En `getConfigFromComment` los tres valores se guardan tal cual en configuracion de la vista; la decision catalogo vs select para `auto` ocurre al armar datos en `ModuleService`. |
| **`rel:`** *tabla* | `rel:tercero` | Tabla desde la que se toma la **etiqueta** del combo (puede diferir de la FK). Si la columna es **`empresa_id`** (valor = `empresa.id`) y pones **`rel:tercero`**, el CRUD hace `JOIN empresa → tercero` y muestra **`razon_social`** sin confundir el id del tercero con el de la empresa. |
| **`label:`** *columna* | `label:razon_social` | Columna de la tabla **`rel:`** usada como texto visible (junto con `rel:`). |
| **`relfilter:`** *lista* | `relfilter:1,2,3` o `relfilter:!5,Bogota` | Restringe las filas que alimentan el **combo relacion** en `getRelationData` (opciones del `<select>`). Lista separada por comas: numeros se interpretan como **`id`** de la tabla origen de la FK (`empresa.id` si la columna es `empresa_id`); texto como coincidencia por **etiqueta mostrada**. Prefijo **`!`** en un elemento → **excluir** ese id o ese nombre (se generan condiciones `NOT IN`). Si no usas `relfilter`, no se añade filtro SQL extra. **Nota:** la busqueda del **catalogo** (`searchCatalogOptions`) **no** reaplica hoy esta lista; el filtro aplica de forma fiable al listado del modo `select` y a datos auxiliares cargados con la misma funcion. |
| **`reltipo:`** *codigo* | `reltipo:GENERAL` | Solo en FK a **`estado`**. Filtra por `estado_tipo.codigo`: **GENERAL** (activo/inactivo), **CONTABLE**, **PERMISO** (permitir/denegar), **DOCUMENTAL** (borrador, vigente, obsoleto, firmado). Preferir `reltipo` frente a `relfilter:1,2` cuando el combo sea de estados. |
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

#### Cuando marcar `uppercase` (criterio del proyecto)

**Si** el valor es un dato de **identificacion oficial** o un **codigo de negocio que
el usuario escribe y lee**:

- Nombres y apellidos de persona natural, razon social de persona juridica
  (`tercero.nombres`, `tercero.apellidos`, `tercero.razon_social`).
- Numero de documento / identificacion.
- Codigos y siglas de negocio: `acad_level.codigo` (A1, B2),
  `fin_impuesto_tipo.codigo` (IVA19), `sgd_tipo_documental.codigo` (PD, F, MT).

**No** en el resto: email, `username`, contraseñas (forzar caja rompe el login y
algunos correos distinguen mayusculas), direcciones, texto libre
(observaciones, descripciones) y el `nombre` de catalogos internos
(sede, rol, item de menu), que escribe el propio equipo.

**Nunca** en **claves tecnicas** que el codigo compara literalmente, aunque la
columna se llame `codigo`. Forzarlas a mayuscula rompe el sistema:

| Columna | Valores | Quien los compara |
|---|---|---|
| `accion.codigo` | `ver`, `guardar`, `eliminar` | `switch` sobre `data-accion` en `public/js/crud.js` y la resolucion de permisos |
| `sgd_seccion.codigo` | `alcance`, `control_cambios` | catalogos JSON de `config.example/sgd_secciones_m4_plantilla.json` |

Los codigos DIVIPOLA/DANE (`departamento`, `municipio`, `barrio`, `vereda`) son
numericos y de datos semilla: la directiva no aportaria nada.

**Donde se aplica.** El motor CRUD generico normaliza al guardar la tabla propia
del item. Los formularios de `empresa` y `usuario` escriben ademas en `tercero`
desde sus propios resolvers, que corren **antes** de esa normalizacion; por eso
`UppercaseColumnService` es el punto unico que consultan todos, leyendo el
`COLUMN_COMMENT` real. **Marcar la columna en la base de datos es suficiente**:
queda cubierta en cliente y en servidor por todas las rutas. Ojo con los campos
**sinteticos** definidos a mano en `CrudService` (formulario de `usuario`, de
`empresa`): esos no leen el comentario de la BD y hay que anotarles la directiva
en su propia cadena.

**Ejemplo combinado** (como en maestros / `tercero`):

```sql
COMMENT 'type:email|relmode:autocomplete|order:40|placeholder:Correo de contacto'
```

**Ejemplo empresa** (catálogos SGD y cualquier `empresa_id`):

```sql
COMMENT 'label:Empresa|rel:tercero|label:razon_social|title:Razón social de la empresa'
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

**Humo rapido CRUD:** nuevo, editar fila, select `*_id`, eliminar, boton especial si aplica. Si el item es **tercero** con `zona_id`, comprobar que al cambiar de zona urbana a rural (y viceversa) las etiquetas de `comuna_id`/`barrio_id` pasan de Comuna/Barrio a Corregimiento/Vereda sin perder el valor seleccionado.

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

El CRUD no permite editar estos campos desde el formulario (se excluyen en `CrudService::save`). En la base de datos, cada columna de auditoría y soft delete debe llevar **`COMMENT 'show:none'`** (u `show:none|…` si ya hay otras directivas) para ocultarla también en formulario y grilla. Plantilla para tablas nuevas: `database/snippets/trackable_columns.sql`. Para actualizar tablas existentes: `php scripts/apply_trackable_column_comments.php`.

---

## Concurrencia: como se evita el doble envio

Operaciones que cambian estado y podrian ejecutarse dos veces (doble clic, dos
usuarios a la vez). El patron peligroso es **leer, validar y luego escribir**:
entre la lectura y la escritura cabe otra peticion.

| Operacion | Como se protege |
|-----------|-----------------|
| Login | `LoginThrottleService`: 5 intentos fallidos por ventana deslizante, registro en `login_intento` y alerta de fuerza bruta |
| Renovar suscripcion | Indice unico `uk_suscripcion_empresa_inicio (empresa_id, fecha_inicio)`. La garantia esta en el motor, no en un chequeo previo; `createRenewal` traduce el error 1062 en un resultado controlado |
| Escrituras SIHOS (5 acciones) | Bloqueo nombrado `GET_LOCK` por operacion logica, mas revalidacion dentro de la transaccion de escritura |
| DetaPlan (borrar / construir) | Ademas, `SELECT ... FOR UPDATE` sobre el prefijo de la PRIMARY KEY |
| Permisos por lote | Un solo DELETE por lote, transaccion corta y reintento ante deadlock/lock timeout |

### Por que un bloqueo nombrado y no `FOR UPDATE` en SIHOS

En `DetaPlan` si se usa `FOR UPDATE`: el filtro es el prefijo de la clave
primaria (`CodiInst, CodiDocu, NumeDocu`), verificado con EXPLAIN
(`key=PRIMARY, rows=1`), asi que el bloqueo queda en un documento pese a que la
tabla tiene ~1,4 millones de filas.

En cambio la comprobacion de "ya existe un ajuste" cruza `DetaCont`/`EncaCont`
por un indice que empieza en `TiDoRefe`, de **baja cardinalidad**. Un bloqueo de
hueco ahi frenaria inserciones ajenas y podria entorpecer a los propios usuarios
de SIHOS. Por eso `SihosExternalWriteRepository::conBloqueo()` usa `GET_LOCK`,
que serializa solo esa operacion logica sin tocar ninguna fila.

Detalles del bloqueo:

- El nombre se acota al objetivo (institucion + documento + cuenta), de modo que
  dos correcciones distintas no se estorban.
- Es de **sesion**, no transaccional: se toma antes de abrir la transaccion y se
  libera en un `finally`, tambien si la operacion lanza excepcion.
- Espera `ESPERA_BLOQUEO` (10 s); si no lo consigue lanza
  **`SihosOperacionEnCursoException`**, que los servicios convierten en un
  mensaje al usuario en vez de un error 500.
- SIHOS corre **MySQL 5.6**, que mantiene un unico lock nombrado por sesion;
  aqui se toma exactamente uno por operacion, asi que encaja.

**Al anadir una escritura nueva contra SIHOS**, envolverla en `conBloqueo()` y
revalidar dentro de la transaccion: no basta con lo que haya comprobado el
servicio, porque esa verificacion viaja por la conexion de **lectura** y el
estado puede cambiar entre una cosa y la otra.

## Overlay de carga global

`savidMostrarCargando(texto, inmediato)` / `savidOcultarCargando()` en
`public/js/app.js`: bloquea toda la pantalla (por encima del `.modal`
principal, `z-index:1000000`) mientras dura una operacion lenta, con spinner
y texto contextual. Se limpia solo al volver por el boton "atras" del
navegador (`cleanupStaleOverlays`, via bfcache), asi que nunca queda pegado.

**Al agregar cualquier accion nueva que tarde lo suficiente para que el
usuario pueda irse a otra parte mientras corre (escrituras SIHOS, reportes
pesados, guardados), envolverla con este helper — es la convencion, no una
excepcion.**

- **Peticion fetch**: mostrar antes, ocultar SIEMPRE en success y en error
  (los errores tambien deben liberar el overlay). El retraso por defecto es
  200ms, para no parpadear en operaciones que resultan instantaneas.
  ```js
  savidMostrarCargando("Guardando…");
  try { await fetch(...); } finally { savidOcultarCargando(); }
  ```
- **`<form>` de navegacion completa** (submit nativo, sin fetch): se pasa
  `inmediato=true` (sin el retraso) y NO se oculta a mano — la navegacion
  reemplaza el documento entero al terminar.
  ```js
  savidMostrarCargando("Generando reporte…", true);
  // se deja continuar el submit nativo, sin preventDefault
  ```

Ya aplicado en: guardado del CRUD generico (`crud.js`), las 3 escrituras de
SIHOS (`sihos-reversar-cuenta.js`, `sihos-reclasificar-cuenta.js`,
`sihos-construir-detaplan.js` — ahi tambien bloquea el boton "Cerrar" del
propio modal, para que no se pierda de vista una escritura contable en
curso), el formulario de fechas de `sihos/cruce`, y el `modal-loader` de
`openModalGod` (mismo spinner visual, sin la caja con fondo/sombra que
estorbaria dentro de un modal ya abierto).

## Riesgos y mantenimiento

| Riesgo | Mitigacion |
|--------|------------|
| Regresion de selects en CRUD | Verificar que `relations` llegue a `crud/table.php`. |
| Etiqueta comuna/barrio no cambia con la zona | Comprobar que `zona` tenga las 2 filas con `tipo` (`urbana`/`rural`) y que los grupos lleven `crud-zona-label` + `data-zona-label-*`; revisar `data-zona-tipo` en grilla y catalogo. |
| Migraciones duplicadas en territorio | No reejecutar `add_tercero_zona_ubicacion_comuna_corregimiento.sql` como ALTER legacy. |
| Accion en BD sin JS | Checklist accion especial (punto 4 arriba). |
| Logica repartida en controllers | Regla controller delgado; mover a Service. |

- SQL nuevo: empezar por Repository.
- Regla duplicada en dos controllers: unificar en Service.
