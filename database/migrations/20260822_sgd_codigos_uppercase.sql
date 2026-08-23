/* ============================================================
   Estandariza mayúscula en los códigos SGD editables por el usuario.

   sgd_proceso.codigo (GA, GC, GE, GF, GH) y sgd_tipo_documental.codigo
   (F, G, M, MT, PD) son códigos de negocio con letras que se escriben
   desde el CRUD genérico. Sus datos ya están en mayúscula de hecho;
   la directiva lo vuelve obligatorio en formulario y persistencia.

   NO se tocan a propósito otras columnas llamadas `codigo` que son
   claves técnicas comparadas por el código fuente, y que forzar a
   mayúscula rompería:
     - accion.codigo      -> ver, limpiar, guardar, eliminar
                             (switch de data-accion en public/js/crud.js
                             y resolución de permisos)
     - sgd_seccion.codigo -> alcance, anexos, control_cambios
                             (se cruzan con los catálogos JSON de
                             config.example/sgd_secciones_m4_plantilla.json)
   Tampoco las de DIVIPOLA/DANE (departamento, municipio, barrio…), que
   son numéricas y de datos semilla, no digitadas por el usuario.

   Se conserva el COMMENT descriptivo previo de cada columna y solo se
   le añade la directiva.
   ============================================================ */

ALTER TABLE sgd_proceso
    MODIFY COLUMN codigo VARCHAR(32) NOT NULL
    COMMENT 'Prefijo proceso|rel:sgd_proceso|label:codigo|uppercase';

ALTER TABLE sgd_tipo_documental
    MODIFY COLUMN codigo VARCHAR(16) NOT NULL
    COMMENT 'PD, F, R, M…|rel:sgd_tipo_documental|label:codigo|uppercase';
