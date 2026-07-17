# CLAUDE.md

Este archivo proporciona orientación a Claude Code (claude.ai/code) al trabajar con código en este repositorio.

## Qué es esto

SAVID: una aplicación PHP MVC-lite (sin framework) con un motor CRUD genérico basado en metadatos, más un módulo grande a medida, **SGD** (Sistema de Gestión Documental — maestros documentales, versiones, plantillas de formularios operativos, registros diligenciados/expedientes).

No hay paso de build, ni suite de tests, ni linter/CI configurados. La "verificación" es manual: ejecutar la app localmente contra MySQL y probar los flujos de CRUD/SGD, o ejecutar los scripts puntuales en `scripts/`.

## Configuración y ejecución

- Requiere PHP >= 8.1 y MySQL/MariaDB. Dependencias vía `composer install` (única dependencia externa: `dompdf/dompdf`).
- La configuración de la BD **no** está en el repo. `public/index.php` y `core/AppBootstrap.php` cargan `config/Database.php` si existe, y si no, usan `config.example/Database.php` (la plantilla versionada). `config/` está en `.gitignore`.
- Para ejecutar en local: `mkdir -p config && cp config.example/env.example config/.env`, luego editar `config/.env` (`DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_CHARSET`). `Database::bootstrapEnv()` lee primero `config/.env`, luego un `.env` en la raíz, y si no, usa los valores por defecto (`localhost` / `savid` / `root` / contraseña vacía).
- El punto de entrada es `public/index.php` (apunta el docroot de tu servidor web ahí, o usa `php -S localhost:8000 -t public`). Todo se enruta mediante `?url=controlador/metodo/param` — no hay reglas de reescritura de URL amigables integradas en el código (configura tu servidor web si quieres URLs limpias).
- No existen tests automatizados. Los scripts de verificación puntual viven en `scripts/` (p. ej. `php scripts/test_word_import_cli.php`, `php scripts/test_sgd_pdf_generate.php`, `php scripts/test_usuario_save_matrix.php`) y `public/test.php` / `public/test-datatables.html`. Ejecútalos manualmente vía CLI/navegador al tocar las áreas que cubren.
- Greps útiles como prueba de humo al revisar un cambio (ver más abajo): `rg "->prepare\\(|->query\\(" app/controllers` (debería estar vacío — sin SQL en controllers) y `rg "data-accion|btn-accion|accion_codigo" public/js app/views`.

## Arquitectura

Capas estrictas, reforzadas por convención y no por herramientas — **no pongas SQL ni reglas de negocio en los controllers**:

```
public/index.php → core/Router.php → Controller → Service → Repository → PDO
```

- `core/`: `Router` (despacho de URL + middleware de autenticación/permisos), configuración de arranque de `Database`, `SessionManager`/`SessionConfigurator` (único punto global de inicio de sesión), `AuditingPDO`/`AuditingPDOStatement` (aquí se intercepta cada escritura SQL para el rastro de auditoría).
- `app/controllers/`: solo HTTP — leen `$_GET`/`$_POST`, llaman a un service, hacen `require` de una vista o emiten JSON. Sin `prepare`/`query`.
- `app/services/`: reglas de negocio, orquestación, verificación de permisos/alcance, construcción de matrices/breadcrumbs. Aquí es donde va la lógica de casos de uso.
- `app/models/`: repositorios — la única capa autorizada a tocar PDO directamente.
- `app/views/`: presentación (plantillas PHP planas, sin framework).
- El autoload es un `spl_autoload_register` hecho a mano en `public/index.php` (y replicado en `core/AppBootstrap.php` para scripts CLI) que busca `ClassName.php` en `core/`, `app/storage/`, `app/controllers/`, `app/models/`, `app/services/`, `app/helpers/`, `core/middleware/`. Una clase por archivo, nombre de archivo == nombre de clase, sin namespaces.
- `core/AppBootstrap.php::initCore()` es el bootstrap mínimo para scripts CLI independientes (cron, scripts puntuales en `scripts/`) que necesitan BD + autoload sin el ciclo completo de request HTTP.

### Enrutamiento y middleware (`core/Router.php`)

`?url=controller/method/params...` → `{Controller}Controller::method(...params)`; si falta el controller, cae en `ModuleController` (el motor CRUD genérico). `Router::middleware()` aplica, en orden: login obligatorio en todas partes salvo `LoginController`/`HealthController`; un puñado de endpoints de búsqueda JSON (`UsuarioController`, `EmpresaController`, endpoints específicos de elaboración de `SgdController`) tienen sus propias verificaciones de permiso finas; cualquier otro controller/method requiere `PermisoService::can($url, 'ver')` salvo que sea uno de los controllers siempre permitidos (`DashboardController`, `ModuleController`, `ContextController`). Al agregar un controller o endpoint JSON nuevo, revisa si necesita una excepción explícita aquí.

`ContextGateService::mustRedirectToContextSelection()` puede forzar a un usuario logueado a `?url=context/cambiarSede` antes de llegar a su destino — la mayor parte de la app está delimitada por `empresa_id`/`sede_id` desde el contexto de sesión.

### Motor CRUD genérico

Dado un `item` (entrada de menú) sin vista personalizada en `app/views/<ruta>/index.php`, `ModuleController` + `ModuleService` + `CrudService` renderizan una pantalla CRUD completa solo a partir de metadatos de la BD — sin código PHP por tabla para los casos básicos.

- `ModuleService::getCrudData` debe devolver `data`, `columns`, `acciones`, `relations`, `relationData` — la vista se rompe (selects de clave foránea) si falta `relations`.
- El comportamiento de columna se define enteramente mediante `COLUMN_COMMENT` de MySQL, interpretado por `getConfigFromComment` en `app/views/crud/table.php` y por `CrudService` (`extractRelModeFromComment`, `extractRelFilterListFromColumnComment`, `columnCommentIsPasswordType`, `columnCommentIsUppercaseOnly`). Las directivas van separadas por pipe, p. ej. `COMMENT 'type:email|relmode:autocomplete|order:40|placeholder:Correo de contacto'`. La referencia completa de directivas (`type:`, `relmode:`, `rel:`, `label:`, `relfilter:`, `reltipo:`, `show:`, `order:`, `placeholder:`, `required`, `uppercase`, `min:`/`max:`) está documentada en README.md y es la fuente de verdad — léela antes de tocar el renderizado de columnas CRUD.
- Botones de acción especiales: fila en `accion` (con `accion_codigo`) → vinculada vía `item_accion` → controlada por `permiso`/`rol_permiso` → manejada en `public/js/crud.js` (`switch` sobre `data-accion`) → endpoint de controller que delega a un service/repository. Las cuatro piezas deben existir juntas (ver README "Riesgos y mantenimiento").
- El formulario de dirección de `tercero` alterna entre campos urbanos (comuna/barrio) y rurales (corregimiento/vereda) según `zona.tipo`, no una columna almacenada — ver README "Tercero: modo urbano o rural" antes de cambiar algo relacionado con `zona_id`.

### Rastro de auditoría y columnas rastreables

Cada escritura SQL pasa por `AuditingPDO`/`AuditingPDOStatement`, que registra en `auditoria` (acción, tabla, id de fila, snapshots JSON antes/después con contraseñas enmascaradas, diff de campos cambiados, contexto de sesión, origen HTTP). Las tablas con `deleted_at` reciben baja lógica en el botón "Eliminar" del CRUD en lugar de `DELETE` físico. `TrackableColumnsService` rellena automáticamente `created_at/by`, `updated_at/by` en cada INSERT/UPDATE a partir de `$_SESSION['user_id']`; estas columnas nunca deben exponerse como editables en un formulario (`CrudService::save` las excluye) y deben llevar `COMMENT 'show:none'` en el esquema. Las tablas nuevas deben partir de `database/snippets/trackable_columns.sql`. El archivado de registros de auditoría antiguos mueve datos de `auditoria` a `auditoria_archivo` (ver README "Auditoría y archivo histórico" para el proceso por cron/manual).

### Módulo SGD

El módulo más grande y hecho a medida (a diferencia del motor CRUD genérico), accesible bajo `?url=sgd/...` y enrutado a través de `SgdController` → muchas clases `Sgd*Service` → `SgdRepository`. Documento de diseño funcional: `docs/sgd/FICHA_MODULO_SGD.md`.

Ideas clave:
- Todo está delimitado por `empresa_id` (desde la sesión o el filtro de superadmin). Los tipos documentales/arquetipos vienen de catálogos JSON en `config.example/` (`sgd_tipos_plantilla.json`, `sgd_arquetipos_operativos.json`, `sgd_secciones_m4_plantilla.json`), no están hardcodeados en PHP.
- Dos familias de documentos: **maestro** (documentos maestros fijos, p. ej. manuales — elaborados vía `sgd/elaboracion`) vs **operativo/dinámico** (plantillas tipo formulario diseñadas vía `sgd/formularios`, construidas a partir de una biblioteca de bloques reutilizable por arquetipo, versionadas, y luego diligenciadas como registros `sgd_registro` vía `sgd/registros`).
- Las plantillas operativas usan un **esquema v3** (`elementos[]`: bloques y campos intercalados). Cada registro diligenciado queda fijado a una `formulario_version_id` publicada específica; editar un borrador nunca afecta a las instancias ya abiertas.
- Importación de Excel/Word: `scripts/sgd_read_sheet.py` (necesita `python3` + `xlrd` para `.xls` legado) para la importación de CCD; los flujos de importación/previsualización de Word pasan por `SgdDocxImportService`/`DocxArchiveReader` y el renderizado de PDF por `SgdPdfGenerationService` (dompdf).
- Las secciones "Modulos refactorizados" y "SGD" del README.md listan el estado actual de las fases (F1–F7) y la tabla de rutas — consúltalas antes de asumir que una funcionalidad está terminada; varias fases están marcadas explícitamente como en progreso (🔄) o sin empezar (⏳).

## Convenciones a seguir

- Nunca SQL en `app/controllers`. Si necesitas nuevo acceso a datos, agrega o extiende un Repository.
- La lógica de negocio va en un Service, nombrado según el caso de uso (`buildMatrixResponse`, `changeContext`, etc.), no dispersa en los controllers.
- Las respuestas JSON de la API siguen `['success' => bool, 'message' => ...]` cuando aplique.
- No dupliques la lógica de breadcrumb/jerarquía o permisos por controller — centralízala en los services existentes (`MenuService`, `PermisoService`, `SgdScopeService`, etc.).
- Al agregar funcionalidad de CRUD puro, prefiere agregar filas `item`/`item_accion`/`permiso` en lugar de escribir un controller personalizado.
- La referencia completa de arquitectura, la tabla completa de directivas de `COLUMN_COMMENT` y el checklist previo al commit viven en `README.md` — trátalo como la especificación principal; este archivo es un mapa hacia él.
