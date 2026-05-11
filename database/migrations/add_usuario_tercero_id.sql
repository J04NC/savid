-- Ejecutar una vez en la base del proyecto.
-- Identifica la persona natural/jurídica del usuario para validar username duplicado entre empresas.

-- Puedes documentar la columna con comentario libre (FK, type:, etc.); el CRUD ya no lo usa como filtro del combo.
-- Para limitar opciones del select: agregar en el comentario una parte |relfilter:1,2,3 (ids) o valores de etiqueta.
ALTER TABLE usuario
ADD COLUMN tercero_id INT UNSIGNED NULL COMMENT 'FK tercero' AFTER estado_id;

-- Opcional: si existe la tabla tercero y quieres integridad referencial:
ALTER TABLE usuario
ADD CONSTRAINT fk_usuario_tercero FOREIGN KEY (tercero_id) REFERENCES tercero(id);
