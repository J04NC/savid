/* ============================================================
   Rediseño de `tercero_nomina`: ya NO existen filas "compartidas"
   (empresa_id NULL). Cada empresa administra su propio vínculo al
   tercero — puede repetirse el mismo tercero/terceroidentificacion en
   varias empresas (misma identidad, distinta fila), pero una empresa
   nunca ve ni puede tocar la fila de otra.

   Motivo (decisión del usuario, 2026-08-27): el modelo anterior con
   filas compartidas (empresa_id NULL + columna generada empresa_dedup
   para el índice único) permitía que, sin querer, una empresa alterara
   un tercero que en realidad usaban varias empresas — verificado con un
   caso real durante las pruebas: se creó una fila duplicada para
   PORVENIR/FONDO_CESANTIAS (una compartida ya existente + una nueva
   específica de una empresa) precisamente porque el modelo compartido
   no era obvio desde la pantalla.

   Se limpian los datos de prueba de `tercero_nomina` (no toca `tercero`
   ni `terceroidentificacion` — esa identidad se conserva) porque el
   índice único cambia de forma incompatible con las filas existentes
   con empresa_id NULL; se repueblan con
   scripts/import_tercero_nomina_sihos.php (ya actualizado para crear
   una fila por empresa en vez de una fila compartida).
============================================================ */

DELETE FROM tercero_nomina;

-- `uk_tercero_nomina` es el único índice con `terceroidentificacion_id` como
-- prefijo; sin este índice de apoyo, MySQL no deja soltarlo porque
-- fk_tercero_nomina_terceroident se queda sin índice donde apoyarse.
ALTER TABLE tercero_nomina
    ADD KEY idx_tercero_nomina_terceroident (terceroidentificacion_id);

ALTER TABLE tercero_nomina
    DROP KEY uk_tercero_nomina;

ALTER TABLE tercero_nomina
    DROP COLUMN empresa_dedup;

-- Comentario simple a propósito (sin `rel:`/`label:` de bridge): CrudService::
-- applyTerceroNominaColumnPresentation() oculta esta columna (show:none) y la
-- reemplaza por la columna sintética de solo lectura `empresa_nombre` —
-- combinar `label:` de bridge con el propio del campo rompía la etiqueta
-- ("tercero_id" en vez de "Empresa"), ver CrudService.php.
ALTER TABLE tercero_nomina
    MODIFY COLUMN empresa_id INT NOT NULL
        COMMENT 'order:9999|show:none|title:Empresa dueña de esta fila — se asigna sola desde la sesión al guardar, nunca la elige el usuario.';

ALTER TABLE tercero_nomina
    ADD CONSTRAINT uk_tercero_nomina UNIQUE KEY (terceroidentificacion_id, tipo_tercero_id, empresa_id);
