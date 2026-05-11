
SET FOREIGN_KEY_CHECKS = 1;

/*
   Siguientes pasos manuales (si aún no aplicaste):

   ALTER TABLE usuario ADD COLUMN tercero_id INT UNSIGNED NULL ...;
   ALTER TABLE usuario ADD CONSTRAINT fk_usuario_tercero
       FOREIGN KEY (tercero_id) REFERENCES tercero(id)
       ON DELETE SET NULL ON UPDATE CASCADE;
*/
