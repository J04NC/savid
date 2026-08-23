/* ============================================================
   Estandariza mayúscula/minúscula en tercero.

   tercero.nombres y tercero.apellidos (persona natural) ya llevan la
   directiva `uppercase` de COLUMN_COMMENT (mayúscula forzada en
   formulario y persistencia, ver README.md "uppercase"). razon_social
   (persona jurídica) es el mismo concepto de nombre identificador para
   el otro tipo de tercero y no la llevaba: se iguala aquí.

   No cambia tipo, nulidad ni datos existentes — solo el comentario.
   ============================================================ */

ALTER TABLE tercero
    MODIFY COLUMN razon_social VARCHAR(255) NULL COMMENT 'uppercase';
